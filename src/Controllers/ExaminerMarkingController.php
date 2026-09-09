<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ExaminerAssignment;
use App\Models\Rubric;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

/**
 * The examiner's marking sheet.
 *
 * Every route here loads the meeting through findForExaminer(), which
 * only returns it if this user was invited to mark it. The check and
 * the fetch are the same query, so there is no path that opens a sheet
 * without passing it.
 */
class ExaminerMarkingController
{
    private PDO $db;
    private Twig $twig;

    public function __construct(PDO $db, Twig $twig)
    {
        $this->db = $db;
        $this->twig = $twig;
    }

    public function index(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $rubric = new Rubric($this->db);
        $meetings = (new ExaminerAssignment($this->db))->forExaminer($_SESSION['user_id']);

        foreach ($meetings as &$meeting) {
            $meeting['my_result'] = $meeting['template_id']
                ? $rubric->examinerResult($meeting['meeting_id'], $_SESSION['user_id'], $meeting['template_id'])
                : null;
            $meeting['is_leader'] = $meeting['panel_leader_id'] === $_SESSION['user_id'];
        }
        unset($meeting);

        return $this->twig->render($response, 'lecturers/examining.twig', [
            'active_page' => 'l-examining',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'last_name'   => $_SESSION['last_name'] ?? '',
            'meetings'    => $meetings,
            'error'       => $this->takeFlash('flash_error'),
            'success'     => $this->takeFlash('flash_success'),
        ]);
    }

    public function sheet(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $meeting = (new ExaminerAssignment($this->db))
            ->findForExaminer((string) $args['id'], $_SESSION['user_id']);

        if (!$meeting) {
            $_SESSION['flash_error'] = 'You are not an examiner on that meeting.';
            return $this->redirect($response, '/lecturer/examining');
        }
        if (!$meeting['template_id']) {
            $_SESSION['flash_error'] = 'No marking scheme is configured for the ' . $meeting['stage_name'] . ' stage.';
            return $this->redirect($response, '/lecturer/examining');
        }

        $rubric = new Rubric($this->db);
        $leader = $rubric->panelLeader($meeting['meeting_id']);
        $isLeader = $leader !== null && $leader['examiner_id'] === $_SESSION['user_id'];

        return $this->twig->render($response, 'lecturers/marking_sheet.twig', [
            'active_page'  => 'l-examining',
            'first_name'   => $_SESSION['first_name'] ?? '',
            'last_name'    => $_SESSION['last_name'] ?? '',
            'meeting'      => $meeting,
            'criteria'     => $rubric->criteriaFor($meeting['template_id']),
            'max_total'    => $rubric->maxTotalFor($meeting['template_id']),
            'my_scores'    => $rubric->scoresFor($meeting['meeting_id'], $_SESSION['user_id']),
            'my_result'    => $rubric->examinerResult($meeting['meeting_id'], $_SESSION['user_id'], $meeting['template_id']),
            'bands'        => $rubric->bandsFor($meeting['template_id']),
            // The panel's own marks are only the leader's business —
            // an ordinary examiner should mark on what they saw, not on
            // what a colleague gave.
            'panel'        => $isLeader ? $rubric->panelResults($meeting['meeting_id'], $meeting['template_id']) : [],
            'calculated_average' => $isLeader ? $rubric->calculatedAverage($meeting['meeting_id'], $meeting['template_id']) : null,
            'leader'       => $leader,
            'is_leader'    => $isLeader,
            'csrf_token'   => $this->csrfToken(),
            'error'        => $this->takeFlash('flash_error'),
            'success'      => $this->takeFlash('flash_success'),
        ]);
    }

    public function save(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handle($request, $response, function (array $data, array $meeting, Rubric $rubric): string {
            $rubric->saveScores(
                $meeting['meeting_id'],
                $_SESSION['user_id'],
                $meeting['template_id'],
                (array) ($data['score'] ?? []),
                (array) ($data['remark'] ?? [])
            );

            $result = $rubric->examinerResult($meeting['meeting_id'], $_SESSION['user_id'], $meeting['template_id']);

            return sprintf('Marks recorded — %s of %s (%s%%).',
                rtrim(rtrim(number_format($result['total'], 2), '0'), '.'),
                rtrim(rtrim(number_format($result['max'], 2), '0'), '.'),
                $result['percentage']);
        });
    }

    public function confirmAverage(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handle($request, $response, function (array $data, array $meeting, Rubric $rubric): string {
            $average = $rubric->confirmAverage($meeting['meeting_id'], $_SESSION['user_id'], $meeting['template_id']);

            return 'Average of ' . $average . '% confirmed. It reaches the student once the coordinator approves it.';
        });
    }

    /**
     * Shared guard: authenticate, verify CSRF, and re-check that this
     * user is an examiner on the meeting being posted about.
     */
    private function handle(
        ServerRequestInterface $request,
        ResponseInterface $response,
        callable $write
    ): ResponseInterface {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $data = (array) $request->getParsedBody();
        $meetingId = (string) ($data['meeting_id'] ?? '');
        $back = $meetingId !== '' ? '/lecturer/examining/' . $meetingId : '/lecturer/examining';

        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, $back);
        }

        $meeting = (new ExaminerAssignment($this->db))->findForExaminer($meetingId, $_SESSION['user_id']);
        if (!$meeting || !$meeting['template_id']) {
            $_SESSION['flash_error'] = 'You are not an examiner on that meeting.';
            return $this->redirect($response, '/lecturer/examining');
        }

        try {
            $_SESSION['flash_success'] = $write($data, $meeting, new Rubric($this->db));
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($response, $back);
    }

    private function requireLecturer(): ?string
    {
        if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'lecturer') {
            return '/login';
        }

        return null;
    }

    private function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    private function verifyCsrf(string $token): bool
    {
        return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    private function takeFlash(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return $value;
    }

    private function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withHeader('Location', $path)->withStatus(302);
    }
}
