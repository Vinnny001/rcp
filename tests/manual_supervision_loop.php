<?php

/**
 * Manual end-to-end exercise of the whole supervisor assignment loop
 * against the real database and the real templates, wrapped in a
 * transaction that is always rolled back.
 *
 * Student sends -> coordinator convenes -> heads vote -> minutes
 * finalised and approved -> decision applied -> coordinator sends to
 * every supervisor at once -> they answer -> supervisors appointed by
 * the student's order. This is the path a real cohort walks, rather
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
     JOIN thesis_proposals tp ON tp.student_id = s.student_id AND tp.status NOT IN ('draft', 'rejected')
     JOIN student_thesis_registrations str ON str.student_id = s.student_id
     WHERE NOT EXISTS (SELECT 1 FROM supervisor_shortlists sl WHERE sl.student_id = s.student_id)
     LIMIT 1"
)->fetch();
$proposal = $pdo->query("SELECT proposal_id FROM thesis_proposals WHERE student_id = '{$student['student_id']}' AND status NOT IN ('draft', 'rejected') LIMIT 1")->fetchColumn();
$program = $pdo->query(
    "SELECT p.program_id, p.department_id FROM programs p
     JOIN thesis_schedules ts ON ts.program_id = p.program_id
     JOIN student_thesis_registrations str ON str.thesis_schedule_id = ts.schedule_id
     WHERE str.student_id = '{$student['student_id']}' LIMIT 1"
)->fetch();

// All three places open, so the outcome is decided by this request alone.
$pdo->prepare("DELETE FROM supervision_assignments WHERE student_id = ?")->execute([$student['student_id']]);

$coordUser = $pdo->query("SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id = u.user_id LIMIT 1")->fetchColumn();
$rc->assign($program['program_id'], $coordUser, $admin);

$pos = $pdo->query("SELECT position_id FROM department_positions LIMIT 1")->fetchColumn();
foreach ($pdo->query("SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id = u.user_id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN) as $uid) {
    $dh->assign($program['department_id'], $uid, $pos, $admin);
}
$headIds = array_column($dh->activeForDepartment($program['department_id']), 'dept_head_id');

// Four internal lecturers; the preferred main is ranked third on purpose.
$lects = $pdo->query(
    "SELECT l.lecturer_id FROM lecturers l
     JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
     WHERE l.user_id <> '{$student['user_id']}' LIMIT 4"
)->fetchAll(PDO::FETCH_COLUMN);
foreach ($lects as $id) {
    $pdo->prepare(
        "UPDATE lecturers SET max_supervision_load =
            (SELECT COUNT(*) FROM supervision_assignments sa WHERE sa.supervisor_id = lecturers.lecturer_id AND sa.is_active = 1) + 3
         WHERE lecturer_id = ?"
    )->execute([$id]);
}

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
$m->saveMinutes($meeting, 'Approved 2-1.', 'Approved, two votes to one.');
$m->finalizeMinutes($meeting);
$m->approveMinutes($meeting, $coordUser);
check('decision applies', $m->recordOutcome($meeting) === 'approved');

echo "\n=== Sent to everyone at once ===\n";
check('approval on its own asks nobody',
    (int) $pdo->query("SELECT COUNT(*) FROM supervisor_shortlist_choices WHERE shortlist_id='$sid' AND request_status<>'not_sent'")->fetchColumn() === 0);
$sent = $m->sendRequests($sid, $coordUser);
check('the coordinator sends it to all four together', $sent['sent'] === 4);

$byRank = $pdo->query(
    "SELECT rank_position, choice_id, lecturer_id, is_preferred_main FROM supervisor_shortlist_choices
     WHERE shortlist_id='$sid' ORDER BY rank_position"
)->fetchAll();
check('the preferred main picked third sits at number 1', (int) $byRank[0]['is_preferred_main'] === 1);

foreach ($byRank as $c) {
    $inbox[$c['rank_position']] = $m->pendingForLecturer($c['lecturer_id']);
}
check('every lecturer on the list has it in their inbox',
    count(array_filter($inbox, fn ($i) => count(array_filter($i, fn ($r) => $r['shortlist_id'] === $sid)) === 1)) === 4);

echo "\n=== Lecturer inbox ===\n";
$mine = array_values(array_filter($inbox[1], fn ($r) => $r['shortlist_id'] === $sid));
$html = (string) $twig->render(new \Slim\Psr7\Response(), 'lecturers/supervision.twig', [
    'active_page' => 'l-supervision', 'first_name' => 'L', 'staff_number' => 'S1',
    'students' => [], 'assignment_requests' => [], 'shortlist_requests' => $mine,
    'shortlist_answers' => [], 'new_student_blocker' => null,
    'request_history' => [], 'documents' => [],
    'csrf_token' => 't', 'error' => null, 'success' => null,
])->getBody();
check('the page tells them they are the preferred main', str_contains($html, 'preferred main supervisor'));
check('and when they must answer by', str_contains($html, 'Answer by'));
check('and that everyone was asked at once', str_contains($html, 'asked at the same time'));
check('the empty-state notice is suppressed', !str_contains($html, 'No pending assignment requests'));

$blocked = fn (?string $blocker): string => (string) $twig->render(new \Slim\Psr7\Response(), 'lecturers/supervision.twig', [
    'active_page' => 'l-supervision', 'first_name' => 'L', 'staff_number' => 'S1',
    'students' => [], 'assignment_requests' => [], 'shortlist_requests' => $mine,
    'shortlist_answers' => [], 'new_student_blocker' => $blocker,
    'request_history' => [], 'documents' => [],
    'csrf_token' => 't', 'error' => null, 'success' => null,
])->getBody();
$full = $blocked('full');
$off = $blocked('unavailable');
check('a lecturer at full load sees why they cannot accept', str_contains($full, 'Your supervision load is full'));
check('a lecturer not taking students is told that instead', str_contains($off, 'availability is switched off') && !str_contains($off, 'load is full'));
check('and either way Accept is disabled',
    (bool) preg_match('/<button[^>]*disabled[^>]*>Accept</', $full) && (bool) preg_match('/<button[^>]*disabled[^>]*>Accept</', $off));
check('while an available lecturer can press it', !preg_match('/<button[^>]*disabled[^>]*>Accept</', $html));

echo "\n=== They answer, in any order ===\n";
$m->respondToRequest($byRank[3]['choice_id'], true, null);
$m->respondToRequest($byRank[0]['choice_id'], false, 'On sabbatical next semester.');
$m->respondToRequest($byRank[2]['choice_id'], true, null);
check('with number 2 still silent nothing is decided',
    $pdo->query("SELECT status FROM supervisor_shortlists WHERE shortlist_id='$sid'")->fetchColumn() === 'requests_sent');
$last = $m->respondToRequest($byRank[1]['choice_id'], true, null);
check('number 2 answering last settles it', $last['decided'] === 'successful');
check('and they are main — the highest in the student\'s order who accepted', $last['appointed_as'] === 'main');
check('with no new meeting scheduled',
    (int) $pdo->query("SELECT COUNT(*) FROM shortlist_meetings WHERE shortlist_id='$sid'")->fetchColumn() === 1);

$assignments = $pdo->query("SELECT role, COUNT(*) c FROM supervision_assignments WHERE proposal_id='$proposal' GROUP BY role")->fetchAll();
$byRole = [];
foreach ($assignments as $r) {
    $byRole[$r['role']] = (int) $r['c'];
}
check('exactly one main supervisor was appointed', ($byRole['main'] ?? 0) === 1, json_encode($byRole));
check('three supervisors in total', array_sum($byRole) === 3);
check('the declined lecturer was not appointed',
    (int) $pdo->query("SELECT COUNT(*) FROM supervision_assignments WHERE proposal_id='$proposal' AND supervisor_id='{$byRank[0]['lecturer_id']}'")->fetchColumn() === 0);

echo "\n=== What the student now sees ===\n";
$proposalRow = $pdo->query("SELECT * FROM thesis_proposals WHERE proposal_id = '$proposal'")->fetch();
$state = $m->studentState($student['student_id'], $proposalRow);
$latest = $state['latest'];
$roles = $pdo->query("SELECT supervisor_id, role FROM supervision_assignments WHERE proposal_id = '$proposal' AND is_active = 1")->fetchAll(PDO::FETCH_KEY_PAIR);

$studentHtml = (string) $twig->render(new \Slim\Psr7\Response(), 'students/proposal.twig', [
    'active_page' => 'proposal', 'first_name' => 'S', 'student_number' => 'X',
    'profile_complete' => true, 'has_thesis_registration' => true,
    'proposal' => $proposalRow, 'synopsis_doc' => null, 'proposal_doc' => null,
    'state' => $state, 'latest' => $latest, 'latest_choices' => $m->choicesFor($latest['shortlist_id']),
    'appointed_roles' => $roles, 'history' => [], 'supervisors' => [], 'picked' => [], 'picked_main' => '',
    'max_choices' => 5, 'max_supervisors' => 3, 'response_days' => 14,
    'csrf_token' => 't', 'error' => null, 'success' => null, 'old' => [],
])->getBody();
check('their page reports supervisors appointed', str_contains($studentHtml, 'Supervisors appointed'));
check('it shows the decline reason', str_contains($studentHtml, 'On sabbatical next semester.'));
check('and who is main', str_contains($studentHtml, 'main supervisor'));
check('and nothing is left to edit', !str_contains($studentHtml, 'id="proposalForm"'));

$pdo->rollBack();

printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
