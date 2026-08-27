<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class ThesisPayment
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByRegistrationId(string $thesisRegistrationId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM thesis_payments WHERE thesis_registration_id = :id ORDER BY created_at DESC"
        );
        $stmt->execute(['id' => $thesisRegistrationId]);
        return $stmt->fetchAll();
    }

        /**
     * Sums confirmed payments for a thesis registration, by fee type.
     * - thesis_registration: one-time, no further scoping needed.
     * - thesis_review_fee: pass $examScheduleId to scope the sum to a
     *   specific document/exam schedule — without it, review-fee
     *   payments for different document types would be summed together,
     *   which is wrong now that review fees are per document type.
     */
    public function sumConfirmed(string $thesisRegistrationId, string $feeType, ?string $examScheduleId = null): float
    {
        if ($feeType === 'thesis_review_fee' && $examScheduleId !== null) {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM thesis_payments
                 WHERE thesis_registration_id = :id AND fee_type = :fee_type
                   AND exam_schedule_id = :exam_schedule_id AND status = 'confirmed'"
            );
            $stmt->execute([
                'id'               => $thesisRegistrationId,
                'fee_type'         => $feeType,
                'exam_schedule_id' => $examScheduleId,
            ]);
            return (float) $stmt->fetchColumn();
        }

        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM thesis_payments
             WHERE thesis_registration_id = :id AND fee_type = :fee_type AND status = 'confirmed'"
        );
        $stmt->execute(['id' => $thesisRegistrationId, 'fee_type' => $feeType]);
        return (float) $stmt->fetchColumn();
    }

    public function create(array $data, string $thesisRegistrationId): string
    {
        $id = $this->generateUuid();
        $stmt = $this->db->prepare(
            "INSERT INTO thesis_payments
                (thesis_payment_id, thesis_registration_id, exam_schedule_id, fee_type, thesis_year, amount, currency, payment_method, reference_number, status, payment_date)
             VALUES
                (:id, :thesis_registration_id, :exam_schedule_id, :fee_type, :thesis_year, :amount, :currency, :payment_method, :reference_number, 'pending', :payment_date)"
        );
        $stmt->execute([
        'id'                     => $id,
        'thesis_registration_id' => $thesisRegistrationId,
        'exam_schedule_id'       => $data['exam_schedule_id'] ?? null,
        'fee_type'               => $data['fee_type'],
        'thesis_year'            => $data['thesis_year'] ?? null,
        'amount'                 => $data['amount'],
        'currency'               => $data['currency'] ?? 'KES',
        'payment_method'         => $data['payment_method'],
        'reference_number'       => $data['reference_number'] ?? null,
        'payment_date'           => $data['payment_date'] ?? date('Y-m-d'),
        ]);
        return $id;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
