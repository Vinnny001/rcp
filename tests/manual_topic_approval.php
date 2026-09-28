<?php

/**
 * The topic a student writes under, and the supervisors approving it.
 *
 * The main supervisor calls the meeting, every supervisor records a
 * decision, and one topic — only one, and only one they all approved —
 * is approved. Until then the student puts forward as many as they like,
 * and a meeting that approves none keeps them all on record and lets the
 * student write new ones. The Proposal Approval exam waits for all this.
 *
 * Drives the real app on this script's connection, inside a transaction
 * that is rolled back at the end.
 *
 * Run: php tests/manual_topic_approval.php
 */

declare(strict_types=1);

use App\Models\ExamReadiness;
use App\Models\ProposalTopic;
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

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail !== '' ? "  ($detail)" : '');
}

function send(PDO $pdo, string $method, string $path, string $userId, string $role, array $form = []): ResponseInterface
{
    global $fail;

    $_SESSION = [
        'user_id' => $userId, 'role' => $role, 'first_name' => 'Test', 'last_name' => 'User',
        'csrf_token' => str_repeat('a', 64),
    ];

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

try {
    $admin = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();
    $topics = new ProposalTopic($pdo);

    // ---- a student with a sent proposal and no topic settled ------------
    $student = $pdo->query(
        "SELECT st.student_id, st.user_id, tp.proposal_id, tp.title, str.thesis_schedule_id, ts.program_id
         FROM students st
         JOIN users u ON u.user_id = st.user_id
         JOIN thesis_proposals tp ON tp.student_id = st.student_id AND tp.status = 'submitted'
         JOIN student_thesis_registrations str ON str.student_id = st.student_id AND str.status = 'active'
         JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
         LIMIT 1"
    )->fetch();

    $pdo->prepare("DELETE FROM proposal_topics WHERE student_id = ?")->execute([$student['student_id']]);

    // Two supervisors: a main and a co, neither of them the student.
    $lecturers = $pdo->prepare(
        "SELECT l.lecturer_id, l.user_id, CONCAT(u.first_name, ' ', u.last_name) AS name
         FROM lecturers l
         JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
         JOIN users u ON u.user_id = l.user_id
         WHERE l.user_id <> ? AND l.user_id NOT IN (SELECT user_id FROM students) LIMIT 3"
    );
    $lecturers->execute([$student['user_id']]);
    [$main, $co, $stranger] = $lecturers->fetchAll();

    $pdo->prepare("UPDATE supervision_assignments SET is_active = 0 WHERE student_id = ?")->execute([$student['student_id']]);
    $appoint = $pdo->prepare(
        "INSERT INTO supervision_assignments (assignment_id, proposal_id, student_id, supervisor_id, role, appointed_by, appointment_date)
         VALUES (UUID(), ?, ?, ?, ?, ?, CURDATE())"
    );
    $appoint->execute([$student['proposal_id'], $student['student_id'], $main['lecturer_id'], 'main', $admin]);
    $appoint->execute([$student['proposal_id'], $student['student_id'], $co['lecturer_id'], 'co_supervisor', $admin]);

    $meetingAt = ['date' => date('Y-m-d', strtotime('+3 days')), 'time' => '10:00'];

    echo "\n=== The student's side ===\n";
    $page = (string) send($pdo, 'GET', '/student/proposal', $student['user_id'], 'student')->getBody();
    check('the title they were given supervisors for is their first topic',
        $pdo->query("SELECT COUNT(*) FROM proposal_topics WHERE student_id = " . $pdo->quote($student['student_id']) . " AND origin = 'proposal'")->fetchColumn() == 1);
    check('the proposal page shows it, waiting on the supervisors',
        str_contains($page, 'Your topic') && str_contains($page, 'Waiting on your supervisors'));

    send($pdo, 'POST', '/student/proposal/topic', $student['user_id'], 'student', ['title' => 'A second angle', 'synopsis' => 'Too short']);
    check('a synopsis too short to judge is refused', str_contains(flash(), 'at least 50 characters'));

    $long = str_repeat('An approach to scheduling under uncertainty. ', 3);
    send($pdo, 'POST', '/student/proposal/topic', $student['user_id'], 'student', ['title' => 'A second angle', 'synopsis' => $long]);
    check('they can put forward another topic', str_contains(flash(), 'Topic put forward'));
    send($pdo, 'POST', '/student/proposal/topic', $student['user_id'], 'student', ['title' => 'A third angle', 'synopsis' => $long]);
    check('and another — as many as they like', str_contains(flash(), 'Topic put forward'));
    check('all three are on record',
        $pdo->query("SELECT COUNT(*) FROM proposal_topics WHERE student_id = " . $pdo->quote($student['student_id']))->fetchColumn() == 3);

    echo "\n=== The exam waits for the topic ===\n";
    $stage = $pdo->query(
        "SELECT s.stage_id FROM exam_stages s JOIN document_types dt ON dt.doc_type_id = s.evidence_doc_type_id
         WHERE dt.doc_type_name = 'Proposal' AND s.is_active = 1 LIMIT 1"
    )->fetchColumn();
    $window = $pdo->query("SELECT UUID()")->fetchColumn();
    $pdo->prepare(
        "INSERT INTO exam_schedule (exam_schedule_id, thesis_schedule_id, starts_at, ends_at, exam_type, exam_stage_id, exam_schedule_description)
         VALUES (?, ?, CURDATE() + INTERVAL 2 DAY, CURDATE() + INTERVAL 20 DAY, 'internal', ?, 'Topic gate test')"
    )->execute([$window, $student['thesis_schedule_id'], $stage]);
    // Clear every booking they hold, scheduled ones included: a student
    // whose exam is already scheduled cannot book, and this suite is
    // about what the topic gates rather than about their real exam.
    $pdo->prepare("DELETE FROM exam_readiness WHERE student_id = ?")->execute([$student['student_id']]);

    check('the exam page says the topic comes first',
        str_contains((string) send($pdo, 'GET', '/student/exam', $student['user_id'], 'student')->getBody(), 'approved by your supervisors'));
    send($pdo, 'POST', '/student/exam/book', $student['user_id'], 'student', ['exam_schedule_id' => $window]);
    check('and booking it is refused', str_contains(flash(), 'has to be approved'));
    check('nothing was booked',
        $pdo->query("SELECT COUNT(*) FROM exam_readiness WHERE student_id = " . $pdo->quote($student['student_id']))->fetchColumn() == 0);

    echo "\n=== Calling the meeting ===\n";
    send($pdo, 'POST', '/lecturer/topics/schedule', $co['user_id'], 'lecturer',
        ['student_id' => $student['student_id'], 'mode' => 'physical', 'location' => 'Room 4'] + $meetingAt);
    check('a co-supervisor cannot call it', str_contains(flash(), 'Only the main supervisor'));

    send($pdo, 'POST', '/lecturer/topics/schedule', $main['user_id'], 'lecturer',
        ['student_id' => $student['student_id'], 'mode' => 'physical', 'location' => ''] + $meetingAt);
    check('a physical meeting needs a location', str_contains(flash(), 'needs a location'));

    send($pdo, 'POST', '/lecturer/topics/schedule', $main['user_id'], 'lecturer',
        ['student_id' => $student['student_id'], 'mode' => 'physical', 'location' => 'Room 4'] + $meetingAt);
    check('the main supervisor calls it', str_contains(flash(), 'meeting scheduled'));

    $meeting = $topics->openMeetingFor($student['student_id']);
    check('it is a topic approval meeting', ($meeting['meeting_type'] ?? '') === 'topic_approval');
    $attendees = $pdo->query("SELECT user_id, role_in_meeting FROM meeting_attendees WHERE meeting_id = " . $pdo->quote($meeting['meeting_id']))->fetchAll(PDO::FETCH_KEY_PAIR);
    check('both supervisors and the student are invited',
        ($attendees[$main['user_id']] ?? '') === 'supervisor' && ($attendees[$co['user_id']] ?? '') === 'supervisor'
        && ($attendees[$student['user_id']] ?? '') === 'student');
    check('all three topics are on its list',
        $pdo->query("SELECT COUNT(*) FROM proposal_topics WHERE meeting_id = " . $pdo->quote($meeting['meeting_id']))->fetchColumn() == 3);

    send($pdo, 'POST', '/student/proposal/topic', $student['user_id'], 'student', ['title' => 'A fourth angle', 'synopsis' => $long]);
    check('the student cannot add more while they sit', str_contains(flash(), 'meeting about the topics'));
    send($pdo, 'POST', '/lecturer/topics/schedule', $main['user_id'], 'lecturer',
        ['student_id' => $student['student_id'], 'mode' => 'physical', 'location' => 'Room 4'] + $meetingAt);
    check('and a second meeting cannot be called', str_contains(flash(), 'already scheduled'));

    echo "\n=== Deciding ===\n";
    $list = $pdo->query("SELECT topic_id, title FROM proposal_topics WHERE meeting_id = " . $pdo->quote($meeting['meeting_id']) . " ORDER BY created_at")->fetchAll(PDO::FETCH_KEY_PAIR);
    [$first, $second, $third] = array_keys($list);
    $decide = fn (array $who, string $topicId, string $decision, string $comment = '') => send(
        $pdo, 'POST', '/lecturer/topics/' . $meeting['meeting_id'] . '/decision', $who['user_id'], 'lecturer',
        ['topic_id' => $topicId, 'decision' => $decision, 'comment' => $comment]
    );

    $decide($stranger, $first, 'approve');
    $message = flash();
    check('a lecturer who does not supervise them cannot decide',
        str_contains($message, 'supervisors decide their topic')
        && $pdo->query("SELECT COUNT(*) FROM topic_decisions WHERE voter_user_id = " . $pdo->quote($stranger['user_id']))->fetchColumn() == 0, $message);

    $decide($main, $first, 'reject', 'Too broad.');
    $decide($co, $first, 'reject');
    $decide($main, $second, 'approve');
    $decide($co, $second, 'approve', 'Workable.');
    $decide($main, $third, 'approve');
    check('each supervisor records their own decision',
        $pdo->query("SELECT COUNT(*) FROM topic_decisions WHERE meeting_id = " . $pdo->quote($meeting['meeting_id']))->fetchColumn() == 5);

    $decide($main, $third, 'reject', 'On reflection, no.');
    check('and can change it while the meeting is open',
        $pdo->query("SELECT decision FROM topic_decisions WHERE topic_id = " . $pdo->quote($third) . " AND voter_user_id = " . $pdo->quote($main['user_id']))->fetchColumn() === 'reject');

    $page = (string) send($pdo, 'GET', '/lecturer/topics/' . $meeting['meeting_id'], $co['user_id'], 'lecturer')->getBody();
    check('the meeting page shows who has decided what', str_contains($page, 'Workable.') && str_contains($page, 'Too broad.'));
    check('and marks the one everyone approves', str_contains($page, 'Every supervisor approves'));
    check('a co-supervisor is not offered the close', !str_contains($page, 'Close the meeting'));
    $turnedAway = send($pdo, 'GET', '/lecturer/topics/' . $meeting['meeting_id'], $stranger['user_id'], 'lecturer');
    check('a stranger is turned away from it',
        $turnedAway->getStatusCode() === 302 && str_contains(flash(), 'not yours'));

    echo "\n=== Closing it with none approved ===\n";
    $close = fn (array $who, ?string $topicId, string $reason) => send(
        $pdo, 'POST', '/lecturer/topics/' . $meeting['meeting_id'] . '/close', $who['user_id'], 'lecturer',
        ['approved_topic_id' => $topicId ?? '', 'reason' => $reason]
    );

    $close($co, $second, '');
    check('a co-supervisor cannot close it', str_contains(flash(), 'Only the main supervisor'));
    $close($main, $first, '');
    check('a topic they did not all approve cannot be the one', str_contains(flash(), 'Every supervisor has to approve'));
    $close($main, null, '');
    check('approving none needs a reason', str_contains(flash(), 'Say why'));

    $close($main, null, 'All three are too close to work already done.');
    check('the meeting closes with none approved', str_contains(flash(), 'no topic approved'));
    check('every topic is kept, marked not approved, with the reason',
        $pdo->query("SELECT COUNT(*) FROM proposal_topics WHERE meeting_id = " . $pdo->quote($meeting['meeting_id']) . " AND status = 'not_approved' AND decision_reason LIKE 'All three%'")->fetchColumn() == 3);
    check('and the student is free to write new ones', $topics->studentState($student['student_id'], ['proposal_id' => $student['proposal_id'], 'title' => $student['title'], 'synopsis' => 'x', 'status' => 'submitted'])['can_submit']);

    $page = (string) send($pdo, 'GET', '/student/proposal', $student['user_id'], 'student')->getBody();
    check('who is told why', str_contains($page, 'too close to work already done'));

    echo "\n=== A second meeting, and an approval ===\n";
    send($pdo, 'POST', '/student/proposal/topic', $student['user_id'], 'student',
        ['title' => 'Scheduling under uncertainty in teaching hospitals', 'synopsis' => $long]);
    check('the student puts a fresh topic forward', str_contains(flash(), 'Topic put forward'));

    send($pdo, 'POST', '/lecturer/topics/schedule', $main['user_id'], 'lecturer',
        ['student_id' => $student['student_id'], 'mode' => 'virtual', 'virtual_link' => 'https://meet.example/topic',
         'date' => date('Y-m-d', strtotime('+10 days')), 'time' => '14:00']);
    check('the main supervisor calls another meeting', str_contains(flash(), 'meeting scheduled'));

    $second = $topics->openMeetingFor($student['student_id']);
    $fresh = $pdo->query("SELECT topic_id FROM proposal_topics WHERE meeting_id = " . $pdo->quote($second['meeting_id']))->fetchAll(PDO::FETCH_COLUMN);
    check('only the new topic is on its list', count($fresh) === 1);

    $decideAt = fn (array $who, string $decision) => send(
        $pdo, 'POST', '/lecturer/topics/' . $second['meeting_id'] . '/decision', $who['user_id'], 'lecturer',
        ['topic_id' => $fresh[0], 'decision' => $decision]
    );
    $decideAt($main, 'approve');
    send($pdo, 'POST', '/lecturer/topics/' . $second['meeting_id'] . '/close', $main['user_id'], 'lecturer',
        ['approved_topic_id' => $fresh[0], 'reason' => '']);
    check('one supervisor short, it cannot be approved', str_contains(flash(), 'Every supervisor has to approve'));

    $decideAt($co, 'approve');
    send($pdo, 'POST', '/lecturer/topics/' . $second['meeting_id'] . '/close', $main['user_id'], 'lecturer',
        ['approved_topic_id' => $fresh[0], 'reason' => 'Agreed by both supervisors.']);
    check('with both approving, it is approved', str_contains(flash(), 'Topic approved'));

    $approved = $topics->approvedFor($student['student_id']);
    check('the approved topic is on record', ($approved['topic_id'] ?? null) === $fresh[0]);
    check('and is now the proposal\'s title',
        $pdo->query("SELECT title FROM thesis_proposals WHERE proposal_id = " . $pdo->quote($student['proposal_id']))->fetchColumn()
        === 'Scheduling under uncertainty in teaching hospitals');
    check('the meeting is completed', $topics->openMeetingFor($student['student_id']) === null);

    send($pdo, 'POST', '/student/proposal/topic', $student['user_id'], 'student', ['title' => 'One more', 'synopsis' => $long]);
    check('the student cannot put more forward afterwards', str_contains(flash(), 'already been approved'));

    echo "\n=== The exam opens up ===\n";
    check('the exam page no longer holds them back',
        !str_contains((string) send($pdo, 'GET', '/student/exam', $student['user_id'], 'student')->getBody(), 'approved by your supervisors'));
    send($pdo, 'POST', '/student/exam/book', $student['user_id'], 'student', ['exam_schedule_id' => $window]);
    check('and they can book their exam', str_contains(flash(), 'Booked'));
    check('the booking is theirs',
        (new ExamReadiness($pdo))->window($student['student_id'], $student['user_id'], $window)['booked'] === true);
} catch (\Throwable $e) {
    $fail++;
    echo "  FAIL  threw " . get_class($e) . ': ' . $e->getMessage() . "\n        " . $e->getFile() . ':' . $e->getLine() . "\n";
} finally {
    $pdo->rollBack();
}

printf("\n%d passed, %d failed\n", $pass, $fail);
exit($fail > 0 ? 1 : 0);
