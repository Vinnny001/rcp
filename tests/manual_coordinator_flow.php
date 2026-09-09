<?php

/**
 * Manual end-to-end exercise of the coordinator and department-head
 * side of supervisor assignment, against the real database and the
 * real templates, wrapped in a transaction that is always rolled back.
 *
 * Checks the scoping in particular: a coordinator only sees their own
 * programs' shortlists, and a lecturer who holds no headship sees no
 * meetings at all.
 *
 * Run: php tests/manual_coordinator_flow.php
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Models\SupervisorShortlist;
use App\Models\ResearchCoordinator;
use App\Models\DepartmentHead;

$env = parse_ini_file(__DIR__ . '/../.env');
$pdo = new PDO("mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4", $env['DB_USER'], $env['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$twig = \Slim\Views\Twig::create(__DIR__ . '/../src/Views', ['cache' => false]);
foreach (['show_chat' => false, 'unread_notifications_count' => 0, 'unread_chats_count' => 0,
          'coordinator_programs' => [], 'is_department_head' => true] as $k => $v) {
    $twig->getEnvironment()->addGlobal($k, $v);
}

$pass = 0; $fail = 0;
function check(string $l, bool $ok, string $d = ''): void {
    global $pass, $fail;
    $ok ? $pass++ : $fail++;
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $l, $d ? "  ($d)" : '');
}

$pdo->beginTransaction();

$m  = new SupervisorShortlist($pdo);
$rc = new ResearchCoordinator($pdo);
$dh = new DepartmentHead($pdo);

// ---- fixtures: a coordinator, three heads, a student with a proposal ----
$admin   = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();
$student = $pdo->query("SELECT s.student_id, s.user_id FROM students s
                        JOIN thesis_proposals tp ON tp.student_id = s.student_id
                        JOIN student_thesis_registrations str ON str.student_id = s.student_id LIMIT 1")->fetch();
$proposal = $pdo->query("SELECT proposal_id FROM thesis_proposals WHERE student_id = '{$student['student_id']}' LIMIT 1")->fetchColumn();
$program = $pdo->query("SELECT p.program_id, p.department_id FROM programs p
                        JOIN thesis_schedules ts ON ts.program_id = p.program_id
                        JOIN student_thesis_registrations str ON str.thesis_schedule_id = ts.schedule_id
                        WHERE str.student_id = '{$student['student_id']}' LIMIT 1")->fetch();

$coordUser = $pdo->query("SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id = u.user_id LIMIT 1")->fetchColumn();
$rc->assign($program['program_id'], $coordUser, $admin);

$pos = $pdo->query("SELECT position_id FROM department_positions LIMIT 1")->fetchColumn();
$headUsers = $pdo->query("SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id = u.user_id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
foreach ($headUsers as $uid) { $dh->assign($program['department_id'], $uid, $pos, $admin); }
$headIds = array_column($dh->activeForDepartment($program['department_id']), 'dept_head_id');

$lects = $pdo->query("SELECT l.lecturer_id FROM lecturers l JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
$sid = $m->submit($student['student_id'], $proposal, [
    ['lecturer_id' => $lects[0], 'rank' => 1, 'preferred_main' => false],
    ['lecturer_id' => $lects[1], 'rank' => 2, 'preferred_main' => true],
    ['lecturer_id' => $lects[2], 'rank' => 3, 'preferred_main' => false],
]);

echo "\n=== Coordinator queue ===\n";
$queue = $m->queueForPrograms([$program['program_id']]);
check('the new shortlist appears in the coordinator queue', count($queue) === 1, count($queue) . ' item(s)');
check('and carries the student and program', ($queue[0]['student_number'] ?? '') !== '' && ($queue[0]['program_name'] ?? '') !== '');

$other = $pdo->query("SELECT program_id FROM programs WHERE program_id <> '{$program['program_id']}' LIMIT 1")->fetchColumn();
check('a coordinator of another program sees nothing', count($m->queueForPrograms([$other])) === 0);

echo "\n=== Coordinator shortlist view renders ===\n";
$ctx = $m->findWithContext($sid);
check('context resolves program and department', !empty($ctx['program_name']) && !empty($ctx['department_name']),
    ($ctx['program_name'] ?? '?') . ' / ' . ($ctx['department_name'] ?? '?'));

$render = function (string $tpl, array $vars) use ($twig): string {
    return (string) $twig->render(new \Slim\Psr7\Response(), $tpl, $vars)->getBody();
};
$coordVars = fn(array $c) => [
    'active_page' => 'l-coordinator', 'first_name' => 'Co', 'last_name' => 'Ord',
    'shortlist' => $c, 'choices' => $m->choicesFor($sid),
    'voters' => $c['meeting_id'] ? $m->meetingVoters($c['meeting_id']) : [],
    'tally' => $c['meeting_id'] ? $m->tally($c['meeting_id']) : null,
    'heads' => $dh->activeForDepartment($c['department_id']),
    // The coordinator scheduled the meeting, so they lead it and hold
    // the pen — the view branches on that.
    'session_user_id' => $coordUser,
    'csrf_token' => 't', 'error' => null, 'success' => null,
];
$h = $render('coordinators/shortlist.twig', $coordVars($ctx));
check('pre-meeting view offers scheduling', str_contains($h, 'Schedule meeting') && str_contains($h, 'dept_head_ids[]'));
check('and lists the invitable heads', substr_count($h, 'dept_head_ids[]') === 3, substr_count($h, 'dept_head_ids[]') . ' heads');

echo "\n=== Schedule, then vote ===\n";
$meeting = $m->scheduleMeeting($sid, '2026-10-05 09:00:00', 'physical', 'Boardroom', null, $headIds, $coordUser);
$m->castVote($meeting, $headIds[0], 'approve');
$m->castVote($meeting, $headIds[1], 'approve', 'Good fit.');

$ctx = $m->findWithContext($sid);
$h = $render('coordinators/shortlist.twig', $coordVars($ctx));
check('votes show on the coordinator view', str_contains($h, '2 approve'), '2 approve · 1 reject expected 0');
check('and it blocks applying until minutes are final', str_contains($h, 'Finalise the minutes to move this forward'));
check('with no apply button offered yet', !str_contains($h, 'Apply the decision'));

echo "\n=== Department head view ===\n";
$headUserId = $pdo->query("SELECT user_id FROM department_heads WHERE dept_head_id = '{$headIds[0]}'")->fetchColumn();
$meetings = $m->meetingsForHead($headUserId);
foreach ($meetings as &$mm) { $mm['choices'] = $m->choicesFor($mm['shortlist_id']); }
unset($mm);
check('the head sees the meeting they were invited to', count($meetings) === 1);
$h = $render('lecturers/shortlist_meetings.twig', [
    'active_page' => 'l-panel', 'first_name' => 'H', 'last_name' => 'Ead',
    'meetings' => $meetings, 'csrf_token' => 't', 'error' => null, 'success' => null,
]);
check('their existing vote is shown back to them', str_contains($h, 'You voted'));
check('and they can still change it', str_contains($h, 'value="approve"'));

$stranger = $pdo->query("SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id = u.user_id
                         WHERE u.user_id NOT IN (SELECT user_id FROM department_heads) LIMIT 1")->fetchColumn();
check('a lecturer who is not a head sees nothing', $stranger === false || count($m->meetingsForHead($stranger)) === 0);

echo "\n=== Applying the outcome ===\n";
$m->saveMinutes($meeting, 'Panel approved the shortlist as submitted.', true);

// Finalised but not yet approved: the view should ask for approval,
// not offer to apply the decision.
$h = $render('coordinators/shortlist.twig', $coordVars($m->findWithContext($sid)));
check('once finalised, the view asks for approval', str_contains($h, 'Approve the minutes'));
check('and still withholds the apply button', !str_contains($h, 'Apply the decision'));

$m->approveMinutes($meeting, $coordUser);
$h = $render('coordinators/shortlist.twig', $coordVars($m->findWithContext($sid)));
check('after approval the apply button appears', str_contains($h, 'Apply the decision'));

$outcome = $m->recordOutcome($meeting);
check('outcome is approved', $outcome === 'approved');
$pending = $pdo->query("SELECT c.choice_id, c.is_preferred_main FROM supervisor_shortlist_choices c
                        WHERE c.shortlist_id = '$sid' AND c.request_status = 'pending'")->fetchAll();
check('exactly the preferred main was approached', count($pending) === 1 && (int) $pending[0]['is_preferred_main'] === 1);

$ctx = $m->findWithContext($sid);
$h = $render('coordinators/shortlist.twig', $coordVars($ctx));
check('the coordinator view now reports it as applied', str_contains($h, 'Decision already applied'));
check('and the minutes are locked', str_contains($h, 'readonly'));

$pdo->rollBack();
printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
