<?php

/**
 * Manual end-to-end exercise of the whole supervisor assignment loop
 * against the real database and the real templates, wrapped in a
 * transaction that is always rolled back.
 *
 * Student submits -> coordinator convenes -> heads vote -> minutes
 * finalised -> decision applied -> lecturers accept and decline ->
 * supervisors appointed. This is the path a real cohort walks, rather
 * than any single unit of it.
 *
 * Run: php tests/manual_supervision_loop.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Models\DepartmentHead;
use App\Models\ResearchCoordinator;
use App\Models\SupervisorShortlist;

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

function check(string $label, bool $ok, string $detail = ''): void
{
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $label, $detail ? "  ($detail)" : '');
}

$pdo->beginTransaction();

$m  = new SupervisorShortlist($pdo);
$rc = new ResearchCoordinator($pdo);
$dh = new DepartmentHead($pdo);

// ---- fixtures ------------------------------------------------------
$admin   = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();
$student = $pdo->query(
    "SELECT s.student_id, s.user_id FROM students s
     JOIN thesis_proposals tp ON tp.student_id = s.student_id
     JOIN student_thesis_registrations str ON str.student_id = s.student_id LIMIT 1"
)->fetch();
$proposal = $pdo->query("SELECT proposal_id FROM thesis_proposals WHERE student_id = '{$student['student_id']}' LIMIT 1")->fetchColumn();
$program = $pdo->query(
    "SELECT p.program_id, p.department_id FROM programs p
     JOIN thesis_schedules ts ON ts.program_id = p.program_id
     JOIN student_thesis_registrations str ON str.thesis_schedule_id = ts.schedule_id
     WHERE str.student_id = '{$student['student_id']}' LIMIT 1"
)->fetch();

$pdo->prepare("DELETE FROM supervision_assignments WHERE proposal_id = ?")->execute([$proposal]);

$coordUser = $pdo->query("SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id = u.user_id LIMIT 1")->fetchColumn();
$rc->assign($program['program_id'], $coordUser, $admin);

$pos = $pdo->query("SELECT position_id FROM department_positions LIMIT 1")->fetchColumn();
foreach ($pdo->query("SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id = u.user_id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN) as $uid) {
    $dh->assign($program['department_id'], $uid, $pos, $admin);
}
$headIds = array_column($dh->activeForDepartment($program['department_id']), 'dept_head_id');

// Four internal lecturers; the preferred main is ranked third on purpose.
$lects = $pdo->query(
    "SELECT l.lecturer_id FROM lecturers l JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id LIMIT 4"
)->fetchAll(PDO::FETCH_COLUMN);

echo "\n=== Student submits ===\n";
$sid = $m->submit($student['student_id'], $proposal, [
    ['lecturer_id' => $lects[0], 'rank' => 1, 'preferred_main' => false],
    ['lecturer_id' => $lects[1], 'rank' => 2, 'preferred_main' => false],
    ['lecturer_id' => $lects[2], 'rank' => 3, 'preferred_main' => true],
    ['lecturer_id' => $lects[3], 'rank' => 4, 'preferred_main' => false],
]);
check('shortlist is waiting on the coordinator', $m->findActiveForStudent($student['student_id'])['status'] === 'pending_coordinator');
check('no lecturer has been approached yet',
    (int) $pdo->query("SELECT COUNT(*) FROM supervisor_shortlist_choices WHERE shortlist_id='$sid' AND request_status<>'not_sent'")->fetchColumn() === 0);

echo "\n=== Department decides ===\n";
$meeting = $m->scheduleMeeting($sid, '2026-10-06 09:00:00', 'physical', 'Boardroom', null, $headIds, $coordUser);
$m->castVote($meeting, $headIds[0], 'approve');
$m->castVote($meeting, $headIds[1], 'approve');
$m->castVote($meeting, $headIds[2], 'reject', 'Prefer a different methodologist.');
check('two to one carries as approved', $m->tally($meeting)['outcome'] === 'approved');
$m->saveMinutes($meeting, 'Approved 2-1.', true);
$m->approveMinutes($meeting, $coordUser);
check('decision applies', $m->recordOutcome($meeting) === 'approved');

echo "\n=== Lecturer inbox ===\n";
$firstAsked = $pdo->query(
    "SELECT c.choice_id, c.lecturer_id, c.is_preferred_main FROM supervisor_shortlist_choices c
     WHERE c.shortlist_id='$sid' AND c.request_status='pending'"
)->fetch();
check('only the preferred main has been approached', (int) $firstAsked['is_preferred_main'] === 1);

$inbox = $m->pendingForLecturer($firstAsked['lecturer_id']);
check('it shows in that lecturer\'s inbox', count($inbox) === 1);

$html = (string) $twig->render(new \Slim\Psr7\Response(), 'lecturers/supervision.twig', [
    'active_page' => 'l-supervision', 'first_name' => 'L', 'staff_number' => 'S1',
    'students' => [], 'assignment_requests' => [], 'shortlist_requests' => $inbox,
    'request_history' => [], 'documents' => [],
    'csrf_token' => 't', 'error' => null, 'success' => null,
])->getBody();
check('the page tells them they are the preferred main', str_contains($html, 'preferred main supervisor'));
check('and warns that declining passes it on', str_contains($html, 'next supervisor on their approved list'));
check('the empty-state notice is suppressed', !str_contains($html, 'No pending assignment requests'));

echo "\n=== Preferred main declines ===\n";
$res = $m->respondToRequest($firstAsked['choice_id'], false, 'On sabbatical next semester.', $admin);
check('the next name is approached automatically', $res['next_contacted'] === true);
check('with no new meeting scheduled',
    (int) $pdo->query("SELECT COUNT(*) FROM shortlist_meetings WHERE shortlist_id='$sid'")->fetchColumn() === 1);

echo "\n=== Two accept, panel fills ===\n";
$roles = [];
for ($i = 0; $i < 3; $i++) {
    $next = $pdo->query("SELECT choice_id FROM supervisor_shortlist_choices WHERE shortlist_id='$sid' AND request_status='pending'")->fetchColumn();
    if (!$next) {
        break;
    }
    $roles[] = $m->respondToRequest($next, true, null, $admin)['role'];
}
check('the first to accept became main', ($roles[0] ?? null) === 'main', implode(',', $roles));
check('the rest are co-supervisors', array_slice($roles, 1) === array_fill(0, count($roles) - 1, 'co_supervisor'));

$assignments = $pdo->query("SELECT role, COUNT(*) c FROM supervision_assignments WHERE proposal_id='$proposal' GROUP BY role")->fetchAll();
$byRole = [];
foreach ($assignments as $r) {
    $byRole[$r['role']] = (int) $r['c'];
}
check('exactly one main supervisor was appointed', ($byRole['main'] ?? 0) === 1, json_encode($byRole));
check('three supervisors in total', array_sum($byRole) === 3);
check('the declined lecturer was not appointed',
    (int) $pdo->query("SELECT COUNT(*) FROM supervision_assignments WHERE proposal_id='$proposal' AND supervisor_id='{$firstAsked['lecturer_id']}'")->fetchColumn() === 0);

echo "\n=== What the student now sees ===\n";
$choices = $m->choicesFor($sid);
$statuses = array_count_values(array_column($choices, 'request_status'));
check('the student sees one declined and three accepted',
    ($statuses['declined'] ?? 0) === 1 && ($statuses['accepted'] ?? 0) === 3, json_encode($statuses));

$studentHtml = (string) $twig->render(new \Slim\Psr7\Response(), 'students/supervisors.twig', [
    'active_page' => 'supervisors', 'first_name' => 'S', 'student_number' => 'X',
    'proposal' => ['proposal_id' => $proposal, 'status' => 'submitted'],
    'shortlist' => $m->findActiveForStudent($student['student_id']),
    'choices' => $choices, 'supervisors' => [], 'can_resubmit' => false,
    'max_choices' => 5, 'max_supervisors' => 3,
    'csrf_token' => 't', 'error' => null, 'success' => null,
])->getBody();
check('their tracker shows the decline reason', str_contains($studentHtml, 'On sabbatical next semester.'));
check('and shows the accepted supervisors', str_contains($studentHtml, 'Accepted'));

$pdo->rollBack();

printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
