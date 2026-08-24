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

    public function create(array $data): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO documents
                (proposal_id, uploaded_by, document_type, file_name, file_path, file_size_kb, mime_type)
             VALUES
                (:proposal_id, :uploaded_by, :document_type, :file_name, :file_path, :file_size_kb, :mime_type)"
        );

        $stmt->execute([
            'proposal_id'    => $data['proposal_id'] ?? null,
            'uploaded_by'    => $data['uploaded_by'],
            'document_type'  => $data['document_type'],
            'file_name'      => $data['file_name'],
            'file_path'      => $data['file_path'],
            'file_size_kb'   => $data['file_size_kb'],
            'mime_type'      => $data['mime_type'],
        ]);
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




        public function findByProposalAndType(string $proposalId, string $documentType): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM documents
             WHERE proposal_id = :proposal_id AND document_type = :document_type
             ORDER BY uploaded_at DESC
             LIMIT 1"
        );
        $stmt->execute(['proposal_id' => $proposalId, 'document_type' => $documentType]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(string $documentId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM documents WHERE document_id = :id LIMIT 1");
        $stmt->execute(['id' => $documentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function delete(string $documentId): void
    {
        $stmt = $this->db->prepare("DELETE FROM documents WHERE document_id = :id");
        $stmt->execute(['id' => $documentId]);
    }




}