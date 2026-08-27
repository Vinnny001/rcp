<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Document
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByUploader(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM documents
             WHERE uploaded_by = :user_id
             ORDER BY uploaded_at DESC"
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function create(array $data): string
    {
        $documentId = $this->generateUuid();
        $stmt = $this->db->prepare(
            "INSERT INTO documents
                (document_id, user_id, uploaded_by, document_type_id, document_status, file_name, file_path, file_size_kb, mime_type)
             VALUES
                (:document_id, :user_id, :uploaded_by, :document_type_id, :document_status, :file_name, :file_path, :file_size_kb, :mime_type)"
        );

        $stmt->execute([
            'document_id'      => $documentId,
            'user_id'          => $data['user_id'],
            'uploaded_by'      => $data['uploaded_by'],
            'document_type_id' => $data['document_type_id'],
            'document_status'  => $data['document_status'] ?? 'submitted',
            'file_name'        => $data['file_name'],
            'file_path'        => $data['file_path'],
            'file_size_kb'     => $data['file_size_kb'],
            'mime_type'        => $data['mime_type'],
        ]);

        return $documentId;
    }

    public function findBySupervisorId(string $lecturerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT d.*,
                    s.student_number,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name
             FROM documents d
             JOIN students s ON s.user_id = d.uploaded_by
             JOIN users u ON u.user_id = d.uploaded_by
             JOIN supervision_assignments sa ON sa.student_id = s.student_id
             WHERE sa.supervisor_id = :lecturer_id
               AND sa.is_active = 1
             ORDER BY d.uploaded_at DESC"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
        return $stmt->fetchAll();
    }

    public function updateValidation(string $documentId, string $status, ?string $notes, string $validatedByUserId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE documents
             SET validation_status = :status, validation_notes = :notes, validated_by = :validated_by
             WHERE document_id = :document_id"
        );
        $stmt->execute([
            'status'       => $status,
            'notes'        => $notes,
            'validated_by' => $validatedByUserId,
            'document_id'  => $documentId,
        ]);
    }

    public function findById(string $documentId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM documents WHERE document_id = :id LIMIT 1");
        $stmt->execute(['id' => $documentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function linkToProposal(string $documentId, string $proposalId, string $documentTypeId, ?string $examScheduleId = null): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO exam_documents (exam_document_id, document_id, proposal_id, exam_schedule_id, document_type_id)
             VALUES (:exam_document_id, :document_id, :proposal_id, :exam_schedule_id, :document_type_id)"
        );
        $stmt->execute([
            'exam_document_id' => $this->generateUuid(),
            'document_id'      => $documentId,
            'proposal_id'      => $proposalId,
            'exam_schedule_id' => $examScheduleId,
            'document_type_id' => $documentTypeId,
        ]);
    }

    public function findByProposalAndType(string $proposalId, string $documentTypeId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT d.*, ed.exam_document_id, ed.submitted_at
             FROM exam_documents ed
             JOIN documents d ON d.document_id = ed.document_id
             WHERE ed.proposal_id = :proposal_id AND ed.document_type_id = :document_type_id
             ORDER BY ed.submitted_at DESC
             LIMIT 1"
        );
        $stmt->execute(['proposal_id' => $proposalId, 'document_type_id' => $documentTypeId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * All document types scheduled for a given thesis_schedule — this is
     * what "counts" as a real requirement; a document type not listed
     * here isn't being checked/reviewed for this schedule, and shouldn't
     * be submittable against it.
     */
    public function findScheduledForThesisSchedule(string $thesisScheduleId): array
    {
        $stmt = $this->db->prepare(
            "SELECT esd.esd_id, esd.exam_schedule_id, esd.document_type_id,
                    esd.document_submission_starts_at, esd.document_submission_deadline,
                    es.exam_type, es.exam_schedule_description,
                    dt.doc_type_name
             FROM exam_schedule_documents esd
             JOIN exam_schedule es ON es.exam_schedule_id = esd.exam_schedule_id
             JOIN document_types dt ON dt.doc_type_id = esd.document_type_id
             WHERE es.thesis_schedule_id = :thesis_schedule_id
             ORDER BY esd.document_submission_deadline ASC"
        );
        $stmt->execute(['thesis_schedule_id' => $thesisScheduleId]);
        return $stmt->fetchAll();
    }

    /**
     * The most recent submission a user has made against a specific
     * exam_schedule slot, if any.
     */
    /**
     * Latest submission for a specific document type within a specific
     * exam_schedule — now needs BOTH ids, since one exam_schedule can
     * cover multiple document types and a submission must match the
     * exact slot, not just the event.
     */
    public function findLatestSubmission(string $userId, string $examScheduleId, string $documentTypeId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT d.*, ed.exam_document_id, ed.submitted_at, ed.requires_resubmit
             FROM exam_documents ed
             JOIN documents d ON d.document_id = ed.document_id
             WHERE ed.exam_schedule_id = :exam_schedule_id
               AND ed.document_type_id = :document_type_id
               AND d.user_id = :user_id
             ORDER BY ed.submitted_at DESC
             LIMIT 1"
        );
        $stmt->execute([
            'exam_schedule_id' => $examScheduleId,
            'document_type_id' => $documentTypeId,
            'user_id'          => $userId,
        ]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

            /**
     * Creates a document and links it to a specific exam_schedule_documents
     * slot in one step. If the student has an active (non-rejected)
     * thesis proposal, that proposal_id is included on the link too —
     * a document type that happens to correspond to the proposal itself
     * (or any type the student is submitting while a proposal exists)
     * shouldn't be orphaned from it just because it came through the
     * generic requirements-upload flow rather than the proposal page.
     */
    public function createAndLinkToSchedule(array $data, string $examScheduleId, string $documentTypeId, ?string $proposalId = null): string
    {
        $documentId = $this->create($data);

        $stmt = $this->db->prepare(
            "INSERT INTO exam_documents (exam_document_id, document_id, proposal_id, exam_schedule_id, document_type_id)
             VALUES (:exam_document_id, :document_id, :proposal_id, :exam_schedule_id, :document_type_id)"
        );
        $stmt->execute([
            'exam_document_id' => $this->generateUuid(),
            'document_id'      => $documentId,
            'proposal_id'      => $proposalId,
            'exam_schedule_id' => $examScheduleId,
            'document_type_id' => $documentTypeId,
        ]);

        return $documentId;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

        /**
     * Deletes a document. First removes any exam_documents link
     * referencing it (that table has a FK back to documents.document_id,
     * so leaving the link in place causes the delete to fail with a
     * constraint violation), then deletes the document row itself.
     */
    public function delete(string $documentId): void
    {
        $unlink = $this->db->prepare("DELETE FROM exam_documents WHERE document_id = :id");
        $unlink->execute(['id' => $documentId]);

        $stmt = $this->db->prepare("DELETE FROM documents WHERE document_id = :id");
        $stmt->execute(['id' => $documentId]);
    }



        /**
     * Promotes an existing document from 'draft' to 'submitted' without
     * requiring a new file upload — used when a student is ready to
     * finalize a draft they already uploaded, as-is.
     */
    public function submitDraft(string $documentId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE documents SET document_status = 'submitted'
             WHERE document_id = :id AND document_status = 'draft'"
        );
        $stmt->execute(['id' => $documentId]);
        return $stmt->rowCount() > 0;
    }




        /**
     * All documents belonging to a user (documents.user_id — who the
     * document is FOR, not necessarily who uploaded it), regardless of
     * which flow created them (proposal, requirements, admin-uploaded).
     * Includes the document type name and, where applicable, which
     * proposal or exam_schedule slot it's linked to, for display.
     */
    public function findByOwner(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT d.*, dt.doc_type_name,
                    ed.proposal_id, ed.exam_schedule_id, ed.submitted_at
             FROM documents d
             JOIN document_types dt ON dt.doc_type_id = d.document_type_id
             LEFT JOIN exam_documents ed ON ed.document_id = d.document_id
             WHERE d.user_id = :user_id
             ORDER BY d.uploaded_at DESC"
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }
}
