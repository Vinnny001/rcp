<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Document;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

class StudentRequirementsController
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

    private function getActiveThesisScheduleId(string $studentId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT thesis_schedule_id FROM student_thesis_registrations
             WHERE student_id = :student_id AND status = 'active' LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);
        return $stmt->fetchColumn() ?: null;
    }

    private function proposalIsSubmitted(string $studentId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT status FROM thesis_proposals
             WHERE student_id = :student_id
             ORDER BY created_at DESC
             LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);
        $status = $stmt->fetchColumn();

        return $status && $status !== 'draft';
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
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $userId = $_SESSION['user_id'];
        $student = $this->getStudentRecord($userId);

        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/login');
        }

        $documentModel = new Document($this->db);
        $thesisScheduleId = $this->getActiveThesisScheduleId($student['student_id']);
        $proposalSubmitted = $this->proposalIsSubmitted($student['student_id']);

        $requirements = [];
        if ($thesisScheduleId) {
            $scheduled = $documentModel->findScheduledForThesisSchedule($thesisScheduleId);
            $now = new \DateTimeImmutable();

            foreach ($scheduled as $item) {
                $submission = $documentModel->findLatestSubmission($userId, $item['exam_schedule_id'], $item['document_type_id']);

                $startsAt = $item['document_submission_starts_at'] ? new \DateTimeImmutable($item['document_submission_starts_at']) : null;
                $deadline = $item['document_submission_deadline'] ? new \DateTimeImmutable($item['document_submission_deadline']) : null;

                // Locked once a submission exists with document_status = 'submitted' —
                // no resubmission permitted at all, regardless of validation outcome.
                $isLocked = $submission && $submission['document_status'] === 'submitted';

                $windowOpen = $proposalSubmitted
                    && !$isLocked
                    && (!$startsAt || $now >= $startsAt)
                    && (!$deadline || $now <= $deadline);

                $requirements[] = array_merge($item, [
                    'submission'    => $submission,
                    'is_locked'     => $isLocked,
                    'window_open'   => $windowOpen,
                    'not_open_yet'  => $startsAt && $now < $startsAt,
                    'past_deadline' => $deadline && $now > $deadline,
                ]);
            }
        }

        return $this->twig->render($response, 'students/requirements.twig', [
            'active_page'        => 'requirements',
            'first_name'         => $_SESSION['first_name'] ?? '',
            'student_number'     => $student['student_number'] ?? null,
            'no_thesis_schedule' => !$thesisScheduleId,
            'proposal_submitted' => $proposalSubmitted,
            'requirements'       => $requirements,
            'csrf_token'         => $this->csrfToken(),
            'error'              => $_SESSION['flash_error'] ?? null,
            'success'            => $_SESSION['flash_success'] ?? null,
        ]);
    }

    public function upload(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/student/requirements');
        }

        $userId = $_SESSION['user_id'];
        $student = $this->getStudentRecord($userId);
        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/student/requirements');
        }

        if (!$this->proposalIsSubmitted($student['student_id'])) {
            $_SESSION['flash_error'] = 'You must submit your thesis proposal before submitting requirement documents.';
            return $this->redirect($response, '/student/requirements');
        }

        $examScheduleId = trim((string) ($data['exam_schedule_id'] ?? ''));
        $documentTypeId = trim((string) ($data['document_type_id'] ?? ''));
        $thesisScheduleId = $this->getActiveThesisScheduleId($student['student_id']);
        $action = ($data['action'] ?? 'submit') === 'draft' ? 'draft' : 'submitted';

        if (!$examScheduleId || !$documentTypeId || !$thesisScheduleId) {
            $_SESSION['flash_error'] = 'Invalid submission.';
            return $this->redirect($response, '/student/requirements');
        }

        $esStmt = $this->db->prepare(
            "SELECT esd.*, es.thesis_schedule_id
             FROM exam_schedule_documents esd
             JOIN exam_schedule es ON es.exam_schedule_id = esd.exam_schedule_id
             WHERE esd.exam_schedule_id = :exam_schedule_id
               AND esd.document_type_id = :document_type_id
               AND es.thesis_schedule_id = :thesis_schedule_id
             LIMIT 1"
        );
        $esStmt->execute([
            'exam_schedule_id'   => $examScheduleId,
            'document_type_id'   => $documentTypeId,
            'thesis_schedule_id' => $thesisScheduleId,
        ]);
        $examSchedule = $esStmt->fetch();

        if (!$examSchedule) {
            $_SESSION['flash_error'] = 'That document is not scheduled for review under your thesis schedule.';
            return $this->redirect($response, '/student/requirements');
        }

        $documentModel = new Document($this->db);

        // Lock check: refuse if this slot already has a 'submitted' document.
        // Drafts CAN be overwritten (replaced) below.
        $existing = $documentModel->findLatestSubmission($userId, $examScheduleId, $documentTypeId);
        if ($existing && $existing['document_status'] === 'submitted') {
            $_SESSION['flash_error'] = 'This document has already been submitted and cannot be changed.';
            return $this->redirect($response, '/student/requirements');
        }

        $now = new \DateTimeImmutable();
        if ($examSchedule['document_submission_starts_at'] && $now < new \DateTimeImmutable($examSchedule['document_submission_starts_at'])) {
            $_SESSION['flash_error'] = 'Submissions for this document are not open yet.';
            return $this->redirect($response, '/student/requirements');
        }
        if ($examSchedule['document_submission_deadline'] && $now > new \DateTimeImmutable($examSchedule['document_submission_deadline'])) {
            $_SESSION['flash_error'] = 'The submission deadline for this document has passed.';
            return $this->redirect($response, '/student/requirements');
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['document_file'] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Please choose a file to upload.';
            return $this->redirect($response, '/student/requirements');
        }

        $mimeType = $file->getClientMediaType();
        if (!in_array($mimeType, self::ALLOWED_MIME, true)) {
            $_SESSION['flash_error'] = 'Only PDF files are accepted.';
            return $this->redirect($response, '/student/requirements');
        }

        $sizeKb = (int) ceil($file->getSize() / 1024);
        if ($sizeKb > self::MAX_SIZE_KB) {
            $_SESSION['flash_error'] = 'File exceeds the 10MB limit.';
            return $this->redirect($response, '/student/requirements');
        }

        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }

                // Replace any existing draft for this slot — a draft can be
        // freely overwritten, unlike a submitted document.
        if ($existing) {
            $oldPath = __DIR__ . '/../../public/' . $existing['file_path'];
            if (is_file($oldPath)) {
                unlink($oldPath);
            }
            $documentModel->delete($existing['document_id']);
        }

        $storedName = bin2hex(random_bytes(16)) . '.pdf';
        $destination = self::UPLOAD_DIR . '/' . $storedName;
        $file->moveTo($destination);

        // Resolve the student's active (non-rejected) proposal, if any,
        // so this document is linked to it rather than left orphaned.
        $proposalStmt = $this->db->prepare(
            "SELECT proposal_id FROM thesis_proposals
             WHERE student_id = :student_id AND status <> 'rejected'
             ORDER BY created_at DESC LIMIT 1"
        );
        $proposalStmt->execute(['student_id' => $student['student_id']]);
        $proposalId = $proposalStmt->fetchColumn() ?: null;

        $documentModel->createAndLinkToSchedule([
            'user_id'          => $userId,
            'uploaded_by'      => $userId,
            'document_type_id' => $examSchedule['document_type_id'],
            'document_status'  => $action, // 'draft' or 'submitted'
            'file_name'        => $file->getClientFilename(),
            'file_path'        => 'uploads/documents/' . $storedName,
            'file_size_kb'     => $sizeKb,
            'mime_type'        => $mimeType,
        ], $examScheduleId, $examSchedule['document_type_id'], $proposalId);

        $_SESSION['flash_success'] = $action === 'submitted'
            ? 'Document submitted and awaiting review. It cannot be changed further.'
            : 'Draft saved. You can replace it or submit it later.';

        return $this->redirect($response, '/student/requirements');
    }



        public function submitDraft(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/student/requirements');
        }

        $userId = $_SESSION['user_id'];
        $student = $this->getStudentRecord($userId);
        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/student/requirements');
        }

        $documentId = trim((string) ($data['document_id'] ?? ''));
        if (!$documentId) {
            $_SESSION['flash_error'] = 'Invalid submission.';
            return $this->redirect($response, '/student/requirements');
        }

        $documentModel = new Document($this->db);
        $doc = $documentModel->findById($documentId);

        // Ownership check: only the student who owns this document can
        // submit it — a POSTed document_id belonging to someone else
        // must not be actionable here.
        if (!$doc || $doc['user_id'] !== $userId) {
            $_SESSION['flash_error'] = 'That document could not be found.';
            return $this->redirect($response, '/student/requirements');
        }

        if ($documentModel->submitDraft($documentId)) {
            $_SESSION['flash_success'] = 'Document submitted and awaiting review. It cannot be changed further.';
        } else {
            $_SESSION['flash_error'] = 'That document is not a draft, or could not be submitted.';
        }

        return $this->redirect($response, '/student/requirements');
    }


    
}