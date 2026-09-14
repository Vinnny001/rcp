<?php

/**
 * Boots the real Slim app in-process and renders each page as a
 * signed-in user, so template errors surface without a browser.
 *
 * Run: php tests/manual_render_pages.php
 */

declare(strict_types=1);

use DI\ContainerBuilder;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

/**
 * A fresh app per request. StudentContextMiddleware calls
 * Twig::addGlobal, which a Twig environment only accepts before its
 * first render — reusing one app across several requests in the same
 * process would throw on the second. A real request is one process,
 * so this only affects the harness.
 */
function buildApp(): Slim\App
{
    $containerBuilder = new ContainerBuilder();
    (require __DIR__ . '/../app/settings.php')($containerBuilder);
    (require __DIR__ . '/../app/dependencies.php')($containerBuilder);
    (require __DIR__ . '/../app/repositories.php')($containerBuilder);
    $container = $containerBuilder->build();

    AppFactory::setContainer($container);
    $app = AppFactory::create();
    (require __DIR__ . '/../app/middleware.php')($app);
    (require __DIR__ . '/../app/routes.php')($app);
    $app->addRoutingMiddleware();
    $app->addBodyParsingMiddleware();
    $app->addErrorMiddleware(true, false, false);

    return $app;
}

$pdo = buildApp()->getContainer()->get(PDO::class);

