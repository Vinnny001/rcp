<?php

/**
 * Manual end-to-end exercise of the supervisor shortlist workflow
 * against the real database, wrapped in a transaction that is always
 * rolled back.
 *
 * Covers the paths that are easy to get wrong: the minutes gate, a tie
 * rejecting, the preferred main being approached first regardless of
 * rank, main being inherited when the preferred main declines, the
 * panel filling up, and every lecturer declining.
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
                         JOIN thesis_proposals tp ON tp.student_id = s.student_id LIMIT 1")->fetch();
$proposal = $pdo->query("SELECT proposal_id FROM thesis_proposals WHERE student_id = '{$student['student_id']}' LIMIT 1")->fetchColumn();
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

echo "\n=== 5. Preferred main is approached first, whatever their rank ===\n";
$sid3 = $makeShortlist();
$meeting3 = $scheduleAndVote($sid3, ['approve', 'approve', 'reject']);
$m->saveMinutes($meeting3, 'Approved.', true);
$m->approveMinutes($meeting3, $admin);
$m->recordOutcome($meeting3);
$contacted = $pdo->query("SELECT lecturer_id, rank_position, is_preferred_main FROM supervisor_shortlist_choices
                          WHERE shortlist_id='$sid3' AND request_status='pending'")->fetchAll();
check('exactly one lecturer is holding a request', count($contacted) === 1, count($contacted) . ' pending');
check('and it is the preferred main (ranked 3rd)', (int) $contacted[0]['is_preferred_main'] === 1 && (int) $contacted[0]['rank_position'] === 3);

echo "\n=== 6. Preferred main accepts -> becomes main ===\n";
$choice = $pdo->query("SELECT choice_id FROM supervisor_shortlist_choices WHERE shortlist_id='$sid3' AND request_status='pending'")->fetchColumn();
$res = $m->respondToRequest($choice, true, null, $admin);
check('accepting appoints them as main', $res['role'] === 'main', "role={$res['role']}");
check('and the next lecturer is approached', $res['next_contacted'] === true);

echo "\n=== 7. Filling the panel stands the rest down ===\n";
for ($i = 0; $i < 2; $i++) {
    $next = $pdo->query("SELECT choice_id FROM supervisor_shortlist_choices WHERE shortlist_id='$sid3' AND request_status='pending'")->fetchColumn();
    if ($next) { $m->respondToRequest($next, true, null, $admin); }
}
$counts = [];
foreach ($pdo->query("SELECT request_status, COUNT(*) c FROM supervisor_shortlist_choices WHERE shortlist_id='$sid3' GROUP BY request_status")->fetchAll() as $r) {
    $counts[$r['request_status']] = (int) $r['c'];
}
check('three accepted', ($counts['accepted'] ?? 0) === 3, json_encode($counts));
check('the fourth was cancelled, not left pending', ($counts['cancelled'] ?? 0) === 1 && !isset($counts['pending']));
$roles = $pdo->query("SELECT role, COUNT(*) c FROM supervision_assignments WHERE proposal_id='$proposal' GROUP BY role")->fetchAll();
$roleMap = [];
foreach ($roles as $r) { $roleMap[$r['role']] = (int) $r['c']; }
check('exactly one main supervisor exists', ($roleMap['main'] ?? 0) === 1, json_encode($roleMap));

echo "\n=== 8. Preferred main declines -> the next acceptor inherits main ===\n";
$pdo->prepare("DELETE FROM supervision_assignments WHERE proposal_id = ?")->execute([$proposal]);
$sid4 = $makeShortlist();
$meeting4 = $scheduleAndVote($sid4, ['approve', 'approve', 'approve']);
$m->saveMinutes($meeting4, 'Approved.', true);
$m->approveMinutes($meeting4, $admin);
$m->recordOutcome($meeting4);
$pref = $pdo->query("SELECT choice_id FROM supervisor_shortlist_choices WHERE shortlist_id='$sid4' AND request_status='pending'")->fetchColumn();
$res = $m->respondToRequest($pref, false, 'Supervision load is full this semester.', $admin);
check('declining approaches the next lecturer with no new meeting', $res['next_contacted'] === true);
check('and does not exhaust the shortlist', $res['exhausted'] === false);
$next = $pdo->query("SELECT choice_id, is_preferred_main FROM supervisor_shortlist_choices WHERE shortlist_id='$sid4' AND request_status='pending'")->fetch();
check('the next one asked is not the preferred main', (int) $next['is_preferred_main'] === 0);
$res = $m->respondToRequest($next['choice_id'], true, null, $admin);
check('the first to accept inherits main', $res['role'] === 'main', "role={$res['role']}");

echo "\n=== 9. Everyone declines -> the student must start again ===\n";
$pdo->prepare("DELETE FROM supervision_assignments WHERE proposal_id = ?")->execute([$proposal]);
$sid5 = $makeShortlist();
$meeting5 = $scheduleAndVote($sid5, ['approve', 'approve', 'approve']);
$m->saveMinutes($meeting5, 'Approved.', true);
$m->approveMinutes($meeting5, $admin);
$m->recordOutcome($meeting5);
$last = null;
while ($c = $pdo->query("SELECT choice_id FROM supervisor_shortlist_choices WHERE shortlist_id='$sid5' AND request_status='pending'")->fetchColumn()) {
    $last = $m->respondToRequest($c, false, 'Unavailable.', $admin);
}
check('the shortlist is marked exhausted', $last['exhausted'] === true);
$st = $pdo->query("SELECT status FROM supervisor_shortlists WHERE shortlist_id='$sid5'")->fetchColumn();
check('status reflects it', $st === 'exhausted', $st);
check('no supervisors were appointed', (int) $pdo->query("SELECT COUNT(*) FROM supervision_assignments WHERE proposal_id='$proposal'")->fetchColumn() === 0);

echo "\n=== 10. A request can only be answered once ===\n";
$sid6 = $makeShortlist();
$meeting6 = $scheduleAndVote($sid6, ['approve', 'approve', 'approve']);
$m->saveMinutes($meeting6, 'Approved.', true);
$m->approveMinutes($meeting6, $admin);
$m->recordOutcome($meeting6);
$c6 = $pdo->query("SELECT choice_id FROM supervisor_shortlist_choices WHERE shortlist_id='$sid6' AND request_status='pending'")->fetchColumn();
$m->respondToRequest($c6, false, 'No.', $admin);
$err = throws(fn() => $m->respondToRequest($c6, true, null, $admin));
check('answering twice is refused', $err !== null, $err ?? '');

echo "\n=== 11. Resubmitting supersedes the old shortlist ===\n";
$sid7 = $makeShortlist();
$active = $m->findActiveForStudent($student['student_id']);
check('the newest shortlist is the active one', $active['shortlist_id'] === $sid7);
check('the previous one is superseded',
    $pdo->query("SELECT status FROM supervisor_shortlists WHERE shortlist_id='$sid6'")->fetchColumn() === 'superseded');

$pdo->rollBack();

printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "database rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
