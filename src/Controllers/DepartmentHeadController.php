<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\SupervisorShortlist;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

/**
 * A department head's shortlist meetings and their vote.
 *
 * Heads read the minutes as soon as the coordinator saves them — a
 * draft is visible, which is the point: the minutes are what a head is
 * voting against. The model refuses a vote from anyone who was not
 * invited, so this controller does not have to police that itself.
 */
class DepartmentHeadController
{
    private PDO $db;
    private Twig $twig;

    public function __construct(PDO $db, Twig $twig)
    {
        $this->db = $db;
        $this->twig = $twig;
    }

    public function meetings(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $model = new SupervisorShortlist($this->db);
        $meetings = $model->meetingsForHead($_SESSION['user_id']);

        foreach ($meetings as &$meeting) {
            $meeting['choices'] = $model->choicesFor($meeting['shortlist_id']);
        }
        unset($meeting);

        return $this->twig->render($response, 'lecturers/shortlist_meetings.twig', [
            'active_page' => 'l-panel',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'last_name'   => $_SESSION['last_name'] ?? '',
            'meetings'    => $meetings,
            'csrf_token'  => $this->csrfToken(),
            'error'       => $this->takeFlash('flash_error'),
            'success'     => $this->takeFlash('flash_success'),
        ]);
    }

    public function vote(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $data = (array) $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/lecturer/shortlist-meetings');
        }

        try {
            // The dept_head_id is resolved from the session rather than
            // taken from the form, so a posted id cannot cast someone
            // else's vote.
            $headId = $this->headIdFor((string) ($data['meeting_id'] ?? ''));
            if ($headId === null) {
                throw new \RuntimeException('You are not on the invitation list for that meeting.');
            }

            (new SupervisorShortlist($this->db))->castVote(
                (string) $data['meeting_id'],
                $headId,
                (string) ($data['vote'] ?? ''),
                trim((string) ($data['comment'] ?? '')) ?: null
            );

            $_SESSION['flash_success'] = 'Your vote has been recorded.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($response, '/lecturer/shortlist-meetings');
    }

    /**
     * This user's department-head row for the department that owns the
     * meeting — null when they hold no active headship there.
     */
    private function headIdFor(string $meetingId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT i.dept_head_id
             FROM shortlist_meeting_invitees i
             JOIN department_heads dh ON dh.dept_head_id = i.dept_head_id
             WHERE i.meeting_id = :meeting_id AND dh.user_id = :user_id AND dh.is_active = 1
             LIMIT 1"
        );
        $stmt->execute(['meeting_id' => $meetingId, 'user_id' => $_SESSION['user_id']]);
        $id = $stmt->fetchColumn();

        return $id ? (string) $id : null;
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
