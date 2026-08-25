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

    /**
     * Resolves a document_types.doc_type_id by name (e.g. 'Synopsis',
     * 'Proposal'). Returns null if that type isn't seeded yet — callers
     * must handle null gracefully rather than assume it always exists.
     */
    private function resolveDocumentTypeId(string $typeName): ?string
    {
        $stmt = $this->db->prepare("SELECT doc_type_id FROM document_types WHERE doc_type_name = :name LIMIT 1");
        $stmt->execute(['name' => $typeName]);
        $id = $stmt->fetchColumn();
        return $id ?: null;
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
            $synopsisTypeId = $this->resolveDocumentTypeId('Synopsis');
            $proposalTypeId = $this->resolveDocumentTypeId('Proposal');
            if ($synopsisTypeId) {
                $synopsisDoc = $documentModel->findByProposalAndType($proposal['proposal_id'], $synopsisTypeId);
            }
            if ($proposalTypeId) {
                $proposalDoc = $documentModel->findByProposalAndType($proposal['proposal_id'], $proposalTypeId);
            }
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

        if ($submitting) {
            $regStmt = $this->db->prepare(
                "SELECT thesis_schedule_id FROM student_thesis_registrations
                 WHERE student_id = :student_id AND status = 'active' LIMIT 1"
            );
            $regStmt->execute(['student_id' => $student['student_id']]);
            $thesisScheduleId = $regStmt->fetchColumn();

            if (!$thesisScheduleId || !$proposalModel->proposalSchedulingExists($thesisScheduleId)) {
                $_SESSION['flash_error'] = 'Proposals are not currently being accepted for your thesis schedule.';
                return $this->redirect($response, '/student/proposal');
            }
        }

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

        if ($proposalId) {
            $this->handleOptionalUpload($request, $proposalId, 'synopsis_file', 'Synopsis', !$submitting);
            $this->handleOptionalUpload($request, $proposalId, 'proposal_file', 'Proposal', !$submitting);
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
        string $documentTypeName,
        bool $proposalIsDraft
    ): void {
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles[$fieldName] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return;
        }

        $documentTypeId = $this->resolveDocumentTypeId($documentTypeName);
        if (!$documentTypeId) {
            $_SESSION['flash_error'] = "The '{$documentTypeName}' document type is not configured yet.";
            return;
        }

        $mimeType = $file->getClientMediaType();
        if (!in_array($mimeType, self::ALLOWED_MIME, true)) {
            $_SESSION['flash_error'] = $documentTypeName . ' must be a PDF.';
            return;
        }

        $sizeKb = (int) ceil($file->getSize() / 1024);
        if ($sizeKb > self::MAX_SIZE_KB) {
            $_SESSION['flash_error'] = $documentTypeName . ' exceeds the 10MB limit.';
            return;
        }

        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }

        $storedName = bin2hex(random_bytes(16)) . '.pdf';
        $destination = self::UPLOAD_DIR . '/' . $storedName;

        $documentModel = new Document($this->db);

        $existingDoc = $documentModel->findByProposalAndType($proposalId, $documentTypeId);
        if ($existingDoc) {
            $oldPath = __DIR__ . '/../../public/' . $existingDoc['file_path'];
            if (is_file($oldPath)) {
                unlink($oldPath);
            }
            $documentModel->delete($existingDoc['document_id']);
        }

        $file->moveTo($destination);

                $examScheduleId = null;
        $stmt = $this->db->prepare(
            "SELECT esd.exam_schedule_id
             FROM exam_schedule es
             JOIN exam_schedule_documents esd ON esd.exam_schedule_id = es.exam_schedule_id
             JOIN student_thesis_registrations str ON str.thesis_schedule_id = es.thesis_schedule_id
             WHERE str.student_id = (SELECT student_id FROM thesis_proposals WHERE proposal_id = :proposal_id)
               AND str.status = 'active'
               AND esd.document_type_id = :document_type_id
             LIMIT 1"
        );
        $stmt->execute(['proposal_id' => $proposalId, 'document_type_id' => $documentTypeId]);
        $examScheduleId = $stmt->fetchColumn() ?: null;

        $newDocumentId = $documentModel->create([
            'user_id'          => $_SESSION['user_id'],
            'uploaded_by'      => $_SESSION['user_id'],
            'document_type_id' => $documentTypeId,
            'document_status'  => $proposalIsDraft ? 'draft' : 'submitted',
            'file_name'        => $file->getClientFilename(),
            'file_path'        => 'uploads/documents/' . $storedName,
            'file_size_kb'     => $sizeKb,
            'mime_type'        => $mimeType,
        ]);

        $documentModel->linkToProposal($newDocumentId, $proposalId, $documentTypeId, $examScheduleId);
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

        // The link to a proposal now lives on exam_documents, not on the
        // documents row itself — look it up there instead.
        $linkStmt = $this->db->prepare(
            "SELECT proposal_id FROM exam_documents WHERE document_id = :document_id LIMIT 1"
        );
        $linkStmt->execute(['document_id' => $documentId]);
        $linkedProposalId = $linkStmt->fetchColumn();

        if (
            !$doc || !$proposal
            || $linkedProposalId !== $proposal['proposal_id']
            || $doc['document_status'] !== 'draft'
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