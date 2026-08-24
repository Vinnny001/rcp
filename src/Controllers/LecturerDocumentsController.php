<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Lecturer;
use App\Models\Document;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

class LecturerDocumentsController
{
    private PDO $db;
    private Twig $twig;

    private const VALID_DOC_STATUSES = ['valid', 'rejected'];

    public function __construct(PDO $db, Twig $twig)
    {
        $this->db = $db;
        $this->twig = $twig;
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

    private function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withHeader('Location', $path)->withStatus(302);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $lecturerModel = new Lecturer($this->db);
        $documentModel = new Document($this->db);
        $lecturer = $lecturerModel->findByUserId($_SESSION['user_id']);

        if (!$lecturer) {
            $_SESSION['flash_error'] = 'Your lecturer profile could not be found. Contact the registrar.';
            return $this->redirect($response, '/login');
        }

        $params = $request->getQueryParams();
        $selectedStudent = $params['student_id'] ?? null;
        $selectedStatus  = $params['status'] ?? null;

        if ($selectedStatus !== null && !in_array($selectedStatus, ['pending', ...self::VALID_DOC_STATUSES], true)) {
            $selectedStatus = null;
        }

        return $this->twig->render($response, 'lecturers/documents.twig', [
            'active_page'      => 'l-documents',
            'first_name'       => $_SESSION['first_name'] ?? '',
            'staff_number'     => $lecturer['staff_number'] ?? null,
            'students'         => $lecturerModel->findActiveSupervisions($lecturer['lecturer_id']),
            'documents'        => $documentModel->findBySupervisorId(
                $lecturer['lecturer_id'],
                $selectedStudent ?: null,
                $selectedStatus ?: null
            ),
            'selected_student' => $selectedStudent,
            'selected_status'  => $selectedStatus,
            'csrf_token'       => $this->csrfToken(),
            'error'            => $_SESSION['flash_error'] ?? null,
            'success'          => $_SESSION['flash_success'] ?? null,
        ]);
    }

    public function validateDocument(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $data = $request->getParsedBody();

        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/lecturer/documents');
        }

        $documentId = $data['document_id'] ?? '';
        $status = $data['status'] ?? '';
        $notes = trim($data['notes'] ?? '') ?: null;

        if (!$documentId || !in_array($status, self::VALID_DOC_STATUSES, true)) {
            $_SESSION['flash_error'] = 'Invalid document review submission.';
            return $this->redirect($response, '/lecturer/documents');
        }

        // Ownership check: only allow validating documents belonging to a
        // student this lecturer actively supervises.
        $lecturerModel = new Lecturer($this->db);
        $documentModel = new Document($this->db);
        $lecturer = $lecturerModel->findByUserId($_SESSION['user_id']);

        if ($lecturer) {
            $ownDocumentIds = array_column($documentModel->findBySupervisorId($lecturer['lecturer_id']), 'document_id');
            if (in_array($documentId, $ownDocumentIds, true)) {
                $documentModel->updateValidation($documentId, $status, $notes, $_SESSION['user_id']);
                $_SESSION['flash_success'] = 'Document review recorded.';
            } else {
                $_SESSION['flash_error'] = 'That document is not under your supervision.';
            }
        }

        return $this->redirect($response, '/lecturer/documents');
    }
}