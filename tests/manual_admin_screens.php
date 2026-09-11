<?php

/**
 * Manual exercise of the three screens that stand between the built
 * system and anyone using it, against the real database and the real
 * templates, wrapped in a transaction that is always rolled back.
 *
 *   - tagging an exam window with the stage it examines, without which
 *     no student can ever mark themselves ready
 *   - editing a marking scheme's rows
 *   - a lecturer filling in the profile students shortlist from
 *
 * Run: php tests/manual_admin_screens.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Models\ExamReadiness;
use App\Models\ExamSchedule;
use App\Models\ExamStage;
use App\Models\Rubric;
use App\Models\SupervisorProfile;

$env = parse_ini_file(__DIR__ . '/../.env');
$pdo = new PDO(
    "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4",
    $env['DB_USER'],
    $env['DB_PASS'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$twig = \Slim\Views\Twig::create(__DIR__ . '/../src/Views', ['cache' => false]);
foreach (['profile_complete' => true, 'has_thesis_registration' => true, 'show_chat' => false,
          'unread_notifications_count' => 0, 'unread_chats_count' => 0,
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

echo "\n=== Tagging an exam window with a stage ===\n";
$examModel = new ExamSchedule($pdo);
$stages = (new ExamStage($pdo))->allActive();
$draftStage = null;
foreach ($stages as $s) {
    if ($s['code'] === 'thesis_draft') {
        $draftStage = $s;
    }
}

$student = $pdo->query(
    "SELECT st.student_id, st.user_id, ts.schedule_id
     FROM students st
     JOIN student_thesis_registrations str ON str.student_id = st.student_id
     JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
     JOIN thesis_proposals tp ON tp.student_id = st.student_id AND tp.status <> 'rejected'
     JOIN exam_schedule es ON es.thesis_schedule_id = ts.schedule_id LIMIT 1"
)->fetch();
$window = $pdo->query(
    "SELECT * FROM exam_schedule WHERE thesis_schedule_id = '{$student['schedule_id']}' LIMIT 1"
)->fetch();

$pdo->prepare("UPDATE exam_schedule SET exam_stage_id = NULL WHERE exam_schedule_id = ?")
    ->execute([$window['exam_schedule_id']]);
$readiness = new ExamReadiness($pdo);
$before = count($readiness->windowsFor($student['student_id'], $student['user_id']));

// This is the whole point of the screen: an untagged window is
// invisible, so without it nobody can ever declare readiness.
$examModel->update($window['exam_schedule_id'], [
    'thesis_schedule_id'        => $window['thesis_schedule_id'],
    'starts_at'                 => $window['starts_at'],
    'ends_at'                   => $window['ends_at'],
    'exam_type'                 => $window['exam_type'],
    'exam_stage_id'             => $draftStage['stage_id'],
    'exam_schedule_description' => $window['exam_schedule_description'],
]);
$after = count($readiness->windowsFor($student['student_id'], $student['user_id']));

check('an untagged window is invisible to the student', $before < $after, $before . ' -> ' . $after);
check('tagging it makes it visible', $after === $before + 1);
check('and the admin list shows which stage it examines',
    in_array($draftStage['name'], array_column($examModel->all(), 'stage_name'), true));

$examModel->update($window['exam_schedule_id'], [
    'thesis_schedule_id'        => $window['thesis_schedule_id'],
    'starts_at'                 => $window['starts_at'],
    'ends_at'                   => $window['ends_at'],
    'exam_type'                 => $window['exam_type'],
    'exam_stage_id'             => '',
    'exam_schedule_description' => $window['exam_schedule_description'],
]);
check('untagging it again hides it', count($readiness->windowsFor($student['student_id'], $student['user_id'])) === $before);

echo "\n=== Editing a marking scheme ===\n";
$rubric = new Rubric($pdo);
$template = null;
foreach ($rubric->allTemplates() as $t) {
    if ((int) $t['score_count'] === 0) {
        $template = $t;
    }
}
$template = $template ?: $rubric->allTemplates()[0];
$startTotal = (float) $template['max_total'];

$rubric->saveCriterion(null, $template['template_id'], [
    'section_name'  => 'Ethics',
    'criterion_text' => 'Is the ethics approval in place and appropriate?',
    'max_score'     => 4,
    'display_order' => 99,
]);
check('adding a row raises the scheme total',
    $rubric->maxTotalFor($template['template_id']) === $startTotal + 4,
    $startTotal . ' -> ' . $rubric->maxTotalFor($template['template_id']));

$added = null;
foreach ($rubric->criteriaFor($template['template_id']) as $c) {
    if ($c['section_name'] === 'Ethics') {
        $added = $c;
    }
}
$rubric->saveCriterion($added['criterion_id'], $template['template_id'], [
    'section_name'  => 'Ethics',
    'criterion_text' => 'Is the ethics approval in place?',
    'max_score'     => 6,
    'display_order' => 99,
]);
check('editing its maximum moves the total with it',
    $rubric->maxTotalFor($template['template_id']) === $startTotal + 6);

check('a zero maximum is refused', throws(fn() => $rubric->saveCriterion(null, $template['template_id'], [
    'section_name' => 'X', 'criterion_text' => 'Y', 'max_score' => 0, 'display_order' => 1,
])) !== null);
check('a blank criterion is refused', throws(fn() => $rubric->saveCriterion(null, $template['template_id'], [
    'section_name' => '', 'criterion_text' => '', 'max_score' => 5, 'display_order' => 1,
])) !== null);

$rubric->deleteCriterion($added['criterion_id']);
check('removing it returns the total to where it started',
    $rubric->maxTotalFor($template['template_id']) === $startTotal);

// A row someone has been marked against must not vanish underneath them.
$scored = $pdo->query(
    "SELECT rc.criterion_id FROM rubric_criteria rc
     JOIN rubric_scores rs ON rs.criterion_id = rc.criterion_id LIMIT 1"
)->fetchColumn();
if (!$scored) {
    $anyCriterion = $rubric->criteriaFor($template['template_id'])[0];
    $meetingId = $pdo->query("SELECT meeting_id FROM meetings LIMIT 1")->fetchColumn();
    $examinerId = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();
    $pdo->prepare("INSERT INTO rubric_scores (rubric_score_id, meeting_id, criterion_id, examiner_id, score)
                   VALUES (UUID(), ?, ?, ?, 1)")
        ->execute([$meetingId, $anyCriterion['criterion_id'], $examinerId]);
    $scored = $anyCriterion['criterion_id'];
}
$err = throws(fn() => $rubric->deleteCriterion($scored));
check('a row already marked against cannot be removed', $err !== null, $err ?? '');

echo "\n=== A lecturer filling in their profile ===\n";
$lecturer = $pdo->query(
    "SELECT l.lecturer_id, l.user_id FROM lecturers l
     JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id LIMIT 1"
)->fetch();

$pdo->prepare("UPDATE internal_lecturers SET research_interests = ? WHERE lecturer_id = ?")
    ->execute(['Distributed systems, and the teaching of them.', $lecturer['lecturer_id']]);
$pdo->prepare("INSERT INTO lecturer_publication_links (link_id, lecturer_id, platform, label, url)
               VALUES (UUID(), ?, 'google_scholar', NULL, 'https://scholar.google.com/citations?user=demo')")
    ->execute([$lecturer['lecturer_id']]);

$profiles = (new SupervisorProfile($pdo))->browsable();
$mine = null;
foreach ($profiles as $p) {
    if ($p['lecturer_id'] === $lecturer['lecturer_id']) {
        $mine = $p;
    }
}
check('the interests reach the student browse page',
    $mine !== null && str_contains((string) $mine['research_interests'], 'Distributed systems'));
check('and so does the link', $mine !== null && count($mine['links']) === 1);

$studentHtml = (string) $twig->render(new \Slim\Psr7\Response(), 'students/supervisors.twig', [
    'active_page' => 'supervisors', 'first_name' => 'S', 'student_number' => 'X',
    'proposal' => ['proposal_id' => 'p', 'status' => 'submitted'],
    'proposal_submitted' => true,
    'old' => [],
    'shortlist' => null, 'choices' => [], 'supervisors' => $profiles, 'can_resubmit' => true,
    'max_choices' => 5, 'max_supervisors' => 3,
    'csrf_token' => 't', 'error' => null, 'success' => null,
])->getBody();
check('a student actually sees the interests', str_contains($studentHtml, 'Distributed systems'));
check('and the link is clickable', str_contains($studentHtml, 'scholar.google.com'));

$pdo->rollBack();

printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
