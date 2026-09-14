<?php

/**
 * The changeover from students asking a lecturer directly to a
 * department-voted shortlist.
 *
 * Two paths used to reach the same place — an appointed supervisor —
 * and only one of them passed the department. These check that the old
 * one is genuinely closed rather than merely unlinked from the
 * templates, and that history stays readable.
 *
 * Run: php tests/manual_cutover.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Models\SupervisionRequest;
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

$requests = new SupervisionRequest($pdo);
$shortlist = new SupervisorShortlist($pdo);

echo "\n=== The direct request path is closed ===\n";

$student = $pdo->query(
    "SELECT s.student_id, s.user_id FROM students s
     JOIN thesis_proposals tp ON tp.student_id = s.student_id LIMIT 1"
)->fetch();
$proposal = $pdo->query(
    "SELECT proposal_id FROM thesis_proposals WHERE student_id = " . $pdo->quote($student['student_id']) . " LIMIT 1"
)->fetchColumn();
$someLecturer = $pdo->query(
    "SELECT lecturers.lecturer_id FROM lecturers JOIN internal_lecturers il ON il.lecturer_id = lecturers.lecturer_id WHERE user_id <> " . $pdo->quote($student['user_id']) . " LIMIT 1"
)->fetchColumn();

$before = (int) $pdo->query("SELECT COUNT(*) FROM supervision_requests")->fetchColumn();
$err = throws(fn() => $requests->create($proposal, $student['student_id'], $someLecturer));
check('creating a direct request is refused', $err !== null, $err ?? 'no exception');
check('and it explains where appointments come from now',
    $err !== null && str_contains(strtolower($err), 'shortlist'));
check('so no row is written',
    (int) $pdo->query("SELECT COUNT(*) FROM supervision_requests")->fetchColumn() === $before);

check('accepting one is gone entirely', !method_exists($requests, 'accept'));
check('but declining survives, so a stale request can be cleared', method_exists($requests, 'decline'));

// The system used to appoint a supervisor by itself when the proposed
// one never answered. That skips the vote, so it is gone.
check('the silent auto-assign is gone from the model',
    !method_exists($requests, 'autoAssignIfUnresponsive'));
check('and from the proposal model',
    !method_exists(new \App\Models\Proposal($pdo), 'autoAssignSupervisorIfEligible'));

echo "\n=== History stays readable ===\n";

$historic = $pdo->query(
    "SELECT lecturer_id, COUNT(*) n FROM supervision_requests GROUP BY lecturer_id
     ORDER BY n DESC LIMIT 1"
)->fetch();

if ($historic) {
    $pending = $requests->findPendingByLecturerId($historic['lecturer_id']);
    $history = $requests->findHistoryByLecturerId($historic['lecturer_id']);
    check('a lecturer can still read the requests made before the change',
        count($pending) + count($history) > 0,
        count($pending) . ' pending, ' . count($history) . ' decided');
} else {
    check('a lecturer can still read the requests made before the change', true, 'none on file');
}

echo "\n=== A student cannot shortlist themselves ===\n";

// Staff studying for their own degree hold a student and a lecturer
// record against one user. There is at least one on file.
$dual = $pdo->query(
    "SELECT s.student_id, s.user_id, l.lecturer_id
     FROM students s JOIN lecturers l ON l.user_id = s.user_id
     JOIN thesis_proposals tp ON tp.student_id = s.student_id LIMIT 1"
)->fetch();

if ($dual) {
    $dualProposal = $pdo->query(
        "SELECT proposal_id FROM thesis_proposals WHERE student_id = " . $pdo->quote($dual['student_id']) . " LIMIT 1"
    )->fetchColumn();
    $other = $pdo->query(
        "SELECT lecturers.lecturer_id FROM lecturers JOIN internal_lecturers il ON il.lecturer_id = lecturers.lecturer_id WHERE user_id <> " . $pdo->quote($dual['user_id']) . " LIMIT 1"
    )->fetchColumn();

    $err = throws(fn() => $shortlist->submit($dual['student_id'], $dualProposal, [
        ['lecturer_id' => $dual['lecturer_id'], 'rank' => 1, 'preferred_main' => true],
    ]));
    check('shortlisting your own lecturer account is refused', $err !== null, $err ?? 'no exception');

    $err = throws(fn() => $shortlist->submit($dual['student_id'], $dualProposal, [
        ['lecturer_id' => $other, 'rank' => 1, 'preferred_main' => true],
        ['lecturer_id' => $dual['lecturer_id'], 'rank' => 2, 'preferred_main' => false],
    ]));
    check('and so is hiding yourself further down the list', $err !== null);

    $err = throws(fn() => $shortlist->submit($dual['student_id'], $dualProposal, [
        ['lecturer_id' => $other, 'rank' => 1, 'preferred_main' => true],
    ]));
    check('while a list of other people still goes through', $err === null, $err ?? '');
} else {
    echo "  SKIP  no dual student/lecturer account on file\n";
}

echo "\n=== The forms match the rules ===\n";

$proposalForm = file_get_contents(__DIR__ . '/../src/Views/students/proposal.twig');
check('the proposal form no longer asks for a supervisor',
    !str_contains($proposalForm, 'name="proposed_supervisor_id"'));
check('supervisors are chosen on the same page and sent with it instead',
    str_contains($proposalForm, "include 'partials/supervisor_picker.twig'"));

$supervisionPage = file_get_contents(__DIR__ . '/../src/Views/lecturers/supervision.twig');
check('the lecturer page offers no way to accept a legacy request',
    !str_contains($supervisionPage, '/lecturer/supervision/accept'));
check('but still offers to decline one',
    str_contains($supervisionPage, '/lecturer/supervision/decline'));

$routes = file_get_contents(__DIR__ . '/../app/routes.php');
check('and the accept route is gone, not just the button',
    !str_contains($routes, "/lecturer/supervision/accept"));

$pdo->rollBack();

printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
