<?php

/**
 * Manual exercise of who writes a shortlist meeting's minutes,
 * against the real database, wrapped in a transaction that is always
 * rolled back.
 *
 * The rule: the secretary writes them; with no secretary, the lead
 * does. The coordinator may take either role, but coordinating the
 * program is not by itself permission to write.
 *
 * Run: php tests/manual_meeting_roles.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Models\DepartmentHead;
use App\Models\SupervisorShortlist;

$env = parse_ini_file(__DIR__ . '/../.env');
$pdo = new PDO(
    "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4",
    $env['DB_USER'],
    $env['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$pass = 0;
$fail = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? "  ($detail)" : '');
}

$pdo->beginTransaction();

$m  = new SupervisorShortlist($pdo);
$dh = new DepartmentHead($pdo);

$admin   = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();
$student = $pdo->query(
    "SELECT s.student_id, s.user_id FROM students s
     JOIN thesis_proposals tp ON tp.student_id = s.student_id
     JOIN student_thesis_registrations str ON str.student_id = s.student_id LIMIT 1"
)->fetch();
$proposal = $pdo->query("SELECT proposal_id FROM thesis_proposals WHERE student_id = '{$student['student_id']}' LIMIT 1")->fetchColumn();
$dept = $pdo->query("SELECT department_id FROM departments LIMIT 1")->fetchColumn();
$pos  = $pdo->query("SELECT position_id FROM department_positions LIMIT 1")->fetchColumn();

$users = $pdo->query("SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id = u.user_id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
[$coordinator, $headA, $headB] = $users;
foreach ([$headA, $headB] as $uid) {
    $dh->assign($dept, $uid, $pos, $admin);
}
$headIds = array_column($dh->activeForDepartment($dept), 'dept_head_id');
$lects = $pdo->query("SELECT lecturer_id FROM lecturers
                      WHERE user_id <> '{$student['user_id']}' LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);

$newMeeting = function (?string $lead, ?string $secretary) use ($m, $student, $proposal, $lects, $headIds, $coordinator): string {
    $sid = $m->submit($student['student_id'], $proposal, [
        ['lecturer_id' => $lects[0], 'rank' => 1, 'preferred_main' => true],
        ['lecturer_id' => $lects[1], 'rank' => 2, 'preferred_main' => false],
    ]);

    return $m->scheduleMeeting($sid, '2026-10-07 10:00:00', 'physical', 'Boardroom', null, $headIds, $coordinator, $lead, $secretary);
};

echo "\n=== Nobody named ===\n";
$meeting = $newMeeting(null, null);
check('the scheduler leads by default', $m->minutesAuthorId($meeting) === $coordinator);
check('so the coordinator writes the minutes', $m->minutesAuthorId($meeting) === $coordinator);

echo "\n=== A head leads, no secretary ===\n";
$meeting = $newMeeting($headA, null);
check('the named lead writes them', $m->minutesAuthorId($meeting) === $headA);
check('and it is no longer the coordinator', $m->minutesAuthorId($meeting) !== $coordinator);

echo "\n=== A secretary is named ===\n";
$meeting = $newMeeting($headA, $headB);
check('the secretary writes them, not the lead', $m->minutesAuthorId($meeting) === $headB, 'secretary wins');
check('the lead does not', $m->minutesAuthorId($meeting) !== $headA);
check('nor does the coordinator', $m->minutesAuthorId($meeting) !== $coordinator);

echo "\n=== The coordinator takes the secretary role themselves ===\n";
$meeting = $newMeeting($headA, $coordinator);
check('the coordinator writes them', $m->minutesAuthorId($meeting) === $coordinator);
check('while a head still leads the meeting',
    $pdo->query("SELECT lead_user_id FROM shortlist_meetings WHERE meeting_id = '$meeting'")->fetchColumn() === $headA);

echo "\n=== Minutes still gate the outcome regardless of who wrote them ===\n";
$meeting = $newMeeting($headA, $headB);
$m->castVote($meeting, $headIds[0], 'approve');
$m->castVote($meeting, $headIds[1], 'approve');
$blocked = false;
try {
    $m->recordOutcome($meeting);
} catch (\Throwable $e) {
    $blocked = true;
}
check('unfinalised minutes still block the decision', $blocked);
$m->saveMinutes($meeting, 'Approved by the panel.', true);
$m->approveMinutes($meeting, $admin);
check('and finalising unlocks it', $m->recordOutcome($meeting) === 'approved');

echo "\n=== A missing meeting ===\n";
check('an unknown meeting has no author', $m->minutesAuthorId('00000000-0000-0000-0000-000000000000') === null);

$pdo->rollBack();

printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
