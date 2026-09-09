<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Whether a student's fees are clear enough to sit an exam.
 *
 * Three separate charges have to be settled, and they live in two
 * different tables:
 *   - thesis registration    (thesis_payments, one-off)
 *   - thesis review fee      (thesis_payments, per exam schedule)
 *   - exam document fees     (document_payment, one per document the
 *                             exam schedule requires)
 *
 * A payment that is merely *pending* still blocks — the money is not
 * verified — but it is reported differently from one never made,
 * because the student can do nothing about a pending verification and
 * telling them to "pay" would be wrong.
 */
class ExamFeeGate
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array{clear: bool, awaiting_verification: bool, items: array<int, array<string, mixed>>}
     */
    public function statusFor(string $studentId, string $examScheduleId): array
    {
        $registration = $this->activeRegistration($studentId);

        if (!$registration) {
            return [
                'clear' => false,
                'awaiting_verification' => false,
                'items' => [[
                    'label'  => 'Thesis registration',
                    'state'  => 'unpaid',
                    'detail' => 'You are not registered for a thesis yet.',
                    'amount' => null,
                    'currency' => null,
                ]],
            ];
        }

        $items = [
            $this->thesisFeeItem($registration['thesis_registration_id'], 'thesis_registration', 'Thesis registration', null),
            $this->thesisFeeItem($registration['thesis_registration_id'], 'thesis_review_fee', 'Thesis review fee', $examScheduleId),
        ];

        foreach ($this->requiredDocuments($examScheduleId, $registration['program_id']) as $doc) {
            $items[] = $this->documentFeeItem($registration['thesis_registration_id'], $examScheduleId, $doc);
        }

        $states = array_column($items, 'state');

        return [
            'clear' => !in_array('unpaid', $states, true) && !in_array('pending', $states, true),
            'awaiting_verification' => in_array('pending', $states, true) && !in_array('unpaid', $states, true),
            'items' => $items,
        ];
    }

    private function activeRegistration(string $studentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT str.thesis_registration_id, ts.program_id
             FROM student_thesis_registrations str
             JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
             WHERE str.student_id = :student_id AND str.status <> 'withdrawn'
             ORDER BY str.registered_at DESC LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requiredDocuments(string $examScheduleId, string $programId): array
    {
        $stmt = $this->db->prepare(
            "SELECT esd.document_type_id, dt.doc_type_name,
                    drr.amount, drr.currency
             FROM exam_schedule_documents esd
             JOIN document_types dt ON dt.doc_type_id = esd.document_type_id
             LEFT JOIN document_review_rates drr
                    ON drr.document_type_id = esd.document_type_id
                   AND drr.program_id = :program_id
             WHERE esd.exam_schedule_id = :exam_schedule_id
             ORDER BY dt.doc_type_name"
        );
        $stmt->execute(['exam_schedule_id' => $examScheduleId, 'program_id' => $programId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>
     */
    private function thesisFeeItem(string $registrationId, string $feeType, string $label, ?string $examScheduleId): array
    {
        $sql = "SELECT status, amount, currency FROM thesis_payments
                WHERE thesis_registration_id = :registration_id AND fee_type = :fee_type";
        $params = ['registration_id' => $registrationId, 'fee_type' => $feeType];

        if ($examScheduleId !== null) {
            $sql .= " AND exam_schedule_id = :exam_schedule_id";
            $params['exam_schedule_id'] = $examScheduleId;
        }

        // Confirmed first: a rejected attempt followed by a good one
        // should read as paid, not as the most recent failure.
        $sql .= " ORDER BY status = 'confirmed' DESC, created_at DESC LIMIT 1";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch();

        return [
            'label'    => $label,
            'state'    => $this->stateFrom($row['status'] ?? null),
            'detail'   => $this->detailFrom($row['status'] ?? null),
            'amount'   => $row['amount'] ?? null,
            'currency' => $row['currency'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    private function documentFeeItem(string $registrationId, string $examScheduleId, array $doc): array
    {
        $stmt = $this->db->prepare(
            "SELECT status FROM document_payment
             WHERE thesis_registration_id = :registration_id
               AND exam_schedule_id = :exam_schedule_id
               AND document_type_id = :document_type_id
             ORDER BY status = 'confirmed' DESC, created_at DESC LIMIT 1"
        );
        $stmt->execute([
            'registration_id'  => $registrationId,
            'exam_schedule_id' => $examScheduleId,
            'document_type_id' => $doc['document_type_id'],
        ]);
        $status = $stmt->fetchColumn();

        return [
            'label'    => $doc['doc_type_name'] . ' review fee',
            'state'    => $this->stateFrom($status ?: null),
            'detail'   => $this->detailFrom($status ?: null),
            'amount'   => $doc['amount'],
            'currency' => $doc['currency'],
        ];
    }

    private function stateFrom(?string $status): string
    {
        return match ($status) {
            'confirmed' => 'confirmed',
            'pending'   => 'pending',
            default     => 'unpaid',
        };
    }

    private function detailFrom(?string $status): string
    {
        return match ($status) {
            'confirmed' => 'Paid and confirmed.',
            'pending'   => 'Paid — waiting for the registrar to confirm it.',
            'rejected'  => 'The last payment was rejected. Please pay again.',
            default     => 'Not paid yet.',
        };
    }
}
