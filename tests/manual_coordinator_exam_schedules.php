<?php

/**
 * A research coordinator schedules exams — the windows students book —
 * for the programs they coordinate, on one of the program's thesis
 * schedules. A program with no thesis schedule cannot have exams yet,
 * and a coordinator cannot touch another program's.
 *
 * Drives the real app on this script's connection, inside a transaction
 * that is rolled back at the end.
 *
 * Run: php tests/manual_coordinator_exam_schedules.php
 */

declare(strict_types=1);

use App\Models\ExamSchedule;
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
    $model = new ExamSchedule($pdo);

    // A program with a thesis schedule, and one without.
    $withSchedule = $pdo->query(
        "SELECT p.program_id, p.name, ts.schedule_id FROM programs p
         JOIN thesis_schedules ts ON ts.program_id = p.program_id LIMIT 1"
    )->fetch();
    $withoutSchedule = $pdo->query(
        "SELECT p.program_id, p.name FROM programs p
         WHERE NOT EXISTS (SELECT 1 FROM thesis_schedules ts WHERE ts.program_id = p.program_id) LIMIT 1"
    )->fetch();
    $otherSchedule = $pdo->query(
        "SELECT ts.schedule_id FROM thesis_schedules ts WHERE ts.program_id <> " . $pdo->quote($withSchedule['program_id']) . " LIMIT 1"
    )->fetchColumn();

    // A coordinator of both, who studies on neither.
    $coordinator = $pdo->query(
        "SELECT l.user_id FROM lecturers l
         WHERE l.user_id NOT IN (SELECT user_id FROM students) LIMIT 1"
    )->fetchColumn();
    $outsider = $pdo->prepare(
        "SELECT l.user_id FROM lecturers l WHERE l.user_id <> ? AND l.user_id NOT IN (SELECT user_id FROM research_coordinators) LIMIT 1"
    );
    $outsider->execute([$coordinator]);
    $outsider = $outsider->fetchColumn();

    foreach (array_filter([$withSchedule['program_id'], $withoutSchedule['program_id'] ?? null]) as $programId) {
        $pdo->prepare("DELETE FROM research_coordinators WHERE program_id = ?")->execute([$programId]);
        $pdo->prepare("INSERT INTO research_coordinators (coordinator_id, program_id, user_id, assigned_by) VALUES (UUID(), ?, ?, ?)")
            ->execute([$programId, $coordinator, $admin]);
    }
    $stage = $pdo->query("SELECT stage_id FROM exam_stages WHERE is_active = 1 ORDER BY display_order LIMIT 1")->fetchColumn();

    echo "\n=== The coordinator's exam schedules page ===\n";
    $page = (string) send($pdo, 'GET', '/coordinator/exam-schedules', $coordinator)->getBody();
    check('it offers to add an exam schedule for the program', str_contains($page, 'action="/coordinator/exam-schedules/create"') && str_contains($page, htmlspecialchars($withSchedule['name'])));
    check('on one of the program\'s thesis schedules', str_contains($page, 'value="' . $withSchedule['schedule_id'] . '"'));
    if ($withoutSchedule) {
        check('a program with no thesis schedule says no exam can be scheduled yet',
            str_contains($page, htmlspecialchars($withoutSchedule['name']) . ' has no thesis schedule yet'));
    }
    check('the tab is in the coordinator\'s navigation', str_contains($page, 'href="/coordinator/exam-schedules"'));

    echo "\n=== Creating one ===\n";
    $form = [
        'thesis_schedule_id' => $withSchedule['schedule_id'], 'exam_stage_id' => $stage, 'exam_type' => 'internal',
        'starts_at' => '2026-11-02T09:00', 'ends_at' => '2026-11-02T17:00', 'exam_schedule_description' => 'Test session A',
    ];
    send($pdo, 'POST', '/coordinator/exam-schedules/create', $coordinator, $form);
    $message = flash();
    $created = $pdo->query("SELECT * FROM exam_schedule WHERE exam_schedule_description = 'Test session A'")->fetch();
    check('the coordinator can create an exam schedule', $created !== false && str_contains($message, 'created'), $message);
    check('on the thesis schedule they chose', ($created['thesis_schedule_id'] ?? null) === $withSchedule['schedule_id']);

    send($pdo, 'POST', '/coordinator/exam-schedules/create', $coordinator, ['exam_schedule_description' => 'Test session B'] + $form);
    send($pdo, 'POST', '/coordinator/exam-schedules/create', $coordinator, ['exam_schedule_description' => 'Test session C', 'starts_at' => '2026-11-09T09:00', 'ends_at' => '2026-11-09T17:00'] + $form);
    flash();
    check('and several for the same stage, on different dates',
        (int) $pdo->query("SELECT COUNT(*) FROM exam_schedule WHERE exam_schedule_description LIKE 'Test session %'")->fetchColumn() === 3);

    send($pdo, 'POST', '/coordinator/exam-schedules/create', $coordinator, ['exam_stage_id' => '', 'exam_schedule_description' => 'No stage'] + $form);
    $message = flash();
    check('it must examine a stage', str_contains($message, 'choose the stage'), $message);

    send($pdo, 'POST', '/coordinator/exam-schedules/create', $coordinator, ['ends_at' => '2026-11-01T09:00', 'exam_schedule_description' => 'Backwards'] + $form);
    $message = flash();
    check('and cannot end before it starts', str_contains($message, 'cannot end before it starts'), $message);

    if ($otherSchedule) {
        send($pdo, 'POST', '/coordinator/exam-schedules/create', $coordinator, ['thesis_schedule_id' => $otherSchedule, 'exam_schedule_description' => 'Elsewhere'] + $form);
        $message = flash();
        check('not on a thesis schedule of a program they do not coordinate', str_contains($message, 'program you coordinate'), $message);
    }

    send($pdo, 'POST', '/coordinator/exam-schedules/create', $outsider, ['exam_schedule_description' => 'By outsider'] + $form);
    $message = flash();
    check('a lecturer who coordinates nothing cannot create one', str_contains($message, 'program you coordinate'), $message);

    echo "\n=== Its documents, changes and removal ===\n";
    $docType = $pdo->query("SELECT doc_type_id FROM document_types LIMIT 1")->fetchColumn();
    send($pdo, 'POST', '/coordinator/exam-schedules/documents/add', $coordinator, [
        'exam_schedule_id' => $created['exam_schedule_id'], 'document_type_id' => $docType,
        'document_submission_starts_at' => '2026-10-20T09:00', 'document_submission_deadline' => '2026-10-30T17:00',
    ]);
    flash();
    $slots = $model->documentSlots($created['exam_schedule_id']);
    check('a required document can be added', count($slots) === 1);

    send($pdo, 'POST', '/coordinator/exam-schedules/documents/add', $coordinator, [
        'exam_schedule_id' => $created['exam_schedule_id'], 'document_type_id' => $docType,
        'document_submission_starts_at' => '2026-10-30T09:00', 'document_submission_deadline' => '2026-10-20T17:00',
    ]);
    check('but not with a deadline before submission opens', str_contains(flash(), 'deadline cannot be before'));

    send($pdo, 'POST', '/coordinator/exam-schedules/documents/remove', $outsider, ['esd_id' => $slots[0]['esd_id']]);
    check('another lecturer cannot remove it', str_contains(flash(), 'program you coordinate') && count($model->documentSlots($created['exam_schedule_id'])) === 1);

    send($pdo, 'POST', '/coordinator/exam-schedules/update', $coordinator, ['exam_schedule_id' => $created['exam_schedule_id'], 'exam_schedule_description' => 'Test session A, moved', 'starts_at' => '2026-11-03T09:00', 'ends_at' => '2026-11-03T17:00'] + $form);
    flash();
    check('the coordinator can change it', $model->findById($created['exam_schedule_id'])['exam_schedule_description'] === 'Test session A, moved');

    $student = $pdo->query("SELECT student_id FROM students LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO exam_readiness (readiness_id, student_id, exam_schedule_id) VALUES (UUID(), ?, ?)")->execute([$student, $created['exam_schedule_id']]);
    send($pdo, 'POST', '/coordinator/exam-schedules/delete', $coordinator, ['exam_schedule_id' => $created['exam_schedule_id']]);
    $message = flash();
    check('one a student has booked cannot be deleted', str_contains($message, 'booked') && $model->findById($created['exam_schedule_id']) !== null, $message);

    $b = $pdo->query("SELECT exam_schedule_id FROM exam_schedule WHERE exam_schedule_description = 'Test session B'")->fetchColumn();
    send($pdo, 'POST', '/coordinator/exam-schedules/delete', $coordinator, ['exam_schedule_id' => $b]);
    flash();
    check('one nobody has booked can', $model->findById($b) === null);

    $page = (string) send($pdo, 'GET', '/coordinator/exam-schedules', $coordinator)->getBody();
    check('the page lists them with how many have booked', str_contains($page, 'Test session A, moved') && str_contains($page, '1 booked'));

    echo "\n=== The administrator's screen still validates the same way ===\n";
    check('an admin window without a stage is still allowed', ExamSchedule::validationError(['stage' => null] + $form + ['exam_stage_id' => '']) === null);
    check('a coordinator window without one is not', ExamSchedule::validationError(['exam_stage_id' => ''] + $form, true) !== null);
} catch (\Throwable $e) {
    $fail++;
    echo "  FAIL  threw " . get_class($e) . ': ' . $e->getMessage() . "\n        " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    $pdo->rollBack();
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
