<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * The fee charged for reviewing one document type under one program —
 * what ThesisRegistration::computeOwed() looks up (by program_id +
 * document_type_id) to work out what a student owes for each document
 * type scheduled under their exam windows.
 */
class DocumentReviewRate
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function all(): array
    {
        return $this->db->query(
            "SELECT drr.*, p.name AS program_name, dt.doc_type_name
             FROM document_review_rates drr
             JOIN programs p ON p.program_id = drr.program_id
             JOIN document_types dt ON dt.doc_type_id = drr.document_type_id
             ORDER BY p.name, dt.doc_type_name"
        )->fetchAll();
    }

    public function create(array $data, string $updatedBy): string
    {
        $rateId = $this->generateUuid();
        $stmt = $this->db->prepare(
            "INSERT INTO document_review_rates
                (rate_id, program_id, document_type_id, amount, currency, due_after_weeks, updated_by)
             VALUES (:rate_id, :program_id, :document_type_id, :amount, :currency, :due_after_weeks, :updated_by)"
        );
        $stmt->execute([
            'rate_id'           => $rateId,
            'program_id'        => $data['program_id'],
            'document_type_id'  => $data['document_type_id'],
            'amount'            => $data['amount'],
            'currency'          => $data['currency'] ?: 'KES',
            'due_after_weeks'   => $data['due_after_weeks'] !== '' ? (int) $data['due_after_weeks'] : null,
            'updated_by'        => $updatedBy,
        ]);
        return $rateId;
    }

    public function update(string $rateId, array $data, string $updatedBy): void
    {
        $stmt = $this->db->prepare(
            "UPDATE document_review_rates
             SET program_id = :program_id, document_type_id = :document_type_id, amount = :amount,
                 currency = :currency, due_after_weeks = :due_after_weeks, updated_by = :updated_by
             WHERE rate_id = :rate_id"
        );
        $stmt->execute([
            'program_id'        => $data['program_id'],
            'document_type_id'  => $data['document_type_id'],
            'amount'            => $data['amount'],
            'currency'          => $data['currency'] ?: 'KES',
            'due_after_weeks'   => $data['due_after_weeks'] !== '' ? (int) $data['due_after_weeks'] : null,
            'updated_by'        => $updatedBy,
            'rate_id'           => $rateId,
        ]);
    }

    public function delete(string $rateId): void
    {
        $this->db->prepare("DELETE FROM document_review_rates WHERE rate_id = :id")->execute(['id' => $rateId]);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
