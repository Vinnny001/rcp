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

    private const UPLOAD_DIR = __DIR__ . '/../../public/uploads/documents';
    private const ALLOWED_MIME = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    ];
    private const MAX_SIZE_KB = 10240;

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

    /**
     * The lecturer's own document library — resources they've uploaded
     * themselves (grading sheets, templates, references), distinct from
     * the students' documents this controller otherwise reviews. These
     * are what "resources" pulls from when scheduling or editing a
     * meeting, alongside a student's own documents.
     */
    public function myDocuments(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $documentModel = new Document($this->db);

        return $this->twig->render($response, 'lecturers/my_documents.twig', [
            'active_page'    => 'l-my-documents',
            'first_name'     => $_SESSION['first_name'] ?? '',
            'documents'      => $documentModel->findByOwner($_SESSION['user_id']),
            'document_types' => $this->db->query("SELECT doc_type_id, doc_type_name FROM document_types ORDER BY doc_type_name")->fetchAll(),
            'csrf_token'     => $this->csrfToken(),
            'error'          => $_SESSION['flash_error'] ?? null,
            'success'        => $_SESSION['flash_success'] ?? null,
        ]);
    }

    public function uploadMyDocument(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/lecturer/my-documents');
        }

        $documentTypeId = trim((string) ($data['document_type_id'] ?? ''));
        if ($documentTypeId === '') {
            $_SESSION['flash_error'] = 'Please choose a document type.';
            return $this->redirect($response, '/lecturer/my-documents');
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['document_file'] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Please choose a file to upload.';
            return $this->redirect($response, '/lecturer/my-documents');
        }

        $mimeType = $file->getClientMediaType();
        if (!in_array($mimeType, self::ALLOWED_MIME, true)) {
            $_SESSION['flash_error'] = 'Only PDF, Word, Excel, or PowerPoint files are accepted.';
            return $this->redirect($response, '/lecturer/my-documents');
        }

        $sizeKb = (int) ceil($file->getSize() / 1024);
        if ($sizeKb > self::MAX_SIZE_KB) {
            $_SESSION['flash_error'] = 'File exceeds the 10MB limit.';
            return $this->redirect($response, '/lecturer/my-documents');
        }

        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }

        $originalName = $file->getClientFilename() ?: 'document';
        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION)) ?: 'bin';
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = self::UPLOAD_DIR . '/' . $storedName;
        $file->moveTo($destination);

        $documentModel = new Document($this->db);

        // A lecturer's own resource isn't part of any review pipeline —
        // 'final' marks it as already settled, never awaiting validation.
        $documentModel->create([
            'user_id'          => $_SESSION['user_id'],
            'uploaded_by'      => $_SESSION['user_id'],
            'document_type_id' => $documentTypeId,
            'document_status'  => 'final',
            'file_name'        => $originalName,
            'file_path'        => 'uploads/documents/' . $storedName,
            'file_size_kb'     => $sizeKb,
            'mime_type'        => $mimeType,
        ]);

        $_SESSION['flash_success'] = 'Document uploaded.';
        return $this->redirect($response, '/lecturer/my-documents');
    }
}
