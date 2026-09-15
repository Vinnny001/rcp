<?php

/**
 * Only the research coordinator schedules exam meetings, and only they
 * hold an exam meeting's attendance code. A supervisor still schedules
 * supervisory meetings; for their students' exams they see where each
 * stands — booked or not, scheduled or waiting — and can remind them.
 *
 * Drives the real app on this script's connection, inside a transaction
 * that is rolled back at the end.
 *
 * Run: php tests/manual_exam_meetings_coordinator_only.php
 */

declare(strict_types=1);

use App\Models\ExamReadiness;
use App\Models\Meeting;
use App\Models\StudentJourney;
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

function send(PDO $pdo, string $method, string $path, string $userId, array $form = []): ResponseInterface
{
    global $fail;

    $_SESSION = ['user_id' => $userId, 'role' => 'lecturer', 'first_name' => 'Test', 'last_name' => 'Lecturer', 'csrf_token' => str_repeat('a', 64)];
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
    $admin = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();

    // A supervisor and a student they supervise, at an exam stage.
    $pair = null;
    foreach ($pdo->query(
        "SELECT l.lecturer_id, l.user_id AS supervisor_user_id,
                st.student_id, st.user_id AS student_user_id, u.first_name AS student_first_name,
                CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                tp.proposal_id, str.thesis_schedule_id, ts.program_id
         FROM supervision_assignments sa
         JOIN lecturers l ON l.lecturer_id = sa.supervisor_id
         JOIN students st ON st.student_id = sa.student_id
         JOIN users u ON u.user_id = st.user_id
         JOIN thesis_proposals tp ON tp.student_id = st.student_id AND tp.status <> 'rejected'
         JOIN student_thesis_registrations str ON str.student_id = st.student_id AND str.status = 'active'
         JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
         WHERE sa.is_active = 1 AND l.user_id <> st.user_id"
    )->fetchAll() as $candidate) {
        if ((new StudentJourney($pdo))->currentExamStage($candidate['student_id'], $candidate['student_user_id']) !== null) {
            $pair = $candidate;
            break;
        }
    }
    if ($pair === null) {
        throw new RuntimeException('No supervised student at an exam stage to test with.');
    }
    $supervisor = $pair['supervisor_user_id'];
    $stage = (new StudentJourney($pdo))->currentExamStage($pair['student_id'], $pair['student_user_id']);

    // A coordinator for the student's program.
    $coordinator = $pdo->prepare(
        "SELECT l.user_id FROM lecturers l WHERE l.user_id NOT IN (?, ?) AND l.user_id NOT IN (SELECT user_id FROM students) LIMIT 1"
    );
    $coordinator->execute([$supervisor, $pair['student_user_id']]);
    $coordinator = $coordinator->fetchColumn();
    $pdo->prepare("DELETE FROM research_coordinators WHERE program_id = ?")->execute([$pair['program_id']]);
    $pdo->prepare("INSERT INTO research_coordinators (coordinator_id, program_id, user_id, assigned_by) VALUES (UUID(), ?, ?, ?)")
        ->execute([$pair['program_id'], $coordinator, $admin]);

    echo "\n=== A supervisor cannot schedule an exam meeting ===\n";
    $before = (int) $pdo->query("SELECT COUNT(*) FROM meetings")->fetchColumn();
    foreach (['approval_board', 'viva', 'concept_presentation'] as $type) {
        send($pdo, 'POST', '/lecturer/meetings/schedule', $supervisor, [
            'proposal_id' => $pair['proposal_id'], 'meeting_type' => $type, 'date' => date('Y-m-d', strtotime('+5 days')),
            'time' => '10:00', 'mode' => 'physical', 'location' => 'Room 4',
        ]);
        $message = flash();
        check('scheduling a ' . str_replace('_', ' ', $type) . ' is refused', str_contains($message, 'research coordinator'), $message);
    }
    send($pdo, 'POST', '/lecturer/meetings/schedule-for-exam', $supervisor, ['proposal_id' => $pair['proposal_id']]);
    $message = flash();
    check('and so is a meeting in an exam window', str_contains($message, 'research coordinator'), $message);
    check('nothing was scheduled', (int) $pdo->query("SELECT COUNT(*) FROM meetings")->fetchColumn() === $before);

    send($pdo, 'POST', '/lecturer/meetings/schedule', $supervisor, [
        'proposal_id' => $pair['proposal_id'], 'meeting_type' => 'supervisory', 'date' => date('Y-m-d', strtotime('+5 days')),
        'time' => '10:00', 'mode' => 'physical', 'location' => 'Room 4', 'include_student' => '1',
    ]);
    $message = flash();
    check('a supervisory meeting still schedules', str_contains($message, 'Meeting scheduled'), $message);
    $supervisory = $pdo->query("SELECT meeting_id FROM meetings ORDER BY created_at DESC LIMIT 1")->fetchColumn();

    $page = (string) send($pdo, 'GET', '/lecturer/meetings', $supervisor)->getBody();
    check('the scheduling form offers only a supervisory meeting', !str_contains($page, 'value="approval_board"') && str_contains($page, 'name="meeting_type" value="supervisory"'));
    $supervisoryCode = (new Meeting($pdo))->ensureSecureCode($supervisory);
    check('and the supervisor sees their supervisory meeting\'s code', str_contains($page, (string) $supervisoryCode));

    echo "\n=== An exam meeting is the coordinator's ===\n";
    // An exam meeting the supervisor created before this change.
    $legacy = (new Meeting($pdo))->create($pair['proposal_id'], [
        'meeting_type' => 'approval_board', 'scheduled_at' => date('Y-m-d H:i:s', strtotime('+6 days')),
        'mode' => 'physical', 'location' => 'Boardroom', 'virtual_link' => null, 'ai_notes_enabled' => false,
    ], $supervisor);
    (new Meeting($pdo))->addAttendee($legacy, $supervisor, 'supervisor');
    $legacyCode = (new Meeting($pdo))->ensureSecureCode($legacy);

    $page = (string) send($pdo, 'GET', '/lecturer/meetings', $supervisor)->getBody();
    check('its code is not shown to the supervisor', !str_contains($page, (string) $legacyCode));
    check('nor can they edit it from the page', !str_contains($page, '/lecturer/meetings/' . $legacy . '/edit'));

    $response = send($pdo, 'GET', '/lecturer/meetings/' . $legacy . '/edit', $supervisor);
    $message = flash();
    check('opening its edit form is refused', $response->getStatusCode() === 302 && str_contains($message, 'research coordinator'), $message);
    send($pdo, 'POST', '/lecturer/meetings/' . $legacy . '/edit', $supervisor, ['date' => date('Y-m-d', strtotime('+7 days')), 'time' => '11:00']);
    check('so is saving it', str_contains(flash(), 'research coordinator'));
    send($pdo, 'POST', '/lecturer/meetings/' . $legacy . '/status', $supervisor, ['status' => 'cancelled', 'status_description' => 'No.']);
    check('and cancelling it', str_contains(flash(), 'research coordinator')
        && $pdo->query("SELECT status FROM meetings WHERE meeting_id = " . $pdo->quote($legacy))->fetchColumn() === 'scheduled');
    $page = (string) send($pdo, 'GET', '/lecturer/meetings/' . $legacy . '/review', $supervisor)->getBody();
    check('its review page does not show the supervisor the code', !str_contains($page, (string) $legacyCode));

    send($pdo, 'POST', '/lecturer/meetings/' . $supervisory . '/edit', $supervisor, [
        'meeting_type' => 'viva', 'date' => date('Y-m-d', strtotime('+8 days')), 'time' => '09:00', 'mode' => 'physical', 'location' => 'Room 4',
    ]);
    flash();
    check('a supervisory meeting cannot be turned into an exam',
        $pdo->query("SELECT meeting_type FROM meetings WHERE meeting_id = " . $pdo->quote($supervisory))->fetchColumn() === 'supervisory');

    echo "\n=== The supervisor sees their student's exam, and can remind them ===\n";
    $window = $pdo->query("SELECT UUID()")->fetchColumn();
    $pdo->prepare(
        "INSERT INTO exam_schedule (exam_schedule_id, thesis_schedule_id, starts_at, ends_at, exam_type, exam_stage_id, exam_schedule_description)
         VALUES (?, ?, NOW() + INTERVAL 10 DAY, NOW() + INTERVAL 12 DAY, 'internal', ?, 'Supervisor view session')"
    )->execute([$window, $pair['thesis_schedule_id'], $stage['stage_id']]);
    $pdo->prepare("UPDATE thesis_schedules SET thesis_registration_rates_id = NULL WHERE schedule_id = ?")->execute([$pair['thesis_schedule_id']]);

    $page = (string) send($pdo, 'GET', '/lecturer/meetings', $supervisor)->getBody();
    check('an unbooked student shows as not booked, with dates open', str_contains($page, htmlspecialchars($pair['student_name']) . ' — ' . htmlspecialchars($stage['name']))
        && str_contains($page, 'Not booked') && str_contains($page, 'open for booking'));
    check('with a reminder to book, ready to send', str_contains($page, 'Book your ' . htmlspecialchars($stage['name']) . ' exam'));

    $readiness = new ExamReadiness($pdo);
    $readiness->book($pair['student_id'], $pair['student_user_id'], $window);
    $page = (string) send($pdo, 'GET', '/lecturer/meetings', $supervisor)->getBody();
    check('once booked, the supervisor sees the booked date', str_contains($page, 'Booked for'));

    $before = (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . $pdo->quote($pair['student_user_id']))->fetchColumn();
    $response = send($pdo, 'POST', '/lecturer/notifications/send', $supervisor, [
        'mode' => 'student', 'student_user_id' => [$pair['student_user_id']], 'return_to' => '/lecturer/meetings',
        'subject' => 'Your exam', 'message' => 'Please submit your documents.',
    ]);
    flash();
    check('the reminder reaches the student', (int) $pdo->query("SELECT COUNT(*) FROM notifications WHERE user_id = " . $pdo->quote($pair['student_user_id']))->fetchColumn() === $before + 1);
    check('and the supervisor is taken back to the meetings page', $response->getHeaderLine('Location') === '/lecturer/meetings');

    $readinessId = $pdo->query("SELECT readiness_id FROM exam_readiness WHERE student_id = " . $pdo->quote($pair['student_id']) . " AND exam_schedule_id = " . $pdo->quote($window))->fetchColumn();
    $examiner = $pdo->prepare("SELECT lecturer_id FROM lecturers WHERE user_id NOT IN (?, ?) LIMIT 1");
    $examiner->execute([$pair['student_user_id'], $coordinator]);
    $examiner = $examiner->fetchColumn();
    $pdo->prepare("INSERT IGNORE INTO examiner_program_qualifications (qualification_id, lecturer_id, program_id, added_by) VALUES (UUID(), ?, ?, ?)")
        ->execute([$examiner, $pair['program_id'], $admin]);
    $examMeeting = $readiness->scheduleExam($readinessId, $pair['program_id'], [$examiner], null,
        date('Y-m-d H:i:s', strtotime('+11 days 10:00')), 'physical', 'Senate Room', null, $coordinator);

    $page = (string) send($pdo, 'GET', '/lecturer/meetings', $supervisor)->getBody();
    check('once the coordinator schedules it, the supervisor sees when and where', str_contains($page, 'Exam scheduled') && str_contains($page, 'Senate Room'));
    $examCode = (new Meeting($pdo))->ensureSecureCode($examMeeting);
    check('but not its attendance code', !str_contains($page, (string) $examCode));

    $page = (string) send($pdo, 'GET', '/coordinator/exams/' . $examMeeting, $coordinator)->getBody();
    check('the coordinator\'s exam page shows the code', str_contains($page, 'Attendance code') && str_contains($page, (string) $examCode));
} catch (\Throwable $e) {
    $fail++;
    echo "  FAIL  threw " . get_class($e) . ': ' . $e->getMessage() . "\n        " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    $pdo->rollBack();
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
