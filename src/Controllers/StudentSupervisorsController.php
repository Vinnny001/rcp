<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Proposal;
use App\Models\SupervisorProfile;
use App\Models\SupervisorShortlist;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

/**
 * The student's side of supervisor assignment: read the profiles, put
 * up to five in order, name a preferred main, then watch what the
 * department and the lecturers do with it.
 *
 * One page, two states — the shortlist builder until something has
 * been submitted, then the tracker. A student with a live shortlist
 * has nothing to build, and one without has nothing to track.
 */
class StudentSupervisorsController
{
    private PDO $db;
    private Twig $twig;

    public function __construct(PDO $db, Twig $twig)
    {
        $this->db = $db;
        $this->twig = $twig;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $this->redirect($response, $redirect);
        }

        $userId = $_SESSION['user_id'];
        $student = $this->studentRecord($userId);
        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/student/dashboard');
        }

        $proposal = (new Proposal($this->db))->findActiveByStudentId($student['student_id']);
        $shortlistModel = new SupervisorShortlist($this->db);
        $shortlist = $shortlistModel->findActiveForStudent($student['student_id']);

        // A rejected or exhausted shortlist is history the student
        // still needs to read — the reason, and who declined — while
        // being able to start again, so both halves are shown at once.
        $canResubmit = $shortlist === null
            || in_array($shortlist['status'], ['rejected', 'exhausted'], true);

        return $this->twig->render($response, 'students/supervisors.twig', [
            'active_page'   => 'supervisors',
            'first_name'    => $_SESSION['first_name'] ?? '',
            'student_number' => $student['student_number'] ?? null,
            'proposal'      => $proposal,
            'shortlist'     => $shortlist,
            'choices'       => $shortlist ? $shortlistModel->choicesFor($shortlist['shortlist_id']) : [],
            'supervisors'   => $canResubmit ? (new SupervisorProfile($this->db))->browsable() : [],
            'can_resubmit'  => $canResubmit,
            'max_choices'   => SupervisorShortlist::MAX_CHOICES,
            'max_supervisors' => SupervisorShortlist::MAX_SUPERVISORS,
            'csrf_token'    => $this->csrfToken(),
            'error'         => $this->takeFlash('flash_error'),
            'success'       => $this->takeFlash('flash_success'),
        ]);
    }

    public function submit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $this->redirect($response, $redirect);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/student/supervisors');
        }

        $userId = $_SESSION['user_id'];
        $student = $this->studentRecord($userId);
        $proposal = $student ? (new Proposal($this->db))->findActiveByStudentId($student['student_id']) : null;

        if (!$student || !$proposal) {
            $_SESSION['flash_error'] = 'Submit your thesis proposal before choosing supervisors.';
            return $this->redirect($response, '/student/supervisors');
        }

        try {
            (new SupervisorShortlist($this->db))->submit(
                $student['student_id'],
                $proposal['proposal_id'],
                $this->readChoices($data)
            );
            $_SESSION['flash_success'] = 'Shortlist submitted. Your research coordinator will take it to the department.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($response, '/student/supervisors');
    }

    /**
     * Turns the form's per-lecturer rank/preferred inputs into the
     * choice list the model validates. A blank rank means "not
     * chosen", which is what keeps the form to one control per
     * lecturer instead of a checkbox that can disagree with a rank.
     *
     * @param array<string, mixed>|null $data
     * @return array<int, array{lecturer_id: string, rank: int, preferred_main: bool}>
     */
    private function readChoices(?array $data): array
    {
        $ranks = $data['rank'] ?? [];
        $preferred = (string) ($data['preferred_main'] ?? '');

        if (!is_array($ranks)) {
            return [];
        }

        $choices = [];
        foreach ($ranks as $lecturerId => $rank) {
            if (trim((string) $rank) === '') {
                continue;
            }
            $choices[] = [
                'lecturer_id'    => (string) $lecturerId,
                'rank'           => (int) $rank,
                'preferred_main' => (string) $lecturerId === $preferred,
            ];
        }

        usort($choices, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return $choices;
    }

    private function studentRecord(string $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT student_id, student_number FROM students WHERE user_id = :user_id LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetch() ?: null;
    }

    private function requireStudent(): ?string
    {
        if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'student') {
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
