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
// Tag the window with the stage the student is on and keep it open: a
// student is only shown the exam for their current stage, within their
// time on the programme (StudentExamWindows).
$stage = (new \App\Models\StudentJourney($pdo))->currentExamStage($student['student_id'], $student['user_id'])['stage_id'] ?? null;
$pdo->prepare("UPDATE exam_schedule SET exam_stage_id = ?, ends_at = NOW() + INTERVAL 30 DAY WHERE exam_schedule_id = ?")->execute([$stage, $window]);

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

$studentVars = fn() => $readiness->bookingPage($student['student_id'], $student['user_id']) + [
    'active_page' => 'exam', 'first_name' => 'S', 'student_number' => $student['student_number'],
    'proposal' => null, 'internal_exam' => null, 'external_exam' => null, 'graduation' => null,
    'csrf_token' => 't', 'error' => null, 'success' => null,
];
$windowRow = $pdo->query("SELECT * FROM exam_schedule WHERE exam_schedule_id = '$window'")->fetch();
$spareName = $pdo->query("SELECT doc_type_name FROM document_types WHERE doc_type_id = '$spare'")->fetchColumn();
$stageName = $pdo->query("SELECT name FROM exam_stages WHERE stage_id = " . $pdo->quote((string) $stage))->fetchColumn();

