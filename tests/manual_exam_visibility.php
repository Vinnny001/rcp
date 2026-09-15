<?php

/**
 * Which exams — and which of their documents — a student can see.
 *
 * Exams are taken in order, so a student sees only the exam for the
 * stage they are on: not the stages they have passed, not the ones they
 * have not reached. And only within their time on the programme: nothing
 * that closed before they registered, nothing closing after they
 * graduated. A document is seen only through a visible exam, and not
 * when its own deadline passed before they registered. What a student
 * cannot see they are not charged for, and cannot upload to.
 *
 * The student is moved onto a fresh thesis schedule holding only this
 * test's windows. Drives the real pages as well as the models, all on
 * one connection inside a transaction that is rolled back at the end.
 *
 * Run: php tests/manual_exam_visibility.php
 */

declare(strict_types=1);

use App\Models\ExamReadiness;
use App\Models\GradingPolicy;
use App\Models\StudentExamWindows;
use App\Models\StudentJourney;
use App\Models\ThesisRegistration;
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

/** One request through the app as the student. Fails the run on any PHP notice. */
function send(PDO $pdo, string $method, string $path, array $user, array $form = []): ResponseInterface
{
    global $fail;

    $_SESSION = [
        'user_id' => $user['user_id'], 'role' => 'student', 'first_name' => 'Test', 'last_name' => 'Student',
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

$pdo->beginTransaction();

try {
    $admin = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();
    $stages = (new App\Models\ExamStage($pdo))->allActive();
    [$first, $second] = $stages;

    // ---- a student at the start of the exams ---------------------------
    // Reset each candidate to nothing passed — no stage recorded, nothing
    // released, proposal not approved — and take the first who is then on
    // the first stage. (Older document scores can still count as a pass;
    // those students are passed over.)
    $student = null;
    foreach ($pdo->query(
        "SELECT st.student_id, st.user_id, str.thesis_registration_id, ts.program_id, tp.proposal_id
         FROM students st
         JOIN student_thesis_registrations str ON str.student_id = st.student_id AND str.status = 'active'
         JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
         JOIN thesis_proposals tp ON tp.student_id = st.student_id AND tp.status <> 'rejected'
         WHERE st.student_number IS NOT NULL AND st.student_email IS NOT NULL"
    )->fetchAll() as $candidate) {
        $cid = $candidate['student_id'];
        $pdo->prepare("DELETE FROM student_stage_progress WHERE student_id = ?")->execute([$cid]);
        $pdo->prepare("UPDATE thesis_proposals SET status = 'submitted' WHERE student_id = ? AND status <> 'rejected'")->execute([$cid]);
        $pdo->prepare(
            "DELETE pl FROM rubric_panel_leaders pl JOIN meetings m ON m.meeting_id = pl.meeting_id
             JOIN thesis_proposals tp ON tp.proposal_id = m.proposal_id WHERE tp.student_id = ?"
        )->execute([$cid]);
        $pdo->prepare("UPDATE students SET current_status = 'active' WHERE student_id = ?")->execute([$cid]);
        $pdo->prepare("DELETE FROM graduation_list WHERE student_id = ?")->execute([$cid]);

        if (((new StudentJourney($pdo))->currentExamStage($cid, $candidate['user_id'])['stage_id'] ?? null) === $first['stage_id']) {
            $student = $candidate;
            break;
        }
    }
    if ($student === null) {
        throw new RuntimeException('No registered student could be put back on the first exam stage.');
    }
    $sid = $student['student_id'];
    // Materialised stage rows from the search above belong to no one now.
    $pdo->prepare("DELETE FROM student_stage_progress WHERE student_id = ?")->execute([$sid]);

    // A fresh thesis schedule holding only this test's windows, registered ten days ago.
    $schedule = $pdo->query("SELECT UUID()")->fetchColumn();
    $pdo->prepare(
        "INSERT INTO thesis_schedules (schedule_id, program_id, enrollment_start_date, enrollment_end_date, created_by)
         VALUES (?, ?, NOW() - INTERVAL 60 DAY, NOW() + INTERVAL 60 DAY, ?)"
    )->execute([$schedule, $student['program_id'], $admin]);
    $pdo->prepare("UPDATE student_thesis_registrations SET thesis_schedule_id = ?, registered_at = NOW() - INTERVAL 10 DAY WHERE thesis_registration_id = ?")
        ->execute([$schedule, $student['thesis_registration_id']]);

    $types = $pdo->query("SELECT doc_type_id, doc_type_name FROM document_types ORDER BY doc_type_name LIMIT 5")->fetchAll();
    [$docCurrent, $docNext, $docBefore, $docEarlyDeadline, $docPlain] = $types;

    $window = function (?string $stageId, string $endsIn, string $description) use ($pdo, $schedule): string {
        $id = $pdo->query("SELECT UUID()")->fetchColumn();
        $pdo->prepare(
            "INSERT INTO exam_schedule (exam_schedule_id, thesis_schedule_id, starts_at, ends_at, exam_type, exam_stage_id, exam_schedule_description)
             VALUES (?, ?, NOW() - INTERVAL 40 DAY, NOW() + INTERVAL $endsIn, 'internal', ?, ?)"
        )->execute([$id, $schedule, $stageId, $description]);
        return $id;
    };
    $requires = function (string $windowId, string $docTypeId, string $deadlineIn) use ($pdo): void {
        $pdo->prepare(
            "INSERT INTO exam_schedule_documents (esd_id, exam_schedule_id, document_type_id, document_submission_starts_at, document_submission_deadline)
             VALUES (UUID(), ?, ?, NOW() - INTERVAL 30 DAY, NOW() + INTERVAL $deadlineIn)"
        )->execute([$windowId, $docTypeId]);
    };

    $current = $window($first['stage_id'], '20 DAY', 'Current stage exam');
    $requires($current, $docCurrent['doc_type_id'], '5 DAY');
    $next = $window($second['stage_id'], '20 DAY', 'Next stage exam');
    $requires($next, $docNext['doc_type_id'], '5 DAY');
    $closedBefore = $window($first['stage_id'], '-20 DAY', 'Closed before registration');
    $requires($closedBefore, $docBefore['doc_type_id'], '-25 DAY');
    $plain = $window(null, '20 DAY', 'Document review');
    $requires($plain, $docEarlyDeadline['doc_type_id'], '-20 DAY');
    $requires($plain, $docPlain['doc_type_id'], '5 DAY');

    $seen = fn () => new StudentExamWindows($pdo);
    $slotVisible = fn (string $windowId, array $type) => $seen()->isDocumentSlotVisible($sid, $windowId, $type['doc_type_id']);
    $examPage = fn () => array_column((new ExamReadiness($pdo))->windowsFor($sid, $student['user_id']), 'exam_schedule_id');

    // =====================================================================
    echo "\n=== Exams are taken in order ===\n";
    check('the student is on the first stage', ((new StudentJourney($pdo))->currentExamStage($sid, $student['user_id'])['stage_id'] ?? null) === $first['stage_id'], $first['name']);
    check('they see the exam for it', in_array($current, $examPage(), true));
    check('not the exam for the stage after it', !in_array($next, $examPage(), true), $second['name']);

    $page = (string) send($pdo, 'GET', '/student/exam', $student)->getBody();
    check('the exam page shows ' . $first['name'], str_contains($page, 'Current stage exam'));
    check('and not ' . $second['name'], !str_contains($page, 'Next stage exam'));

    echo "\n=== Only within their time on the programme ===\n";
    check('an exam that closed before they registered is not shown', !$seen()->isWindowVisible($sid, $closedBefore));
    check('even for the stage they are on', !in_array($closedBefore, $examPage(), true));
    check('a window with no stage is kept to its dates, and stays off the exam page',
        $seen()->isWindowVisible($sid, $plain) && !in_array($plain, $examPage(), true));

    echo "\n=== The documents follow their exam ===\n";
    check('the current exam\'s document is shown', $slotVisible($current, $docCurrent));
    check('the next stage\'s document is not', !$slotVisible($next, $docNext));
    check('nor one from the exam that closed before they registered', !$slotVisible($closedBefore, $docBefore));
    check('nor a document whose own deadline passed before they registered', !$slotVisible($plain, $docEarlyDeadline));
    check('while the same window\'s later document is', $slotVisible($plain, $docPlain));

    $page = (string) send($pdo, 'GET', '/student/requirements', $student)->getBody();
    check('the Requirements page lists what they can see', str_contains($page, $docCurrent['doc_type_name']) && str_contains($page, $docPlain['doc_type_name']));
    check('and nothing they cannot', !str_contains($page, $docNext['doc_type_name'])
        && !str_contains($page, $docBefore['doc_type_name']) && !str_contains($page, $docEarlyDeadline['doc_type_name']));

    send($pdo, 'POST', '/student/requirements/upload', $student, ['exam_schedule_id' => $next, 'document_type_id' => $docNext['doc_type_id']]);
    $message = ($_SESSION['flash_error'] ?? '') . ($_SESSION['flash_success'] ?? '');
    check('uploading to a hidden exam\'s document is refused', str_contains($message, 'not open to you'), $message);

    echo "\n=== Nor are they charged for it ===\n";
    $pdo->prepare("UPDATE supervision_assignments SET is_active = 0 WHERE student_id = ?")->execute([$sid]);
    $pdo->prepare(
        "INSERT INTO supervision_assignments (assignment_id, proposal_id, student_id, supervisor_id, role, appointed_by, appointment_date, is_active)
         VALUES (UUID(), ?, ?, (SELECT lecturer_id FROM lecturers LIMIT 1), 'main', ?, CURDATE(), 1)"
    )->execute([$student['proposal_id'], $sid, $admin]);
    foreach ([$docCurrent, $docNext, $docBefore] as $type) {
        $pdo->prepare(
            "INSERT INTO document_review_rates (rate_id, program_id, document_type_id, amount, currency, due_after_weeks)
             VALUES (UUID(), ?, ?, 500, 'KES', 1) ON DUPLICATE KEY UPDATE amount = 500, due_after_weeks = 1"
        )->execute([$student['program_id'], $type['doc_type_id']]);
    }
    $registration = $pdo->query("SELECT * FROM student_thesis_registrations WHERE thesis_registration_id = " . $pdo->quote($student['thesis_registration_id']))->fetch();
    $pdo->prepare("UPDATE thesis_schedules SET thesis_registration_rates_id = NULL WHERE schedule_id = ?")->execute([$schedule]);
    $charged = array_column(array_filter(
        (new ThesisRegistration($pdo))->computeOwed($registration),
        fn ($o) => $o['fee_type'] === 'document_review_fee'
    ), 'exam_schedule_id');
    check('the document they can see is charged', in_array($current, $charged, true));
    check('the ones they cannot are not', !in_array($next, $charged, true) && !in_array($closedBefore, $charged, true));

    // =====================================================================
    echo "\n=== Passing moves them on ===\n";
    check('a mark of 90 passes', GradingPolicy::isPass(GradingPolicy::examOutcome($pdo, 90)['outcome']));
    $meeting = $pdo->query("SELECT UUID()")->fetchColumn();
    $pdo->prepare(
        "INSERT INTO meetings (meeting_id, proposal_id, meeting_type, scheduled_at, mode, location, status, created_by, exam_stage_id, exam_schedule_id)
         VALUES (?, ?, 'exam', NOW() - INTERVAL 1 DAY, 'physical', 'Boardroom', 'completed', ?, ?, ?)"
    )->execute([$meeting, $student['proposal_id'], $admin, $first['stage_id'], $current]);
    $pdo->prepare(
        "INSERT INTO rubric_panel_leaders (meeting_id, examiner_id, average_score, confirmed_at, approved_at, approved_by)
         VALUES (?, ?, 90, NOW(), NOW(), ?)"
    )->execute([$meeting, $admin, $admin]);

    check('once the result is released, they are on the next stage',
        ((new StudentJourney($pdo))->currentExamStage($sid, $student['user_id'])['stage_id'] ?? null) === $second['stage_id'], $second['name']);
    check('which is recorded, so their track keeps it',
        (bool) $pdo->query("SELECT 1 FROM student_stage_progress WHERE student_id = " . $pdo->quote($sid)
            . " AND stage_id = " . $pdo->quote($first['stage_id']) . " AND status = 'complete'")->fetchColumn());
    check('the next exam is now shown', in_array($next, $examPage(), true));
    check('and the one they passed is not', !in_array($current, $examPage(), true));
    check('its document goes with it', !$slotVisible($current, $docCurrent) && $slotVisible($next, $docNext));

    // =====================================================================
    echo "\n=== Nothing after graduation ===\n";
    $pdo->prepare(
        "INSERT INTO graduation_list (graduation_id, student_id, proposal_id, ceremony_year, award, graduate_school_approved, approved_by, approval_date)
         VALUES (UUID(), ?, ?, YEAR(CURDATE()), 'MSc', 1, ?, CURDATE() - INTERVAL 1 DAY)"
    )->execute([$sid, $student['proposal_id'], $admin]);
    check('an exam closing after they graduated is not shown', !$seen()->isWindowVisible($sid, $next));
    check('nor a window with no stage closing after it', !$seen()->isWindowVisible($sid, $plain));
    check('nor their documents', $seen()->visibleDocumentSlots($sid) === []);

    $pdo->prepare("UPDATE students SET current_status = 'graduated' WHERE student_id = ?")->execute([$sid]);
    check('a graduated student has no exam stage left', (new StudentJourney($pdo))->currentExamStage($sid, $student['user_id']) === null);
    check('and the exam page shows none', $examPage() === []);
} catch (\Throwable $e) {
    $fail++;
    echo "  FAIL  threw " . get_class($e) . ': ' . $e->getMessage() . "\n        " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    $pdo->rollBack();
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
