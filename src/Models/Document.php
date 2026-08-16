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
}