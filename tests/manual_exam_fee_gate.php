<?php

/**
 * Manual exercise of the exam fee gate against the real database,
 * wrapped in a transaction that is always rolled back.
 *
 * The gate holds a student back only for what the Thesis Fees page says
 * they owe (ThesisRegistration::computeOwed), so the two pages cannot
 * disagree. The cases that matter: a waived (zero) or unset fee is not
 * owed; a charged one blocks until paid in full; a pending payment
 * blocks but reads as pending, not unpaid; a rejected payment reads as
 * rejected; review and document fees start once a supervisor is
 * appointed, and a review fee paid for its year counts even though the
 * payment names no exam window.
 *
 * Run: php tests/manual_exam_fee_gate.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Models\ExamFeeGate;
use App\Models\ThesisRegistration;

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
$registrations = new ThesisRegistration($pdo);

// ---- a registered student, with a clean fee history --------------------
$reg = $pdo->query(
    "SELECT str.*, ts.program_id, ts.enrollment_start_date
     FROM student_thesis_registrations str
     JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
     WHERE str.status = 'active' AND ts.enrollment_start_date <= NOW()
     LIMIT 1"
)->fetch();
$regId = $reg['thesis_registration_id'];
$pdo->prepare("DELETE FROM thesis_payments WHERE thesis_registration_id = ?")->execute([$regId]);
$pdo->prepare("DELETE FROM document_payment WHERE thesis_registration_id = ?")->execute([$regId]);
$pdo->prepare("UPDATE supervision_assignments SET is_active = 0 WHERE student_id = ?")->execute([$reg['student_id']]);
$pdo->prepare("UPDATE thesis_proposals SET assigned_supervisor_id = NULL WHERE student_id = ?")->execute([$reg['student_id']]);

// The schedule's registration rate, which the test sets as it goes.
$regRate = $pdo->prepare("SELECT rate_id FROM thesis_registration_rates WHERE program_id = ?");
$regRate->execute([$reg['program_id']]);
$regRateId = $regRate->fetchColumn();
if (!$regRateId) {
    $regRateId = $pdo->query("SELECT UUID()")->fetchColumn();
    $pdo->prepare("INSERT INTO thesis_registration_rates (rate_id, program_id, amount, currency, due_after_weeks) VALUES (?, ?, 0, 'KES', 1)")
        ->execute([$regRateId, $reg['program_id']]);
}
$pdo->prepare("UPDATE thesis_schedules SET thesis_registration_rates_id = ? WHERE schedule_id = ?")
    ->execute([$regRateId, $reg['thesis_schedule_id']]);
$setRegistrationFee = fn (float $amount) => $pdo->prepare("UPDATE thesis_registration_rates SET amount = ? WHERE rate_id = ?")
    ->execute([$amount, $regRateId]);

// A window of this schedule requiring one document, open for a month.
$window = $pdo->query("SELECT UUID()")->fetchColumn();
$pdo->prepare(
    "INSERT INTO exam_schedule (exam_schedule_id, thesis_schedule_id, starts_at, ends_at, exam_type, exam_schedule_description)
     VALUES (?, ?, NOW() - INTERVAL 30 DAY, NOW() + INTERVAL 30 DAY, 'internal', 'Fee gate test')"
)->execute([$window, $reg['thesis_schedule_id']]);
$docType = $pdo->query("SELECT doc_type_id FROM document_types LIMIT 1")->fetchColumn();
$docName = $pdo->query("SELECT doc_type_name FROM document_types WHERE doc_type_id = " . $pdo->quote($docType))->fetchColumn();
$pdo->prepare(
    "INSERT INTO exam_schedule_documents (esd_id, exam_schedule_id, document_type_id, document_submission_starts_at, document_submission_deadline)
     VALUES (UUID(), ?, ?, NOW() - INTERVAL 30 DAY, NOW() + INTERVAL 30 DAY)"
)->execute([$window, $docType]);
$setDocumentFee = function (float $amount) use ($pdo, $reg, $docType): void {
    $pdo->prepare(
        "INSERT INTO document_review_rates (rate_id, program_id, document_type_id, amount, currency, due_after_weeks)
         VALUES (UUID(), ?, ?, ?, 'KES', 1)
         ON DUPLICATE KEY UPDATE amount = VALUES(amount), due_after_weeks = 1"
    )->execute([$reg['program_id'], $docType, $amount]);
};
$setDocumentFee(0);

$paymentSeconds = 0;
$payThesis = function (string $feeType, float $amount, string $status, ?int $year = null) use ($pdo, $regId, &$paymentSeconds): string {
    $id = $pdo->query("SELECT UUID()")->fetchColumn();
    // As the Thesis Fees page records it: a year, never an exam window.
    // Each a second later than the last, so "newest" is unambiguous.
    $pdo->prepare(
        "INSERT INTO thesis_payments (thesis_payment_id, thesis_registration_id, exam_schedule_id, fee_type, thesis_year, amount, payment_method, status, created_at)
         VALUES (?, ?, NULL, ?, ?, ?, 'mpesa', ?, NOW() + INTERVAL ? SECOND)"
    )->execute([$id, $regId, $feeType, $year, $amount, $status, ++$paymentSeconds]);
    return $id;
};
$payDoc = function (float $amount, string $status) use ($pdo, $regId, $window, $docType): void {
    $pdo->prepare(
        "INSERT INTO document_payment (document_payment_id, thesis_registration_id, exam_schedule_id, document_type_id, amount, payment_method, status)
         VALUES (UUID(), ?, ?, ?, ?, 'mpesa', ?)"
    )->execute([$regId, $window, $docType, $amount, $status]);
};

$status = fn () => $gate->statusFor($reg['student_id'], $window);
$byLabel = fn (array $s) => array_column($s['items'], null, 'label');
// What the Thesis Fees page says is owed that this exam cares about.
$pageOwes = fn () => array_values(array_filter(
    $registrations->computeOwed($reg),
    fn ($o) => $o['fee_type'] !== 'document_review_fee' || $o['exam_schedule_id'] === $window
));

echo "\n=== A waived fee is not owed ===\n";
$setRegistrationFee(0);
$s = $status();
check('with the registration fee at zero, the gate is open', $s['clear'] === true);
check('and nothing reads as unpaid', !in_array('unpaid', array_column($s['items'], 'state'), true), implode(', ', array_column($s['items'], 'state')));
check('the registration fee says nothing is owed', $byLabel($s)['Thesis registration']['detail'] === 'Nothing owed.');
check('agreeing with the Thesis Fees page', $pageOwes() === []);

$pdo->prepare("UPDATE thesis_schedules SET thesis_registration_rates_id = NULL WHERE schedule_id = ?")->execute([$reg['thesis_schedule_id']]);
check('with no registration rate set at all, nothing is owed either', $status()['clear'] === true);
$pdo->prepare("UPDATE thesis_schedules SET thesis_registration_rates_id = ? WHERE schedule_id = ?")->execute([$regRateId, $reg['thesis_schedule_id']]);

echo "\n=== A charged registration fee ===\n";
$setRegistrationFee(1000);
$s = $status();
check('blocks until it is paid', $s['clear'] === false && $byLabel($s)['Thesis registration']['state'] === 'unpaid');
check('saying how much', str_contains($byLabel($s)['Thesis registration']['detail'], '1,000.00 not paid yet'));
check('as the Thesis Fees page does', count($pageOwes()) === 1);

$pending = $payThesis('thesis_registration', 1000, 'pending');
$s = $status();
check('a payment awaiting the registrar reads as pending, not unpaid', $byLabel($s)['Thesis registration']['state'] === 'pending');
check('still blocks', $s['clear'] === false);
check('and is described as being verified', $s['awaiting_verification'] === true
    && str_contains($byLabel($s)['Thesis registration']['detail'], 'waiting for the registrar'));

$pdo->prepare("UPDATE thesis_payments SET status = 'rejected' WHERE thesis_payment_id = ?")->execute([$pending]);
$s = $status();
check('a rejected payment reads as rejected', $byLabel($s)['Thesis registration']['state'] === 'unpaid'
    && str_contains($byLabel($s)['Thesis registration']['detail'], 'rejected'));

$payThesis('thesis_registration', 600, 'confirmed');
check('part of the fee is not enough', $status()['clear'] === false);
$payThesis('thesis_registration', 400, 'confirmed');
$s = $status();
check('paying the rest opens the gate', $s['clear'] === true);
check('and the fee reads as paid', $byLabel($s)['Thesis registration']['state'] === 'confirmed');

echo "\n=== Review and document fees, once a supervisor is appointed ===\n";
$setDocumentFee(500);
check('without a supervisor they are not charged yet', $status()['clear'] === true);

$supervisor = $pdo->query("SELECT lecturer_id FROM lecturers LIMIT 1")->fetchColumn();
$proposal = $pdo->query("SELECT proposal_id FROM thesis_proposals WHERE student_id = " . $pdo->quote($reg['student_id']) . " LIMIT 1")->fetchColumn();
$pdo->prepare(
    "INSERT INTO supervision_assignments (assignment_id, proposal_id, student_id, supervisor_id, role, appointed_by, appointment_date, is_active)
     VALUES (UUID(), ?, ?, ?, 'main', ?, CURDATE(), 1)"
)->execute([$proposal, $reg['student_id'], $supervisor, $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn()]);

$startYear = (int) date('Y', strtotime($reg['enrollment_start_date']));
$pdo->prepare(
    "INSERT INTO thesis_review_fee_rates (rate_id, program_id, academic_year, amount, currency, due_after_months, created_by)
     VALUES (UUID(), ?, ?, 6000, 'KES', 0, ?)
     ON DUPLICATE KEY UPDATE amount = 6000, due_after_months = 0"
)->execute([$reg['program_id'], $startYear, $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn()]);

$s = $status();
check('a supervisor appointed through a request counts — the fees start', $s['clear'] === false);
check('the review fee for the year is owed', $byLabel($s)['Thesis review fee']['state'] === 'unpaid');
check('and so is the document\'s review fee', $byLabel($s)[$docName . ' review fee']['state'] === 'unpaid');
check('matching the Thesis Fees page item for item', count($pageOwes()) === 2);

$payThesis('thesis_review_fee', 6000, 'confirmed', $startYear);
$s = $status();
check('a review fee paid for its year clears, though the payment names no exam window',
    $byLabel($s)['Thesis review fee']['state'] === 'confirmed');

$setDocumentFee(0);
check('a document fee set to zero is not owed', $status()['clear'] === true);
$setDocumentFee(500);
$payDoc(500, 'rejected');
check('a rejected document payment does not clear it', $status()['clear'] === false);
$payDoc(500, 'confirmed');
$s = $status();
check('a confirmed one does', $s['clear'] === true && $byLabel($s)[$docName . ' review fee']['state'] === 'confirmed');
check('and the Thesis Fees page agrees nothing is owed', $pageOwes() === []);

echo "\n=== A student with no thesis registration ===\n";
$stray = $pdo->query(
    "SELECT student_id FROM students
     WHERE student_id NOT IN (SELECT student_id FROM student_thesis_registrations) LIMIT 1"
)->fetchColumn();
if ($stray) {
    $s = $gate->statusFor($stray, $window);
    check('the gate is closed and says why', $s['clear'] === false
        && str_contains($s['items'][0]['detail'], 'not registered for a thesis'));
} else {
    check('skipped — every student is registered', true, 'no unregistered student to test');
}

$pdo->rollBack();

printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
