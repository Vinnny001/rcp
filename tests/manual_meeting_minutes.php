<?php

/**
 * The minutes of a supervisor-request meeting: typed, uploaded as a
 * document, or both, always with a summary — and who may read which.
 * The people at the meeting read the full minutes; the student only
 * ever gets the summary.
 *
 * Drives the real app (routes, controllers, templates) as well as the
 * model. Every app built here is handed this script's own PDO, so the
 * requests' writes happen inside the same transaction as the fixtures,
 * and all of it is rolled back at the end. Documents written to disk
 * are deleted explicitly.
 *
 * Run: php tests/manual_meeting_minutes.php
 */

declare(strict_types=1);

use App\Models\DepartmentHead;
use App\Models\ResearchCoordinator;
use App\Models\SupervisorShortlist;
use DI\ContainerBuilder;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\UploadedFile;

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

$pdo = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

/** A fresh app per request (Twig globals are set once per app), all on one connection. */
function buildApp(PDO $pdo): Slim\App
{
    $containerBuilder = new ContainerBuilder();
    (require __DIR__ . '/../app/settings.php')($containerBuilder);
    (require __DIR__ . '/../app/dependencies.php')($containerBuilder);
    (require __DIR__ . '/../app/repositories.php')($containerBuilder);
    $container = $containerBuilder->build();
    $container->set(PDO::class, $pdo);

    AppFactory::setContainer($container);
    $app = AppFactory::create();
    (require __DIR__ . '/../app/middleware.php')($app);
    (require __DIR__ . '/../app/routes.php')($app);
    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();
    $app->addErrorMiddleware(true, false, false);

    return $app;
}

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

function throws(callable $fn): ?string
{
    try {
        $fn();
        return null;
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

/**
 * One request through the app as this user. Fails the run on any PHP
 * notice, which the live app's ShutdownHandler would turn into a 500.
 *
 * @param array<string, string> $user user_id, first_name, last_name
 */
function send(PDO $pdo, string $method, string $path, array $user, string $role, array $form = [], array $files = []): ResponseInterface
{
    global $fail;

    $_SESSION = [
        'user_id'    => $user['user_id'],
        'role'       => $role,
        'first_name' => $user['first_name'],
        'last_name'  => $user['last_name'],
        'csrf_token' => str_repeat('a', 64),
    ];

    $request = (new ServerRequestFactory())->createServerRequest($method, $path);
    if ($method === 'POST') {
        $request = $request
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody($form + ['csrf_token' => str_repeat('a', 64)])
            ->withUploadedFiles($files);
    }

    $notices = [];
    set_error_handler(static function (int $type, string $message, string $file = '', int $line = 0) use (&$notices): bool {
        // Only because this script has already printed; a real request has not.
        if (!str_starts_with($message, 'session_start():')) {
            $notices[] = $message . ' (' . basename($file) . ':' . $line . ')';
        }
        return true;
    });
    try {
        $response = buildApp($pdo)->handle($request);
    } finally {
        restore_error_handler();
    }

    if ($notices) {
        $fail++;
        echo "  FAIL  $method $path raised notices the live app would turn into a 500:\n        "
            . implode("\n        ", $notices) . "\n";
    }

    return $response;
}

/** Takes the flash message a POST left behind. */
function flash(): string
{
    $message = ($_SESSION['flash_error'] ?? '') . ($_SESSION['flash_success'] ?? '');
    unset($_SESSION['flash_error'], $_SESSION['flash_success']);

    return $message;
}

/** A file in the scratch area, as the browser would have uploaded it. */
function upload(string $name, string $contents): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'minutes');
    file_put_contents($path, $contents);

    return new UploadedFile($path, $name, 'application/octet-stream', strlen($contents), UPLOAD_ERR_OK, false);
}

