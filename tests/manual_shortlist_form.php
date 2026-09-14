<?php

/**
 * The proposal and supervisor form as a student meets it.
 *
 *   - the proposal and its supervisor list are written on one page and
 *     sent together; once sent, nothing on it is editable
 *   - when only part is handed back after a failed request, only that
 *     part is editable
 *   - the order is a list they arrange, not a number they type
 *   - lecturer profiles open on demand instead of filling the page
 *   - naming a preferred main is optional, undoable, and puts them first
 *   - a refused attempt keeps what was picked
 *
 * Renders the real template with the state the model works out, inside
 * a rolled-back transaction.
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

$pdo->beginTransaction();

$model = new SupervisorShortlist($pdo);
$profiles = (new SupervisorProfile($pdo))->browsable();

$student = $pdo->query(
    "SELECT s.student_id, s.user_id, tp.proposal_id FROM students s
     JOIN thesis_proposals tp ON tp.student_id = s.student_id AND tp.status NOT IN ('draft', 'rejected')
     WHERE NOT EXISTS (SELECT 1 FROM supervisor_shortlists sl WHERE sl.student_id = s.student_id)
     LIMIT 1"
)->fetch();
$proposalRow = fn (): array => $pdo->query(
    "SELECT * FROM thesis_proposals WHERE proposal_id = " . $pdo->quote($student['proposal_id'])
)->fetch();
$lects = $pdo->query(
    "SELECT lecturers.lecturer_id FROM lecturers JOIN internal_lecturers il ON il.lecturer_id = lecturers.lecturer_id WHERE user_id <> " . $pdo->quote($student['user_id']) . " LIMIT 3"
)->fetchAll(PDO::FETCH_COLUMN);

/**
 * The page exactly as the controller would render it for this student
 * right now, with anything in $extra laid over the top.
 */
$page = function (array $extra = []) use ($twig, $model, $profiles, $student, $proposalRow): string {
    $proposal = $proposalRow();
    $state = $model->studentState($student['student_id'], $proposal);
    $latest = $state['latest'];

    return (string) $twig->render(new \Slim\Psr7\Response(), 'students/proposal.twig', array_merge([
        'active_page' => 'proposal', 'first_name' => 'S', 'student_number' => 'X',
        'proposal' => $proposal, 'synopsis_doc' => null, 'proposal_doc' => null,
        'state' => $state, 'latest' => $latest,
        'latest_choices' => $latest ? $model->choicesFor($latest['shortlist_id']) : [],
        'appointed_roles' => [], 'history' => [],
        'supervisors' => $state['list_editable'] ? $profiles : [],
        'picked' => [], 'picked_main' => '',
        'max_choices' => SupervisorShortlist::MAX_CHOICES,
        'max_supervisors' => SupervisorShortlist::MAX_SUPERVISORS,
        'response_days' => SupervisorShortlist::RESPONSE_DAYS,
        'old' => [], 'csrf_token' => 't', 'error' => null, 'success' => null,
    ], $extra))->getBody();
};

echo "\n=== Written together on one page ===\n";

check('a draft does not count as submitted', !Proposal::isSubmitted(['status' => 'draft']));
check('a submitted one does', Proposal::isSubmitted(['status' => 'submitted']));

$pdo->prepare("UPDATE thesis_proposals SET status = 'draft' WHERE proposal_id = ?")->execute([$student['proposal_id']]);
$draftPage = $page();
check('a draft proposal can be edited', str_contains($draftPage, 'name="title"') && str_contains($draftPage, 'name="synopsis"'));
check('and the supervisor list is built right beside it', str_contains($draftPage, 'id="chosenList"'));
check('both inside the same form', (bool) preg_match('/id="proposalForm".*id="chosenList".*<\/form>/s', $draftPage));
check('with one button that sends both', str_contains($draftPage, 'Send proposal and request'));
check('and the page says sending locks both', str_contains($draftPage, 'Sending locks both'));

echo "\n=== The order is a list you arrange ===\n";

check('everyone is said to be asked at the same time', str_contains($draftPage, 'all of them are asked at the same time'));
check('and the order is what decides who is appointed', str_contains($draftPage, 'your order decides who'));
check('no position is typed or picked anywhere',
    !str_contains($draftPage, 'name="rank[') && !str_contains($draftPage, '>1st<'));
check('the list can be dragged or nudged with arrows',
    str_contains($draftPage, 'draggable') && str_contains($draftPage, 'Move up'));

echo "\n=== Profiles open on demand, not inline ===\n";

check('each card offers a profile to open',
    str_contains($draftPage, 'data-profile="' . $profiles[0]['lecturer_id'] . '"'));
check('the profile dialog carries the long detail',
    str_contains($draftPage, 'id="profile-' . $profiles[0]['lecturer_id'] . '"')
    && str_contains($draftPage, 'Research interests'));
