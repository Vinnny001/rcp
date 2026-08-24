<?php

declare(strict_types=1);

namespace App\Controllers;

use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use App\Models\Lecturer;
use App\Models\Proposal;
use App\Models\SupervisionRequest;
use App\Models\Document;

use PDO;

class StudentProposalController
{
    private PDO $db;
    private Twig $twig;

    private const UPLOAD_DIR = __DIR__ . '/../../public/uploads/documents';
    private const ALLOWED_MIME = ['application/pdf'];
    private const MAX_SIZE_KB = 10240;

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
        $documentModel = new Document($this->db);

        $proposal = $student ? $proposalModel->findActiveByStudentId($student['student_id']) : null;

        $synopsisDoc = null;
        $proposalDoc = null;
        if ($proposal) {
            $synopsisDoc = $documentModel->findByProposalAndType($proposal['proposal_id'], 'synopsis');
            $proposalDoc = $documentModel->findByProposalAndType($proposal['proposal_id'], 'proposal');
        }

        $rendered = $this->twig->render($response, 'students/proposal.twig', [
            'active_page'    => 'proposal',
            'first_name'     => $_SESSION['first_name'] ?? '',
            'student_number' => $student['student_number'] ?? null,
            'proposal'       => $proposal,
            'supervisors'    => $lecturerModel->listAvailableSupervisors(),
            'synopsis_doc'   => $synopsisDoc,
            'proposal_doc'   => $proposalDoc,
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

        $action = $data['action'] ?? 'submit';
        $submitting = $action === 'submit';

        $title              = trim((string) ($data['title'] ?? ''));
        $synopsis           = trim((string) ($data['synopsis'] ?? ''));
        $proposedSupervisor = trim((string) ($data['proposed_supervisor_id'] ?? ''));

        $errors = [];
        if ($title === '' || mb_strlen($title) > 255) {
            $errors[] = 'Please provide a working title (up to 255 characters).';
        }
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

        $proposalId = null;

        try {
            if ($existing && $existing['status'] === 'draft') {
                $proposalId = $existing['proposal_id'];
                $proposalModel->updateDraft($proposalId, [
                    'title' => $title,
                    'synopsis' => $synopsis,
                    'proposed_supervisor_id' => $proposedSupervisor ?: null,
                ], $submitting);

                if ($submitting && $proposedSupervisor !== '') {
                    $requestModel = new SupervisionRequest($this->db);
                    $requestModel->create($proposalId, $student['student_id'], $proposedSupervisor);
                }
            } elseif ($existing) {
                $_SESSION['flash_error'] = 'You already have an active proposal under review.';
                return $this->redirect($response, '/student/proposal');
            } else {
                $proposalId = $proposalModel->create($student['student_id'], [
                    'title' => $title,
                    'synopsis' => $synopsis,
                    'proposed_supervisor_id' => $proposedSupervisor ?: null,
                ], $submitting);

                if ($submitting && $proposedSupervisor !== '') {
                    $requestModel = new SupervisionRequest($this->db);
                    $requestModel->create($proposalId, $student['student_id'], $proposedSupervisor);
                }
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Submission failed: ' . $e->getMessage();
            return $this->redirect($response, '/student/proposal');
        }

        // Optional PDF uploads, attached to whichever proposal we just saved.
        if ($proposalId) {
            $this->handleOptionalUpload($request, $proposalId, 'synopsis_file', 'synopsis');
            $this->handleOptionalUpload($request, $proposalId, 'proposal_file', 'proposal');
        }

        $_SESSION['flash_success'] = $submitting
            ? 'Your proposal was submitted for review.'
            : 'Draft saved. You can keep editing it until you submit.';

        return $this->redirect($response, '/student/proposal');
    }

    private function handleOptionalUpload(
        ServerRequestInterface $request,
        string $proposalId,
        string $fieldName,
        string $documentType
    ): void {
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles[$fieldName] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return;
        }

        $mimeType = $file->getClientMediaType();
        if (!in_array($mimeType, self::ALLOWED_MIME, true)) {
            $_SESSION['flash_error'] = ucfirst($documentType) . ' must be a PDF.';
            return;
        }

        $sizeKb = (int) ceil($file->getSize() / 1024);
        if ($sizeKb > self::MAX_SIZE_KB) {
            $_SESSION['flash_error'] = ucfirst($documentType) . ' exceeds the 10MB limit.';
            return;
        }

        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }

        $storedName = bin2hex(random_bytes(16)) . '.pdf';
        $destination = self::UPLOAD_DIR . '/' . $storedName;

        $documentModel = new Document($this->db);

        $existingDoc = $documentModel->findByProposalAndType($proposalId, $documentType);
        if ($existingDoc) {
            $oldPath = __DIR__ . '/../../public/' . $existingDoc['file_path'];
            if (is_file($oldPath)) {
                unlink($oldPath);
            }
            $documentModel->delete($existingDoc['document_id']);
        }

        $file->moveTo($destination);

        $documentModel->create([
            'proposal_id'   => $proposalId,
            'uploaded_by'   => $_SESSION['user_id'],
            'document_type' => $documentType,
            'file_name'     => $file->getClientFilename(),
            'file_path'     => 'uploads/documents/' . $storedName,
            'file_size_kb'  => $sizeKb,
            'mime_type'     => $mimeType,
        ]);
    }

    public function removeDocument(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
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
        $documentId = $data['document_id'] ?? '';

        if (!$student || !$documentId) {
            return $this->redirect($response, '/student/proposal');
        }

        $documentModel = new Document($this->db);
        $doc = $documentModel->findById($documentId);

        $proposalModel = new Proposal($this->db);
        $proposal = $proposalModel->findActiveByStudentId($student['student_id']);

        if (
            !$doc || !$proposal
            || $doc['proposal_id'] !== $proposal['proposal_id']
            || $proposal['status'] !== 'draft'
        ) {
            $_SESSION['flash_error'] = 'That document cannot be removed.';
            return $this->redirect($response, '/student/proposal');
        }

        $path = __DIR__ . '/../../public/' . $doc['file_path'];
        if (is_file($path)) {
            unlink($path);
        }
        $documentModel->delete($documentId);

        $_SESSION['flash_success'] = 'Document removed.';
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