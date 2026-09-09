<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\DepartmentHead;
use App\Models\ResearchCoordinator;
use App\Models\SupervisorShortlist;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

/**
 * The research coordinator's side of supervisor assignment: pick up a
 * student's shortlist, take it to the department, record what was
 * decided, then release the decision.
 *
 * A coordinator signs in as a lecturer — the powers come from a
 * research_coordinators row, and every action here re-checks it
 * against the program that actually owns the shortlist. Holding the
 * role for one program grants nothing over another's students.
 */
class CoordinatorController
{
    private PDO $db;
    private Twig $twig;

    public function __construct(PDO $db, Twig $twig)
    {
        $this->db = $db;
        $this->twig = $twig;
    }

    public function queue(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $programs = (new ResearchCoordinator($this->db))->programsForUser($_SESSION['user_id']);
        $shortlists = (new SupervisorShortlist($this->db))
            ->queueForPrograms(array_column($programs, 'program_id'));

        return $this->twig->render($response, 'coordinators/queue.twig', [
            'active_page' => 'l-coordinator',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'last_name'   => $_SESSION['last_name'] ?? '',
            'programs'    => $programs,
            'shortlists'  => $shortlists,
            'error'       => $this->takeFlash('flash_error'),
            'success'     => $this->takeFlash('flash_success'),
        ]);
    }

    public function showShortlist(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $model = new SupervisorShortlist($this->db);
        $shortlist = $model->findWithContext((string) $args['id']);

        if (!$shortlist || !$this->coordinates($shortlist['program_id'])) {
            $_SESSION['flash_error'] = 'That shortlist is not on a program you coordinate.';
            return $this->redirect($response, '/coordinator/shortlists');
        }

        return $this->twig->render($response, 'coordinators/shortlist.twig', [
            'active_page' => 'l-coordinator',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'last_name'   => $_SESSION['last_name'] ?? '',
            'shortlist'   => $shortlist,
            'choices'     => $model->choicesFor($shortlist['shortlist_id']),
            'voters'      => $shortlist['meeting_id'] ? $model->meetingVoters($shortlist['meeting_id']) : [],
            'tally'       => $shortlist['meeting_id'] ? $model->tally($shortlist['meeting_id']) : null,
            'heads'       => (new DepartmentHead($this->db))->activeForDepartment($shortlist['department_id']),
            'csrf_token'  => $this->csrfToken(),
            'error'       => $this->takeFlash('flash_error'),
            'success'     => $this->takeFlash('flash_success'),
        ]);
    }

    public function scheduleMeeting(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handle($request, $response, function (array $data, SupervisorShortlist $model): string {
            $shortlist = $this->authorisedShortlist($data['shortlist_id'] ?? '', $model);

            $model->scheduleMeeting(
                $shortlist['shortlist_id'],
                (string) ($data['scheduled_at'] ?? ''),
                (string) ($data['mode'] ?? 'physical'),
                (string) ($data['location'] ?? ''),
                (string) ($data['virtual_link'] ?? ''),
                (array) ($data['dept_head_ids'] ?? []),
                $_SESSION['user_id']
            );

            return 'Meeting scheduled and heads invited.';
        });
    }

    public function saveMinutes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handle($request, $response, function (array $data, SupervisorShortlist $model): string {
            $shortlist = $this->authorisedShortlist($data['shortlist_id'] ?? '', $model);
            if (!$shortlist['meeting_id']) {
                throw new \RuntimeException('Schedule the meeting before writing minutes.');
            }

            $finalize = ($data['finalize'] ?? '') === '1';
            $model->saveMinutes($shortlist['meeting_id'], (string) ($data['minutes'] ?? ''), $finalize);

            return $finalize
                ? 'Minutes finalised. The decision can now be applied.'
                : 'Minutes saved as a draft.';
        });
    }

    public function applyOutcome(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handle($request, $response, function (array $data, SupervisorShortlist $model): string {
            $shortlist = $this->authorisedShortlist($data['shortlist_id'] ?? '', $model);
            if (!$shortlist['meeting_id']) {
                throw new \RuntimeException('There is no meeting to apply.');
            }

            return $model->recordOutcome($shortlist['meeting_id']) === 'approved'
                ? 'Approved. The preferred main supervisor has been sent the request.'
                : 'Recorded as not approved. The student has been told why and can submit a new shortlist.';
        });
    }

    /**
     * Loads a shortlist and refuses it unless this coordinator holds
     * the program it belongs to.
     *
     * @return array<string, mixed>
     */
    private function authorisedShortlist(string $shortlistId, SupervisorShortlist $model): array
    {
        $shortlist = $model->findWithContext($shortlistId);
        if (!$shortlist || !$this->coordinates($shortlist['program_id'])) {
            throw new \RuntimeException('That shortlist is not on a program you coordinate.');
        }

        return $shortlist;
    }

    private function coordinates(string $programId): bool
    {
        return (new ResearchCoordinator($this->db))->isCoordinatorFor($_SESSION['user_id'], $programId);
    }

    private function handle(
        ServerRequestInterface $request,
        ResponseInterface $response,
        callable $write
    ): ResponseInterface {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $data = (array) $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/coordinator/shortlists');
        }

        $back = isset($data['shortlist_id'])
            ? '/coordinator/shortlists/' . $data['shortlist_id']
            : '/coordinator/shortlists';

        try {
            $_SESSION['flash_success'] = $write($data, new SupervisorShortlist($this->db));
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
