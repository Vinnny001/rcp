<?php

/**
 * Manual exercise of an exam meeting after scheduling — its roles,
 * minutes and one-off documents — against the real database and the
 * real template, wrapped in a transaction that is always rolled back.
 *
 * Exam minutes follow the same rule shortlist minutes do: the
 * secretary writes them, the lead does when nobody was named, and the
 * coordinator approves. That rule now lives in two classes, so it is
 * worth checking it behaves the same in both.
 *
 * Run: php tests/manual_exam_meeting.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Models\ExamMeeting;

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

$model = new ExamMeeting($pdo);

$meeting = $pdo->query(
    "SELECT m.meeting_id, m.created_by, m.exam_schedule_id
     FROM meetings m WHERE m.exam_stage_id IS NOT NULL LIMIT 1"
)->fetch();
$meetingId = $meeting['meeting_id'];

$users = $pdo->query("SELECT u.user_id FROM users u JOIN lecturers l ON l.user_id = u.user_id LIMIT 3")->fetchAll(PDO::FETCH_COLUMN);
[$coordinator, $examA, $examB] = $users;

foreach ([$examA, $examB] as $uid) {
    $pdo->prepare("INSERT IGNORE INTO meeting_attendees (attendee_id, meeting_id, user_id, role_in_meeting)
                   VALUES (UUID(), ?, ?, 'examiner')")->execute([$meetingId, $uid]);
}
$pdo->prepare("UPDATE meetings SET lead_user_id = ?, secretary_user_id = NULL WHERE meeting_id = ?")
    ->execute([$coordinator, $meetingId]);

echo "\n=== Who writes the minutes ===\n";
check('with no secretary, the lead does', $model->minutesAuthorId($meetingId) === $coordinator);

$model->setRoles($meetingId, $examA, $examB);
check('naming a secretary hands the pen over', $model->minutesAuthorId($meetingId) === $examB);
check('and not to the lead', $model->minutesAuthorId($meetingId) !== $examA);

$model->setRoles($meetingId, $examA, null);
check('clearing the secretary hands it back to the lead', $model->minutesAuthorId($meetingId) === $examA);

$model->setRoles($meetingId, null, null);
check('naming nobody leaves the existing lead in place', $model->minutesAuthorId($meetingId) === $examA);

echo "\n=== Minutes, then approval ===\n";
check('cannot approve before finalising',
    throws(fn() => $model->approveMinutes($meetingId, $coordinator)) !== null);

$model->saveMinutes($meetingId, 'Panel questioned the sampling strategy.', false);
check('a draft does not count as finalised', $model->find($meetingId)['minutes_finalized_at'] === null);
check('and still cannot be approved',
    throws(fn() => $model->approveMinutes($meetingId, $coordinator)) !== null);

$model->saveMinutes($meetingId, 'Panel questioned the sampling strategy.', true);
check('finalising records the time', $model->find($meetingId)['minutes_finalized_at'] !== null);

$model->approveMinutes($meetingId, $coordinator);
$after = $model->find($meetingId);
check('approval is recorded with who gave it',
    $after['minutes_approved_at'] !== null && $after['minutes_approved_by'] === $coordinator);
check('approving twice is harmless',
    throws(fn() => $model->approveMinutes($meetingId, $coordinator)) === null);

echo "\n=== One-off documents ===\n";
$before = count($model->requiredDocumentsFor($meetingId));

$windowDoc = $pdo->query(
    "SELECT document_type_id FROM exam_schedule_documents
     WHERE exam_schedule_id = '{$meeting['exam_schedule_id']}' LIMIT 1"
)->fetchColumn();
if ($windowDoc) {
    $err = throws(fn() => $model->addExtraDocument($meetingId, $windowDoc, null, $coordinator));
    check('asking again for what the window already requires is refused', $err !== null, $err ?? '');
} else {
    check('asking again for what the window already requires is refused', true, 'window requires nothing');
}

$spare = $pdo->query(
    "SELECT doc_type_id FROM document_types
     WHERE doc_type_id NOT IN (SELECT document_type_id FROM exam_schedule_documents
                                WHERE exam_schedule_id = '{$meeting['exam_schedule_id']}') LIMIT 1"
)->fetchColumn();
$model->addExtraDocument($meetingId, $spare, 'Ethics clearance for the fieldwork', $coordinator);

$docs = $model->requiredDocumentsFor($meetingId);
check('the extra joins what this candidate owes', count($docs) === $before + 1, $before . ' -> ' . count($docs));
$extra = array_values(array_filter($docs, fn($d) => (int) $d['is_extra'] === 1));
check('marked as asked of them specifically', count($extra) === 1);
check('with the reason the student will read',
    $extra[0]['note'] === 'Ethics clearance for the fieldwork');
check('and showing as not yet submitted', $extra[0]['is_submitted'] === false);

$model->addExtraDocument($meetingId, $spare, 'Updated wording', $coordinator);
check('adding the same one again updates rather than duplicates',
    count($model->extraDocumentsFor($meetingId)) === 1);
check('and takes the new wording',
    $model->extraDocumentsFor($meetingId)[0]['note'] === 'Updated wording');

echo "\n=== The coordinator page ===\n";
$m = $model->find($meetingId);
$html = (string) $twig->render(new \Slim\Psr7\Response(), 'coordinators/exam_meeting.twig', [
    'active_page' => 'l-coordinator-exams', 'first_name' => 'C', 'last_name' => 'O',
    'meeting' => $m,
    'documents' => $model->requiredDocumentsFor($meetingId),
    'extras' => $model->extraDocumentsFor($meetingId),
    'doc_types' => $pdo->query("SELECT doc_type_id, doc_type_name FROM document_types ORDER BY doc_type_name")->fetchAll(),
    'panel' => [], 'attendees' => [],
    'session_user_id' => $coordinator,
    'csrf_token' => 't', 'error' => null, 'success' => null,
])->getBody();

check('the page renders', strlen($html) > 0);
check('once finalised, the coordinator gets no draft buttons', !str_contains($html, 'Save draft'));
check('but can read what was written', str_contains($html, 'sampling strategy'));

// The "someone else holds the pen" notice only applies while the
// minutes are still open, so check that branch on its own.
$openHtml = (string) $twig->render(new \Slim\Psr7\Response(), 'coordinators/exam_meeting.twig', [
    'active_page' => 'l-coordinator-exams', 'first_name' => 'C', 'last_name' => 'O',
    'meeting' => array_merge($m, ['minutes_finalized_at' => null, 'minutes_approved_at' => null]),
    'documents' => $model->requiredDocumentsFor($meetingId),
    'extras' => $model->extraDocumentsFor($meetingId),
    'doc_types' => [], 'panel' => [], 'attendees' => [],
    'session_user_id' => $coordinator,
    'csrf_token' => 't', 'error' => null, 'success' => null,
])->getBody();
check('while open, it names who holds the pen', str_contains($openHtml, 'writes the minutes for this exam'));
check('and offers the coordinator no edit form', !str_contains($openHtml, 'Save draft'));

$authorHtml = (string) $twig->render(new \Slim\Psr7\Response(), 'coordinators/exam_meeting.twig', [
    'active_page' => 'l-coordinator-exams', 'first_name' => 'C', 'last_name' => 'O',
    'meeting' => array_merge($m, ['minutes_finalized_at' => null, 'minutes_approved_at' => null]),
    'documents' => [], 'extras' => [], 'doc_types' => [], 'panel' => [], 'attendees' => [],
    'session_user_id' => $m['minutes_author_id'],
    'csrf_token' => 't', 'error' => null, 'success' => null,
])->getBody();
check('the author does get one', str_contains($authorHtml, 'Save draft'));
check('the extra document is listed as theirs', str_contains($html, 'You, for this student'));
check('with a way to withdraw it', str_contains($html, 'No longer needed'));
check('minutes already approved are shown as such', str_contains($html, 'Minutes approved'));

$model->removeExtraDocument($model->extraDocumentsFor($meetingId)[0]['extra_doc_id']);
check('withdrawing it drops it from what they owe',
    count($model->requiredDocumentsFor($meetingId)) === $before);

$pdo->rollBack();

printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
