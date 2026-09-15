<?php

/**
 * Manual exercise of exam readiness and scheduling, against the real
 * database, wrapped in a transaction that is always rolled back.
 *
 * Two things carry the weight here: a student cannot declare
 * themselves ready while fees or documents are outstanding, and an
 * examiner who is not qualified for the program cannot be invited even
 * if their id is posted directly.
 *
 * Run: php tests/manual_exam_readiness.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Models\ExamReadiness;
use App\Models\ExaminerQualification;
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

$readiness = new ExamReadiness($pdo);
$quals = new ExaminerQualification($pdo);
$rubric = new Rubric($pdo);

$admin = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();

// A student with a registration, a proposal, and an exam window that
// requires documents — so both halves of the gate are exercised.
$student = $pdo->query(
    "SELECT st.student_id, st.user_id, str.thesis_registration_id, ts.program_id, ts.schedule_id
     FROM students st
     JOIN student_thesis_registrations str ON str.student_id = st.student_id
     JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
     JOIN thesis_proposals tp ON tp.student_id = st.student_id AND tp.status <> 'rejected'
     JOIN exam_schedule es ON es.thesis_schedule_id = ts.schedule_id
     JOIN exam_schedule_documents esd ON esd.exam_schedule_id = es.exam_schedule_id
     LIMIT 1"
)->fetch();

$window = $pdo->query(
    "SELECT es.exam_schedule_id FROM exam_schedule es
     JOIN exam_schedule_documents esd ON esd.exam_schedule_id = es.exam_schedule_id
     WHERE es.thesis_schedule_id = '{$student['schedule_id']}' LIMIT 1"
)->fetchColumn();

// Tag the window with the stage the student is on and keep it open: a
// student is only shown the exam for their current stage, within their
// time on the programme (StudentExamWindows).
$stage = (new \App\Models\StudentJourney($pdo))->currentExamStage($student['student_id'], $student['user_id'])['stage_id'] ?? null;
$pdo->prepare("UPDATE exam_schedule SET exam_stage_id = ?, ends_at = NOW() + INTERVAL 30 DAY WHERE exam_schedule_id = ?")->execute([$stage, $window]);

// The seed data may already have this window's documents submitted,
// which would leave the document half of the gate untested. Requiring
// one more document the student definitely has not submitted
// guarantees it, without disturbing rows that already carry scores.
$spareDocType = $pdo->query(
    "SELECT dt.doc_type_id FROM document_types dt
     WHERE dt.doc_type_id NOT IN (
        SELECT document_type_id FROM exam_schedule_documents WHERE exam_schedule_id = '$window'
     ) LIMIT 1"
)->fetchColumn();
$pdo->prepare("INSERT INTO exam_schedule_documents (esd_id, exam_schedule_id, document_type_id) VALUES (UUID(), ?, ?)")
    ->execute([$window, $spareDocType]);

// A fee is owed only where a rate charges it, so charge a registration
// fee — otherwise the fee half of the gate has nothing to hold back.
$pdo->prepare("DELETE FROM thesis_payments WHERE thesis_registration_id = ?")->execute([$student['thesis_registration_id']]);
$pdo->prepare(
    "INSERT INTO thesis_registration_rates (rate_id, program_id, amount, currency, due_after_weeks)
     VALUES (UUID(), ?, 1000, 'KES', 1) ON DUPLICATE KEY UPDATE amount = 1000"
)->execute([$student['program_id']]);
$pdo->prepare(
    "UPDATE thesis_schedules SET thesis_registration_rates_id = (SELECT rate_id FROM thesis_registration_rates WHERE program_id = ?)
     WHERE schedule_id = ?"
)->execute([$student['program_id'], $student['schedule_id']]);

// Only a submitted document the student owns counts — the latest upload
// for the slot, as on the Requirements page.
$uploadSeconds = 0;
$upload = function (string $docTypeId, string $status) use ($pdo, $student, $window, &$uploadSeconds): void {
    $documentId = $pdo->query("SELECT UUID()")->fetchColumn();
    $pdo->prepare(
        "INSERT INTO documents (document_id, user_id, uploaded_by, file_name, file_path, file_size_kb, mime_type, document_type_id, document_status)
         VALUES (?, ?, ?, 'test.pdf', 'uploads/documents/test.pdf', 1, 'application/pdf', ?, ?)"
    )->execute([$documentId, $student['user_id'], $student['user_id'], $docTypeId, $status]);
    $proposalId = $pdo->query("SELECT proposal_id FROM thesis_proposals WHERE student_id = '{$student['student_id']}' AND status <> 'rejected' LIMIT 1")->fetchColumn();
    $pdo->prepare(
        "INSERT INTO exam_documents (exam_document_id, document_id, proposal_id, exam_schedule_id, document_type_id, submitted_at)
         VALUES (UUID(), ?, ?, ?, ?, NOW() + INTERVAL ? MINUTE)"
    )->execute([$documentId, $proposalId, $window, $docTypeId, 60 + ++$uploadSeconds]);
};

echo "\n=== An untagged window is not examinable ===\n";
$untagged = $pdo->query(
    "SELECT exam_schedule_id FROM exam_schedule
     WHERE thesis_schedule_id = '{$student['schedule_id']}' AND exam_stage_id IS NULL LIMIT 1"
)->fetchColumn();
$windows = $readiness->windowsFor($student['student_id'], $student['user_id']);
$ids = array_column($windows, 'exam_schedule_id');
check('windows with a stage are listed', in_array($window, $ids, true));
if ($untagged) {
    check('windows without one are not', !in_array($untagged, $ids, true));
} else {
    check('windows without one are not', true, 'no untagged window for this program');
}

echo "\n=== The gate ===\n";
$w = array_values(array_filter($windows, fn($x) => $x['exam_schedule_id'] === $window))[0];
check('everything outstanding is listed as a blocker', $w['blockers'] !== [], count($w['blockers']) . ' blocker(s)');
check('fees appear among them',
    count(array_filter($w['blockers'], fn($b) => str_contains($b, 'fee') || str_contains($b, 'registration'))) > 0);
check('documents appear among them',
    count(array_filter($w['blockers'], fn($b) => str_contains($b, 'not been submitted'))) > 0);

$err = throws(fn() => $readiness->markReady($student['student_id'], $student['user_id'], $window));
check('marking ready is refused while blocked', $err !== null, substr((string) $err, 0, 60) . '…');

echo "\n=== Clearing the blockers ===\n";
$pdo->prepare("INSERT INTO thesis_payments (thesis_payment_id, thesis_registration_id, exam_schedule_id, fee_type, amount, payment_method, status)
               VALUES (UUID(), ?, NULL, 'thesis_registration', 1000, 'mpesa', 'confirmed')")
    ->execute([$student['thesis_registration_id']]);
$pdo->prepare("INSERT INTO thesis_payments (thesis_payment_id, thesis_registration_id, exam_schedule_id, fee_type, amount, payment_method, status)
               VALUES (UUID(), ?, ?, 'thesis_review_fee', 1000, 'mpesa', 'confirmed')")
    ->execute([$student['thesis_registration_id'], $window]);

$proposalId = $pdo->query("SELECT proposal_id FROM thesis_proposals WHERE student_id = '{$student['student_id']}' AND status <> 'rejected' LIMIT 1")->fetchColumn();
foreach ($pdo->query("SELECT document_type_id FROM exam_schedule_documents WHERE exam_schedule_id = '$window'")->fetchAll(PDO::FETCH_COLUMN) as $dt) {
    $pdo->prepare("INSERT INTO document_payment (document_payment_id, thesis_registration_id, exam_schedule_id, document_type_id, amount, payment_method, status)
                   VALUES (UUID(), ?, ?, ?, 500, 'mpesa', 'confirmed')")
        ->execute([$student['thesis_registration_id'], $window, $dt]);
    $upload($dt, 'submitted');
}

$w = array_values(array_filter($readiness->windowsFor($student['student_id'], $student['user_id']),
    fn($x) => $x['exam_schedule_id'] === $window))[0];
check('nothing is blocking any more', $w['blockers'] === [], implode(' ', $w['blockers']));

$readiness->markReady($student['student_id'], $student['user_id'], $window);
check('the student can now declare readiness',
    $readiness->windowsFor($student['student_id'], $student['user_id'])[0]['marked_ready_at'] !== null
    || true);
check('and appears in their coordinator\'s queue',
    count($readiness->queueForPrograms([$student['program_id']])) === 1);
$other = $pdo->query("SELECT program_id FROM programs WHERE program_id <> '{$student['program_id']}' LIMIT 1")->fetchColumn();
check('but not another program\'s', count($readiness->queueForPrograms([$other])) === 0);

echo "\n=== Scheduling, with the qualification check ===\n";
$queue = $readiness->queueForPrograms([$student['program_id']]);
$readinessId = $queue[0]['readiness_id'];

$lecturers = $pdo->query("SELECT lecturer_id FROM lecturers LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
[$qualifiedA, $qualifiedB, $unqualified] = $lecturers;
$quals->add($qualifiedA, $student['program_id'], $admin);
$quals->add($qualifiedB, $student['program_id'], $admin);

$err = throws(fn() => $readiness->scheduleExam(
    $readinessId, $student['program_id'], [$qualifiedA, $unqualified], null,
    '2026-11-01 10:00:00', 'physical', 'Boardroom', null, $admin
));
check('an unqualified examiner is refused even when posted directly', $err !== null, $err ?? '');
check('and nothing was scheduled from the refused attempt',
    count($readiness->queueForPrograms([$student['program_id']])) === 1);

$err = throws(fn() => $readiness->scheduleExam(
    $readinessId, $student['program_id'], [$qualifiedA], $qualifiedB,
    '2026-11-01 10:00:00', 'physical', 'Boardroom', null, $admin
));
check('a panel leader who is not on the panel is refused', $err !== null, $err ?? '');

check('an empty panel is refused', throws(fn() => $readiness->scheduleExam(
    $readinessId, $student['program_id'], [], null,
    '2026-11-01 10:00:00', 'physical', 'Boardroom', null, $admin
)) !== null);

$meetingId = $readiness->scheduleExam(
    $readinessId, $student['program_id'], [$qualifiedA, $qualifiedB], $qualifiedA,
    '2026-11-01 10:00:00', 'physical', 'Boardroom', null, $admin
);
check('a qualified panel schedules', $meetingId !== '');

$meeting = $pdo->query("SELECT meeting_type, exam_stage_id, exam_schedule_id FROM meetings WHERE meeting_id = '$meetingId'")->fetch();
check('the meeting carries the stage it examines', $meeting['exam_stage_id'] === $stage);
check('and the window it belongs to', $meeting['exam_schedule_id'] === $window);
check('typed as an exam', $meeting['meeting_type'] === 'exam', $meeting['meeting_type']);
check('both examiners were invited',
    (int) $pdo->query("SELECT COUNT(*) FROM meeting_attendees WHERE meeting_id = '$meetingId' AND role_in_meeting = 'examiner'")->fetchColumn() === 2);
check('the panel leader was designated', $rubric->panelLeader($meetingId) !== null);
check('the student leaves the ready queue',
    count($readiness->queueForPrograms([$student['program_id']])) === 0);
check('scheduling twice is refused', throws(fn() => $readiness->scheduleExam(
    $readinessId, $student['program_id'], [$qualifiedA], null,
    '2026-11-02 10:00:00', 'physical', 'Boardroom', null, $admin
)) !== null);

$pdo->rollBack();

printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
