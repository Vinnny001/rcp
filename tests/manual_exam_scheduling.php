<?php

/**
 * The coordinator scheduling an exam: the panel and everyone else they
 * invite, whether the student attends, the documents and links shared
 * with the room, and rescheduling or cancelling it afterwards.
 *
 * An internal exam takes internal lecturers only — examiners and anyone
 * else invited — an external one external lecturers. The exam falls
 * inside the window the student booked. Cancelling puts the student back
 * in the queue with their booking.
 *
 * Drives the real app on this script's connection, inside a transaction
 * that is rolled back at the end.
 *
 * Run: php tests/manual_exam_scheduling.php
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

    $_SESSION = ['user_id' => $userId, 'role' => 'lecturer', 'first_name' => 'Co', 'last_name' => 'Ord', 'csrf_token' => str_repeat('a', 64)];
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
    $readiness = new ExamReadiness($pdo);

    // ---- a student at an exam stage, booked on an internal exam ----------
    $student = null;
    foreach ($pdo->query(
        "SELECT st.student_id, st.user_id, CONCAT(u.first_name, ' ', u.last_name) AS name, tp.proposal_id,
                str.thesis_schedule_id, ts.program_id
         FROM students st
         JOIN users u ON u.user_id = st.user_id
         JOIN thesis_proposals tp ON tp.student_id = st.student_id AND tp.status <> 'rejected'
         JOIN student_thesis_registrations str ON str.student_id = st.student_id AND str.status = 'active'
         JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id"
    )->fetchAll() as $candidate) {
        if ((new StudentJourney($pdo))->currentExamStage($candidate['student_id'], $candidate['user_id']) !== null) {
            $student = $candidate;
            break;
        }
    }
    $stage = (new StudentJourney($pdo))->currentExamStage($student['student_id'], $student['user_id']);

    $window = $pdo->query("SELECT UUID()")->fetchColumn();
    $pdo->prepare(
        "INSERT INTO exam_schedule (exam_schedule_id, thesis_schedule_id, starts_at, ends_at, exam_type, exam_stage_id, exam_schedule_description)
         VALUES (?, ?, CURDATE() + INTERVAL 2 DAY, CURDATE() + INTERVAL 12 DAY, 'internal', ?, 'Scheduling test session')"
    )->execute([$window, $student['thesis_schedule_id'], $stage['stage_id']]);
    $pdo->prepare("UPDATE thesis_schedules SET thesis_registration_rates_id = NULL WHERE schedule_id = ?")->execute([$student['thesis_schedule_id']]);
    $pdo->prepare("DELETE FROM exam_readiness WHERE student_id = ? AND meeting_id IS NULL")->execute([$student['student_id']]);
    $readiness->book($student['student_id'], $student['user_id'], $window);

    $inWindow = fn (string $offset) => date('Y-m-d\TH:i', strtotime('today ' . $offset));

    // A coordinator, two internal lecturers and an external one, all qualified.
    $internal = $pdo->prepare(
        "SELECT l.lecturer_id, l.user_id, CONCAT(u.first_name, ' ', u.last_name) AS name FROM lecturers l
         JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id JOIN users u ON u.user_id = l.user_id
         WHERE l.user_id <> ? AND l.user_id NOT IN (SELECT user_id FROM students) LIMIT 4"
    );
    $internal->execute([$student['user_id']]);
    [$coordinator, $examinerA, $chair, $observer] = $internal->fetchAll();
    $external = $pdo->query(
        "SELECT l.lecturer_id, l.user_id, CONCAT(u.first_name, ' ', u.last_name) AS name FROM lecturers l
         JOIN external_lecturers el ON el.lecturer_id = l.lecturer_id JOIN users u ON u.user_id = l.user_id LIMIT 1"
    )->fetch();

    $pdo->prepare("DELETE FROM research_coordinators WHERE program_id = ?")->execute([$student['program_id']]);
    $pdo->prepare("INSERT INTO research_coordinators (coordinator_id, program_id, user_id, assigned_by) VALUES (UUID(), ?, ?, ?)")
        ->execute([$student['program_id'], $coordinator['user_id'], $admin]);
    foreach (array_filter([$examinerA['lecturer_id'], $external['lecturer_id'] ?? null]) as $lecturerId) {
        $pdo->prepare("INSERT IGNORE INTO examiner_program_qualifications (qualification_id, lecturer_id, program_id, added_by) VALUES (UUID(), ?, ?, ?)")
            ->execute([$lecturerId, $student['program_id'], $admin]);
    }

    // Documents to share: the student's, the coordinator's, and someone else's.
    $docType = $pdo->query("SELECT doc_type_id FROM document_types LIMIT 1")->fetchColumn();
    $document = function (string $ownerUserId, string $name) use ($pdo, $docType): string {
        $id = $pdo->query("SELECT UUID()")->fetchColumn();
        $pdo->prepare(
            "INSERT INTO documents (document_id, user_id, uploaded_by, file_name, file_path, file_size_kb, mime_type, document_type_id, document_status)
             VALUES (?, ?, ?, ?, 'uploads/documents/x.pdf', 1, 'application/pdf', ?, 'submitted')"
        )->execute([$id, $ownerUserId, $ownerUserId, $name, $docType]);
        return $id;
    };
    $studentDoc = $document($student['user_id'], 'student-chapter.pdf');
    $coordinatorDoc = $document($coordinator['user_id'], 'marking-guide.pdf');
    $foreignDoc = $document($observer['user_id'], 'someone-else.pdf');

    $readinessId = $pdo->query("SELECT readiness_id FROM exam_readiness WHERE student_id = " . $pdo->quote($student['student_id']) . " AND exam_schedule_id = " . $pdo->quote($window))->fetchColumn();
    $base = [
        'readiness_id' => $readinessId, 'program_id' => $student['program_id'],
        'examiner_ids' => [$examinerA['lecturer_id']], 'scheduled_at' => $inWindow('+5 days 10:00'),
        'mode' => 'physical', 'location' => 'Senate Room', 'include_student' => '1',
    ];
    $schedule = function (array $changes) use ($pdo, $coordinator, $base): string {
        send($pdo, 'POST', '/coordinator/exams/schedule', $coordinator['user_id'], $changes + $base);
        return flash();
    };

    echo "\n=== The scheduling form ===\n";
    $page = (string) send($pdo, 'GET', '/coordinator/exams', $coordinator['user_id'])->getBody();
    $start = strpos($page, 'value="' . $readinessId . '"');
    $form = $start === false ? '' : substr($page, $start, (int) strpos($page, '</form>', $start) - $start);
    check('an internal exam offers internal examiners', str_contains($form, $examinerA['lecturer_id']));
    if ($external) {
        check('and not external ones', !str_contains($form, 'value="' . $external['lecturer_id'] . '"'));
        check('nor external lecturers among the others it can invite', !str_contains($form, 'value="' . $external['user_id'] . '"'));
    }
    check('it can invite others — chair, supervisors, observers', str_contains($form, 'data-invite-select') && str_contains($form, 'value="chairperson"') && str_contains($form, 'value="observer"'));
    check('the student is invited unless the coordinator unticks it', (bool) preg_match('/name="include_student" value="1" checked/', $form));
    check('the student\'s documents can be shared', str_contains($form, 'student-chapter.pdf'));
    check('and the coordinator\'s own', str_contains($form, 'marking-guide.pdf'));
    check('but not anyone else\'s', !str_contains($form, 'someone-else.pdf'));

    echo "\n=== What scheduling refuses ===\n";
    if ($external) {
        $message = $schedule(['examiner_ids' => [$external['lecturer_id']]]);
        check('an external examiner on an internal exam', str_contains($message, 'Only internal lecturers can examine'), $message);
        $message = $schedule(['attendee_user_ids' => [$external['user_id']], 'attendee_roles' => ['observer']]);
        check('an external lecturer invited to it in any role', str_contains($message, 'Only internal lecturers can be invited'), $message);
    }
    $message = $schedule(['attendee_user_ids' => [$student['user_id']], 'attendee_roles' => ['observer']]);
    check('the student invited as a lecturer', str_contains($message, 'attends as the student'), $message);
    $message = $schedule(['attendee_user_ids' => [$chair['user_id']], 'attendee_roles' => ['examiner']]);
    check('an examiner slipped in outside the panel', str_contains($message, 'chairperson, supervisor or observer'), $message);
    $message = $schedule(['scheduled_at' => $inWindow('+20 days 10:00')]);
    check('a date outside the booked window', str_contains($message, 'within its window'), $message);
    $message = $schedule(['location' => '']);
    check('a physical exam with no location', str_contains($message, 'needs a location'), $message);
    $message = $schedule(['mode' => 'virtual', 'location' => '']);
    check('a virtual exam with no link', str_contains($message, 'needs a meeting link'), $message);
    check('and nothing was scheduled', $pdo->query("SELECT meeting_id FROM exam_readiness WHERE readiness_id = " . $pdo->quote($readinessId))->fetchColumn() === null);

    echo "\n=== Scheduling with everyone and everything ===\n";
    $message = $schedule([
        'attendee_user_ids' => [$chair['user_id'], $observer['user_id']], 'attendee_roles' => ['chairperson', 'observer'],
        'resource_documents' => [$studentDoc, $coordinatorDoc, $foreignDoc],
        'resource_links' => ['https://example.org/rubric', 'javascript:alert(1)'], 'resource_link_labels' => ['Rubric', 'Bad'],
    ]);
    $meeting = (string) $pdo->query("SELECT meeting_id FROM exam_readiness WHERE readiness_id = " . $pdo->quote($readinessId))->fetchColumn();
    check('the exam is scheduled', $meeting !== '' && str_contains($message, 'scheduled'), $message);

    $roles = $pdo->query("SELECT user_id, role_in_meeting FROM meeting_attendees WHERE meeting_id = " . $pdo->quote($meeting))->fetchAll(PDO::FETCH_KEY_PAIR);
    check('with the panel', ($roles[$examinerA['user_id']] ?? null) === 'examiner');
    check('the student', ($roles[$student['user_id']] ?? null) === 'student');
    check('and the chair and observer invited', ($roles[$chair['user_id']] ?? null) === 'chairperson' && ($roles[$observer['user_id']] ?? null) === 'observer');

    $resources = (new Meeting($pdo))->findResources($meeting);
    $shared = array_column(array_filter($resources, fn ($r) => $r['resource_type'] === 'document'), 'document_id');
    check('the student\'s and coordinator\'s documents are shared', in_array($studentDoc, $shared, true) && in_array($coordinatorDoc, $shared, true));
    check('someone else\'s is not', !in_array($foreignDoc, $shared, true));
    $links = array_column(array_filter($resources, fn ($r) => $r['resource_type'] === 'link'), 'url');
    check('a real link is kept, a javascript: one is not', $links === ['https://example.org/rubric']);

    $page = (string) send($pdo, 'GET', '/coordinator/exams/' . $meeting, $coordinator['user_id'])->getBody();
    check('the exam page lists who else is invited', str_contains($page, 'Also invited') && str_contains($page, htmlspecialchars($chair['name']) . ' (chairperson)'));
    check('and the resources', str_contains($page, 'marking-guide.pdf') && str_contains($page, 'Rubric'));
    check('and offers to reschedule or cancel', str_contains($page, 'action="/coordinator/exams/reschedule"') && str_contains($page, 'action="/coordinator/exams/cancel"'));

    echo "\n=== Rescheduling ===\n";
    $reschedule = function (array $changes) use ($pdo, $coordinator, $meeting): string {
        send($pdo, 'POST', '/coordinator/exams/reschedule', $coordinator['user_id'], $changes + [
            'meeting_id' => $meeting, 'scheduled_at' => date('Y-m-d\TH:i', strtotime('today +7 days 14:00')),
            'mode' => 'hybrid', 'location' => 'Room 12', 'virtual_link' => 'https://meet.example/exam',
        ]);
        return flash();
    };
    $message = $reschedule([]);
    $row = $pdo->query("SELECT scheduled_at, mode, location, virtual_link FROM meetings WHERE meeting_id = " . $pdo->quote($meeting))->fetch();
    check('it moves the exam', str_contains($message, 'rescheduled')
        && $row['scheduled_at'] === date('Y-m-d 14:00:00', strtotime('today +7 days')) && $row['location'] === 'Room 12' && $row['mode'] === 'hybrid', $message);
    $message = $reschedule(['scheduled_at' => date('Y-m-d\TH:i', strtotime('today +30 days 10:00'))]);
    check('but not outside the booked window', str_contains($message, 'within its window'), $message);
    $message = $reschedule(['virtual_link' => '']);
    check('and a hybrid exam still needs its link', str_contains($message, 'needs a meeting link'), $message);

    send($pdo, 'POST', '/coordinator/exams/reschedule', $observer['user_id'], ['meeting_id' => $meeting, 'scheduled_at' => $inWindow('+3 days 09:00'), 'mode' => 'physical', 'location' => 'X']);
    check('a lecturer who does not coordinate the program cannot', str_contains(flash(), 'not on a program you coordinate'));

    echo "\n=== Cancelling ===\n";
    send($pdo, 'POST', '/coordinator/exams/cancel', $coordinator['user_id'], ['meeting_id' => $meeting, 'reason' => '']);
    check('it needs a reason', str_contains(flash(), 'Give a reason'));

    send($pdo, 'POST', '/coordinator/exams/cancel', $coordinator['user_id'], ['meeting_id' => $meeting, 'reason' => 'The chair is unwell.']);
    $message = flash();
    check('the exam is cancelled, with the reason recorded', str_contains($message, 'cancelled')
        && $pdo->query("SELECT CONCAT(status, '|', status_description) FROM meetings WHERE meeting_id = " . $pdo->quote($meeting))->fetchColumn() === 'cancelled|The chair is unwell.', $message);
    check('the student keeps their booking, no longer scheduled',
        $pdo->query("SELECT COUNT(*) FROM exam_readiness WHERE readiness_id = " . $pdo->quote($readinessId) . " AND meeting_id IS NULL")->fetchColumn() == 1);
    check('and is back in the coordinator\'s queue',
        in_array($readinessId, array_column($readiness->queueForPrograms([$student['program_id']]), 'readiness_id'), true));
    check('free to switch dates again', $readiness->window($student['student_id'], $student['user_id'], $window)['meeting_id'] === null);

    $page = (string) send($pdo, 'GET', '/coordinator/exams/' . $meeting, $coordinator['user_id'])->getBody();
    check('the exam page shows it cancelled, with the reason', str_contains($page, 'Cancelled') && str_contains($page, 'The chair is unwell.'));
    check('and no longer offers to reschedule it', !str_contains($page, 'action="/coordinator/exams/reschedule"'));
    check('rescheduling a cancelled exam is refused', str_contains($reschedule([]), 'not started'));

    echo "\n=== Scheduling again, without the student ===\n";
    $message = $schedule(['include_student' => '']);
    $again = (string) $pdo->query("SELECT meeting_id FROM exam_readiness WHERE readiness_id = " . $pdo->quote($readinessId))->fetchColumn();
    check('it schedules', $again !== '' && $again !== $meeting, $message);
    check('and the student is not invited',
        (int) $pdo->query("SELECT COUNT(*) FROM meeting_attendees WHERE meeting_id = " . $pdo->quote($again) . " AND role_in_meeting = 'student'")->fetchColumn() === 0);
    $page = (string) send($pdo, 'GET', '/coordinator/exams/' . $again, $coordinator['user_id'])->getBody();
    check('which the exam page says', str_contains($page, 'The student is not invited.'));
} catch (\Throwable $e) {
    $fail++;
    echo "  FAIL  threw " . get_class($e) . ': ' . $e->getMessage() . "\n        " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    $pdo->rollBack();
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
