<?php

/**
 * Manual end-to-end exercise of examiner marking, against the real
 * database and the real templates, wrapped in a transaction that is
 * always rolled back.
 *
 * Walks the full release chain: examiners mark, the panel leader
 * confirms the calculated average, the coordinator releases it, and
 * only then is it the student's business.
 *
 * Run: php tests/manual_examiner_marking.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Models\ExaminerAssignment;
use App\Models\ResearchCoordinator;
use App\Models\Rubric;

$env = parse_ini_file(__DIR__ . '/../.env');
$pdo = new PDO(
    "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4",
    $env['DB_USER'],
    $env['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$twig = \Slim\Views\Twig::create(__DIR__ . '/../src/Views', ['cache' => false]);
foreach (['show_chat' => false, 'unread_notifications_count' => 0, 'unread_chats_count' => 0,
          'coordinator_programs' => [], 'is_department_head' => false] as $k => $v) {
    $twig->getEnvironment()->addGlobal($k, $v);
}

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, mixed $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    $detail = (string) $detail;
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

function render(\Slim\Views\Twig $twig, string $tpl, array $vars): string
{
    return (string) $twig->render(new \Slim\Psr7\Response(), $tpl, $vars)->getBody();
}

$pdo->beginTransaction();

$rubric = new Rubric($pdo);
$assign = new ExaminerAssignment($pdo);
$rc     = new ResearchCoordinator($pdo);

$admin = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();

// An existing approval_board meeting already maps to the Proposal
// Approval stage, which is marked with the oral presentation scheme.
$meeting = $pdo->query(
    "SELECT m.meeting_id, m.proposal_id, s.stage_id, s.rubric_template_id
     FROM meetings m JOIN exam_stages s ON s.stage_id = m.exam_stage_id
     WHERE s.rubric_template_id IS NOT NULL LIMIT 1"
)->fetch();
$templateId = $meeting['rubric_template_id'];
$criteria = $rubric->criteriaFor($templateId);

$examiners = $pdo->query("SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id=u.user_id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
[$examA, $examB, $outsider] = $examiners;

$invite = $pdo->prepare(
    "INSERT IGNORE INTO meeting_attendees (attendee_id, meeting_id, user_id, role_in_meeting)
     VALUES (UUID(), ?, ?, 'examiner')"
);
$invite->execute([$meeting['meeting_id'], $examA]);
$invite->execute([$meeting['meeting_id'], $examB]);

echo "\n=== Reaching a marking sheet ===\n";
$mine = $assign->forExaminer($examA);
check('an invited examiner sees the meeting', count($mine) >= 1);
check('with its stage and scheme resolved',
    $mine[0]['stage_name'] !== null && $mine[0]['template_name'] !== null,
    $mine[0]['stage_name'] . ' / ' . $mine[0]['template_name']);
check('a lecturer who was not invited sees nothing',
    count(array_filter($assign->forExaminer($outsider), fn($r) => $r['meeting_id'] === $meeting['meeting_id'])) === 0);
check('and cannot open the sheet directly',
    $assign->findForExaminer($meeting['meeting_id'], $outsider) === null);

echo "\n=== The sheet renders ===\n";
$ctx = $assign->findForExaminer($meeting['meeting_id'], $examA);
$sheetVars = fn(bool $isLeader, ?array $leader) => [
    'active_page' => 'l-examining', 'first_name' => 'E', 'last_name' => 'X',
    'meeting' => $ctx, 'criteria' => $criteria,
    'max_total' => $rubric->maxTotalFor($templateId),
    'my_scores' => $rubric->scoresFor($meeting['meeting_id'], $examA),
    'my_result' => $rubric->examinerResult($meeting['meeting_id'], $examA, $templateId),
    'bands' => $rubric->bandsFor($templateId),
    'panel' => $isLeader ? $rubric->panelResults($meeting['meeting_id'], $templateId) : [],
    'calculated_average' => $isLeader ? $rubric->calculatedAverage($meeting['meeting_id'], $templateId) : null,
    'leader' => $leader, 'is_leader' => $isLeader,
    'csrf_token' => 't', 'error' => null, 'success' => null,
];
$html = render($twig, 'lecturers/marking_sheet.twig', $sheetVars(false, null));
check('every criterion gets an input', substr_count($html, 'name="score[') === count($criteria), count($criteria) . ' rows');
check('the scheme maximum is shown', str_contains($html, (string) (int) $rubric->maxTotalFor($templateId)));
check('a non-leader is not shown the panel', !str_contains($html, 'Panel average'));

echo "\n=== Marking ===\n";
$full = [];
foreach ($criteria as $c) {
    $full[$c['criterion_id']] = $c['max_score'];
}
$rubric->saveScores($meeting['meeting_id'], $examA, $templateId, $full, []);

$half = [];
foreach ($criteria as $c) {
    $half[$c['criterion_id']] = round((float) $c['max_score'] / 2, 2);
}
$rubric->saveScores($meeting['meeting_id'], $examB, $templateId, $half, []);

check('examiner A scored 100%', $rubric->examinerResult($meeting['meeting_id'], $examA, $templateId)['percentage'] === 100.0);
check('examiner B scored 50%', $rubric->examinerResult($meeting['meeting_id'], $examB, $templateId)['percentage'] === 50.0);
check('the calculated average is 75%', $rubric->calculatedAverage($meeting['meeting_id'], $templateId) === 75.0);

echo "\n=== Only the leader confirms ===\n";
$rubric->designateLeader($meeting['meeting_id'], $examA);
check('a non-leader confirming is refused',
    throws(fn() => $rubric->confirmAverage($meeting['meeting_id'], $examB, $templateId)) !== null);

$leaderHtml = render($twig, 'lecturers/marking_sheet.twig', $sheetVars(true, $rubric->panelLeader($meeting['meeting_id'])));
check('the leader sees the panel and the figure to confirm',
    str_contains($leaderHtml, 'Panel average') && str_contains($leaderHtml, 'Confirm 75%'));

$rubric->confirmAverage($meeting['meeting_id'], $examA, $templateId);
check('confirmed but not yet the student\'s business', $rubric->isReleasedToStudent($meeting['meeting_id']) === false);

$leaderHtml = render($twig, 'lecturers/marking_sheet.twig', $sheetVars(true, $rubric->panelLeader($meeting['meeting_id'])));
check('and the sheet says it is waiting on the coordinator',
    str_contains($leaderHtml, 'once the coordinator approves'));

echo "\n=== The coordinator releases it ===\n";
$programId = $assign->programForMeeting($meeting['meeting_id']);
check('the meeting resolves to a program', $programId !== null);

$coordUser = $examiners[2];
$rc->assign($programId, $coordUser, $admin);
$queue = $assign->awaitingApproval([$programId]);
check('it appears in that coordinator\'s release queue', count($queue) === 1);
check('showing the confirmed figure and who confirmed it',
    (float) $queue[0]['average_score'] === 75.0 && $queue[0]['leader_name'] !== null);

$other = $pdo->query("SELECT program_id FROM programs WHERE program_id <> '$programId' LIMIT 1")->fetchColumn();
check('a coordinator of another program sees nothing', count($assign->awaitingApproval([$other])) === 0);

$queue[0]['band'] = $rubric->bandFor($templateId, (float) $queue[0]['average_score']);
$queue[0]['panel'] = $rubric->panelResults($meeting['meeting_id'], $templateId);
$resultsHtml = render($twig, 'coordinators/results.twig', [
    'active_page' => 'l-coordinator-results', 'first_name' => 'C', 'last_name' => 'O',
    'programs' => $rc->programsForUser($coordUser), 'pending' => $queue,
    'csrf_token' => 't', 'error' => null, 'success' => null,
]);
check('the results page shows the marks behind the average',
    str_contains($resultsHtml, 'The marks behind it') && str_contains($resultsHtml, 'Release to the student'));

$rubric->approveAverage($meeting['meeting_id'], $coordUser);
check('released to the student', $rubric->isReleasedToStudent($meeting['meeting_id']) === true);
check('and it leaves the queue', count($assign->awaitingApproval([$programId])) === 0);

$leader = $rubric->panelLeader($meeting['meeting_id']);
check('confirmer and approver are recorded separately',
    $leader['examiner_id'] === $examA && $leader['approved_by'] === $coordUser);

$pdo->rollBack();

printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
