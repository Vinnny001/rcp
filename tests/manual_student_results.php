<?php

/**
 * Manual exercise of what reaches a student's results page from
 * rubric marking, against the real database and the real template,
 * wrapped in a transaction that is always rolled back.
 *
 * The point under test is the release gate: an average that has been
 * marked and even confirmed must not appear, and the same figure must
 * appear the moment the coordinator approves it.
 *
 * Run: php tests/manual_student_results.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Models\GradingPolicy;
use App\Models\Rubric;

$env = parse_ini_file(__DIR__ . '/../.env');
$pdo = new PDO(
    "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4",
    $env['DB_USER'],
    $env['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$twig = \Slim\Views\Twig::create(__DIR__ . '/../src/Views', ['cache' => false]);
foreach (['profile_complete' => true, 'has_thesis_registration' => true, 'show_chat' => false,
          'unread_notifications_count' => 0, 'unread_chats_count' => 0] as $k => $v) {
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

$pdo->beginTransaction();

$rubric = new Rubric($pdo);

$meeting = $pdo->query(
    "SELECT m.meeting_id, m.proposal_id, s.rubric_template_id, s.name AS stage_name,
            st.user_id, tp.title
     FROM meetings m
     JOIN exam_stages s ON s.stage_id = m.exam_stage_id
     JOIN thesis_proposals tp ON tp.proposal_id = m.proposal_id
     JOIN students st ON st.student_id = tp.student_id
     WHERE s.rubric_template_id IS NOT NULL LIMIT 1"
)->fetch();
$templateId = $meeting['rubric_template_id'];
$studentUserId = $meeting['user_id'];
$criteria = $rubric->criteriaFor($templateId);

$examiners = $pdo->query("SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id=u.user_id LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
$coordinator = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();

$renderResults = function () use ($twig, $rubric, $pdo, $studentUserId): string {
    $byProposal = [];
    foreach ($rubric->releasedOutcomesForStudent($studentUserId) as $o) {
        $byProposal[$o['proposal_id']][] = $o;
    }
    $groups = [];
    foreach ($byProposal as $outcomes) {
        $groups[] = ['proposal_title' => $outcomes[0]['proposal_title'], 'examinations' => $outcomes];
    }

    return (string) $twig->render(new \Slim\Psr7\Response(), 'students/outcomes.twig', [
        'active_page' => 'outcomes', 'first_name' => 'S', 'student_number' => 'X',
        'exam_outcomes' => $groups, 'document_outcomes' => [],
        'exam_scale' => GradingPolicy::examScale($pdo),
        'document_scale' => GradingPolicy::documentScale(),
    ])->getBody();
};

echo "\n=== Nothing marked yet ===\n";
check('no released outcomes', $rubric->releasedOutcomesForStudent($studentUserId) === []);

echo "\n=== Marked, but not confirmed ===\n";
// Two examiners: 100% and 50%, so the average is 75% — a Distinction
// on the student scale, which the 70 cut point makes reachable.
$full = [];
$half = [];
foreach ($criteria as $c) {
    $full[$c['criterion_id']] = $c['max_score'];
    $half[$c['criterion_id']] = round((float) $c['max_score'] / 2, 2);
}
$rubric->saveScores($meeting['meeting_id'], $examiners[0], $templateId, $full, [$criteria[0]['criterion_id'] => 'Strong framing throughout.']);
$rubric->saveScores($meeting['meeting_id'], $examiners[1], $templateId, $half, []);
$rubric->designateLeader($meeting['meeting_id'], $examiners[0]);

check('still nothing on the results page', $rubric->releasedOutcomesForStudent($studentUserId) === []);
check('and the page says so', str_contains($renderResults(), 'No examination results yet'));

echo "\n=== Confirmed by the leader ===\n";
$rubric->confirmAverage($meeting['meeting_id'], $examiners[0], $templateId);
check('a confirmed average is still withheld', $rubric->releasedOutcomesForStudent($studentUserId) === []);
check('the page still shows nothing', str_contains($renderResults(), 'No examination results yet'));

echo "\n=== Released by the coordinator ===\n";
$rubric->approveAverage($meeting['meeting_id'], $coordinator);
$outcomes = $rubric->releasedOutcomesForStudent($studentUserId);
check('the outcome now appears', count($outcomes) === 1);
check('labelled by its stage', $outcomes[0]['doc_type_name'] === $meeting['stage_name'], $outcomes[0]['doc_type_name']);
check('75% bands as a distinction', $outcomes[0]['outcome'] === 'distinction', $outcomes[0]['outcome_label']);
check('both examiners are counted', $outcomes[0]['reviewer_count'] === 2, $outcomes[0]['reviewer_count']);
check('examiner remarks come through', in_array('Strong framing throughout.', $outcomes[0]['comments'], true));

$html = $renderResults();
check('the page renders the stage name', str_contains($html, $meeting['stage_name']));
check('and the banded outcome, not a mark', str_contains($html, 'Distinction') && !str_contains($html, '75%'));
check('with the examiner count', str_contains($html, '2 examiners'));
check('and the remark', str_contains($html, 'Strong framing throughout.'));

echo "\n=== The scale shown to students ===\n";
check('lists all five bands', substr_count($html, '%)') >= 5);
check('including the new middle band', str_contains($html, 'Pass with corrections'));

$pdo->rollBack();

printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
