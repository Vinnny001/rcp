<?php

/**
 * Manual exercise of rubric-based examiner marking against the real
 * database, wrapped in a transaction that is always rolled back.
 *
 * The things worth pinning: the maximum is derived from the criteria
 * so it cannot disagree with them, a half-marked sheet is refused
 * rather than averaged as zeros, the panel average is calculated
 * rather than typed, and only the named leader can confirm it.
 *
 * Run: php tests/manual_rubric_marking.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Models\Rubric;

$env = parse_ini_file(__DIR__ . '/../.env');
$pdo = new PDO(
    "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4",
    $env['DB_USER'],
    $env['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

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

$pdo->beginTransaction();

$r = new Rubric($pdo);

$oral   = $r->findTemplate($pdo->query("SELECT template_id FROM rubric_templates WHERE code='oral_proposal_presentation'")->fetchColumn());
$thesis = $r->findTemplate($pdo->query("SELECT template_id FROM rubric_templates WHERE code='thesis_marking_scheme'")->fetchColumn());
$meeting = $pdo->query("SELECT meeting_id FROM meetings LIMIT 1")->fetchColumn();
$examiners = $pdo->query("SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id=u.user_id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);

echo "\n=== The maximum comes from the criteria ===\n";
check('oral presentation totals 45', (float) $oral['max_total'] === 45.0, $oral['max_total']);
check('thesis scheme totals 100', (float) $thesis['max_total'] === 100.0, $thesis['max_total']);
check('oral has 16 criteria', (int) $oral['criterion_count'] === 16, $oral['criterion_count']);
check('thesis has 14', (int) $thesis['criterion_count'] === 14, $thesis['criterion_count']);

$criteria = $r->criteriaFor($oral['template_id']);
$maxBefore = $r->maxTotalFor($oral['template_id']);
$pdo->prepare("UPDATE rubric_criteria SET max_score = max_score + 5 WHERE criterion_id = ?")
    ->execute([$criteria[0]['criterion_id']]);
check('editing a criterion moves the maximum with it',
    $r->maxTotalFor($oral['template_id']) === $maxBefore + 5, $maxBefore . ' -> ' . $r->maxTotalFor($oral['template_id']));
$pdo->prepare("UPDATE rubric_criteria SET max_score = max_score - 5 WHERE criterion_id = ?")
    ->execute([$criteria[0]['criterion_id']]);

echo "\n=== Marking is all-or-nothing ===\n";
$full = [];
$remarks = [];
foreach ($criteria as $c) {
    $full[$c['criterion_id']] = $c['max_score'];
}
$partial = $full;
array_pop($partial);

$err = throws(fn() => $r->saveScores($meeting, $examiners[0], $oral['template_id'], $partial, []));
check('a half-marked sheet is refused', $err !== null, $err ?? '');
check('nothing was written from the refused attempt',
    (int) $pdo->query("SELECT COUNT(*) FROM rubric_scores WHERE meeting_id='$meeting'")->fetchColumn() === 0);

$overMax = $full;
$overMax[$criteria[0]['criterion_id']] = 99;
$err = throws(fn() => $r->saveScores($meeting, $examiners[0], $oral['template_id'], $overMax, []));
check('a score above the row maximum is refused', $err !== null, $err ?? '');

$notNumber = $full;
$notNumber[$criteria[0]['criterion_id']] = 'good';
check('a non-numeric score is refused',
    throws(fn() => $r->saveScores($meeting, $examiners[0], $oral['template_id'], $notNumber, [])) !== null);

echo "\n=== A complete sheet ===\n";
$r->saveScores($meeting, $examiners[0], $oral['template_id'], $full, [$criteria[0]['criterion_id'] => 'Clear framing.']);
$result = $r->examinerResult($meeting, $examiners[0], $oral['template_id']);
check('full marks reads as 100%', $result['percentage'] === 100.0, $result['total'] . '/' . $result['max']);
check('remarks are kept', $r->scoresFor($meeting, $examiners[0])[$criteria[0]['criterion_id']]['remarks'] === 'Clear framing.');

$half = [];
foreach ($criteria as $c) {
    $half[$c['criterion_id']] = round((float) $c['max_score'] / 2, 2);
}
$r->saveScores($meeting, $examiners[0], $oral['template_id'], $half, []);
check('re-marking replaces rather than adds',
    (int) $pdo->query("SELECT COUNT(*) FROM rubric_scores WHERE meeting_id='$meeting' AND examiner_id='{$examiners[0]}'")->fetchColumn() === 16);
check('and the total follows the new marks',
    $r->examinerResult($meeting, $examiners[0], $oral['template_id'])['percentage'] === 50.0);

echo "\n=== The panel average ===\n";
$r->saveScores($meeting, $examiners[1], $oral['template_id'], $full, []);
$panel = $r->panelResults($meeting, $oral['template_id']);
check('both examiners appear', count($panel) === 2);
check('the average is the mean of their percentages',
    $r->calculatedAverage($meeting, $oral['template_id']) === 75.0, '50 and 100 -> ' . $r->calculatedAverage($meeting, $oral['template_id']));

$partialThird = $full;
array_pop($partialThird);
$pdo->prepare("INSERT INTO rubric_scores (rubric_score_id, meeting_id, criterion_id, examiner_id, score)
               VALUES (UUID(), ?, ?, ?, 1)")->execute([$meeting, $criteria[0]['criterion_id'], $examiners[2]]);
check('an examiner who has not finished is excluded from the average',
    $r->calculatedAverage($meeting, $oral['template_id']) === 75.0, 'still 75');
$incomplete = array_values(array_filter($r->panelResults($meeting, $oral['template_id']), fn($p) => !$p['complete']));
check('but is still listed as outstanding', count($incomplete) === 1);

echo "\n=== Only the named leader confirms ===\n";
check('nobody can confirm before a leader is named',
    throws(fn() => $r->confirmAverage($meeting, $examiners[0], $oral['template_id'])) !== null);

$r->designateLeader($meeting, $examiners[0]);
$err = throws(fn() => $r->confirmAverage($meeting, $examiners[1], $oral['template_id']));
check('another examiner cannot confirm', $err !== null, $err ?? '');

$confirmed = $r->confirmAverage($meeting, $examiners[0], $oral['template_id']);
check('the leader confirms the calculated figure', $confirmed === 75.0, (string) $confirmed);
$leader = $r->panelLeader($meeting);
check('and it is recorded with a timestamp', $leader['confirmed_at'] !== null && (float) $leader['average_score'] === 75.0);

$r->designateLeader($meeting, $examiners[1]);
$leader = $r->panelLeader($meeting);
check('naming a new leader replaces the old one', $leader['examiner_id'] === $examiners[1]);
check('and clears the previous confirmation', $leader['confirmed_at'] === null);
check('there is still only one leader row',
    (int) $pdo->query("SELECT COUNT(*) FROM rubric_panel_leaders WHERE meeting_id='$meeting'")->fetchColumn() === 1);

echo "\n=== The coordinator releases the result ===\n";
// Fresh leader from the reassignment above, so re-confirm first.
check('a confirmed average is not yet the student\'s business',
    $r->isReleasedToStudent($meeting) === false);
check('and cannot be approved before it is confirmed',
    throws(fn() => $r->approveAverage($meeting, $examiners[0])) !== null);

$r->confirmAverage($meeting, $examiners[1], $oral['template_id']);
check('still withheld after confirmation alone', $r->isReleasedToStudent($meeting) === false);

$approved = $r->approveAverage($meeting, $examiners[2]);
check('the coordinator approves the confirmed figure', $approved === 75.0, (string) $approved);
check('and only then does it reach the student', $r->isReleasedToStudent($meeting) === true);
$leader = $r->panelLeader($meeting);
check('the approver is recorded separately from the confirmer',
    $leader['approved_by'] === $examiners[2] && $leader['examiner_id'] === $examiners[1]);
check('approving twice is harmless',
    throws(fn() => $r->approveAverage($meeting, $examiners[2])) === null);

echo "\n=== Interpretation bands ===\n";
check('the thesis scheme has five', count($r->bandsFor($thesis['template_id'])) === 5);
foreach ([[85, 'typographical'], [65, 'minor'], [55, 'major'], [45, 'resubmit'], [20, 'Rejected']] as [$pct, $expect]) {
    $band = $r->bandFor($thesis['template_id'], (float) $pct);
    check("  {$pct}% reads as \"{$expect}\"", $band !== null && str_contains($band['label'], $expect), $band['label'] ?? 'none');
}
check('the oral scheme has none, as printed', $r->bandFor($oral['template_id'], 80.0) === null);

echo "\n=== Stage links ===\n";
$stage = $pdo->query("SELECT stage_id FROM exam_stages WHERE code='concept_presentation'")->fetchColumn();
check('concept presentation is marked with the oral scheme',
    $r->templateForStage($stage)['code'] === 'oral_proposal_presentation');
$stage = $pdo->query("SELECT stage_id FROM exam_stages WHERE code='final_thesis'")->fetchColumn();
check('final thesis is marked with the thesis scheme',
    $r->templateForStage($stage)['code'] === 'thesis_marking_scheme');

$pdo->rollBack();

printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
