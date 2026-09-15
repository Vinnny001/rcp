<?php

/**
 * The supervisor request, sent to every shortlisted lecturer at once.
 *
 * Walks the whole flow against the real database inside one rolled-back
 * transaction, with savepoints so each scenario starts from the same
 * state:
 *
 *   - a proposal and its list are written together, then locked for good
 *   - the department approves the request without touching the proposal
 *   - the coordinator sends to everyone together; a full lecturer is
 *     passed over rather than asked
 *   - the student's order decides who is appointed, not reply speed, and
 *     the outcome is decided as soon as it can no longer change
 *   - a lecturer who is not taking students is treated like a full one:
 *     passed over at send, refused at accept, dropped at the decision
 *   - only internal lecturers can be on a list at all, and one made
 *     external afterwards is treated the same way
 *   - after a failure the coordinator either hands it back to the
 *     student or revises the list, and every attempt stays on record,
 *     files included
 *
 * Run: php tests/manual_request_rounds.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Models\DepartmentHead;
use App\Models\Document;
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

$m = new SupervisorShortlist($pdo);

// ---- fixtures ----
// A student with a proposal, no request on record and no supervisors,
// so every one of their three places is open.
$student = $pdo->query(
    "SELECT s.student_id, s.user_id, tp.proposal_id, p.department_id
     FROM students s
     JOIN thesis_proposals tp ON tp.student_id = s.student_id AND tp.status <> 'rejected'
     JOIN student_thesis_registrations str ON str.student_id = s.student_id
     JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
     JOIN programs p ON p.program_id = ts.program_id
     WHERE NOT EXISTS (SELECT 1 FROM supervisor_shortlists sl WHERE sl.student_id = s.student_id)
       AND NOT EXISTS (SELECT 1 FROM supervision_assignments sa WHERE sa.student_id = s.student_id AND sa.is_active = 1)
     LIMIT 1"
)->fetch();

$lects = $pdo->query(
    "SELECT l.lecturer_id FROM lecturers l
     JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
     WHERE l.user_id <> " . $pdo->quote($student['user_id']) . "
     ORDER BY l.lecturer_id LIMIT 5"
)->fetchAll(PDO::FETCH_COLUMN);

// Plenty of room for everyone, so capacity only matters where a scenario
// takes it away on purpose.
$roomy = $pdo->prepare(
    "UPDATE lecturers SET is_available = 1, max_supervision_load =
        (SELECT COUNT(*) FROM supervision_assignments sa WHERE sa.supervisor_id = lecturers.lecturer_id AND sa.is_active = 1) + 5
     WHERE lecturer_id = ?"
);
$switchOff = $pdo->prepare("UPDATE lecturers SET is_available = 0 WHERE lecturer_id = ?");
$switchOn = $pdo->prepare("UPDATE lecturers SET is_available = 1 WHERE lecturer_id = ?");
$fill = $pdo->prepare(
    "UPDATE lecturers SET max_supervision_load =
        (SELECT COUNT(*) FROM supervision_assignments sa WHERE sa.supervisor_id = lecturers.lecturer_id AND sa.is_active = 1)
     WHERE lecturer_id = ?"
);
foreach ($lects as $id) {
    $roomy->execute([$id]);
}

$externalLecturer = $pdo->query(
    "SELECT l.lecturer_id, CONCAT(u.first_name, ' ', u.last_name) AS name
     FROM lecturers l JOIN users u ON u.user_id = l.user_id
     JOIN external_lecturers el ON el.lecturer_id = l.lecturer_id LIMIT 1"
)->fetch();
// What Admin -> Users "external" does to the internal side of a lecturer.
$makeExternal = $pdo->prepare("DELETE FROM internal_lecturers WHERE lecturer_id = ?");

$admin = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();
$coordinator = $pdo->query(
    "SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id = u.user_id
     WHERE u.user_id <> " . $pdo->quote($student['user_id']) . " LIMIT 1"
)->fetchColumn();
$dh = new DepartmentHead($pdo);
$pos = $pdo->query("SELECT position_id FROM department_positions LIMIT 1")->fetchColumn();
$dh->assign($student['department_id'], $coordinator, $pos, $admin);
$headIds = array_column(array_filter(
    $dh->activeForDepartment($student['department_id']),
    fn ($head) => $head['user_id'] !== $student['user_id']
), 'dept_head_id');

$proposalRow = fn (): array => $pdo->query(
    "SELECT * FROM thesis_proposals WHERE proposal_id = " . $pdo->quote($student['proposal_id'])
)->fetch();

$list = [
    ['lecturer_id' => $lects[0], 'rank' => 1, 'preferred_main' => false],
    ['lecturer_id' => $lects[1], 'rank' => 2, 'preferred_main' => false],
    ['lecturer_id' => $lects[2], 'rank' => 3, 'preferred_main' => false],
    ['lecturer_id' => $lects[3], 'rank' => 4, 'preferred_main' => false],
    ['lecturer_id' => $lects[4], 'rank' => 5, 'preferred_main' => false],
];

$choiceFor = function (string $sid, int $rank) use ($pdo): array {
    return $pdo->query(
        "SELECT * FROM supervisor_shortlist_choices WHERE shortlist_id = " . $pdo->quote($sid) . " AND rank_position = $rank"
    )->fetch();
};
$statuses = function (string $sid) use ($pdo): string {
    return implode(' ', $pdo->query(
        "SELECT CONCAT(rank_position, ':', request_status) FROM supervisor_shortlist_choices
         WHERE shortlist_id = " . $pdo->quote($sid) . " ORDER BY rank_position"
    )->fetchAll(PDO::FETCH_COLUMN));
};
$requestStatus = fn (string $sid): string => (string) $pdo->query(
    "SELECT status FROM supervisor_shortlists WHERE shortlist_id = " . $pdo->quote($sid)
)->fetchColumn();
$roleOf = function (string $lecturerId) use ($pdo, $student): ?string {
    $r = $pdo->query(
        "SELECT role FROM supervision_assignments WHERE proposal_id = " . $pdo->quote($student['proposal_id'])
        . " AND supervisor_id = " . $pdo->quote($lecturerId) . " AND is_active = 1"
    )->fetchColumn();
    return $r ?: null;
};
$answer = fn (string $sid, int $rank, bool $accept) => $m->respondToRequest($choiceFor($sid, $rank)['choice_id'], $accept, $accept ? null : 'Not my area.');
$approve = function (string $sid, string $vote = 'approve') use ($m, $headIds, $coordinator): string {
    $meeting = $m->scheduleMeeting($sid, '2026-10-01 10:00:00', 'physical', 'Boardroom', null, $headIds, $coordinator);
    $m->castVote($meeting, $headIds[0], $vote);
    $m->saveMinutes(
        $meeting,
        'Discussion recorded in full.',
        $vote === 'approve' ? 'Approved.' : 'The topic overlaps an existing thesis.'
    );
    $m->finalizeMinutes($meeting);
    $m->approveMinutes($meeting, $coordinator);
    return $m->recordOutcome($meeting);
};

// =====================================================================
echo "\n=== Written together, then locked for good ===\n";

$pdo->prepare("UPDATE thesis_proposals SET status = 'draft' WHERE proposal_id = ?")->execute([$student['proposal_id']]);
$state = $m->studentState($student['student_id'], $proposalRow());
check('with a draft proposal the student may edit it', $state['proposal_editable']);
check('and the supervisor list with it', $state['list_editable'] && $state['can_send']);

$draftId = $m->saveDraft($student['student_id'], $proposalRow(), array_slice($list, 0, 2), $student['user_id']);
check('a list can be kept as a draft', $requestStatus($draftId) === 'draft');
$err = throws(fn () => $m->saveDraft($student['student_id'], $proposalRow(), [
    ['lecturer_id' => $externalLecturer['lecturer_id'], 'rank' => 1, 'preferred_main' => false],
], $student['user_id']));
check('a draft naming an external lecturer is refused', $err !== null && str_contains($err, 'internal'), (string) $err);
check('a draft is not a request anyone else can see', $m->latestSentForStudent($student['student_id']) === null);
check('so no meeting can be scheduled on it',
    throws(fn () => $m->scheduleMeeting($draftId, '2026-10-01 10:00:00', 'physical', null, null, $headIds, $coordinator)) !== null);
check('and the request cannot go without the proposal',
    throws(fn () => $m->sendForStudent($student['student_id'], $proposalRow(), $list, $student['user_id'])) !== null);

// The PDF the proposal goes out with. A row only — no file on disk is
// needed to prove which path each request records.
$documents = new Document($pdo);
$proposalTypeId = $pdo->query("SELECT doc_type_id FROM document_types WHERE doc_type_name = 'Proposal' LIMIT 1")->fetchColumn();
$originalPath = 'uploads/documents/rounds-test-original-' . bin2hex(random_bytes(4)) . '.pdf';
$originalDoc = $documents->create([
    'user_id' => $student['user_id'], 'uploaded_by' => $student['user_id'], 'document_type_id' => $proposalTypeId,
    'document_status' => 'submitted', 'file_name' => 'original-proposal.pdf', 'file_path' => $originalPath,
    'file_size_kb' => 120, 'mime_type' => 'application/pdf',
]);
$documents->linkToProposal($originalDoc, $student['proposal_id'], $proposalTypeId, null);

// What the controller does in one transaction: submit the proposal, send the request.
$pdo->prepare("UPDATE thesis_proposals SET status = 'submitted' WHERE proposal_id = ?")->execute([$student['proposal_id']]);
$sid = $m->sendForStudent($student['student_id'], $proposalRow(), $list, $student['user_id']);

check('sending creates the request', $requestStatus($sid) === 'pending_coordinator');
check('the working draft is gone once it is sent',
    (int) $pdo->query("SELECT COUNT(*) FROM supervisor_shortlists WHERE shortlist_id = " . $pdo->quote($draftId))->fetchColumn() === 0);
check('it records the file it was sent with',
    array_column($m->filesFor($sid), 'file_path') === [$originalPath]);
check('it keeps a copy of the proposal as sent',
    $pdo->query("SELECT proposal_title_snapshot FROM supervisor_shortlists WHERE shortlist_id = " . $pdo->quote($sid))->fetchColumn() === $proposalRow()['title']);

$state = $m->studentState($student['student_id'], $proposalRow());
check('now the student can edit neither part', !$state['proposal_editable'] && !$state['list_editable'] && !$state['can_send']);
check('saving the list again is refused',
    throws(fn () => $m->saveDraft($student['student_id'], $proposalRow(), $list, $student['user_id'])) !== null);
check('and so is sending a second request',
    throws(fn () => $m->sendForStudent($student['student_id'], $proposalRow(), $list, $student['user_id'])) !== null);

$pdo->exec('SAVEPOINT sent_to_coordinator');

// =====================================================================
echo "\n=== The department approves the request, not the proposal ===\n";

check('the vote carries', $approve($sid) === 'approved');
check('the request is approved', $requestStatus($sid) === 'approved');
check('the proposal itself is untouched', $proposalRow()['status'] === 'submitted');
check('and nobody has been asked yet', $statuses($sid) === '1:not_sent 2:not_sent 3:not_sent 4:not_sent 5:not_sent');
check('applying the same decision twice is refused',
    throws(fn () => $m->recordOutcome((string) $pdo->query("SELECT meeting_id FROM shortlist_meetings WHERE shortlist_id = " . $pdo->quote($sid))->fetchColumn())) !== null);

// =====================================================================
echo "\n=== Sent to everyone at once ===\n";

$fill->execute([$lects[4]]);
$sent = $m->sendRequests($sid, $coordinator);
check('every lecturer with room is asked together', $sent['sent'] === 4, $statuses($sid));
check('a lecturer at full load is passed over, not asked', $choiceFor($sid, 5)['request_status'] === 'unavailable');
check('nothing is decided just by sending', $sent['outcome'] === null && $requestStatus($sid) === 'requests_sent');

$due = $pdo->query("SELECT TIMESTAMPDIFF(DAY, NOW(), responses_due_at) FROM supervisor_shortlists WHERE shortlist_id = " . $pdo->quote($sid))->fetchColumn();
check('lecturers have two weeks from the moment it is sent', (int) $due >= 13 && (int) $due <= 14, $due . ' days');
check('sending again is refused', throws(fn () => $m->sendRequests($sid, $coordinator)) !== null);

$pdo->exec('SAVEPOINT requests_out');

// =====================================================================
echo "\n=== The top three accept: decided on the spot ===\n";

$answer($sid, 3, true);
$answer($sid, 2, true);
check('two acceptances with the first choice still silent decide nothing', $requestStatus($sid) === 'requests_sent');
$result = $answer($sid, 1, true);
check('the third acceptance settles it at once', $result['decided'] === 'successful', $statuses($sid));
check('the first choice is the main supervisor', $roleOf($lects[0]) === 'main');
check('the next two are co-supervisors', $roleOf($lects[1]) === 'co_supervisor' && $roleOf($lects[2]) === 'co_supervisor');
check('the lecturer still deciding is stood down', $choiceFor($sid, 4)['request_status'] === 'cancelled');
check('the accepting lecturer is told how they were appointed', $result['appointed_as'] === 'main');

// =====================================================================
echo "\n=== Order decides, not who replied first ===\n";
$pdo->exec('ROLLBACK TO SAVEPOINT requests_out');

$answer($sid, 4, true);
$answer($sid, 3, true);
$answer($sid, 2, true);
check('three acceptances below a silent first choice still decide nothing', $requestStatus($sid) === 'requests_sent', $statuses($sid));
$answer($sid, 1, true);
check('the first choice answering last still takes a place', $roleOf($lects[0]) === 'main');
check('and is main, though everyone else replied sooner', $roleOf($lects[1]) === 'co_supervisor');
check('the fourth acceptance is not needed', $choiceFor($sid, 4)['request_status'] === 'cancelled' && $roleOf($lects[3]) === null);
check('so the panel is the top three by the student\'s order',
    (int) $pdo->query("SELECT COUNT(*) FROM supervision_assignments WHERE proposal_id = " . $pdo->quote($student['proposal_id']) . " AND is_active = 1")->fetchColumn() === 3);

// =====================================================================
echo "\n=== Declines are passed over; one acceptance is enough ===\n";
$pdo->exec('ROLLBACK TO SAVEPOINT requests_out');

$answer($sid, 1, false);
$answer($sid, 2, true);
check('an acceptance with a silent choice below it but places left waits', $requestStatus($sid) === 'requests_sent');
$answer($sid, 3, false);
$result = $answer($sid, 4, false);
check('once everyone has answered it is decided', $result['decided'] === 'successful', $statuses($sid));
check('the only acceptance becomes main', $roleOf($lects[1]) === 'main');

// =====================================================================
echo "\n=== The window closes ===\n";
$pdo->exec('ROLLBACK TO SAVEPOINT requests_out');

$answer($sid, 3, true);
$pdo->prepare("UPDATE supervisor_shortlists SET responses_due_at = NOW() - INTERVAL 1 MINUTE WHERE shortlist_id = ?")->execute([$sid]);

$late = throws(fn () => $answer($sid, 1, true));
check('a lecturer answering after the deadline is refused', $late !== null && str_contains($late, 'ran out'), (string) $late);
check('and their arriving settles the request', $requestStatus($sid) === 'successful', $statuses($sid));
check('whoever accepted in time is appointed', $roleOf($lects[2]) === 'main');
check('lecturers who never answered are recorded as having run out of time',
    $choiceFor($sid, 1)['request_status'] === 'expired' && $choiceFor($sid, 2)['request_status'] === 'expired');

$pdo->exec('ROLLBACK TO SAVEPOINT requests_out');
$pdo->prepare("UPDATE supervisor_shortlists SET responses_due_at = NOW() - INTERVAL 1 MINUTE WHERE shortlist_id = ?")->execute([$sid]);
check('with nobody visiting, the pages settle lapsed requests on load', $m->resolveDue() >= 1 && $requestStatus($sid) === 'exhausted');

// =====================================================================
echo "\n=== Full load ===\n";
$pdo->exec('ROLLBACK TO SAVEPOINT requests_out');

$fill->execute([$lects[0]]);
$err = throws(fn () => $answer($sid, 1, true));
check('a lecturer at full load cannot accept', $err !== null && str_contains($err, 'full'), (string) $err);
check('their request stays open for a decline', $choiceFor($sid, 1)['request_status'] === 'pending');
$roomy->execute([$lects[0]]);

$answer($sid, 1, true);
$fill->execute([$lects[0]]);   // appointed elsewhere before this one was decided
$answer($sid, 2, true);
$answer($sid, 3, true);
$answer($sid, 4, false);
check('an acceptance whose load filled before the decision is not acted on', $roleOf($lects[0]) === null, $statuses($sid));
check('it is recorded as unavailable', $choiceFor($sid, 1)['request_status'] === 'unavailable');
check('and the next in order becomes main instead', $roleOf($lects[1]) === 'main');
$roomy->execute([$lects[0]]);

// =====================================================================
echo "\n=== Nobody accepts ===\n";
$pdo->exec('ROLLBACK TO SAVEPOINT requests_out');

foreach ([1, 2, 3, 4] as $rank) {
    $last = $answer($sid, $rank, false);
}
check('the last decline decides it as exhausted', $last['decided'] === 'exhausted');
check('the proposal is still locked', $proposalRow()['status'] === 'submitted');
check('the failed request stays on record', $requestStatus($sid) === 'exhausted');
check('and is waiting on the coordinator', $m->decisionState($m->findWithContext($sid))['can_decide']);

// =====================================================================
echo "\n=== Not taking new students ===\n";
$pdo->exec('ROLLBACK TO SAVEPOINT requests_out');

$switchOff->execute([$lects[0]]);
$err = throws(fn () => $answer($sid, 1, true));
check('a lecturer who switched availability off cannot accept', $err !== null && str_contains($err, 'availability'), (string) $err);
check('and is told that, not that they are full', $err !== null && !str_contains($err, 'load is full'));
check('their request stays open so they can still decline', $choiceFor($sid, 1)['request_status'] === 'pending');
$switchOn->execute([$lects[0]]);

$answer($sid, 1, true);
$switchOff->execute([$lects[0]]);   // accepted, then stopped taking students before it was decided
$answer($sid, 2, true);
$answer($sid, 3, true);
$answer($sid, 4, false);
check('an acceptance from someone no longer taking students is not acted on', $roleOf($lects[0]) === null, $statuses($sid));
check('it is recorded with that reason',
    str_contains((string) $choiceFor($sid, 1)['decline_reason'], 'availability'));
check('and the next in order becomes main', $roleOf($lects[1]) === 'main');

$pdo->exec('ROLLBACK TO SAVEPOINT sent_to_coordinator');
$approve($sid);
$switchOff->execute([$lects[1]]);
$fill->execute([$lects[2]]);
$sent = $m->sendRequests($sid, $coordinator);
check('a lecturer not taking students is passed over rather than asked', $choiceFor($sid, 2)['request_status'] === 'unavailable');
check('with the reason recorded', str_contains((string) $choiceFor($sid, 2)['decline_reason'], 'Not taking on new students'));
check('a full lecturer is passed over with their own reason', str_contains((string) $choiceFor($sid, 3)['decline_reason'], 'full supervision load'));
check('everyone else is asked', $sent['sent'] === 3 && $sent['unavailable'] === 2, $statuses($sid));

// =====================================================================
echo "\n=== Only internal lecturers supervise ===\n";

$err = throws(fn () => $m->submit($student['student_id'], $student['proposal_id'], [
    ['lecturer_id' => $lects[0], 'rank' => 1, 'preferred_main' => false],
    ['lecturer_id' => $externalLecturer['lecturer_id'], 'rank' => 2, 'preferred_main' => false],
]));
check('a list naming an external lecturer is refused', $err !== null && str_contains($err, 'internal'), (string) $err);
check('and the refusal names them', $err !== null && str_contains($err, $externalLecturer['name']));
check('an id that belongs to no lecturer is refused too',
    throws(fn () => $m->submit($student['student_id'], $student['proposal_id'], [
        ['lecturer_id' => '00000000-0000-4000-8000-000000000000', 'rank' => 1, 'preferred_main' => false],
    ])) !== null);

$pdo->exec('ROLLBACK TO SAVEPOINT sent_to_coordinator');
$approve($sid);
$makeExternal->execute([$lects[3]]);
$m->sendRequests($sid, $coordinator);
check('a lecturer made external after being shortlisted is passed over at send',
    $choiceFor($sid, 4)['request_status'] === 'unavailable' && str_contains((string) $choiceFor($sid, 4)['decline_reason'], 'internal'),
    (string) $choiceFor($sid, 4)['decline_reason']);

$pdo->exec('ROLLBACK TO SAVEPOINT sent_to_coordinator');
$approve($sid);
$m->sendRequests($sid, $coordinator);
$makeExternal->execute([$lects[0]]);
$err = throws(fn () => $answer($sid, 1, true));
check('one made external after being asked cannot accept', $err !== null && str_contains($err, 'internal'), (string) $err);
check('but can still decline', throws(fn () => $answer($sid, 1, false)) === null);

$answer($sid, 2, true);
$makeExternal->execute([$lects[1]]);   // accepted, then made external before the decision
$answer($sid, 3, true);
$answer($sid, 4, false);
$answer($sid, 5, false);
check('an acceptance from someone no longer internal is not acted on', $roleOf($lects[1]) === null, $statuses($sid));
check('it is recorded with that reason', str_contains((string) $choiceFor($sid, 2)['decline_reason'], 'internal'));
check('and the next internal lecturer in order becomes main', $roleOf($lects[2]) === 'main');

// =====================================================================
echo "\n=== The department rejects ===\n";
$pdo->exec('ROLLBACK TO SAVEPOINT sent_to_coordinator');

check('a rejection is applied', $approve($sid, 'reject') === 'rejected');
check('nobody is sent anything', throws(fn () => $m->sendRequests($sid, $coordinator)) !== null);
$state = $m->studentState($student['student_id'], $proposalRow());
check('the student still cannot edit anything', !$state['proposal_editable'] && !$state['list_editable'] && !$state['can_send']);
check('it is the coordinator\'s move', $state['waiting_on'] === 'coordinator');
$pdo->exec('SAVEPOINT rejected');

// =====================================================================
echo "\n=== Coordinator hands it back to the student ===\n";

check('handing back nothing is refused', throws(fn () => $m->grantEdit($sid, false, false, $coordinator)) !== null);
$m->grantEdit($sid, true, true, $coordinator);
check('the proposal goes back to draft', $proposalRow()['status'] === 'draft');

$state = $m->studentState($student['student_id'], $proposalRow());
check('the student may now edit both', $state['proposal_editable'] && $state['list_editable'] && $state['can_send']);
check('the coordinator may not revise the list meanwhile',
    throws(fn () => $m->reviseList($sid, array_reverse($list), $coordinator)) !== null);
check('nor hand it back a second time', throws(fn () => $m->grantEdit($sid, true, false, $coordinator)) !== null);

$m->saveDraft($student['student_id'], $proposalRow(), array_slice($list, 1, 3), $student['user_id']);
check('the rejected request is still what the queue shows while the student works',
    in_array($sid, array_column($m->queueForPrograms([$pdo->query(
        "SELECT ts.program_id FROM student_thesis_registrations str JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
         WHERE str.student_id = " . $pdo->quote($student['student_id']) . " LIMIT 1")->fetchColumn()]), 'shortlist_id'), true));

// The student replaces the PDF while revising: the document row goes,
// exactly as the upload does, and a new one takes its place.
$documents->delete($originalDoc);
$revisedPath = 'uploads/documents/rounds-test-revised-' . bin2hex(random_bytes(4)) . '.pdf';
$revisedDoc = $documents->create([
    'user_id' => $student['user_id'], 'uploaded_by' => $student['user_id'], 'document_type_id' => $proposalTypeId,
    'document_status' => 'draft', 'file_name' => 'revised-proposal.pdf', 'file_path' => $revisedPath,
    'file_size_kb' => 130, 'mime_type' => 'application/pdf',
]);
$documents->linkToProposal($revisedDoc, $student['proposal_id'], $proposalTypeId, null);

$pdo->prepare("UPDATE thesis_proposals SET title = 'A revised title', status = 'submitted' WHERE proposal_id = ?")->execute([$student['proposal_id']]);
$sid2 = $m->sendForStudent($student['student_id'], $proposalRow(), array_slice($list, 1, 3), $student['user_id']);

check('sending again starts a new request', $requestStatus($sid2) === 'pending_coordinator');
check('which points back at the one that failed',
    $pdo->query("SELECT previous_shortlist_id FROM supervisor_shortlists WHERE shortlist_id = " . $pdo->quote($sid2))->fetchColumn() === $sid);
check('the failed request keeps its outcome and reason',
    $requestStatus($sid) === 'rejected'
    && str_contains((string) $pdo->query("SELECT rejection_reason FROM supervisor_shortlists WHERE shortlist_id = " . $pdo->quote($sid))->fetchColumn(), 'overlaps'));
check('the failed request still records the file it was judged on',
    array_column($m->filesFor($sid), 'file_path') === [$originalPath]);
check('so that file is kept on disk rather than deleted', $m->isFileOnRecord($originalPath));
check('the new request records the replacement',
    array_column($m->filesFor($sid2), 'file_path') === [$revisedPath]);
check('and still records the proposal as it was then',
    $pdo->query("SELECT proposal_title_snapshot FROM supervisor_shortlists WHERE shortlist_id = " . $pdo->quote($sid))->fetchColumn() !== 'A revised title');
check('the new request is the current one', $m->latestSentForStudent($student['student_id'])['shortlist_id'] === $sid2);
check('both attempts are on the student\'s record', count($m->historyForStudent($student['student_id'])) === 2);
check('and it is locked again', !$m->studentState($student['student_id'], $proposalRow())['can_send']);

// =====================================================================
echo "\n=== Handing back only the proposal ===\n";
$pdo->exec('ROLLBACK TO SAVEPOINT rejected');

$m->grantEdit($sid, true, false, $coordinator);
$state = $m->studentState($student['student_id'], $proposalRow());
check('the student may edit the proposal but not the list', $state['proposal_editable'] && !$state['list_editable']);
check('saving a different list is refused',
    throws(fn () => $m->saveDraft($student['student_id'], $proposalRow(), array_slice($list, 0, 1), $student['user_id'])) !== null);

$pdo->prepare("UPDATE thesis_proposals SET status = 'submitted' WHERE proposal_id = ?")->execute([$student['proposal_id']]);
$sid3 = $m->sendForStudent($student['student_id'], $proposalRow(), array_slice($list, 0, 1), $student['user_id']);
check('the list goes out again exactly as it was, whatever was posted',
    count($m->choicesFor($sid3)) === 5 && $m->choicesFor($sid3)[0]['lecturer_id'] === $lects[0]);

// =====================================================================
echo "\n=== The coordinator revises the list instead ===\n";
$pdo->exec('ROLLBACK TO SAVEPOINT rejected');

$err = throws(fn () => $m->reviseList($sid, [
    ['lecturer_id' => $externalLecturer['lecturer_id'], 'rank' => 1, 'preferred_main' => true],
], $coordinator));
check('the coordinator cannot put an external lecturer on the list either', $err !== null && str_contains($err, 'internal'), (string) $err);

$same = throws(fn () => $m->reviseList($sid, $list, $coordinator));
check('an unchanged list is refused, so no pointless meeting can follow', $same !== null && str_contains($same, 'Nothing has changed'), (string) $same);
check('and no new request was created', count($m->historyForStudent($student['student_id'])) === 1);

$revised = [
    ['lecturer_id' => $lects[1], 'rank' => 1, 'preferred_main' => true],
    ['lecturer_id' => $lects[3], 'rank' => 2, 'preferred_main' => false],
];
$sid4 = $m->reviseList($sid, $revised, $coordinator);
check('a changed list becomes a new request', $requestStatus($sid4) === 'pending_coordinator');
check('recorded as the coordinator\'s revision',
    $pdo->query("SELECT created_by FROM supervisor_shortlists WHERE shortlist_id = " . $pdo->quote($sid4))->fetchColumn() === $coordinator);
check('the proposal was not touched', $proposalRow()['status'] === 'submitted');
check('the student still cannot edit anything', !$m->studentState($student['student_id'], $proposalRow())['can_send']);
check('and a meeting can now be scheduled',
    throws(fn () => $m->scheduleMeeting($sid4, '2026-10-08 10:00:00', 'physical', null, null, $headIds, $coordinator)) === null);
check('the failed request is off the coordinator\'s to-do list', !$m->decisionState($m->findWithContext($sid))['can_decide']);

$pdo->rollBack();

printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
