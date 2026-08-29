<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Payments for the per-document-type review fee (document_review_rates),
 * scoped precisely to (exam_schedule_id, document_type_id) — unlike
 * thesis_payments, which has no document_type_id and so can't say
 * which specific document a payment against an exam window covers
 * once that window requires more than one document type.
 */
class DocumentPayment
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(array $data, string $thesisRegistrationId): string
    {
        $id = $this->generateUuid();
        $stmt = $this->db->prepare(
            "INSERT INTO document_payment
                (document_payment_id, thesis_registration_id, exam_schedule_id, document_type_id, amount, currency, payment_method, reference_number, status, payment_date)
             VALUES
                (:id, :thesis_registration_id, :exam_schedule_id, :document_type_id, :amount, :currency, :payment_method, :reference_number, 'pending', :payment_date)"
        );
        $stmt->execute([
            'id'                     => $id,
            'thesis_registration_id' => $thesisRegistrationId,
            'exam_schedule_id'       => $data['exam_schedule_id'],
            'document_type_id'       => $data['document_type_id'],
            'amount'                 => $data['amount'],
            'currency'               => $data['currency'] ?? 'KES',
            'payment_method'         => $data['payment_method'],
            'reference_number'       => $data['reference_number'] ?? null,
            'payment_date'           => $data['payment_date'] ?? date('Y-m-d'),
        ]);
        return $id;
    }

    public function sumConfirmed(string $thesisRegistrationId, string $examScheduleId, string $documentTypeId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM document_payment
             WHERE thesis_registration_id = :id AND exam_schedule_id = :exam_schedule_id
               AND document_type_id = :document_type_id AND status = 'confirmed'"
        );
        $stmt->execute([
            'id'               => $thesisRegistrationId,
            'exam_schedule_id' => $examScheduleId,
            'document_type_id' => $documentTypeId,
        ]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * Payment history for one registration, with the document type's
     * display name attached — for the student's payment history table.
     */
    public function findByRegistrationId(string $thesisRegistrationId): array
    {
        $stmt = $this->db->prepare(
            "SELECT dp.*, dt.doc_type_name
             FROM document_payment dp
             JOIN document_types dt ON dt.doc_type_id = dp.document_type_id
             WHERE dp.thesis_registration_id = :id
             ORDER BY dp.created_at DESC"
        );
        $stmt->execute(['id' => $thesisRegistrationId]);
        return $stmt->fetchAll();
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
