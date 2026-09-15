<?php

/**
 * Nobody holds a role over their own studies.
 *
 * Staff studying for their own degree have a student record and a
 * lecturer record against one user. This makes such a person — a
 * student who is also an internal lecturer, a department head and, by
 * force, their own program's coordinator — and checks they can never
 * act as their own research coordinator, supervisor or examiner, are
 * never invited to the department meeting on their own supervisor
 * request, and never vote on it.
 *
 * Drives the real app as well as the models. Every app built here is
 * handed this script's PDO, so everything happens inside one
 * transaction that is rolled back at the end.
 *
 * Run: php tests/manual_own_record.php
 */

declare(strict_types=1);

use App\Models\DepartmentHead;
use App\Models\ExaminerAssignment;
use App\Models\ExaminerQualification;
use App\Models\ExamMeeting;
use App\Models\ExamReadiness;
use App\Models\ResearchCoordinator;
use App\Models\StudentJourney;
use App\Models\SupervisorProfile;
use App\Models\SupervisorShortlist;
use DI\ContainerBuilder;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

$pdo = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

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

function throws(callable $fn): string
{
    try {
        $fn();
        return '';
    } catch (\Throwable $e) {
        return $e->getMessage();
    }
}

/** One request through the app. Fails the run on any PHP notice. */
function send(PDO $pdo, string $method, string $path, string $userId, string $role, array $form = []): ResponseInterface
{
    global $fail;

    $_SESSION = [
        'user_id' => $userId, 'role' => $role, 'first_name' => 'Test', 'last_name' => 'User',
        'csrf_token' => str_repeat('a', 64),
    ];
    $request = (new ServerRequestFactory())->createServerRequest($method, $path);
    if ($method === 'POST') {
        $request = $request->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withParsedBody($form + ['csrf_token' => str_repeat('a', 64)]);
    }

    $notices = [];
    set_error_handler(static function (int $type, string $message, string $file = '', int $line = 0) use (&$notices): bool {
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
        echo "  FAIL  $method $path raised notices:\n        " . implode("\n        ", $notices) . "\n";
    }

    return $response;
}

function flash(): string
{
    $message = ($_SESSION['flash_error'] ?? '') . ($_SESSION['flash_success'] ?? '');
    unset($_SESSION['flash_error'], $_SESSION['flash_success']);

    return $message;
}

$pdo->beginTransaction();

try {
    $m      = new SupervisorShortlist($pdo);
    $rc     = new ResearchCoordinator($pdo);
    $dh     = new DepartmentHead($pdo);
    $quals  = new ExaminerQualification($pdo);
    $admin  = $pdo->query(
        "SELECT ur.user_id FROM user_roles ur JOIN roles r ON r.role_id = ur.role_id
         WHERE r.role_name = 'admin' AND ur.revoked_at IS NULL LIMIT 1"
    )->fetchColumn();

    // ---- the staff member studying for their own degree ------------------
    $student = $pdo->query(
        "SELECT st.student_id, st.user_id, tp.proposal_id, p.program_id, p.department_id, ts.schedule_id
         FROM students st
         JOIN thesis_proposals tp ON tp.student_id = st.student_id AND tp.status <> 'rejected'
         JOIN student_thesis_registrations str ON str.student_id = st.student_id AND str.status = 'active'
         JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
         JOIN programs p ON p.program_id = ts.program_id
         WHERE st.student_number IS NOT NULL
         LIMIT 1"
    )->fetch();
    $me = $student['user_id'];
    $pdo->prepare("UPDATE thesis_proposals SET status = 'submitted' WHERE proposal_id = ? AND status = 'draft'")
        ->execute([$student['proposal_id']]);

    // Give them a lecturer record, internal, if they have none.
    $myLecturerId = $pdo->query("SELECT lecturer_id FROM lecturers WHERE user_id = " . $pdo->quote($me))->fetchColumn();
    if (!$myLecturerId) {
        $myLecturerId = $pdo->query("SELECT UUID()")->fetchColumn();
        $pdo->prepare("INSERT INTO lecturers (lecturer_id, user_id) VALUES (?, ?)")->execute([$myLecturerId, $me]);
    }
    if (!$pdo->query("SELECT 1 FROM internal_lecturers WHERE lecturer_id = " . $pdo->quote($myLecturerId))->fetchColumn()) {
        $pdo->prepare(
            "INSERT INTO internal_lecturers (staff_id, lecturer_id, staff_number, department_id) VALUES (UUID(), ?, ?, ?)"
        )->execute([$myLecturerId, 'OWN-' . bin2hex(random_bytes(4)), $student['department_id']]);
    }

    // Colleagues: a real coordinator, two heads, another internal lecturer.
    $colleagues = $pdo->prepare(
        "SELECT l.user_id, l.lecturer_id FROM lecturers l
         JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
         WHERE l.user_id <> ? LIMIT 4"
    );
    $colleagues->execute([$me]);
    [$coordinator, $headA, $headB, $examiner] = $colleagues->fetchAll();

    $setCoordinator = function (string $userId) use ($pdo, $student, $admin): void {
        // Straight into the table: the conflict has to be possible to
        // test what happens when it arises anyway.
        $pdo->prepare("DELETE FROM research_coordinators WHERE program_id = ?")->execute([$student['program_id']]);
        $pdo->prepare("INSERT INTO research_coordinators (coordinator_id, program_id, user_id, assigned_by) VALUES (UUID(), ?, ?, ?)")
            ->execute([$student['program_id'], $userId, $admin]);
    };

    // =====================================================================
    echo "\n=== Not their own research coordinator ===\n";
    $err = throws(fn () => $rc->assign($student['program_id'], $me, $admin));
    check('an administrator cannot make a student the coordinator of their own program', str_contains($err, 'student on this program'), $err);

    $otherProgram = $pdo->prepare(
        "SELECT p.program_id FROM programs p WHERE p.program_id <> ?
           AND NOT EXISTS (SELECT 1 FROM student_thesis_registrations str
                           JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
                           WHERE str.student_id = ? AND ts.program_id = p.program_id AND str.status = 'active')
         LIMIT 1"
    );
    $otherProgram->execute([$student['program_id'], $student['student_id']]);
    $otherProgram = $otherProgram->fetchColumn();
    check('but they can coordinate a program they do not study on',
        $otherProgram === false || throws(fn () => $rc->assign($otherProgram, $me, $admin)) === '');

    send($pdo, 'POST', '/admin/research-roles/coordinator', $admin, 'admin', ['program_id' => $student['program_id'], 'user_id' => $me]);
    $message = flash();
    check('the admin screen says why', str_contains($message, 'student on this program'), $message);

    // Registered after being made coordinator: the conflict exists anyway.
    $setCoordinator($me);
    $row = array_values(array_filter($rc->allProgramsWithCoordinator(), fn ($p) => $p['program_id'] === $student['program_id']))[0];
    check('the admin screen flags a coordinator who studies on the program', (bool) $row['coordinator_studies_here']);
    $page = (string) send($pdo, 'GET', '/admin/research-roles', $admin, 'admin')->getBody();
    check('and tells the administrator to assign someone else', str_contains($page, 'cannot coordinate their own studies'));

    $lects = $pdo->prepare(
        "SELECT l.lecturer_id FROM lecturers l JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
         WHERE l.user_id NOT IN (?, ?) LIMIT 2"
    );
    $lects->execute([$me, $coordinator['user_id']]);
    $lects = $lects->fetchAll(PDO::FETCH_COLUMN);
    $sid = $m->submit($student['student_id'], $student['proposal_id'], [
        ['lecturer_id' => $lects[0], 'rank' => 1, 'preferred_main' => true],
        ['lecturer_id' => $lects[1], 'rank' => 2, 'preferred_main' => false],
    ]);

    $page = (string) send($pdo, 'GET', '/coordinator/shortlists', $me, 'lecturer')->getBody();
    check('their own request is not in their coordinator queue', !str_contains($page, $sid));
    check('and the queue says why', str_contains($page, 'not listed here'));

    $response = send($pdo, 'GET', '/coordinator/shortlists/' . $sid, $me, 'lecturer');
    $message = flash();
    check('opening it directly is refused', $response->getStatusCode() === 302 && str_contains($message, 'your own record'), $message);

    send($pdo, 'POST', '/coordinator/shortlists/grant-edit', $me, 'lecturer', ['shortlist_id' => $sid, 'proposal' => '1']);
    $message = flash();
    check('and so is acting on it', str_contains($message, 'your own record'), $message);

    // =====================================================================
    echo "\n=== Not their own supervisor ===\n";
    $err = throws(fn () => $m->submit($student['student_id'], $student['proposal_id'], [
        ['lecturer_id' => $myLecturerId, 'rank' => 1, 'preferred_main' => true],
    ]));
    check('a student cannot put themselves on their supervisor list', str_contains($err, 'yourself'), $err);
    $offered = array_column((new SupervisorProfile($pdo))->browsable($me), 'lecturer_id');
    check('and the picker does not offer them', !in_array($myLecturerId, $offered, true));
    check('while it still offers them to everyone else',
        in_array($myLecturerId, array_column((new SupervisorProfile($pdo))->browsable(), 'lecturer_id'), true));

    // =====================================================================
    echo "\n=== Not invited to the meeting on their own request ===\n";
    $setCoordinator($coordinator['user_id']);
    $pos = $pdo->query("SELECT position_id FROM department_positions LIMIT 1")->fetchColumn();
    foreach ([$me, $headA['user_id'], $headB['user_id']] as $userId) {
        $dh->assign($student['department_id'], $userId, $pos, $admin);
    }
    $headIdOf = array_column($dh->activeForDepartment($student['department_id']), 'dept_head_id', 'user_id');
    $myHeadId = $headIdOf[$me];
    $otherHeads = [$headIdOf[$headA['user_id']], $headIdOf[$headB['user_id']]];

    $page = (string) send($pdo, 'GET', '/coordinator/shortlists/' . $sid, $coordinator['user_id'], 'lecturer')->getBody();
    check('the coordinator is not offered the student among the heads to invite',
        str_contains($page, 'dept_head_ids[]') && !str_contains($page, 'value="' . $myHeadId . '"'));
    check('nor as lead or secretary', !str_contains($page, '<option value="' . $me . '"'));

    $schedule = fn (array $heads, ?string $lead = null, ?string $secretary = null, ?string $by = null) => throws(
        fn () => $m->scheduleMeeting($sid, '2026-10-09 10:00:00', 'physical', 'Boardroom', null, $heads,
            $by ?? $coordinator['user_id'], $lead, $secretary)
    );
    $err = $schedule(array_merge($otherHeads, [$myHeadId]));
    check('inviting the student as a head is refused', str_contains($err, 'cannot be invited'), $err);
    $err = $schedule($otherHeads, $me);
    check('so is making them the lead', str_contains($err, 'cannot lead or minute'), $err);
    $err = $schedule($otherHeads, null, $me);
    check('or the secretary', str_contains($err, 'cannot lead or minute'), $err);
    $err = $schedule($otherHeads, null, null, $me);
    check('and they cannot convene it themselves', str_contains($err, 'your own'), $err);

    send($pdo, 'POST', '/coordinator/shortlists/schedule', $coordinator['user_id'], 'lecturer', [
        'shortlist_id' => $sid, 'scheduled_at' => '2026-10-09 10:00:00', 'mode' => 'physical',
        'dept_head_ids' => array_merge($otherHeads, [$myHeadId]),
    ]);
    $message = flash();
    check('a posted form naming them is refused the same way', str_contains($message, 'cannot be invited'), $message);

    $meeting = $m->scheduleMeeting($sid, '2026-10-09 10:00:00', 'physical', 'Boardroom', null, $otherHeads, $coordinator['user_id']);
    check('without them, the meeting is scheduled', $meeting !== '');

    echo "\n=== And never vote on it ===\n";
    // An invitation that got in some other way — an older row, a hand edit.
    $pdo->prepare("INSERT INTO shortlist_meeting_invitees (invitee_id, meeting_id, dept_head_id) VALUES (UUID(), ?, ?)")
        ->execute([$meeting, $myHeadId]);

    $err = throws(fn () => $m->castVote($meeting, $myHeadId, 'approve'));
    check('an invited head who is the student cannot vote', str_contains($err, 'own supervisor request'), $err);
    $err = throws(fn () => $m->castVoteAs($meeting, $me, 'approve'));
    check('by any route', str_contains($err, 'own supervisor request'), $err);
    check('they are not counted among the voters', !in_array($me, array_column($m->meetingVoters($meeting), 'voter_user_id'), true));
    check('so the tally does not wait on them', $m->tally($meeting)['invited'] === count($m->meetingVoters($meeting)));
    check('the meeting is not on their heads\' page', !in_array($meeting, array_column($m->meetingsForHead($me), 'meeting_id'), true));
    check('and they cannot read its full minutes', !$m->belongsToMeeting($meeting, $me));

    send($pdo, 'POST', '/lecturer/shortlist-meetings/vote', $me, 'lecturer', ['meeting_id' => $meeting, 'vote' => 'approve']);
    $message = flash();
    check('voting through the heads\' page is refused', str_contains($message, 'own supervisor request'), $message);

    $setCoordinator($me);
    $err = throws(fn () => $m->castVoteAs($meeting, $me, 'reject'));
    check('nor as the program\'s coordinator', str_contains($err, 'own supervisor request'), $err);
    check('where they are not listed as a voter either', !in_array($me, array_column($m->meetingVoters($meeting), 'voter_user_id'), true));
    check('no vote of theirs was recorded',
        (int) $pdo->query("SELECT COUNT(*) FROM shortlist_meeting_votes WHERE meeting_id = " . $pdo->quote($meeting)
            . " AND voter_user_id = " . $pdo->quote($me))->fetchColumn() === 0);
    check('the other heads still vote', throws(fn () => $m->castVote($meeting, $otherHeads[0], 'approve')) === '');
    $setCoordinator($coordinator['user_id']);

    // =====================================================================
    echo "\n=== Not their own examiner ===\n";
    // An exam for the stage they are on, open now, needing no documents
    // and no fees — so only the own-record rules stand in the way.
    $currentStage = (new StudentJourney($pdo))->currentExamStage($student['student_id'], $me)['stage_id'] ?? null;
    $window = $pdo->query("SELECT UUID()")->fetchColumn();
    $pdo->prepare(
        "INSERT INTO exam_schedule (exam_schedule_id, thesis_schedule_id, starts_at, ends_at, exam_type, exam_stage_id, exam_schedule_description)
         VALUES (?, ?, NOW(), NOW() + INTERVAL 30 DAY, 'internal', ?, 'Own record exam')"
    )->execute([$window, $student['schedule_id'], $currentStage]);
    $pdo->prepare("UPDATE thesis_schedules SET thesis_registration_rates_id = NULL WHERE schedule_id = ?")->execute([$student['schedule_id']]);
    $readinessId = $pdo->query("SELECT UUID()")->fetchColumn();
    $pdo->prepare("INSERT INTO exam_readiness (readiness_id, student_id, exam_schedule_id) VALUES (?, ?, ?)")
        ->execute([$readinessId, $student['student_id'], $window]);
    $quals->add($myLecturerId, $student['program_id'], $admin);
    $quals->add($examiner['lecturer_id'], $student['program_id'], $admin);

    $readiness = new ExamReadiness($pdo);
    $scheduleExam = fn (array $panel, ?string $leader = null, ?string $by = null) => throws(
        fn () => $readiness->scheduleExam($readinessId, $student['program_id'], $panel, $leader,
            '2026-10-20 09:00:00', 'physical', 'Boardroom', null, $by ?? $coordinator['user_id'])
    );
    $err = $scheduleExam([$examiner['lecturer_id'], $myLecturerId]);
    check('the student cannot be on their own exam panel', str_contains($err, 'cannot examine their own work'), $err);
    $err = $scheduleExam([$examiner['lecturer_id'], $myLecturerId], $myLecturerId);
    check('or lead it', str_contains($err, 'cannot examine their own work'), $err);
    $err = $scheduleExam([$examiner['lecturer_id']], null, $me);
    check('or schedule it', str_contains($err, 'your own'), $err);

    $page = (string) send($pdo, 'GET', '/coordinator/exams', $coordinator['user_id'], 'lecturer')->getBody();
    $start = strpos($page, 'value="' . $readinessId . '"');
    $form = $start === false ? '' : substr($page, $start, (int) strpos($page, '</form>', $start) - $start);
    check('the exam queue offers a panel for their exam', $form !== '' && str_contains($form, $examiner['lecturer_id']));
    check('without them on it', $form !== '' && !str_contains($form, $myLecturerId));

    $examMeeting = $readiness->scheduleExam($readinessId, $student['program_id'], [$examiner['lecturer_id']], null,
        '2026-10-20 09:00:00', 'physical', 'Boardroom', null, $coordinator['user_id']);
    check('with a proper panel the exam is scheduled', $examMeeting !== '');

    $exams = new ExamMeeting($pdo);
    check('the student cannot lead their own exam', str_contains(throws(fn () => $exams->setRoles($examMeeting, $me, null)), 'their own exam'));
    check('or minute it', str_contains(throws(fn () => $exams->setRoles($examMeeting, null, $me)), 'their own exam'));

    // An examiner invitation that got in some other way.
    $pdo->prepare("INSERT INTO meeting_attendees (attendee_id, meeting_id, user_id, role_in_meeting, attendance_status) VALUES (UUID(), ?, ?, 'examiner', 'invited')")
        ->execute([$examMeeting, $me]);
    $assignments = new ExaminerAssignment($pdo);
    check('even listed as an examiner, they cannot open the marking sheet', $assignments->findForExaminer($examMeeting, $me) === null);
    check('and it is not on their examining page', !in_array($examMeeting, array_column($assignments->forExaminer($me), 'meeting_id'), true));
    send($pdo, 'POST', '/lecturer/examining/save', $me, 'lecturer', ['meeting_id' => $examMeeting, 'score' => []]);
    $message = flash();
    check('so they cannot mark it', str_contains($message, 'not an examiner'), $message);

    $setCoordinator($me);
    $response = send($pdo, 'GET', '/coordinator/exams/' . $examMeeting, $me, 'lecturer');
    $message = flash();
    check('as coordinator they cannot open their own exam', $response->getStatusCode() === 302 && str_contains($message, 'your own record'), $message);
    $page = (string) send($pdo, 'GET', '/coordinator/exams', $me, 'lecturer')->getBody();
    check('and it is left off their exam screen', !str_contains($page, $examMeeting) && str_contains($page, 'not listed here'));
} catch (\Throwable $e) {
    $fail++;
    echo "  FAIL  threw " . get_class($e) . ': ' . $e->getMessage() . "\n        " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    $pdo->rollBack();
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
