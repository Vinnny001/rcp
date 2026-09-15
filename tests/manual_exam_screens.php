<?php

/**
 * Manual exercise of the exam readiness and scheduling screens,
 * against the real database and the real templates, wrapped in a
 * transaction that is always rolled back.
 *
 * Walks each state a student and their coordinator actually see:
 * blocked, clear, declared, then queued — and the two states where a
 * coordinator cannot schedule at all, which should explain themselves
 * rather than just offering no button.
 *
 * Run: php tests/manual_exam_screens.php
 */

declare(strict_types=1);
require __DIR__ . '/../vendor/autoload.php';

use App\Models\ExamReadiness;
use App\Models\ExaminerQualification;
use App\Models\ResearchCoordinator;

$env = parse_ini_file(__DIR__ . '/../.env');
$pdo = new PDO("mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4", $env['DB_USER'], $env['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$twig = \Slim\Views\Twig::create(__DIR__ . '/../src/Views', ['cache' => false]);
foreach (['profile_complete' => true, 'has_thesis_registration' => true, 'show_chat' => false,
          'unread_notifications_count' => 0, 'unread_chats_count' => 0,
          'coordinator_programs' => [], 'is_department_head' => false] as $k => $v) {
    $twig->getEnvironment()->addGlobal($k, $v);
}

$pass = 0; $fail = 0;
function check(string $l, bool $ok, mixed $d = ''): void {
    global $pass, $fail; $ok ? $pass++ : $fail++; $d = (string) $d;
    printf("  %s  %s%s\n", $ok ? 'PASS' : 'FAIL', $l, $d !== '' ? "  ($d)" : '');
}
function render(\Slim\Views\Twig $t, string $tpl, array $v): string {
    return (string) $t->render(new \Slim\Psr7\Response(), $tpl, $v)->getBody();
}

$pdo->beginTransaction();

$readiness = new ExamReadiness($pdo);
$quals = new ExaminerQualification($pdo);
$rc = new ResearchCoordinator($pdo);
$admin = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();

$student = $pdo->query(
    "SELECT st.student_id, st.user_id, st.student_number, str.thesis_registration_id, ts.program_id, ts.schedule_id
     FROM students st
     JOIN student_thesis_registrations str ON str.student_id = st.student_id
     JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
     JOIN thesis_proposals tp ON tp.student_id = st.student_id AND tp.status <> 'rejected'
     JOIN exam_schedule es ON es.thesis_schedule_id = ts.schedule_id
     JOIN exam_schedule_documents esd ON esd.exam_schedule_id = es.exam_schedule_id LIMIT 1"
)->fetch();
$window = $pdo->query(
    "SELECT es.exam_schedule_id FROM exam_schedule es
     JOIN exam_schedule_documents esd ON esd.exam_schedule_id = es.exam_schedule_id
     WHERE es.thesis_schedule_id = '{$student['schedule_id']}' LIMIT 1"
)->fetchColumn();
$stage = $pdo->query("SELECT stage_id FROM exam_stages WHERE code = 'thesis_draft'")->fetchColumn();
$pdo->prepare("UPDATE exam_schedule SET exam_stage_id = ? WHERE exam_schedule_id = ?")->execute([$stage, $window]);

// Require a document the student has not submitted, so the blocked state is real.
$spare = $pdo->query("SELECT doc_type_id FROM document_types WHERE doc_type_id NOT IN
    (SELECT document_type_id FROM exam_schedule_documents WHERE exam_schedule_id='$window') LIMIT 1")->fetchColumn();
$pdo->prepare("INSERT INTO exam_schedule_documents (esd_id, exam_schedule_id, document_type_id) VALUES (UUID(),?,?)")
    ->execute([$window, $spare]);

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

$studentVars = fn() => [
    'active_page' => 'exam', 'first_name' => 'S', 'student_number' => $student['student_number'],
    'proposal' => null, 'internal_exam' => null, 'external_exam' => null, 'graduation' => null,
    'exam_windows' => $readiness->windowsFor($student['student_id'], $student['user_id']),
    'csrf_token' => 't', 'error' => null, 'success' => null,
];

echo "\n=== Student page: blocked ===\n";
$h = render($twig, 'students/exam.twig', $studentVars());
check('the exams section appears', str_contains($h, 'Your exams'));

$windowRow = $pdo->query("SELECT * FROM exam_schedule WHERE exam_schedule_id = '$window'")->fetch();
$spareName = $pdo->query("SELECT doc_type_name FROM document_types WHERE doc_type_id = '$spare'")->fetchColumn();
check('with the exam\'s details: type and window',
    str_contains($h, ucfirst($windowRow['exam_type']) . ' examination')
    && str_contains($h, date('d M Y', strtotime($windowRow['starts_at']))));
check('and that it is not scheduled yet', str_contains($h, 'Not scheduled yet'));
check('it lists the documents the exam requires', str_contains($h, 'Documents required') && str_contains($h, $spareName));
check('with where each one stands', (bool) preg_match('/' . preg_quote($spareName, '/') . '.*?(Not submitted|Opens |Deadline passed)/s', $h));

// A second window that needs no documents says so.
$noDocs = $pdo->query("SELECT UUID()")->fetchColumn();
$pdo->prepare("INSERT INTO exam_schedule (exam_schedule_id, thesis_schedule_id, starts_at, ends_at, exam_type, exam_stage_id, exam_schedule_description)
               VALUES (?, ?, '2026-11-02 09:00:00', '2026-11-20 17:00:00', 'external', ?, 'Oral defence')")
    ->execute([$noDocs, $student['schedule_id'], $stage]);
$h = render($twig, 'students/exam.twig', $studentVars());
check('an exam that requires no documents says so', str_contains($h, 'No documents are required for this exam.'));
check('and shows its own details', str_contains($h, 'External examination') && str_contains($h, 'Oral defence') && str_contains($h, '02 Nov 2026'));
$pdo->prepare("DELETE FROM exam_schedule WHERE exam_schedule_id = ?")->execute([$noDocs]);

$empty = $studentVars();
$empty['exam_windows'] = [];
check('with no exam set, the page says so rather than showing nothing',
    str_contains(render($twig, 'students/exam.twig', $empty), 'No exam set for your thesis timeline yet'));
$h = render($twig, 'students/exam.twig', $studentVars());
check('blockers are listed', str_contains($h, 'Outstanding before you can be examined'));
check('naming the missing document', str_contains($h, 'has not been submitted'));
check('and the ready button is withheld', !str_contains($h, 'I am ready to be examined'));

// A draft is not a submission.
$upload($spare, 'draft');
$h = render($twig, 'students/exam.twig', $studentVars());
check('an uploaded draft reads as a draft, not as submitted',
    (bool) preg_match('/' . preg_quote($spareName, '/') . '.*?>Draft</s', $h));
check('and still holds the student back, saying why', str_contains($h, $spareName . ' is still a draft'));
check('the fee that is charged shows as not paid', str_contains($h, 'Thesis registration is not paid'));

echo "\n=== Student page: clear ===\n";
$pdo->prepare("INSERT INTO thesis_payments (thesis_payment_id, thesis_registration_id, exam_schedule_id, fee_type, amount, payment_method, status)
               VALUES (UUID(),?,NULL,'thesis_registration',1000,'mpesa','confirmed')")->execute([$student['thesis_registration_id']]);
$pdo->prepare("INSERT INTO thesis_payments (thesis_payment_id, thesis_registration_id, exam_schedule_id, fee_type, amount, payment_method, status)
               VALUES (UUID(),?,?,'thesis_review_fee',1000,'mpesa','confirmed')")->execute([$student['thesis_registration_id'], $window]);
foreach ($pdo->query("SELECT document_type_id FROM exam_schedule_documents WHERE exam_schedule_id='$window'")->fetchAll(PDO::FETCH_COLUMN) as $dt) {
    $pdo->prepare("INSERT INTO document_payment (document_payment_id, thesis_registration_id, exam_schedule_id, document_type_id, amount, payment_method, status)
                   VALUES (UUID(),?,?,?,500,'mpesa','confirmed')")->execute([$student['thesis_registration_id'], $window, $dt]);
    $upload($dt, 'submitted');
}
$h = render($twig, 'students/exam.twig', $studentVars());
check('the ready button now appears', str_contains($h, 'I am ready to be examined'));
check('and every required document shows as submitted', !str_contains($h, 'Not submitted') && str_contains($h, '>Submitted<'));
check('and no blockers remain', !str_contains($h, 'Outstanding before you can be examined'));

echo "\n=== Student page: declared ===\n";
$readiness->markReady($student['student_id'], $student['user_id'], $window);
$h = render($twig, 'students/exam.twig', $studentVars());
check('it says who they are waiting on', str_contains($h, 'Waiting on your coordinator'));
check('and offers to withdraw', str_contains($h, 'I am not ready after all'));

echo "\n=== Coordinator queue ===\n";
$coordUser = $pdo->query("SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id=u.user_id LIMIT 1")->fetchColumn();
$rc->assign($student['program_id'], $coordUser, $admin);
$queue = $readiness->queueForPrograms([$student['program_id']]);
foreach ($queue as &$row) { $row['examiners'] = $quals->qualifiedForProgram($row['program_id']); }
unset($row);

$coordVars = fn(array $q) => [
    'active_page' => 'l-coordinator-exams', 'first_name' => 'C', 'last_name' => 'O',
    'programs' => $rc->programsForUser($coordUser), 'queue' => $q,
    'csrf_token' => 't', 'error' => null, 'success' => null,
];

$h = render($twig, 'coordinators/exams.twig', $coordVars($queue));
check('the student appears in the queue', str_contains($h, $student['student_number']));
check('with no qualified examiners, scheduling is refused with a reason',
    str_contains($h, 'No lecturer is qualified'));
check('and no schedule form is offered', !str_contains($h, 'Schedule the exam'));

$lecturers = $pdo->query("SELECT lecturer_id FROM lecturers LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
foreach ($lecturers as $l) { $quals->add($l, $student['program_id'], $admin); }
$queue = $readiness->queueForPrograms([$student['program_id']]);
foreach ($queue as &$row) { $row['examiners'] = $quals->qualifiedForProgram($row['program_id']); }
unset($row);

$h = render($twig, 'coordinators/exams.twig', $coordVars($queue));
check('with qualified examiners the form appears', str_contains($h, 'Schedule the exam'));
check('offering exactly the qualified ones', substr_count($h, 'name="examiner_ids[]"') === 2, substr_count($h, 'name="examiner_ids[]"'));
check('and a panel leader picker', str_contains($h, 'name="panel_leader_id"'));

echo "\n=== Student page: scheduled ===\n";
$panel = $pdo->query("SELECT l.lecturer_id FROM lecturers l JOIN examiner_program_qualifications q ON q.lecturer_id = l.lecturer_id
                      WHERE q.program_id = '{$student['program_id']}' AND l.user_id <> '{$student['user_id']}' LIMIT 1")->fetchColumn();
$readinessId = $pdo->query("SELECT readiness_id FROM exam_readiness WHERE student_id = '{$student['student_id']}' AND exam_schedule_id = '$window'")->fetchColumn();
$scheduleBy = $pdo->query("SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id = u.user_id WHERE u.user_id <> '{$student['user_id']}' LIMIT 1")->fetchColumn();
$readiness->scheduleExam($readinessId, $student['program_id'], [$panel], null, '2026-10-21 10:30:00', 'hybrid', 'Senate Room', 'https://meet.example/exam', $scheduleBy);
$h = render($twig, 'students/exam.twig', $studentVars());
check('the student sees when and where the exam is',
    str_contains($h, '21 Oct 2026, 10:30 AM') && str_contains($h, 'Hybrid') && str_contains($h, 'Senate Room') && str_contains($h, 'https://meet.example/exam'));
check('and that it is scheduled', str_contains($h, 'Exam scheduled'));

$pdo->rollBack();
printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back\n";
exit($fail === 0 ? 0 : 1);