// A second date for the same stage, needing no documents.
$otherDate = $pdo->query("SELECT UUID()")->fetchColumn();
$pdo->prepare("INSERT INTO exam_schedule (exam_schedule_id, thesis_schedule_id, starts_at, ends_at, exam_type, exam_stage_id, exam_schedule_description)
               VALUES (?, ?, NOW() + INTERVAL 40 DAY, NOW() + INTERVAL 40 DAY, 'external', ?, 'Oral defence')")
    ->execute([$otherDate, $student['schedule_id'], $stage]);

echo "\n=== Student page: choosing a date ===\n";
$h = render($twig, 'students/exam.twig', $studentVars());
check('the exams section says which stage they are on', str_contains($h, 'Your exams') && str_contains($h, 'You are on <span class="strong">' . htmlspecialchars((string) $stageName)));
check('with a calendar of the dates on offer', str_contains($h, 'class="cal-grid"') && substr_count($h, 'class="cal-mark') > 0);
check('and each date listed with its details', str_contains($h, ucfirst($windowRow['exam_type']) . ' examination')
    && str_contains($h, 'External examination') && str_contains($h, 'Oral defence'));
check('what each requires', str_contains($h, 'Requires') && str_contains($h, $spareName) && str_contains($h, 'No documents required'));
check('and a button to book each', substr_count($h, 'action="/student/exam/book"') === 2 && str_contains($h, 'Book this date'));
check('nothing is booked yet', !str_contains($h, 'Your booked exam'));

$empty = $studentVars();
$empty['exam_windows'] = [];
$empty['calendar'] = [];
check('with no dates open, the page says so rather than showing nothing',
    str_contains(render($twig, 'students/exam.twig', $empty), 'exams open for booking'));

echo "\n=== Student page: booked ===\n";
$readiness->book($student['student_id'], $student['user_id'], $window);
$h = render($twig, 'students/exam.twig', $studentVars());
check('the booked exam leads the page', str_contains($h, 'Your booked exam'));
check('with its details and that it is not scheduled yet', str_contains($h, date('d M Y', strtotime($windowRow['starts_at']))) && str_contains($h, 'Not scheduled yet'));
check('the documents it requires, with where each stands', str_contains($h, 'Documents required')
    && (bool) preg_match('/' . preg_quote($spareName, '/') . '.*?(Not submitted|Opens |Deadline passed)/s', $h));
check('what is outstanding before the coordinator can schedule it', str_contains($h, 'Outstanding before your coordinator can schedule your exam')
    && str_contains($h, 'has not been submitted'));
check('a way to cancel', str_contains($h, 'action="/student/exam/cancel-booking"'));
check('and the other date is offered as a switch', str_contains($h, 'Switch to this date'));

$upload($spare, 'draft');
$h = render($twig, 'students/exam.twig', $studentVars());
check('an uploaded draft reads as a draft, not as submitted', (bool) preg_match('/' . preg_quote($spareName, '/') . '.*?>Draft</s', $h));
check('and still holds them back, saying why', str_contains($h, $spareName . ' is still a draft'));
check('the fee that is charged shows as not paid', str_contains($h, 'Thesis registration is not paid'));

echo "\n=== Switching dates ===\n";
$readiness->book($student['student_id'], $student['user_id'], $otherDate);
$booking = $studentVars();
check('booking another date moves them to it', ($booking['booked']['exam_schedule_id'] ?? null) === $otherDate);
check('holding one booking for the stage, not two',
    (int) $pdo->query("SELECT COUNT(*) FROM exam_readiness WHERE student_id = '{$student['student_id']}' AND exam_schedule_id IN ('$window', '$otherDate')")->fetchColumn() === 1);
$readiness->cancelBooking($student['student_id'], $otherDate);
check('a booking can be cancelled', $studentVars()['booked'] === null);
$readiness->book($student['student_id'], $student['user_id'], $window);

echo "\n=== Student page: settled ===\n";
$pdo->prepare("INSERT INTO thesis_payments (thesis_payment_id, thesis_registration_id, exam_schedule_id, fee_type, amount, payment_method, status)
               VALUES (UUID(),?,NULL,'thesis_registration',1000,'mpesa','confirmed')")->execute([$student['thesis_registration_id']]);
foreach ($pdo->query("SELECT document_type_id FROM exam_schedule_documents WHERE exam_schedule_id='$window'")->fetchAll(PDO::FETCH_COLUMN) as $dt) {
    $pdo->prepare("INSERT INTO document_payment (document_payment_id, thesis_registration_id, exam_schedule_id, document_type_id, amount, payment_method, status)
                   VALUES (UUID(),?,?,?,500,'mpesa','confirmed')")->execute([$student['thesis_registration_id'], $window, $dt]);
    $upload($dt, 'submitted');
}
$h = render($twig, 'students/exam.twig', $studentVars());
check('every required document shows as submitted', !str_contains($h, 'Not submitted') && str_contains($h, '>Submitted<'));
check('and it says the coordinator will schedule the exam', str_contains($h, 'your coordinator will schedule your exam')
    && !str_contains($h, 'Outstanding before'));

echo "\n=== Coordinator queue ===\n";
$coordUser = $pdo->query("SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id=u.user_id WHERE u.user_id <> '{$student['user_id']}' LIMIT 1")->fetchColumn();
$rc->assign($student['program_id'], $coordUser, $admin);
// Real students book too; only this student's row matters here.
$queueFor = function () use ($readiness, $quals, $student): array {
    $rows = array_values(array_filter(
        $readiness->queueForPrograms([$student['program_id']]),
        fn ($row) => $row['student_id'] === $student['student_id']
    ));
    foreach ($rows as &$row) {
        $row['examiners'] = $quals->qualifiedForProgram($row['program_id']);
        $row['blockers'] = $readiness->window($row['student_id'], $row['student_user_id'], $row['exam_schedule_id'])['blockers'] ?? [];
    }
    return $rows;
};

$coordVars = fn(array $q) => [
    'active_page' => 'l-coordinator-exams', 'first_name' => 'C', 'last_name' => 'O',
    'programs' => $rc->programsForUser($coordUser), 'queue' => $q,
    'csrf_token' => 't', 'error' => null, 'success' => null,
];

$h = render($twig, 'coordinators/exams.twig', $coordVars($queueFor()));
check('the booked student appears in the queue', str_contains($h, $student['student_number']) && str_contains($h, 'booked'));
if ($pdo->query("SELECT COUNT(*) FROM examiner_program_qualifications WHERE program_id = '{$student['program_id']}'")->fetchColumn() == 0) {
    check('with no qualified examiners, scheduling is refused with a reason', str_contains($h, 'No lecturer is qualified'));
    check('and no schedule form is offered', !str_contains($h, 'Schedule the exam'));
}

$lecturers = $pdo->query("SELECT lecturer_id FROM lecturers WHERE user_id <> '{$student['user_id']}' LIMIT 2")->fetchAll(PDO::FETCH_COLUMN);
foreach ($lecturers as $l) { $quals->add($l, $student['program_id'], $admin); }
$h = render($twig, 'coordinators/exams.twig', $coordVars($queueFor()));
check('with qualified examiners and nothing outstanding, the form appears', str_contains($h, 'Schedule the exam'));
check('offering the qualified ones', substr_count($h, 'name="examiner_ids[]"') === count($quals->qualifiedForProgram($student['program_id'])));
check('and a panel leader picker', str_contains($h, 'name="panel_leader_id"'));

$pdo->prepare("DELETE FROM thesis_payments WHERE thesis_registration_id = ?")->execute([$student['thesis_registration_id']]);
$h = render($twig, 'coordinators/exams.twig', $coordVars($queueFor()));
check('while something is outstanding, the queue says what and offers no form',
    str_contains($h, 'Waiting on the student') && str_contains($h, 'Thesis registration is not paid') && !str_contains($h, 'Schedule the exam'));
$pdo->prepare("INSERT INTO thesis_payments (thesis_payment_id, thesis_registration_id, exam_schedule_id, fee_type, amount, payment_method, status)
               VALUES (UUID(),?,NULL,'thesis_registration',1000,'mpesa','confirmed')")->execute([$student['thesis_registration_id']]);

echo "\n=== Student page: scheduled ===\n";
$panel = $pdo->query("SELECT l.lecturer_id FROM lecturers l JOIN examiner_program_qualifications q ON q.lecturer_id = l.lecturer_id
                      WHERE q.program_id = '{$student['program_id']}' AND l.user_id <> '{$student['user_id']}' LIMIT 1")->fetchColumn();
$readinessId = $pdo->query("SELECT readiness_id FROM exam_readiness WHERE student_id = '{$student['student_id']}' AND exam_schedule_id = '$window'")->fetchColumn();
$readiness->scheduleExam($readinessId, $student['program_id'], [$panel], null, '2026-10-21 10:30:00', 'hybrid', 'Senate Room', 'https://meet.example/exam', $coordUser);
$h = render($twig, 'students/exam.twig', $studentVars());
check('the student sees when and where the exam is',
    str_contains($h, '21 Oct 2026, 10:30 AM') && str_contains($h, 'Hybrid') && str_contains($h, 'Senate Room') && str_contains($h, 'https://meet.example/exam'));
check('and that it is scheduled', str_contains($h, 'Exam scheduled'));
check('the booking can no longer be cancelled', !str_contains($h, 'action="/student/exam/cancel-booking"'));
check('or switched', str_contains($h, 'Your exam is already scheduled') && !str_contains($h, 'Switch to this date'));
$err = null;
try { $readiness->book($student['student_id'], $student['user_id'], $otherDate); } catch (\Throwable $e) { $err = $e->getMessage(); }
check('switching is refused by the model too', str_contains((string) $err, 'already scheduled'), (string) $err);

$pdo->rollBack();
printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back\n";
exit($fail === 0 ? 0 : 1);
