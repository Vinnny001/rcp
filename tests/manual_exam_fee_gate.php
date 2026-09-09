<?php

/**
 * Manual exercise of the exam fee gate against the real database,
 * wrapped in a transaction that is always rolled back.
 *
 * Three charges have to clear before a student can sit an exam:
 * thesis registration, the thesis review fee for that exam schedule,
 * and a review fee per document the schedule requires. The cases that
 * matter are the partial ones — a pending payment blocks but must not
 * be reported as unpaid, and a rejected-then-paid history must read as
 * paid rather than as the most recent failure.
 *
 * Run: php tests/manual_exam_fee_gate.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Models\ExamFeeGate;

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

$gate = new ExamFeeGate($pdo);

$reg = $pdo->query(
    "SELECT str.thesis_registration_id, str.student_id, ts.program_id
     FROM student_thesis_registrations str
     JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id LIMIT 1"
)->fetch();

// An exam schedule that actually requires documents, so the per-document
// half of the gate is exercised rather than skipped.
$schedule = $pdo->query(
    "SELECT esd.exam_schedule_id, COUNT(*) doc_count
     FROM exam_schedule_documents esd
     GROUP BY esd.exam_schedule_id ORDER BY doc_count DESC LIMIT 1"
)->fetch();
$docTypes = $pdo->query(
    "SELECT document_type_id FROM exam_schedule_documents WHERE exam_schedule_id = '{$schedule['exam_schedule_id']}'"
)->fetchAll(PDO::FETCH_COLUMN);

$payThesis = function (string $feeType, string $status, ?string $scheduleId) use ($pdo, $reg): void {
    $pdo->prepare(
        "INSERT INTO thesis_payments
            (thesis_payment_id, thesis_registration_id, exam_schedule_id, fee_type, amount, payment_method, status)
         VALUES (UUID(), ?, ?, ?, 1000, 'mpesa', ?)"
    )->execute([$reg['thesis_registration_id'], $scheduleId, $feeType, $status]);
};
$payDoc = function (string $docTypeId, string $status) use ($pdo, $reg, $schedule): void {
    $pdo->prepare(
        "INSERT INTO document_payment
            (document_payment_id, thesis_registration_id, exam_schedule_id, document_type_id, amount, payment_method, status)
         VALUES (UUID(), ?, ?, ?, 500, 'mpesa', ?)"
    )->execute([$reg['thesis_registration_id'], $schedule['exam_schedule_id'], $docTypeId, $status]);
};

echo "\n=== Nothing paid ===\n";
$s = $gate->statusFor($reg['student_id'], $schedule['exam_schedule_id']);
check('the gate is closed', $s['clear'] === false);
check('every charge is listed', count($s['items']) === 2 + count($docTypes), count($s['items']) . ' items for ' . count($docTypes) . ' documents');
check('all read as unpaid', array_unique(array_column($s['items'], 'state')) === ['unpaid']);
check('it is not described as awaiting verification', $s['awaiting_verification'] === false);

echo "\n=== Registration paid, review fee pending ===\n";
$payThesis('thesis_registration', 'confirmed', null);
$payThesis('thesis_review_fee', 'pending', $schedule['exam_schedule_id']);
$s = $gate->statusFor($reg['student_id'], $schedule['exam_schedule_id']);
$byLabel = array_column($s['items'], null, 'label');
check('registration reads as confirmed', $byLabel['Thesis registration']['state'] === 'confirmed');
check('the review fee reads as pending, not unpaid', $byLabel['Thesis review fee']['state'] === 'pending');
check('a pending payment still blocks', $s['clear'] === false);
check('but the wording tells them it is being verified',
    str_contains($byLabel['Thesis review fee']['detail'], 'waiting for the registrar'));

echo "\n=== Document fees ===\n";
$payThesis('thesis_review_fee', 'confirmed', $schedule['exam_schedule_id']);
foreach (array_slice($docTypes, 0, -1) as $dt) {
    $payDoc($dt, 'confirmed');
}
$s = $gate->statusFor($reg['student_id'], $schedule['exam_schedule_id']);
check('one outstanding document fee still closes the gate', $s['clear'] === false);
$unpaid = array_values(array_filter($s['items'], fn($i) => $i['state'] === 'unpaid'));
check('and exactly that one is flagged', count($unpaid) === 1, $unpaid[0]['label'] ?? '?');

echo "\n=== A rejected payment followed by a good one ===\n";
$last = end($docTypes);
$payDoc($last, 'rejected');
$s = $gate->statusFor($reg['student_id'], $schedule['exam_schedule_id']);
check('a rejected payment does not open the gate', $s['clear'] === false);
$payDoc($last, 'confirmed');
$s = $gate->statusFor($reg['student_id'], $schedule['exam_schedule_id']);
check('paying again after a rejection clears it', $s['clear'] === true);
check('the confirmed payment wins over the later rejection record',
    array_unique(array_column($s['items'], 'state')) === ['confirmed']);

echo "\n=== A student with no thesis registration ===\n";
$stray = $pdo->query(
    "SELECT student_id FROM students
     WHERE student_id NOT IN (SELECT student_id FROM student_thesis_registrations) LIMIT 1"
)->fetchColumn();
if ($stray) {
    $s = $gate->statusFor($stray, $schedule['exam_schedule_id']);
    check('the gate is closed and says why', $s['clear'] === false
        && str_contains($s['items'][0]['detail'], 'not registered for a thesis'));
} else {
    check('skipped — every student is registered', true, 'no unregistered student to test');
}

$pdo->rollBack();

printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
