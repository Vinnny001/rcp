<?php

declare(strict_types=1);

namespace App\Controllers;

use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use App\Models\Lecturer;
use App\Models\Proposal;
use PDO;

class StudentProposalController
{
    private PDO $db;
    private Twig $twig;

    public function __construct(PDO $db, Twig $twig)
    {
        $this->db = $db;
        $this->twig = $twig;
    }

    private function requireStudent(): ?string
    {
        if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'student') {
            return '/login';
        }
        return null;
    }

    private function getStudentRecord(string $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT student_id, student_number FROM students WHERE user_id = :user_id LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $student = $this->getStudentRecord($_SESSION['user_id']);

        $proposalModel = new Proposal($this->db);
        $lecturerModel = new Lecturer($this->db);

        $proposal = $student ? $proposalModel->findActiveByStudentId($student['student_id']) : null;

        $rendered = $this->twig->render($response, 'students/proposal.twig', [
            'active_page'    => 'proposal',
            'first_name'     => $_SESSION['first_name'] ?? '',
            'student_number' => $student['student_number'] ?? null,
            'proposal'       => $proposal,
            'supervisors'    => $lecturerModel->listAvailableSupervisors(),
            'csrf_token'     => $this->csrfToken(),
            'error'          => $_SESSION['flash_error'] ?? null,
            'success'        => $_SESSION['flash_success'] ?? null,
            'old'            => $_SESSION['old_input'] ?? [],
        ]);

        unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['old_input']);
        return $rendered;
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();

        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/student/proposal');
        }

        $student = $this->getStudentRecord($_SESSION['user_id']);
        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/student/proposal');
        }

        // action = 'draft' (save without submitting) or 'submit' (submit for review)
        $action = $data['action'] ?? 'submit';
        $submitting = $action === 'submit';

        $title              = trim((string) ($data['title'] ?? ''));
        $synopsis           = trim((string) ($data['synopsis'] ?? ''));
        $proposedSupervisor = trim((string) ($data['proposed_supervisor_id'] ?? ''));

        $errors = [];
        if ($title === '' || mb_strlen($title) > 255) {
            $errors[] = 'Please provide a working title (up to 255 characters).';
        }
        // Drafts can be saved with a shorter/incomplete synopsis; only
        // enforce the full length requirement when actually submitting.
        if ($synopsis === '' || ($submitting && mb_strlen($synopsis) < 50)) {
            $errors[] = $submitting
                ? 'Please provide a synopsis of at least 50 characters before submitting.'
                : 'Please provide a synopsis.';
        }
        if ($submitting && $proposedSupervisor === '') {
            $errors[] = 'Please propose a supervisor before submitting.';
        }

        $proposalModel = new Proposal($this->db);
        $existing = $proposalModel->findActiveByStudentId($student['student_id']);

        if ($errors) {
            $_SESSION['flash_error'] = implode(' ', $errors);
            $_SESSION['old_input'] = [
                'title' => $title,
                'synopsis' => $synopsis,
                'proposed_supervisor_id' => $proposedSupervisor,
            ];
            return $this->redirect($response, '/student/proposal');
        }

        try {
            if ($existing && $existing['status'] === 'draft') {
                // Editing/advancing an existing draft.
                $proposalModel->updateDraft($existing['proposal_id'], [
                    'title' => $title,
                    'synopsis' => $synopsis,
                    'proposed_supervisor_id' => $proposedSupervisor ?: null,
                ], $submitting);
            } elseif ($existing) {
                // Already submitted/approved/under review — nothing to do here.
                $_SESSION['flash_error'] = 'You already have an active proposal under review.';
                return $this->redirect($response, '/student/proposal');
            } else {
                // Brand new proposal.
                $proposalModel->create($student['student_id'], [
                    'title' => $title,
                    'synopsis' => $synopsis,
                    'proposed_supervisor_id' => $proposedSupervisor ?: null,
                ], $submitting);
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Submission failed: ' . $e->getMessage();
            return $this->redirect($response, '/student/proposal');
        }

        $_SESSION['flash_success'] = $submitting
            ? 'Your proposal was submitted for review.'
            : 'Draft saved. You can keep editing it until you submit.';

        return $this->redirect($response, '/student/proposal');
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

    private function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withHeader('Location', $path)->withStatus(302);
    }
}