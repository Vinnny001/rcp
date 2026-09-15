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

function throws(callable $fn): ?string {
    try { $fn(); return null; } catch (\Throwable $e) { return $e->getMessage(); }
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

// Excludes this student's own lecturer account: staff studying for
// their own degree hold both records, and a shortlist may not name
// the student on it.
$lects = $pdo->query("SELECT l.lecturer_id FROM lecturers l
                      JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
                      WHERE l.user_id <> '{$student['user_id']}' LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
$sid = $m->submit($student['student_id'], $proposal, [
    ['lecturer_id' => $lects[0], 'rank' => 1, 'preferred_main' => false],
    ['lecturer_id' => $lects[1], 'rank' => 2, 'preferred_main' => true],
    ['lecturer_id' => $lects[2], 'rank' => 3, 'preferred_main' => false],
]);

echo "\n=== Coordinator queue ===\n";
$queue = $m->queueForPrograms([$program['program_id']]);
check('the new shortlist appears in the coordinator queue', count($queue) === 1, count($queue) . ' item(s)');
check('and carries the student and program', ($queue[0]['student_number'] ?? '') !== '' && ($queue[0]['program_name'] ?? '') !== '');

// Scoped to the shortlist this test made, not to the queue being
// empty: the database is shared, and real shortlists live here too.
$other = $pdo->query("SELECT program_id FROM programs WHERE program_id <> '{$program['program_id']}' LIMIT 1")->fetchColumn();
$otherQueue = array_column($m->queueForPrograms([$other]), 'shortlist_id');
check('a coordinator of another program does not see this one',
    !in_array($sid, $otherQueue, true), count($otherQueue) . ' item(s) in that queue');

echo "\n=== Coordinator shortlist view renders ===\n";
$ctx = $m->findWithContext($sid);
check('context resolves program and department', !empty($ctx['program_name']) && !empty($ctx['department_name']),
    ($ctx['program_name'] ?? '?') . ' / ' . ($ctx['department_name'] ?? '?'));

$render = function (string $tpl, array $vars) use ($twig): string {
    return (string) $twig->render(new \Slim\Psr7\Response(), $tpl, $vars)->getBody();
};
$lecturerModel = new \App\Models\Lecturer($pdo);
$coordVars = fn(array $c) => [
    'active_page' => 'l-coordinator', 'first_name' => 'Co', 'last_name' => 'Ord',
    'shortlist' => $c,
    'choices' => array_map(
        fn ($choice) => $choice + ['blocker' => $lecturerModel->newStudentBlocker($choice['lecturer_id'])],
        $m->choicesFor($c['shortlist_id'])
    ),
    'files' => $m->filesFor($c['shortlist_id']),
    'decision' => $m->decisionState($c), 'previous' => null,
    'supervisors' => [], 'picked' => [], 'picked_main' => '',
    'max_choices' => SupervisorShortlist::MAX_CHOICES, 'response_days' => SupervisorShortlist::RESPONSE_DAYS,
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

echo "\n=== The coordinator votes too ===\n";
// A coordinator of this program who is not one of the invited heads.
$quotedHeads = implode(',', array_map([$pdo, 'quote'], $headUsers));
$plainCoordinator = $pdo->query(
    "SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id = u.user_id
     WHERE u.user_id NOT IN ($quotedHeads) AND u.user_id <> " . $pdo->quote($student['user_id']) . " LIMIT 1"
)->fetchColumn();
$rc->assign($program['program_id'], $plainCoordinator, $admin);

$voterRow = fn (string $userId): array => array_values(array_filter(
    $m->meetingVoters($meeting), fn ($v) => $v['voter_user_id'] === $userId
));
$before = $m->tally($meeting);

check('the program coordinator is listed among the voters', count($voterRow($plainCoordinator)) === 1);
check('as research coordinator', ($voterRow($plainCoordinator)[0]['capacity'] ?? '') === 'coordinator');
check('and counted among those with a vote', $before['invited'] === count($m->meetingVoters($meeting)), $before['invited'] . ' voters');

check('a coordinator who is not a head can vote', $m->castVoteAs($meeting, $plainCoordinator, 'reject', 'Not convinced.') === 'coordinator');
check('and the vote counts', $m->tally($meeting)['reject'] === $before['reject'] + 1);
check('with their comment', ($voterRow($plainCoordinator)[0]['comment'] ?? '') === 'Not convinced.');

$m->castVoteAs($meeting, $plainCoordinator, 'approve');
$after = $m->tally($meeting);
check('changing it replaces the vote rather than adding one',
    $after['reject'] === $before['reject'] && $after['approve'] === $before['approve'] + 1);

// The fixture coordinator is also one of the invited heads.
check('a coordinator who is also an invited head votes as the head', $m->castVoteAs($meeting, $coordUser, 'approve') === 'head');
check('so they appear only once among the voters', count($voterRow($coordUser)) === 1);
check('and hold a single vote',
    (int) $pdo->query("SELECT COUNT(*) FROM shortlist_meeting_votes WHERE meeting_id = " . $pdo->quote($meeting)
        . " AND voter_user_id = " . $pdo->quote($coordUser))->fetchColumn() === 1);

$outsider = $pdo->query(
    "SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id = u.user_id
     WHERE u.user_id NOT IN ($quotedHeads) AND u.user_id <> " . $pdo->quote($plainCoordinator) . "
       AND u.user_id NOT IN (SELECT user_id FROM research_coordinators) LIMIT 1"
)->fetchColumn();
check('a lecturer who is neither a head nor the coordinator cannot vote',
    $outsider === false || throws(fn () => $m->castVoteAs($meeting, $outsider, 'approve')) !== null);

$asPlain = $coordVars($m->findWithContext($sid));
$asPlain['session_user_id'] = $plainCoordinator;
$h = $render('coordinators/shortlist.twig', $asPlain);
check('the coordinator page offers them their vote', str_contains($h, 'action="/coordinator/shortlists/vote"'));
check('and says in what capacity', str_contains($h, 'as research coordinator'));

$plainName = $pdo->query("SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE user_id = " . $pdo->quote($plainCoordinator))->fetchColumn();
preg_match('/<tbody>.*?<\/tbody>/s', substr($h, strpos($h, '>Votes<')), $votesTable);
$votesTable = $votesTable[0] ?? '';
check('their own row in the votes table says You', (bool) preg_match('/<td class="strong">You<\/td>\s*<td class="meta">Research coordinator<\/td>/', $votesTable));
check('rather than their name', !str_contains($votesTable, '<td class="strong">' . $plainName . '</td>'));
check('while everyone else still shows by name', substr_count($votesTable, '<td class="strong">You</td>') === 1);

echo "\n=== Your own name reads as You ===\n";
$coordName = $pdo->query("SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE user_id = " . $pdo->quote($coordUser))->fetchColumn();
$h = $render('coordinators/shortlist.twig', $coordVars($m->findWithContext($sid)));
check('the coordinator who leads the meeting reads "Led by you"', str_contains($h, 'Led by you.'));
check('and not their own name', !str_contains($h, 'Led by ' . $coordName));
$h = $render('coordinators/shortlist.twig', $asPlain);
check('another coordinator still sees who leads it', str_contains($h, 'Led by ' . $coordName . '.'));

// Someone on the student's list, looking at the request.
$listedUser = $pdo->query("SELECT user_id FROM lecturers WHERE lecturer_id = " . $pdo->quote($lects[1]))->fetchColumn();
$listedName = $pdo->query("SELECT CONCAT(first_name, ' ', last_name) FROM users WHERE user_id = " . $pdo->quote($listedUser))->fetchColumn();
$asListed = $coordVars($m->findWithContext($sid));
$asListed['session_user_id'] = $listedUser;
$h = $render('coordinators/shortlist.twig', $asListed);
preg_match('/<tbody>.*?<\/tbody>/s', substr($h, strpos($h, "in the student's order")), $listTable);
check('a coordinator on the supervisor list sees themselves as You',
    str_contains($listTable[0] ?? '', 'You') && !str_contains($listTable[0] ?? '', $listedName));

echo "\n=== Department head view ===\n";
$headUserId = $pdo->query("SELECT user_id FROM department_heads WHERE dept_head_id = '{$headIds[0]}'")->fetchColumn();
$meetings = $m->meetingsForHead($headUserId);
foreach ($meetings as &$mm) { $mm['choices'] = $m->choicesFor($mm['shortlist_id']); }
unset($mm);
check('the head sees the meeting they were invited to',
    in_array($meeting, array_column($meetings, 'meeting_id'), true),
    count($meetings) . ' meeting(s) visible to them');
$h = $render('lecturers/shortlist_meetings.twig', [
    'active_page' => 'l-panel', 'first_name' => 'H', 'last_name' => 'Ead',
    'meetings' => $meetings, 'csrf_token' => 't', 'error' => null, 'success' => null,
]);
check('their existing vote is shown back to them', str_contains($h, 'You voted'));
check('and they can still change it', str_contains($h, 'value="approve"'));
check('and are told they can', str_contains($h, 'You can change your vote'));

$h = $render('lecturers/shortlist_meetings.twig', [
    'active_page' => 'l-panel', 'first_name' => 'H', 'last_name' => 'Ead',
    'meetings' => $meetings, 'session_user_id' => $listedUser, 'csrf_token' => 't', 'error' => null, 'success' => null,
]);
check('a head on the proposed list sees themselves as You, preferred main',
    (bool) preg_match('/<li>You <span class="meta">— preferred main<\/span><\/li>/', $h));
check('rather than their own name', !str_contains($h, '<li>' . $listedName));

$stranger = $pdo->query("SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id = u.user_id
                         WHERE u.user_id NOT IN (SELECT user_id FROM department_heads) LIMIT 1")->fetchColumn();
check('a lecturer who is not a head sees nothing', $stranger === false || count($m->meetingsForHead($stranger)) === 0);

echo "\n=== Applying the outcome ===\n";
$m->saveMinutes($meeting, 'Panel approved the shortlist as submitted.', 'Approved as submitted.');
$m->finalizeMinutes($meeting);

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
$asked = (int) $pdo->query("SELECT COUNT(*) FROM supervisor_shortlist_choices
                             WHERE shortlist_id = '$sid' AND request_status <> 'not_sent'")->fetchColumn();
check('approval on its own contacts nobody', $asked === 0, $asked . ' asked');

$ctx = $m->findWithContext($sid);
$h = $render('coordinators/shortlist.twig', $coordVars($ctx));
check('the coordinator view now reports it as applied', str_contains($h, 'Department decision applied'));
check('and the minutes can no longer be edited', !str_contains($h, 'action="/coordinator/shortlists/minutes"'));
check('the summary leads, with the full minutes beneath it',
    str_contains($h, 'Approved as submitted.') && str_contains($h, 'Full minutes') && str_contains($h, 'Panel approved the shortlist as submitted.'));
check('and offers to send it to every supervisor at once', str_contains($h, '/coordinator/shortlists/send'));
check('voting closes once the decision is applied — for the coordinator',
    str_contains((string) throws(fn () => $m->castVoteAs($meeting, $plainCoordinator, 'reject')), 'closed'));
check('and for the heads', str_contains((string) throws(fn () => $m->castVote($meeting, $headIds[0], 'reject')), 'closed'));
check('so the vote form is gone', !str_contains($h, 'action="/coordinator/shortlists/vote"'));

$meetings = $m->meetingsForHead($headUserId);
foreach ($meetings as &$mm) { $mm['choices'] = $m->choicesFor($mm['shortlist_id']); }
unset($mm);
$h = $render('lecturers/shortlist_meetings.twig', [
    'active_page' => 'l-panel', 'first_name' => 'H', 'last_name' => 'Ead',
    'meetings' => array_values(array_filter($meetings, fn ($mm) => $mm['meeting_id'] === $meeting)),
    'session_user_id' => $headUserId, 'csrf_token' => 't', 'error' => null, 'success' => null,
]);
check('a head is no longer told they can change their vote', !str_contains($h, 'You can change your vote'));
check('and reads the decision in words', str_contains($h, 'Decided — the department approved this request.'));

echo "\n=== Sending ===\n";
$sent = $m->sendRequests($sid, $coordUser);
$pending = (int) $pdo->query("SELECT COUNT(*) FROM supervisor_shortlist_choices WHERE shortlist_id = '$sid' AND request_status = 'pending'")->fetchColumn();
check('every supervisor with room is asked together', $pending === $sent['sent'] && $sent['sent'] + $sent['unavailable'] === 3,
    $sent['sent'] . ' asked, ' . $sent['unavailable'] . ' full');
$h = $render('coordinators/shortlist.twig', $coordVars($m->findWithContext($sid)));
check('the page shows when answers are due', str_contains($h, 'Answers due'));
check('and no longer offers to send', !str_contains($h, '/coordinator/shortlists/send'));
check('the queue shows it as with the supervisors',
    in_array('requests_sent', array_column($m->queueForPrograms([$program['program_id']]), 'status'), true));

$pdo->rollBack();
printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
