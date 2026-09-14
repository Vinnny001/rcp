<?php

/**
 * Manual end-to-end exercise of the supervisor shortlist workflow
 * against the real database, wrapped in a transaction that is always
 * rolled back.
 *
 * Covers the department half of a request: choice validation, the
 * minutes gate, voting rules, rejection reasons, and approval sending
 * nothing on its own. What happens once it reaches the lecturers lives
 * in manual_request_rounds.php.
 *
 * Run: php tests/manual_shortlist_flow.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Models\SupervisorShortlist;

$env = parse_ini_file(__DIR__ . '/../.env');
$pdo = new PDO(
    "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4",
    $env['DB_USER'],
    $env['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$pass = 0; $fail = 0;
function check(string $label, bool $ok, string $detail = ''): void {
    global $pass, $fail;
    if ($ok) { $pass++; printf("  PASS  %s%s\n", $label, $detail ? "  ($detail)" : ''); }
    else     { $fail++; printf("  FAIL  %s%s\n", $label, $detail ? "  ($detail)" : ''); }
}
function throws(callable $fn): ?string {
    try { $fn(); return null; } catch (\Throwable $e) { return $e->getMessage(); }
}

$pdo->beginTransaction();

$m = new SupervisorShortlist($pdo);

// ---- fixtures -------------------------------------------------------
$student  = $pdo->query("SELECT s.student_id, s.user_id FROM students s
                         JOIN thesis_proposals tp ON tp.student_id = s.student_id AND tp.status NOT IN ('draft', 'rejected')
                         LIMIT 1")->fetch();
$proposal = $pdo->query("SELECT proposal_id FROM thesis_proposals WHERE student_id = '{$student['student_id']}' AND status NOT IN ('draft', 'rejected') LIMIT 1")->fetchColumn();
$lecturers = $pdo->query("SELECT lecturer_id FROM lecturers
                          WHERE user_id <> '{$student['user_id']}' LIMIT 5")->fetchAll(PDO::FETCH_COLUMN);
$admin = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();
$dept = $pdo->query("SELECT department_id FROM departments LIMIT 1")->fetchColumn();
$pos  = $pdo->query("SELECT position_id FROM department_positions LIMIT 1")->fetchColumn();

// three department heads to vote
$headIds = [];
foreach (array_slice($pdo->query("SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id=u.user_id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN), 0, 3) as $uid) {
    $hid = $pdo->query("SELECT UUID()")->fetchColumn();
    $pdo->prepare("INSERT INTO department_heads (dept_head_id, department_id, user_id, position_id, assigned_by)
                   VALUES (?,?,?,?,?)")->execute([$hid, $dept, $uid, $pos, $admin]);
    $headIds[] = $hid;
}

echo "\n=== 1. Shortlist validation ===\n";
check('rejects more than 5 choices',
    throws(fn() => $m->submit($student['student_id'], $proposal, array_map(
        fn($i) => ['lecturer_id' => $lecturers[$i % 5], 'rank' => $i + 1, 'preferred_main' => $i === 0],
        range(0, 5)))) !== null);

// Naming a preferred main is optional: without one the list is worked
// in the student's own order and whoever accepts first becomes main.
check('accepts zero preferred mains',
    ($e = throws(fn() => $m->submit($student['student_id'], $proposal, [
        ['lecturer_id' => $lecturers[0], 'rank' => 1, 'preferred_main' => false],
    ]))) === null, $e ?? '');

check('rejects two preferred mains',
    throws(fn() => $m->submit($student['student_id'], $proposal, [
        ['lecturer_id' => $lecturers[0], 'rank' => 1, 'preferred_main' => true],
        ['lecturer_id' => $lecturers[1], 'rank' => 2, 'preferred_main' => true],
    ])) !== null);

check('rejects the same lecturer twice',
    throws(fn() => $m->submit($student['student_id'], $proposal, [
        ['lecturer_id' => $lecturers[0], 'rank' => 1, 'preferred_main' => true],
        ['lecturer_id' => $lecturers[0], 'rank' => 2, 'preferred_main' => false],
    ])) !== null);

// helper: a fresh 4-choice shortlist where the preferred main is ranked 3rd,
// so "preferred main goes first" is actually being proved.
$makeShortlist = function () use ($m, $student, $proposal, $lecturers): string {
    return $m->submit($student['student_id'], $proposal, [
        ['lecturer_id' => $lecturers[0], 'rank' => 1, 'preferred_main' => false],
        ['lecturer_id' => $lecturers[1], 'rank' => 2, 'preferred_main' => false],
        ['lecturer_id' => $lecturers[2], 'rank' => 3, 'preferred_main' => true],
        ['lecturer_id' => $lecturers[3], 'rank' => 4, 'preferred_main' => false],
    ]);
};
$scheduleAndVote = function (string $sid, array $votes) use ($m, $headIds, $admin): string {
    $meeting = $m->scheduleMeeting($sid, '2026-10-01 10:00:00', 'physical', 'Boardroom', null, $headIds, $admin);
    foreach ($votes as $i => $v) { $m->castVote($meeting, $headIds[$i], $v); }
    return $meeting;
};

echo "\n=== 2. Minutes gate the outcome, in two steps ===\n";
$sid = $makeShortlist();
$meeting = $scheduleAndVote($sid, ['approve', 'approve', 'reject']);
$err = throws(fn() => $m->recordOutcome($meeting));
check('refuses to apply the vote before minutes are finalised', $err !== null, $err ?? '');

$m->saveMinutes($meeting, 'Panel discussed and approved the shortlist.', false);
check('saving a draft does not unlock it', throws(fn() => $m->recordOutcome($meeting)) !== null);

check('minutes cannot be approved while still a draft',
    throws(fn() => $m->approveMinutes($meeting, $admin)) !== null);

$m->saveMinutes($meeting, 'Panel discussed and approved the shortlist.', true);
$err = throws(fn() => $m->recordOutcome($meeting));
check('finalising alone is not enough — the coordinator must approve', $err !== null, $err ?? '');

$m->approveMinutes($meeting, $admin);
check('applies once approved', throws(fn() => $m->recordOutcome($meeting)) === null);
check('approving twice is harmless', throws(fn() => $m->approveMinutes($meeting, $admin)) === null);

echo "\n=== 3. Voting rules ===\n";
$sid2 = $makeShortlist();
$meeting2 = $m->scheduleMeeting($sid2, '2026-10-02 10:00:00', 'physical', 'Boardroom', null, [$headIds[0], $headIds[1]], $admin);
$err = throws(fn() => $m->castVote($meeting2, $headIds[2], 'approve'));
check('a head who was not invited cannot vote', $err !== null, $err ?? '');
$m->castVote($meeting2, $headIds[0], 'approve');
$m->castVote($meeting2, $headIds[1], 'reject');
$t = $m->tally($meeting2);
check('a tie rejects', $t['outcome'] === 'rejected', "approve={$t['approve']} reject={$t['reject']}");
$m->castVote($meeting2, $headIds[0], 'reject'); // change of mind, not a second vote
$t = $m->tally($meeting2);
check('re-voting replaces rather than stacks', $t['approve'] + $t['reject'] === 2, "total={$t['approve']}+{$t['reject']}");

echo "\n=== 4. Rejection carries the minutes as the reason ===\n";
$m->saveMinutes($meeting2, 'Panel felt the shortlist lacked methodological fit.', true);
$m->approveMinutes($meeting2, $admin);
$outcome = $m->recordOutcome($meeting2);
$row = $pdo->query("SELECT status, rejection_reason FROM supervisor_shortlists WHERE shortlist_id='$sid2'")->fetch();
check('rejected shortlist records the reason', $outcome === 'rejected' && str_contains((string) $row['rejection_reason'], 'methodological'), $row['status']);

echo "\n=== 5. Approval asks nobody; the order is stored as it will be used ===\n";
$sid3 = $makeShortlist();
$meeting3 = $scheduleAndVote($sid3, ['approve', 'approve', 'reject']);
$m->saveMinutes($meeting3, 'Approved.', true);
$m->approveMinutes($meeting3, $admin);
$m->recordOutcome($meeting3);
check('approving does not contact any lecturer — the coordinator sends it',
    (int) $pdo->query("SELECT COUNT(*) FROM supervisor_shortlist_choices WHERE shortlist_id='$sid3' AND request_status <> 'not_sent'")->fetchColumn() === 0);
// Picked third, but marked preferred main: the order decides who is
// appointed and who becomes main, so they belong at position 1.
$pref = $pdo->query("SELECT rank_position FROM supervisor_shortlist_choices WHERE shortlist_id='$sid3' AND is_preferred_main = 1")->fetchColumn();
check('a preferred main picked third is stored at position 1', (int) $pref === 1, 'rank ' . $pref);

echo "\n=== 6. A request can only be answered once ===\n";
$m->sendRequests($sid3, $admin);
$c = $pdo->query("SELECT choice_id FROM supervisor_shortlist_choices WHERE shortlist_id='$sid3' AND request_status='pending' ORDER BY rank_position DESC LIMIT 1")->fetchColumn();
$m->respondToRequest($c, false, 'No.');
$err = throws(fn() => $m->respondToRequest($c, true, null));
check('answering twice is refused', $err !== null, $err ?? '');

// The rest of the lifecycle — everyone asked at once, the student's order
// deciding who is appointed, early decisions, the deadline, and what the
// coordinator does after a failure — is exercised in
// tests/manual_request_rounds.php.

$pdo->rollBack();

printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "database rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
