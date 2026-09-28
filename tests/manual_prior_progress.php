<?php

/**
 * A student continuing from where they left off.
 *
 * They name the last stage they passed and attach the evidence the
 * graduate school asks for; an administrator approves it, and that stage
 * and every earlier one are recorded, so the journey resumes at the next
 * one. What counts as evidence is the administrator's to set, per stage,
 * with a default list behind it.
 *
 * Drives the real app on this script's connection, inside a transaction
 * that is rolled back at the end.
 *
 * Run: php tests/manual_prior_progress.php
 */

declare(strict_types=1);

use App\Models\ExamStage;
use App\Models\PriorProgress;
use App\Models\StudentJourney;
use DI\ContainerBuilder;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;
use Slim\Psr7\UploadedFile;

require __DIR__ . '/../vendor/autoload.php';

Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();

$pdo = new PDO(
    "mysql:host={$_ENV['DB_HOST']};dbname={$_ENV['DB_NAME']};charset=utf8mb4",
    $_ENV['DB_USER'],
    $_ENV['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$pass = 0;
$fail = 0;
$tempFiles = [];

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

/**
 * A real PDF-ish upload the controller will move into place.
 */
function upload(string $name): UploadedFile
{
    global $tempFiles;

    $path = sys_get_temp_dir() . '/rcp-test-' . bin2hex(random_bytes(6)) . '.pdf';
    file_put_contents($path, "%PDF-1.4\n% evidence\n");
    $tempFiles[] = $path;

    return new UploadedFile($path, $name, 'application/pdf', filesize($path), UPLOAD_ERR_OK);
}

function send(PDO $pdo, string $method, string $path, string $userId, string $role, array $form = [], array $files = []): ResponseInterface
{
    global $fail;

    $_SESSION = ['user_id' => $userId, 'role' => $role, 'first_name' => 'Test', 'last_name' => 'User',
                 'csrf_token' => str_repeat('a', 64)];

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

    $request = (new ServerRequestFactory())->createServerRequest($method, $path);
    if ($method === 'POST') {
        $request = $request->withHeader('Content-Type', 'multipart/form-data')
            ->withParsedBody($form + ['csrf_token' => str_repeat('a', 64)])
            ->withUploadedFiles($files);
    }

    $notices = [];
    set_error_handler(static function (int $type, string $message, string $file = '', int $line = 0) use (&$notices): bool {
        if (!str_starts_with($message, 'session_start():')) {
            $notices[] = $message . ' (' . basename($file) . ':' . $line . ')';
        }
        return true;
    });
    try {
        $response = $app->handle($request);
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
$storedFiles = [];

try {
    $progress = new PriorProgress($pdo);
    $stages = (new ExamStage($pdo))->allActive();
    $admin = $pdo->query(
        "SELECT u.user_id FROM users u JOIN user_roles r ON r.user_id = u.user_id
         JOIN roles ro ON ro.role_id = r.role_id WHERE ro.role_name = 'admin' AND r.revoked_at IS NULL LIMIT 1"
    )->fetchColumn();

    // A registered student at the very start of their journey.
    $student = $pdo->query(
        "SELECT st.student_id, st.user_id, st.student_number
         FROM students st
         JOIN student_thesis_registrations str ON str.student_id = st.student_id AND str.status = 'active'
         JOIN users u ON u.user_id = st.user_id
         WHERE NOT EXISTS (SELECT 1 FROM student_stage_progress p WHERE p.student_id = st.student_id)
         LIMIT 1"
    )->fetch();
    $pdo->prepare("DELETE FROM prior_progress_claims WHERE student_id = ?")->execute([$student['student_id']]);

    $journey = new StudentJourney($pdo);
    $startedAt = $journey->currentExamStage($student['student_id'], $student['user_id']);

    echo "\n=== What the graduate school asks for ===\n";
    $defaults = $progress->requirementsFor(null);
    check('there is a default list', count($defaults) >= 2, implode(', ', array_column($defaults, 'label')));
    check('minutes and a transcript, to begin with',
        in_array('Meeting minutes', array_column($defaults, 'label'), true)
        && in_array('Transcript', array_column($defaults, 'label'), true));

    $second = $stages[1];
    check('a stage with no list of its own uses the default',
        array_column($progress->requirementsFor($second['stage_id']), 'label') === array_column($defaults, 'label'));

    send($pdo, 'POST', '/admin/prior-progress/evidence', $admin, 'admin', [
        'stage_id' => $second['stage_id'], 'label' => 'Examiner\'s report',
        'document_type_id' => $defaults[0]['document_type_id'], 'note' => 'Signed by the panel.', 'is_required' => '1',
    ]);
    check('an administrator can ask a stage for something else', str_contains(flash(), 'saved'));
    $own = $progress->requirementsFor($second['stage_id']);
    check('and that stage now asks only for it', array_column($own, 'label') === ['Examiner\'s report'],
        implode(', ', array_column($own, 'label')));
    check('while other stages still use the default',
        array_column($progress->requirementsFor($stages[0]['stage_id']), 'label') === array_column($defaults, 'label'));

    send($pdo, 'POST', '/admin/prior-progress/evidence', $admin, 'admin', [
        'stage_id' => '', 'label' => 'Fee receipt',
        'document_type_id' => $defaults[0]['document_type_id'], 'is_required' => '',
    ]);
    $withOptional = $progress->requirementsFor(null);
    check('the default list takes an optional item too', count($withOptional) === count($defaults) + 1
        && !end($withOptional)['is_required']);

    $receipt = end($withOptional);
    send($pdo, 'POST', '/admin/prior-progress/evidence/remove', $admin, 'admin', ['requirement_id' => $receipt['requirement_id']]);
    check('and it can be dropped again', count($progress->requirementsFor(null)) === count($defaults), flash());

    echo "\n=== The student's claim ===\n";
    $page = (string) send($pdo, 'GET', '/student/thesis/continue', $student['user_id'], 'student')->getBody();
    check('the page offers the stages they might have passed', str_contains($page, $stages[0]['name']) && str_contains($page, $second['name']));
    check('and asks for that stage\'s evidence', str_contains($page, 'Examiner&#039;s report') || str_contains($page, "Examiner's report"));

    $thesisPage = (string) send($pdo, 'GET', '/student/thesis', $student['user_id'], 'student')->getBody();
    check('the thesis page points them here', str_contains($thesisPage, '/student/thesis/continue'));

    // Claiming the second stage, which asks only for the examiner's report.
    send($pdo, 'POST', '/student/thesis/continue', $student['user_id'], 'student',
        ['stage_id' => $second['stage_id'], 'note' => 'Passed at Kenyatta in 2024.']);
    check('a claim with nothing attached is refused', str_contains(flash(), 'Examiner'));
    check('and nothing is recorded',
        $pdo->query("SELECT COUNT(*) FROM prior_progress_claims WHERE student_id = " . $pdo->quote($student['student_id']))->fetchColumn() == 0);

    send($pdo, 'POST', '/student/thesis/continue', $student['user_id'], 'student',
        ['stage_id' => $second['stage_id'], 'note' => 'Passed at Kenyatta in 2024.'],
        ['evidence' => [$own[0]['requirement_id'] => upload('panel-report.pdf')]]);
    check('with the evidence it is sent', str_contains(flash(), 'graduate school'));

    $claim = $progress->latestClaim($student['student_id']);
    check('the claim names the stage and carries the file',
        $claim['stage_id'] === $second['stage_id'] && count($claim['files']) === 1
        && $claim['files'][0]['file_name'] === 'panel-report.pdf');
    $storedFiles[] = __DIR__ . '/../public/' . $claim['files'][0]['file_path'];
    check('nothing is granted yet', $journey->currentExamStage($student['student_id'], $student['user_id'])['stage_id'] === $startedAt['stage_id']);

    send($pdo, 'POST', '/student/thesis/continue', $student['user_id'], 'student',
        ['stage_id' => $stages[0]['stage_id']],
        ['evidence' => [
            $defaults[0]['requirement_id'] => upload('minutes.pdf'),
            $defaults[1]['requirement_id'] => upload('transcript.pdf'),
        ]]);
    check('a second claim while one waits is refused', str_contains(flash(), 'with the graduate school'));

    echo "\n=== The administrator decides ===\n";
    $queue = (string) send($pdo, 'GET', '/admin/prior-progress', $admin, 'admin')->getBody();
    check('the claim is in the queue with its evidence',
        str_contains($queue, $student['student_number']) && str_contains($queue, 'panel-report.pdf'));
    check('and says what approving would grant', str_contains($queue, 'approving records that stage'));

    send($pdo, 'POST', '/admin/prior-progress/decision', $admin, 'admin',
        ['claim_id' => $claim['claim_id'], 'decision' => 'reject', 'reason' => '']);
    check('rejecting without a reason is refused', str_contains(flash(), 'Give a reason'));

    send($pdo, 'POST', '/admin/prior-progress/decision', $admin, 'admin',
        ['claim_id' => $claim['claim_id'], 'decision' => 'reject', 'reason' => 'The report is not signed.']);
    check('rejected, with the reason kept', str_contains(flash(), 'rejected')
        && $progress->latestClaim($student['student_id'])['decision_reason'] === 'The report is not signed.');
    check('the student may try again', $progress->studentState($student['student_id'], $student['user_id'])['can_claim']);

    $page = (string) send($pdo, 'GET', '/student/thesis/continue', $student['user_id'], 'student')->getBody();
    check('who is told why', str_contains($page, 'The report is not signed.'));

    send($pdo, 'POST', '/student/thesis/continue', $student['user_id'], 'student',
        ['stage_id' => $second['stage_id']],
        ['evidence' => [$own[0]['requirement_id'] => upload('panel-report-signed.pdf')]]);
    check('fresh evidence is accepted', str_contains(flash(), 'graduate school'));

    $claim = $progress->latestClaim($student['student_id']);
    $storedFiles[] = __DIR__ . '/../public/' . $claim['files'][0]['file_path'];

    send($pdo, 'POST', '/admin/prior-progress/decision', $admin, 'admin',
        ['claim_id' => $claim['claim_id'], 'decision' => 'approve']);
    $message = flash();
    check('approving records the stage and every earlier one',
        str_contains($message, $stages[0]['name']) && str_contains($message, $second['name']), $message);

    $recorded = $pdo->query(
        "SELECT COUNT(*) FROM student_stage_progress WHERE student_id = " . $pdo->quote($student['student_id']) . " AND status = 'complete'"
    )->fetchColumn();
    check('both stages are on the student\'s record', (int) $recorded === 2);
    check('and they carry on from the next stage',
        ($journey->currentExamStage($student['student_id'], $student['user_id'])['stage_id'] ?? null) === $stages[2]['stage_id'],
        $stages[2]['name']);

    send($pdo, 'POST', '/admin/prior-progress/decision', $admin, 'admin',
        ['claim_id' => $claim['claim_id'], 'decision' => 'approve']);
    check('deciding a decided claim again is refused', str_contains(flash(), 'already been decided'));

    $page = (string) send($pdo, 'GET', '/student/thesis/continue', $student['user_id'], 'student')->getBody();
    check('the student sees what was recorded',
        str_contains($page, 'Approved') && str_contains($page, $stages[0]['name']));

    echo "\n=== Who may do any of this ===\n";
    $turnedAway = send($pdo, 'GET', '/admin/prior-progress', $student['user_id'], 'student');
    check('a student cannot open the queue', $turnedAway->getStatusCode() === 302);
    send($pdo, 'POST', '/admin/prior-progress/evidence', $student['user_id'], 'student',
        ['stage_id' => '', 'label' => 'Anything', 'document_type_id' => $defaults[0]['document_type_id']]);
    check('nor change what is asked for', count($progress->requirementsFor(null)) === count($defaults));
} catch (\Throwable $e) {
    $fail++;
    echo "  FAIL  threw " . get_class($e) . ': ' . $e->getMessage() . "\n        " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    $pdo->rollBack();

    // The rows are rolled back; the files the controller moved into place
    // are not, so they go by hand.
    foreach (array_merge($storedFiles, $tempFiles) as $path) {
        if (is_file($path)) {
            unlink($path);
        }
    }
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