check('and a supervisor can be added straight from it', str_contains($draftPage, 'js-add-from-profile'));
check('the dialogs sit outside the form, so their buttons cannot submit it',
    strpos($draftPage, 'id="profile-') > strpos($draftPage, '</form>'));

echo "\n=== Preferred main ===\n";

check('naming one is optional', str_contains($draftPage, 'optional'));
check('there is a way back to no preference', str_contains($draftPage, 'id="noPreferred"'));
check('and it is the state the form opens in', (bool) preg_match('/id="noPreferred"[^>]*checked/', $draftPage));

$pdo->prepare("UPDATE thesis_proposals SET status = 'submitted' WHERE proposal_id = ?")->execute([$student['proposal_id']]);
$sid = $model->sendForStudent($student['student_id'], $proposalRow(), [
    ['lecturer_id' => $lects[0], 'rank' => 1, 'preferred_main' => false],
    ['lecturer_id' => $lects[1], 'rank' => 2, 'preferred_main' => false],
    ['lecturer_id' => $lects[2], 'rank' => 3, 'preferred_main' => true],
], $student['user_id']);
$ordered = $model->choicesFor($sid);
check('a preferred main dropped last is stored at number 1', $ordered[0]['lecturer_id'] === $lects[2]);
check('everyone else keeps their order behind them',
    $ordered[1]['lecturer_id'] === $lects[0] && $ordered[2]['lecturer_id'] === $lects[1]);

echo "\n=== Once sent, nothing is editable ===\n";

$sentPage = $page();
check('no proposal fields', !str_contains($sentPage, 'name="title"'));
check('no supervisor picker', !str_contains($sentPage, 'id="chosenList"'));
check('no send button', !str_contains($sentPage, 'Send proposal and request'));
check('the student sees it is with the coordinator', str_contains($sentPage, 'With your research coordinator'));
check('and the list they sent, in order', str_contains($sentPage, 'your preferred main'));

echo "\n=== Handed back in part ===\n";

$pdo->prepare("UPDATE supervisor_shortlists SET status = 'rejected', rejection_reason = 'Scope too wide.' WHERE shortlist_id = ?")->execute([$sid]);
$coordinator = $pdo->query("SELECT user_id FROM users LIMIT 1")->fetchColumn();
$pdo->exec('SAVEPOINT rejected');

$model->grantEdit($sid, false, true, $coordinator);
$listOnly = $page();
check('with only the list handed back, the picker is there', str_contains($listOnly, 'id="chosenList"'));
check('but the proposal is not editable', !str_contains($listOnly, 'name="title"'));
check('and the student is told why it failed', str_contains($listOnly, 'Scope too wide.'));

$pdo->exec('ROLLBACK TO SAVEPOINT rejected');
$model->grantEdit($sid, true, false, $coordinator);
$proposalOnly = $page();
check('with only the proposal handed back, it is editable', str_contains($proposalOnly, 'name="title"'));
check('but the list is not', !str_contains($proposalOnly, 'id="chosenList"'));
check('and the page says the list goes out again unchanged', str_contains($proposalOnly, 'goes out again unchanged'));

$pdo->exec('ROLLBACK TO SAVEPOINT rejected');
$waiting = $page();
check('with nothing handed back, the student can only wait', !str_contains($waiting, 'id="proposalForm"'));
check('and is told the coordinator decides', str_contains($waiting, 'coordinator decides what happens next'));

echo "\n=== A refused attempt keeps what was picked ===\n";

$pdo->prepare("UPDATE thesis_proposals SET status = 'draft' WHERE proposal_id = ?")->execute([$student['proposal_id']]);
$pdo->prepare("DELETE FROM supervisor_shortlists WHERE student_id = ?")->execute([$student['student_id']]);
$retry = $page([
    'error'       => 'Choose at least one supervisor to send with your proposal.',
    'picked'      => [$profiles[0]['lecturer_id'] => 2],
    'picked_main' => $profiles[0]['lecturer_id'],
]);
check('the supervisor they picked comes back with its place in the order',
    (bool) preg_match('/data-id="' . preg_quote($profiles[0]['lecturer_id'], '/') . '"[^>]*data-rank="2"/s', $retry));
check('and their preferred main is restored too',
    (bool) preg_match('/data-id="' . preg_quote($profiles[0]['lecturer_id'], '/') . '"[^>]*data-main="1"/s', $retry));
check('so the no-preference option is no longer the checked one',
    !preg_match('/id="noPreferred"[^>]*checked/', $retry));

$pdo->rollBack();

printf("\n%s\n%d passed, %d failed\n", str_repeat('-', 56), $pass, $fail);
echo "rolled back — no test rows kept\n";
exit($fail === 0 ? 0 : 1);