$minutesDir = __DIR__ . '/../var/uploads/minutes';
$filesBefore = is_dir($minutesDir) ? scandir($minutesDir) : [];
$newFiles = static fn (): array => is_dir($minutesDir) ? array_values(array_diff(scandir($minutesDir), $filesBefore, ['.', '..'])) : [];
$onDisk = static fn (?string $relative): bool => $relative !== null && is_file(__DIR__ . '/../var/uploads/' . $relative);

$pdo->beginTransaction();

try {
    $m  = new SupervisorShortlist($pdo);
    $rc = new ResearchCoordinator($pdo);
    $dh = new DepartmentHead($pdo);

    // ---- fixtures -----------------------------------------------------
    $admin = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();

    // Far enough through onboarding for the proposal page to render.
    $student = $pdo->query(
        "SELECT s.student_id, s.user_id, u.first_name, u.last_name, tp.proposal_id,
                p.program_id, p.department_id
         FROM students s
         JOIN users u ON u.user_id = s.user_id
         JOIN thesis_proposals tp ON tp.student_id = s.student_id
         JOIN student_thesis_registrations str ON str.student_id = s.student_id AND str.status = 'active'
         JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
         JOIN programs p ON p.program_id = ts.program_id
         WHERE s.student_number IS NOT NULL AND s.student_email IS NOT NULL
         LIMIT 1"
    )->fetch();
    $pdo->prepare("UPDATE thesis_proposals SET status = 'submitted' WHERE proposal_id = ? AND status = 'draft'")
        ->execute([$student['proposal_id']]);

    $lecturerUsers = $pdo->prepare(
        "SELECT u.user_id, u.first_name, u.last_name FROM users u
         JOIN lecturers l ON l.user_id = u.user_id
         WHERE u.user_id <> ? LIMIT 4"
    );
    $lecturerUsers->execute([$student['user_id']]);
    [$coordinator, $headA, $headB, $outsider] = $lecturerUsers->fetchAll();

    $rc->assign($student['program_id'], $coordinator['user_id'], $admin);
    $pos = $pdo->query("SELECT position_id FROM department_positions LIMIT 1")->fetchColumn();
    foreach ([$headA, $headB] as $head) {
        $dh->assign($student['department_id'], $head['user_id'], $pos, $admin);
    }
    $headIdOf = [];
    foreach ($dh->activeForDepartment($student['department_id']) as $row) {
        $headIdOf[$row['user_id']] = $row['dept_head_id'];
    }
    $invited = [$headIdOf[$headA['user_id']], $headIdOf[$headB['user_id']]];

    // The outsider must hold no part in this meeting.
    $pdo->prepare("UPDATE department_heads SET is_active = 0 WHERE user_id = ?")->execute([$outsider['user_id']]);
    $pdo->prepare("DELETE FROM research_coordinators WHERE user_id = ?")->execute([$outsider['user_id']]);

    $lects = $pdo->prepare(
        "SELECT l.lecturer_id FROM lecturers l
         JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
         WHERE l.user_id <> ? LIMIT 2"
    );
    $lects->execute([$student['user_id']]);
    $lects = $lects->fetchAll(PDO::FETCH_COLUMN);

    $newMeeting = function (?string $secretary = null) use ($m, $student, $lects, $invited, $coordinator): array {
        $sid = $m->submit($student['student_id'], $student['proposal_id'], [
            ['lecturer_id' => $lects[0], 'rank' => 1, 'preferred_main' => true],
            ['lecturer_id' => $lects[1], 'rank' => 2, 'preferred_main' => false],
        ]);
        $meeting = $m->scheduleMeeting($sid, '2026-10-07 10:00:00', 'physical', 'Boardroom', null,
            $invited, $coordinator['user_id'], null, $secretary);

        return [$sid, $meeting];
    };
    $meetingRow = fn (string $meetingId): array => $pdo->query(
        "SELECT * FROM shortlist_meetings WHERE meeting_id = " . $pdo->quote($meetingId)
    )->fetch();

    // =================================================================
    echo "\n=== What finalising needs ===\n";
    [, $meeting] = $newMeeting();

    check('a draft may be empty', throws(fn () => $m->saveMinutes($meeting, '', '')) === null);
    check('and is stored as nothing rather than blanks', $meetingRow($meeting)['summary'] === null && $meetingRow($meeting)['minutes'] === null);

    $m->saveMinutes($meeting, 'Heads discussed the list at length.', '');
    $err = (string) throws(fn () => $m->finalizeMinutes($meeting));
    check('finalising without a summary is refused', str_contains($err, 'Write a summary'), $err);
    check('and leaves the minutes open', $meetingRow($meeting)['minutes_finalized_at'] === null);

    $m->saveMinutes($meeting, '', 'Approved as sent.');
    $err = (string) throws(fn () => $m->finalizeMinutes($meeting));
    check('a summary without the minutes is refused too', str_contains($err, 'Type the minutes or upload them'), $err);

    check('a summary over the limit is refused',
        str_contains((string) throws(fn () => $m->saveMinutes($meeting, 'x', str_repeat('a', SupervisorShortlist::SUMMARY_MAX_LENGTH + 1))), 'Keep the summary'));
    check('line breaks count once, as the browser counted them',
        // 1001 characters as sent, 801 once each \r\n is one break.
        throws(fn () => $m->saveMinutes($meeting, 'x', str_repeat("abc\r\n", (int) (SupervisorShortlist::SUMMARY_MAX_LENGTH / 5)) . 'x')) === null);

    $m->saveMinutes($meeting, 'Heads discussed the list at length.', 'Approved as sent.');
    check('typed minutes and a summary can be finalised', throws(fn () => $m->finalizeMinutes($meeting)) === null);
    check('finalising again changes nothing', throws(fn () => $m->finalizeMinutes($meeting)) === null);
    $err = (string) throws(fn () => $m->saveMinutes($meeting, 'Rewritten.', 'Rewritten.'));
    check('final minutes can no longer be changed', str_contains($err, 'can no longer be changed'), $err);
    check('so they read as they were finalised', $meetingRow($meeting)['minutes'] === 'Heads discussed the list at length.');

    [, $docOnly] = $newMeeting();
    $m->saveMinutes($docOnly, '', 'Approved.', ['name' => 'minutes.pdf', 'path' => 'minutes/' . str_repeat('0', 32) . '.pdf', 'size_kb' => 1]);
    check('a document and a summary can be finalised without typed minutes', throws(fn () => $m->finalizeMinutes($docOnly)) === null);

    // =================================================================
    echo "\n=== Writing them on the coordinator screen ===\n";
    [$sid, $meeting] = $newMeeting();
    $post = fn (array $form, array $files = []) => send($pdo, 'POST', '/coordinator/shortlists/minutes', $coordinator, 'lecturer',
        $form + ['shortlist_id' => $sid], $files);

    $post(['minutes' => 'Typed before the summary.', 'summary' => '', 'finalize' => '1']);
    $message = flash();
    check('finalising without a summary says so', str_contains($message, 'Write a summary'), $message);
    check('and keeps what was typed as a draft',
        $meetingRow($meeting)['minutes'] === 'Typed before the summary.' && $meetingRow($meeting)['minutes_finalized_at'] === null);

    $post(['minutes' => 'Typed again.', 'summary' => 'A summary.'], ['minutes_file' => upload('minutes.txt', 'plain text')]);
    $message = flash();
    check('a document that is not PDF or Word is refused', str_contains($message, 'PDF or a Word document'), $message);
    check('while the rest is still saved',
        $meetingRow($meeting)['summary'] === 'A summary.' && $meetingRow($meeting)['minutes_file_path'] === null);

    $post(['minutes' => '', 'summary' => 'A summary.'], ['minutes_file' => upload('minutes.pdf', 'not really a pdf')]);
    $message = flash();
    check('a file only named .pdf is refused', str_contains($message, 'not a real PDF'), $message);
    check('and is not left on disk', $newFiles() === []);

    $post(['minutes' => '', 'summary' => 'A summary.'], ['minutes_file' => upload('First draft.pdf', '%PDF-1.4 first draft')]);
    $first = $meetingRow($meeting)['minutes_file_path'];
    check('a PDF is saved with the draft', $first !== null && $meetingRow($meeting)['minutes_file_name'] === 'First draft.pdf', flash());
    check('outside public/', $onDisk($first) && !str_contains((string) realpath(__DIR__ . '/../var/uploads/' . $first), 'public'));

    $post(['minutes' => '', 'summary' => 'A summary.'], ['minutes_file' => upload('Final.docx', "PK\x03\x04 word document")]);
    $second = $meetingRow($meeting)['minutes_file_path'];
    check('uploading another replaces it', $second !== $first && $meetingRow($meeting)['minutes_file_name'] === 'Final.docx', flash());
    check('and the replaced one is deleted from disk', !$onDisk($first) && $onDisk($second));

    $post(['minutes' => '', 'summary' => 'A summary.', 'remove_minutes_file' => '1']);
    flash();
    check('a document can be removed from a draft', $meetingRow($meeting)['minutes_file_path'] === null && !$onDisk($second));

    $post(['minutes' => '', 'summary' => 'A summary.', 'finalize' => '1']);
    $message = flash();
    check('with neither typed minutes nor a document it cannot be finalised', str_contains($message, 'Type the minutes or upload them'), $message);

    $post(
        ['minutes' => 'Secret deliberations: the second supervisor was questioned.', 'summary' => 'The heads approved the request as sent.', 'finalize' => '1'],
        ['minutes_file' => upload('Minutes 7 Oct.pdf', '%PDF-1.4 the signed minutes')]
    );
    $message = flash();
    check('with a summary, typed minutes and a document it is finalised', str_contains($message, 'Minutes finalised'), $message);
    $document = $meetingRow($meeting)['minutes_file_path'];

    $post(['minutes' => 'Changed later.', 'summary' => 'Changed later.']);
    $message = flash();
    check('a finalised meeting refuses further saves', str_contains($message, 'can no longer be changed'), $message);

    $page = (string) send($pdo, 'GET', '/coordinator/shortlists/' . $sid, $coordinator, 'lecturer')->getBody();
    check('the coordinator page leads with the summary', str_contains($page, 'The heads approved the request as sent.'));
    check('with the full minutes folded beneath it', str_contains($page, '<details') && str_contains($page, 'Full minutes'));
    check('including the typed minutes and the document', str_contains($page, 'Secret deliberations') && str_contains($page, 'Minutes 7 Oct.pdf'));
    check('and no minutes form any more', !str_contains($page, 'action="/coordinator/shortlists/minutes"'));

    // =================================================================
    echo "\n=== Who can open the document ===\n";
    $documentUrl = '/lecturer/shortlist-meetings/' . $meeting . '/minutes-document';

    $response = send($pdo, 'GET', $documentUrl, $coordinator, 'lecturer');
    check('the coordinator can', $response->getStatusCode() === 200 && str_starts_with((string) $response->getBody(), '%PDF'));
    check('a PDF opens in the browser, under its own name',
        $response->getHeaderLine('Content-Type') === 'application/pdf'
        && str_contains($response->getHeaderLine('Content-Disposition'), 'inline')
        && str_contains($response->getHeaderLine('Content-Disposition'), 'Minutes%207%20Oct.pdf'));
    check('an invited head can', send($pdo, 'GET', $documentUrl, $headA, 'lecturer')->getStatusCode() === 200);
    check('a lecturer with no part in the meeting cannot', send($pdo, 'GET', $documentUrl, $outsider, 'lecturer')->getStatusCode() === 403);
    $response = send($pdo, 'GET', $documentUrl, $student, 'student');
    check('the student cannot', $response->getStatusCode() === 302 && $response->getHeaderLine('Location') === '/login');
    check('a document missing from disk is a 404, not an error',
        send($pdo, 'GET', '/lecturer/shortlist-meetings/' . $docOnly . '/minutes-document', $coordinator, 'lecturer')->getStatusCode() === 404);

    check('the lead belongs to the meeting', $m->belongsToMeeting($meeting, $coordinator['user_id']));
    check('as do the invited heads', $m->belongsToMeeting($meeting, $headA['user_id']) && $m->belongsToMeeting($meeting, $headB['user_id']));
    check('the student does not', !$m->belongsToMeeting($meeting, $student['user_id']));
    check('nor does anyone else', !$m->belongsToMeeting($meeting, $outsider['user_id']));

    // =================================================================
    echo "\n=== A head's meetings page ===\n";
    $page = (string) send($pdo, 'GET', '/lecturer/shortlist-meetings', $headA, 'lecturer')->getBody();
    check('an invited head reads the summary', str_contains($page, 'The heads approved the request as sent.'));
    check('with the full minutes available to them', str_contains($page, 'Full minutes') && str_contains($page, $documentUrl));

    [, $headWrites] = $newMeeting($headB['user_id']);
    check('a meeting with no document is a 404',
        send($pdo, 'GET', '/lecturer/shortlist-meetings/' . $headWrites . '/minutes-document', $headA, 'lecturer')->getStatusCode() === 404);
    send($pdo, 'POST', '/lecturer/shortlist-meetings/minutes', $headA, 'lecturer',
        ['meeting_id' => $headWrites, 'minutes' => 'Not mine to write.', 'summary' => 'No.', 'finalize' => '1']);
    $message = flash();
    check('a head who is not the secretary cannot write them', str_contains($message, 'not the one writing'), $message);

    $page = (string) send($pdo, 'GET', '/lecturer/shortlist-meetings', $headB, 'lecturer')->getBody();
    check('the secretary gets the form, with the summary required',
        str_contains($page, 'name="summary"') && str_contains($page, 'enctype="multipart/form-data"'));

    send($pdo, 'POST', '/lecturer/shortlist-meetings/minutes', $headB, 'lecturer',
        ['meeting_id' => $headWrites, 'minutes' => 'Written by the secretary.', 'summary' => 'Deferred to the next sitting.', 'finalize' => '1']);
    $message = flash();
    check('and can finalise them there', str_contains($message, 'Minutes finalised') && $meetingRow($headWrites)['minutes_finalized_at'] !== null, $message);

    // =================================================================
    echo "\n=== The student gets the summary, never the minutes ===\n";
    // The request under test must be the student's latest.
    $pdo->prepare("UPDATE supervisor_shortlists SET created_at = NOW() + INTERVAL 1 MINUTE WHERE shortlist_id = ?")->execute([$sid]);

    check('nothing reaches the student before the decision is applied',
        $m->latestSentForStudent($student['student_id'])['meeting_summary'] === null);
    $page = (string) send($pdo, 'GET', '/student/proposal', $student, 'student')->getBody();
    check('not on their page either', !str_contains($page, 'The heads approved the request as sent.'));

    $m->castVote($meeting, $invited[0], 'reject');
    $m->approveMinutes($meeting, $coordinator['user_id']);
    check('the department turns it down', $m->recordOutcome($meeting) === 'rejected');

    $request = $pdo->query("SELECT rejection_reason FROM supervisor_shortlists WHERE shortlist_id = " . $pdo->quote($sid))->fetch();
    check('the reason recorded is the summary', $request['rejection_reason'] === 'The heads approved the request as sent.');

    $page = (string) send($pdo, 'GET', '/student/proposal', $student, 'student')->getBody();
    check('the student reads the summary', str_contains($page, 'The heads approved the request as sent.'));
    check('but not the typed minutes', !str_contains($page, 'Secret deliberations'));
    check('nor the document', !str_contains($page, 'minutes-document') && !str_contains($page, 'Minutes 7 Oct.pdf'));
    check('and the model never hands the minutes to the student page',
        !array_key_exists('minutes', $m->latestSentForStudent($student['student_id'])));

    // =================================================================
    echo "\n=== Minutes finalised before summaries existed ===\n";
    [$oldSid, $old] = $newMeeting();
    $pdo->prepare(
        "UPDATE shortlist_meetings SET minutes = 'Old minutes, no summary.', summary = NULL,
                minutes_finalized_at = NOW(), minutes_approved_at = NOW(), minutes_approved_by = ?
         WHERE meeting_id = ?"
    )->execute([$coordinator['user_id'], $old]);
    $m->castVote($old, $invited[0], 'approve');
    $m->castVote($old, $invited[1], 'reject');
    $m->recordOutcome($old);

    $tied = 'The department did not approve this supervisor request: the vote was tied, 1 for and 1 against, and a tie does not approve.';
    check('applying the decision writes a summary from the vote', $meetingRow($old)['summary'] === $tied, (string) $meetingRow($old)['summary']);
    check('and gives the student that, not the old minutes',
        $pdo->query("SELECT rejection_reason FROM supervisor_shortlists WHERE shortlist_id = " . $pdo->quote($oldSid))->fetchColumn() === $tied);
    check('an approval reads as one',
        SupervisorShortlist::decisionSummary('approved', 2, 1) === 'The department approved this supervisor request, with 2 for and 1 against.');
    check('a plain rejection too',
        SupervisorShortlist::decisionSummary('rejected', 0, 2) === 'The department did not approve this supervisor request, with 0 for and 2 against.');

    echo "\n=== Long text on the student page is cut short ===\n";
    $page = (string) send($pdo, 'GET', '/student/proposal', $student, 'student')->getBody();
    check('an earlier request\'s outcome is held to one line, with View more',
        (bool) preg_match('~class="view-more view-more-inline"[^>]*>\s*<div class="view-more-text" style="-webkit-line-clamp:1;[^"]*">'
            . preg_quote($tied, '~') . '</div>\s*<button type="button" class="view-more-open" hidden>View more</button>~', $page));
    $synopsis = $pdo->query("SELECT synopsis FROM thesis_proposals WHERE proposal_id = " . $pdo->quote($student['proposal_id']))->fetchColumn();
    check('the proposal synopsis to five lines',
        !$synopsis || str_contains($page, '-webkit-line-clamp:5;'));
    check('with one dialog for the page to open them in', substr_count($page, 'id="viewMoreDialog"') === 1);

    echo "\n=== The record on file ===\n";
    check('every applied decision has a summary',
        (int) $pdo->query(
            "SELECT COUNT(*) FROM shortlist_meetings m JOIN supervisor_shortlists s ON s.shortlist_id = m.shortlist_id
             WHERE m.minutes_finalized_at IS NOT NULL AND m.summary IS NULL
               AND s.status NOT IN ('pending_coordinator', 'meeting_scheduled')"
        )->fetchColumn() === 0);
    check('no turned-down request shows the student its full minutes',
        (int) $pdo->query(
            "SELECT COUNT(*) FROM supervisor_shortlists s JOIN shortlist_meetings m ON m.shortlist_id = s.shortlist_id
             WHERE s.status = 'rejected' AND m.minutes IS NOT NULL
               AND s.rejection_reason = m.minutes AND s.rejection_reason <> COALESCE(m.summary, '')"
        )->fetchColumn() === 0);
} catch (\Throwable $e) {
    $fail++;
    echo "  FAIL  threw " . get_class($e) . ': ' . $e->getMessage() . "\n        " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    $pdo->rollBack();

    // Rolling back the rows does not remove the files the uploads wrote.
    foreach ($newFiles() as $left) {
        @unlink($minutesDir . '/' . $left);
    }
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