function userFor(PDO $pdo, string $roleName): array
{
    $stmt = $pdo->prepare(
        "SELECT u.user_id, u.first_name, u.last_name
         FROM users u
         JOIN user_roles ur ON ur.user_id = u.user_id AND ur.revoked_at IS NULL
         JOIN roles r ON r.role_id = ur.role_id
         WHERE r.role_name = :role
         LIMIT 1"
    );
    $stmt->execute(['role' => $roleName]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$pass = 0;
$fail = 0;

function assertThat(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    echo '  ' . ($ok ? 'PASS  ' : 'FAIL  ') . $label . ($detail !== '' ? ' — ' . $detail : '') . "\n";
}

function visit(string $path, string $role, array $user): ?string
{
    global $pass, $fail;

    $app = buildApp();

    $_SESSION = [
        'user_id'    => $user['user_id'],
        'role'       => $role,
        'first_name' => $user['first_name'],
        'last_name'  => $user['last_name'],
        'csrf_token' => str_repeat('a', 64),
    ];

    $request = (new ServerRequestFactory())->createServerRequest('GET', $path);

    try {
        $response = $app->handle($request);
        $status = $response->getStatusCode();
        $body = (string) $response->getBody();

        if ($status === 200) {
            $pass++;
            printf("  PASS  %-34s %s  %d bytes\n", $path, $status, strlen($body));
            return $body;
        }

        if ($status === 302) {
            $pass++;
            printf("  PASS  %-34s %s -> %s (guard)\n", $path, $status, $response->getHeaderLine('Location'));
            return null;
        }

        $fail++;
        printf("  FAIL  %-34s %s\n%s\n", $path, $status, substr(strip_tags($body), 0, 900));
    } catch (\Throwable $e) {
        $fail++;
        printf("  FAIL  %-34s threw %s: %s\n        %s:%d\n", $path, get_class($e), $e->getMessage(), $e->getFile(), $e->getLine());
    }

    return null;
}

// Prefer a lecturer who actually organises meetings, so the edit and
// review pages are reachable rather than skipped.
$lecturer = $pdo->query(
    "SELECT u.user_id, u.first_name, u.last_name
     FROM users u
     JOIN meetings m ON m.created_by = u.user_id
     JOIN lecturers l ON l.user_id = u.user_id
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC) ?: userFor($pdo, 'lecturer');

// Prefer a student far enough through onboarding to clear the
// profile-complete and registration gates.
$student = $pdo->query(
    "SELECT u.user_id, u.first_name, u.last_name
     FROM users u
     JOIN students s ON s.user_id = u.user_id
     WHERE s.student_number IS NOT NULL AND s.student_email IS NOT NULL
     ORDER BY (SELECT COUNT(*) FROM student_thesis_registrations str
                WHERE str.student_id = s.student_id AND str.status = 'active') DESC
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC) ?: userFor($pdo, 'student');

$admin = userFor($pdo, 'admin');

// Only internal lecturers supervise, so only they have a supervisor
// profile to fill in. An external examiner has no internal_lecturers
// row at all, and must get the page without a half-form on it.
$externalLecturer = $pdo->query(
    "SELECT u.user_id, u.first_name, u.last_name
     FROM users u
     JOIN lecturers l ON l.user_id = u.user_id
     LEFT JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
     WHERE il.lecturer_id IS NULL
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

$internalLecturer = $pdo->query(
    "SELECT u.user_id, u.first_name, u.last_name
     FROM users u
     JOIN lecturers l ON l.user_id = u.user_id
     JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

// The meetings page lists only what is still ahead (scheduled_at >=
// NOW()), so the attendance-code assertions below depend on this
// lecturer having a future meeting. Seed data drifts into the past as
// real time moves, which quietly turned those assertions into a
// failure rather than a skip — so one meeting is pushed forward for
// the duration of this run and put back afterwards, including if the
// script dies partway.
$shiftedMeeting = $pdo->query(
    "SELECT meeting_id, scheduled_at FROM meetings
     WHERE created_by = " . $pdo->quote($lecturer['user_id']) . "
       AND status <> 'cancelled' AND secure_code IS NOT NULL
     ORDER BY scheduled_at DESC LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if ($shiftedMeeting) {
    $pdo->prepare("UPDATE meetings SET scheduled_at = DATE_ADD(NOW(), INTERVAL 7 DAY) WHERE meeting_id = :id")
        ->execute(['id' => $shiftedMeeting['meeting_id']]);

    register_shutdown_function(static function () use ($pdo, $shiftedMeeting): void {
        $pdo->prepare("UPDATE meetings SET scheduled_at = :at WHERE meeting_id = :id")
            ->execute(['at' => $shiftedMeeting['scheduled_at'], 'id' => $shiftedMeeting['meeting_id']]);
    });
}

echo "\n--- lecturer pages ---\n";
$lecturerMeetings = visit('/lecturer/meetings', 'lecturer', $lecturer);
visit('/lecturer/my-documents', 'lecturer', $lecturer);
$internalProfile = $internalLecturer ? visit('/lecturer/profile', 'lecturer', $internalLecturer) : null;
$externalProfile = $externalLecturer ? visit('/lecturer/profile', 'lecturer', $externalLecturer) : null;

$meetingId = $pdo->query(
    "SELECT m.meeting_id FROM meetings m
     WHERE m.created_by = '{$lecturer['user_id']}'
     LIMIT 1"
)->fetchColumn();

$editPage = null;
$reviewPage = null;

if ($meetingId) {
    $editPage = visit("/lecturer/meetings/$meetingId/edit", 'lecturer', $lecturer);
    $reviewPage = visit("/lecturer/meetings/$meetingId/review", 'lecturer', $lecturer);
} else {
    echo "  SKIP  no meeting owned by this lecturer to open\n";
}

// The review form only appears when there is something to review, so
// attach a document briefly to exercise that branch, then clean up.
if ($meetingId) {
    $subjectDocId = $pdo->query(
        "SELECT d.document_id
         FROM meetings m
         JOIN thesis_proposals tp ON tp.proposal_id = m.proposal_id
         JOIN students s ON s.student_id = tp.student_id
         JOIN documents d ON d.user_id = s.user_id
         WHERE m.meeting_id = '$meetingId'
         LIMIT 1"
    )->fetchColumn();

    if ($subjectDocId) {
        $insert = $pdo->prepare(
            "INSERT INTO meeting_documents (meeting_document_id, meeting_id, document_id, added_by)
             VALUES (UUID(), :m, :d, :u)"
        );
        $insert->execute(['m' => $meetingId, 'd' => $subjectDocId, 'u' => $lecturer['user_id']]);

        try {
            $withDocs = visit("/lecturer/meetings/$meetingId/review", 'lecturer', $lecturer);
            if ($withDocs) {
                assertThat('review form appears once a document is attached', str_contains($withDocs, 'name="review_document_id[]"'));
                assertThat('and it requires the attendance code', str_contains($withDocs, 'name="secure_code"'));
                assertThat('and it offers a score and comment', str_contains($withDocs, 'name="review_score[]"') && str_contains($withDocs, 'name="review_comment[]"'));
            }
        } finally {
            $pdo->prepare("DELETE FROM meeting_documents WHERE meeting_id = :m AND document_id = :d")
                ->execute(['m' => $meetingId, 'd' => $subjectDocId]);
        }
    }
}

echo "\n--- student pages ---\n";
$studentMeetings = visit('/student/meetings', 'student', $student);
$studentExam = visit('/student/exam', 'student', $student);
$studentOutcomes = visit('/student/outcomes', 'student', $student);
visit('/student/documents', 'student', $student);

echo "\n--- content assertions ---\n";

// Every code currently on file, so we can prove none of them appear on
// a student page.
$allCodes = array_filter($pdo->query("SELECT secure_code FROM meetings")->fetchAll(PDO::FETCH_COLUMN));

if ($lecturerMeetings) {
    assertThat(
        'supervisor sees an attendance code on their meetings page',
        str_contains($lecturerMeetings, 'Attendance code')
    );

    $shown = array_filter($allCodes, fn($c) => str_contains($lecturerMeetings, $c));
    assertThat('at least one real code is rendered for the supervisor', count($shown) > 0, count($shown) . ' code(s)');

    // A student this lecturer supervises, who also holds a lecturer
    // account, must not be offered as a colleague to invite — they
    // could be the subject of the very meeting being scheduled.
    //
    // Scoped to THIS lecturer's supervisees on purpose. A lecturer
    // studying for their own degree under somebody else is a perfectly
    // ordinary examiner, and the student picker on this form only ever
    // offers this lecturer's own supervisions, so nobody else's
    // supervisee can end up as the subject here. An earlier version of
    // this check looked at every dual-role account on the system and
    // failed for exactly that reason.
    preg_match_all('/<select id="genInviteSelect".*?<\/select>/s', $lecturerMeetings, $m);
    $dropdown = $m[0][0] ?? '';
    $conflicted = $pdo->query(
        "SELECT DISTINCT s.user_id
         FROM students s
         JOIN lecturers l ON l.user_id = s.user_id
         JOIN supervision_assignments sa ON sa.student_id = s.student_id AND sa.is_active = 1
         JOIN lecturers sup ON sup.lecturer_id = sa.supervisor_id
         WHERE sup.user_id = " . $pdo->quote($lecturer['user_id'])
    )->fetchAll(PDO::FETCH_COLUMN);

    $leaked = array_filter($conflicted, fn($uid) => str_contains($dropdown, $uid));
    assertThat(
        'a supervisee who is also a lecturer is absent from the invite dropdown',
        count($leaked) === 0,
        count($conflicted) . ' dual-role supervisee(s) checked'
    );
}

if ($reviewPage) {
    // A meeting with nothing attached renders the empty notice and no
    // form at all; the code field belongs to the form, so the two cases
    // are asserted together rather than assuming which one this is.
    $hasNothingToReview = str_contains($reviewPage, 'No documents have been attached');
    $asksForCode = str_contains($reviewPage, 'name="secure_code"');

    assertThat(
        'review page asks for the attendance code whenever it offers a form',
        $hasNothingToReview xor $asksForCode,
        $hasNothingToReview ? 'nothing attached, so no form shown' : 'form shown with code field'
    );
}

if ($editPage) {
    assertThat('edit page offers documents for review', str_contains($editPage, 'name="review_documents[]"'));
    assertThat('edit page offers cancellation', str_contains($editPage, '/status'));
}

foreach (['meetings' => $studentMeetings, 'exam' => $studentExam, 'outcomes' => $studentOutcomes] as $name => $page) {
    if (!$page) {
        continue;
    }

    $leaked = array_filter($allCodes, fn($c) => str_contains($page, $c));
    assertThat("student /$name page contains no attendance code", count($leaked) === 0, count($allCodes) . ' code(s) checked');
}

if ($studentOutcomes) {
    assertThat('outcomes page explains the banding', str_contains($studentOutcomes, 'outcome rather than a mark'));
    assertThat('outcomes page shows no percentage column', !str_contains($studentOutcomes, 'score_percentage'));
}

if ($internalProfile) {
    assertThat('an internal lecturer can edit their research interests', str_contains($internalProfile, 'name="research_interests"'));
    assertThat('and can add a link students will read', str_contains($internalProfile, 'action="/lecturer/profile/links/add"'));
}

if ($externalProfile) {
    // The section is skipped whole rather than rendered empty — an
    // external examiner has nowhere to save interests to.
    assertThat(
        'an external lecturer is not shown a profile form they cannot save',
        !str_contains($externalProfile, 'name="research_interests"')
    );
    assertThat('but still gets their own page', str_contains($externalProfile, 'Supervision'));
}

echo "\n--- admin pages ---\n";
visit('/admin/dashboard', 'admin', $admin);
visit('/admin/users', 'admin', $admin);
visit('/admin/programs', 'admin', $admin);
visit('/admin/thesis-schedules', 'admin', $admin);
$examSchedules = visit('/admin/exam-schedules', 'admin', $admin);
$rubrics = visit('/admin/rubrics', 'admin', $admin);
visit('/admin/audit', 'admin', $admin);
visit('/admin/fee-rates', 'admin', $admin);

echo "\n--- supervisor requests, through the real controllers ---\n";
// The proposal page for every student with a registration, whatever
// state their request is in — each state renders a different branch.
foreach ($pdo->query(
    "SELECT DISTINCT u.user_id, u.first_name, u.last_name
     FROM users u
     JOIN students s ON s.user_id = u.user_id AND s.student_number IS NOT NULL AND s.student_email IS NOT NULL
     JOIN student_thesis_registrations str ON str.student_id = s.student_id AND str.status = 'active'"
)->fetchAll(PDO::FETCH_ASSOC) as $proposalStudent) {
    visit('/student/proposal', 'student', $proposalStudent);
}
visit('/student/supervisors', 'student', $student);

$coordinatorUser = $pdo->query(
    "SELECT u.user_id, u.first_name, u.last_name FROM users u
     JOIN research_coordinators rc ON rc.user_id = u.user_id LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if ($coordinatorUser) {
    $queuePage = visit('/coordinator/shortlists', 'lecturer', $coordinatorUser);
    foreach ($pdo->query(
        "SELECT sl.shortlist_id FROM supervisor_shortlists sl
         JOIN student_thesis_registrations str ON str.student_id = sl.student_id
         JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
         JOIN research_coordinators rc ON rc.program_id = ts.program_id
         WHERE rc.user_id = " . $pdo->quote($coordinatorUser['user_id']) . " AND sl.status <> 'draft'"
    )->fetchAll(PDO::FETCH_COLUMN) as $requestId) {
        visit('/coordinator/shortlists/' . $requestId, 'lecturer', $coordinatorUser);
    }
} else {
    echo "  SKIP  no research coordinator on file\n";
}

$askedLecturer = $pdo->query(
    "SELECT u.user_id, u.first_name, u.last_name FROM users u
     JOIN lecturers l ON l.user_id = u.user_id
     JOIN supervisor_shortlist_choices c ON c.lecturer_id = l.lecturer_id AND c.request_status = 'pending'
     LIMIT 1"
)->fetch(PDO::FETCH_ASSOC);

if ($askedLecturer) {
    $inboxPage = visit('/lecturer/supervision', 'lecturer', $askedLecturer);
    if ($inboxPage) {
        assertThat('a lecturer holding a request sees when to answer by', str_contains($inboxPage, 'Answer by'));
    }
} else {
    echo "  SKIP  no lecturer holding a request\n";
}

if ($examSchedules) {
    // Without this picker no window can be tied to a stage, and a
    // window with no stage is invisible to every student.
    assertThat('exam windows can be tagged with the stage they examine', str_contains($examSchedules, 'name="exam_stage_id"'));
    assertThat('and an untagged window is called out', str_contains($examSchedules, 'invisible to students'));
}

if ($rubrics) {
    assertThat('marking scheme rows are editable', str_contains($rubrics, 'action="/admin/rubrics/criteria/save"'));
    assertThat('and a row can be removed', str_contains($rubrics, 'action="/admin/rubrics/criteria/delete"'));
}

echo "\n$pass passed, $fail failed\n";
exit($fail === 0 ? 0 : 1);
