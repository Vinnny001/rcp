<?php

/**
 * The shortlist form as a student meets it.
 *
 *   - a draft proposal is not something the department can act on, so
 *     it carries no shortlist
 *   - the order is a list they arrange, not a number they type
 *   - naming a preferred main is optional, and undoable
 *
 * Run: php tests/manual_shortlist_form.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use App\Models\Proposal;
use App\Models\SupervisorProfile;
use App\Models\SupervisorShortlist;

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

function render(\Slim\Views\Twig $twig, array $extra): string
{
    return (string) $twig->render(new \Slim\Psr7\Response(), 'students/supervisors.twig', array_merge([
        'active_page' => 'supervisors', 'first_name' => 'S', 'student_number' => 'X',
        'proposal' => null, 'proposal_submitted' => false,
        'shortlist' => null, 'choices' => [], 'supervisors' => [], 'can_resubmit' => true,
        'max_choices' => SupervisorShortlist::MAX_CHOICES,
        'max_supervisors' => SupervisorShortlist::MAX_SUPERVISORS,
        'old' => [], 'csrf_token' => 't', 'error' => null, 'success' => null,
    ], $extra))->getBody();
}

$pdo->beginTransaction();

$profiles = (new SupervisorProfile($pdo))->browsable();

echo "\n=== A draft proposal carries no shortlist ===\n";

check('a draft does not count as submitted', !Proposal::isSubmitted(['status' => 'draft']));
check('a submitted one does', Proposal::isSubmitted(['status' => 'submitted']));
check('so does one under review', Proposal::isSubmitted(['status' => 'under_review']));
check('and no proposal at all is not submitted', !Proposal::isSubmitted(null));

$draftPage = render($twig, [
    'proposal' => ['proposal_id' => 'p', 'status' => 'draft'],
    'proposal_submitted' => false,
    'supervisors' => $profiles,
]);
check('a student with only a draft is offered no supervisors to pick',
    !str_contains($draftPage, 'js-add'));
check('and is told to submit the proposal first',
    str_contains($draftPage, 'still a draft') && str_contains($draftPage, '/student/proposal'));

$noProposalPage = render($twig, ['proposal' => null, 'proposal_submitted' => false]);
check('a student with no proposal gets the other message',
    str_contains($noProposalPage, 'Write your thesis proposal'));

$livePage = render($twig, [
    'proposal' => ['proposal_id' => 'p', 'status' => 'submitted'],
    'proposal_submitted' => true,
    'supervisors' => $profiles,
]);
check('once submitted, the form appears', str_contains($livePage, 'js-add'));

echo "\n=== Ordering is a list you arrange, not a number you type ===\n";

check('the page says they are asked one at a time in the student\'s order',
    str_contains($livePage, 'one at a time, in your'));
// Nobody types or picks a position, so two supervisors sharing one is
// not something a student can express in the first place.
check('no position is typed or picked anywhere',
    !str_contains($livePage, 'name="rank[') && !str_contains($livePage, '>1st<'));
check('the chosen list is what carries the order',
    str_contains($livePage, 'id="chosenList"'));
check('and it can be dragged or nudged with arrows',
    str_contains($livePage, 'draggable') && str_contains($livePage, 'Move up'));
check('supervisors are added to it rather than ranked in place',
    str_contains($livePage, 'Add to shortlist'));

echo "\n=== Profiles open on demand, not inline ===\n";

check('each card offers a profile to open',
    str_contains($livePage, 'data-profile="' . $profiles[0]['lecturer_id'] . '"'));
check('the profile dialog carries the long detail instead',
    str_contains($livePage, 'id="profile-' . $profiles[0]['lecturer_id'] . '"')
    && str_contains($livePage, 'Research interests'));
check('and a supervisor can be added straight from it',
    str_contains($livePage, 'js-add-from-profile'));

echo "\n=== Preferred main is optional and undoable ===\n";

check('the page says naming one is optional', str_contains($livePage, 'optional'));
check('there is a way back to no preference',
    str_contains($livePage, 'id="noPreferred"'));
check('and it is the state the form opens in',
    (bool) preg_match('/id="noPreferred"[^>]*checked/', $livePage));

$student = $pdo->query(
    "SELECT s.student_id, s.user_id FROM students s
     JOIN thesis_proposals tp ON tp.student_id = s.student_id LIMIT 1"
)->fetch();
$proposalId = $pdo->query(
    "SELECT proposal_id FROM thesis_proposals WHERE student_id = " . $pdo->quote($student['student_id']) . " LIMIT 1"
)->fetchColumn();
$lects = $pdo->query(
    "SELECT lecturer_id FROM lecturers WHERE user_id <> " . $pdo->quote($student['user_id']) . " LIMIT 3"
)->fetchAll(PDO::FETCH_COLUMN);

$model = new SupervisorShortlist($pdo);

$err = throws(fn() => $model->submit($student['student_id'], $proposalId, [
    ['lecturer_id' => $lects[0], 'rank' => 1, 'preferred_main' => false],
    ['lecturer_id' => $lects[1], 'rank' => 2, 'preferred_main' => false],
]));
check('a shortlist with no preferred main is accepted', $err === null, $err ?? '');

// The whole point of allowing it: the order still decides who is asked.
$sid = $model->findActiveForStudent($student['student_id'])['shortlist_id'];
$pdo->prepare("UPDATE supervisor_shortlists SET status = 'approved' WHERE shortlist_id = ?")->execute([$sid]);
$contacted = $model->contactNext($sid);
$first = $pdo->query(
    "SELECT lecturer_id, rank_position FROM supervisor_shortlist_choices
     WHERE choice_id = " . $pdo->quote((string) $contacted)
)->fetch();
check('and the first name on the list is the one approached',
    $first && $first['lecturer_id'] === $lects[0], 'rank ' . ($first['rank_position'] ?? '?'));

$err = throws(fn() => $model->submit($student['student_id'], $proposalId, [
    ['lecturer_id' => $lects[0], 'rank' => 1, 'preferred_main' => true],
    ['lecturer_id' => $lects[1], 'rank' => 2, 'preferred_main' => true],
]));
check('two preferred mains is still refused', $err !== null, $err ?? 'no exception');

$err = throws(fn() => $model->submit($student['student_id'], $proposalId, [
    ['lecturer_id' => $lects[0], 'rank' => 1, 'preferred_main' => true],
]));
check('and naming exactly one still works', $err === null, $err ?? '');

echo "\n=== A refused attempt keeps what was picked ===\n";

$retry = render($twig, [
    'proposal' => ['proposal_id' => 'p', 'status' => 'submitted'],
    'proposal_submitted' => true,
    'supervisors' => $profiles,
    'error' => 'Each supervisor needs a different position in your ranking.',
    'old' => ['rank' => [$profiles[0]['lecturer_id'] => 2], 'preferred_main' => $profiles[0]['lecturer_id']],
]);
check('the supervisor they picked comes back with its place in the order',
    (bool) preg_match('/data-id="' . preg_quote($profiles[0]['lecturer_id'], '/') . '"[^>]*data-rank="2"/s', $retry));
check('and their preferred main is restored too',
    str_contains($retry, 'value="' . $profiles[0]['lecturer_id'] . '"'));
check('so the no-preference option is no longer the checked one',
    !preg_match('/id="noPreferred"[^>]*checked/', $retry));

$pdo->rollBack();

printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
