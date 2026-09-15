<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Whether a student's fees are clear enough to sit an exam.
 *
 * It asks the question the Thesis Fees page answers —
 * ThesisRegistration::computeOwed() — so the two cannot disagree: a
 * student is held back only by what that page says they owe. A fee set
 * to zero (waived) or not set at all is not owed, and neither is one
 * that has not fallen due yet.
 *
 * Three charges are reported for an exam window:
 *   - thesis registration   (thesis_payments, one-off)
 *   - thesis review fee     (thesis_payments, per academic year)
 *   - a review fee for each document the window requires
 *                           (document_payment, per window and type)
 *
 * An owed charge with a payment waiting for the registrar still blocks —
 * the money is not verified — but reads as pending rather than unpaid,
 * because the student can do nothing about a verification and telling
 * them to "pay" would be wrong.
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

        $owed = (new ThesisRegistration($this->db))->computeOwed($registration);
        $registrationId = $registration['thesis_registration_id'];

        $owedRegistration = array_values(array_filter($owed, fn (array $o): bool => $o['fee_type'] === 'thesis_registration'));
        $owedReview = array_values(array_filter($owed, fn (array $o): bool => $o['fee_type'] === 'thesis_review_fee'));

        $items = [
            $this->item(
                'Thesis registration',
                $owedRegistration,
                $this->thesisPaymentStatuses($registrationId, 'thesis_registration', [])
            ),
            $this->item(
                'Thesis review fee',
                $owedReview,
                $this->thesisPaymentStatuses($registrationId, 'thesis_review_fee', array_column($owedReview, 'year'))
            ),
        ];

        foreach ($this->requiredDocuments($examScheduleId) as $doc) {
            $owedDocument = array_values(array_filter(
                $owed,
                fn (array $o): bool => $o['fee_type'] === 'document_review_fee'
                    && $o['exam_schedule_id'] === $examScheduleId
                    && $o['document_type_id'] === $doc['document_type_id']
            ));
            $items[] = $this->item(
                $doc['doc_type_name'] . ' review fee',
                $owedDocument,
                $this->documentPaymentStatuses($registrationId, $examScheduleId, $doc['document_type_id'])
            );
        }

        $states = array_column($items, 'state');

        return [
            'clear' => !in_array('unpaid', $states, true) && !in_array('pending', $states, true),
            'awaiting_verification' => in_array('pending', $states, true) && !in_array('unpaid', $states, true),
            'items' => $items,
        ];
    }

    /**
     * One charge, from what is owed on it and the payments made towards it.
     *
     * @param array<int, array<string, mixed>> $owed entries from computeOwed() for this charge
     * @param array<int, string> $statuses payment statuses, newest first
     * @return array<string, mixed>
     */
    private function item(string $label, array $owed, array $statuses): array
    {
        if ($owed === []) {
            // Nothing owed: paid, waived, not set, or not due yet.
            $paid = in_array('confirmed', $statuses, true);

            return [
                'label'    => $label,
                'state'    => $paid ? 'confirmed' : 'not_owed',
                'detail'   => $paid ? 'Paid and confirmed.' : 'Nothing owed.',
                'amount'   => null,
                'currency' => null,
            ];
        }

        $remaining = array_sum(array_column($owed, 'remaining'));
        $currency = $owed[0]['currency'] ?? null;

        if (in_array('pending', $statuses, true)) {
            return [
                'label'    => $label,
                'state'    => 'pending',
                'detail'   => 'Paid — waiting for the registrar to confirm it.',
                'amount'   => $remaining,
                'currency' => $currency,
            ];
        }

        return [
            'label'    => $label,
            'state'    => 'unpaid',
            'detail'   => ($statuses[0] ?? null) === 'rejected'
                ? 'The last payment was rejected. Please pay again.'
                : trim(($currency ?? '') . ' ' . number_format((float) $remaining, 2)) . ' not paid yet.',
            'amount'   => $remaining,
            'currency' => $currency,
        ];
    }

    /**
     * @return array<string, mixed>|null the registration row, as computeOwed() needs it
     */
    private function activeRegistration(string $studentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT str.*, ts.program_id
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
    private function requiredDocuments(string $examScheduleId): array
    {
        $stmt = $this->db->prepare(
            "SELECT esd.document_type_id, dt.doc_type_name
             FROM exam_schedule_documents esd
             JOIN document_types dt ON dt.doc_type_id = esd.document_type_id
             WHERE esd.exam_schedule_id = :exam_schedule_id
             ORDER BY dt.doc_type_name"
        );
        $stmt->execute(['exam_schedule_id' => $examScheduleId]);

        return $stmt->fetchAll();
    }

    /**
     * Statuses of payments towards a thesis fee, newest first. For the
     * review fee, $years narrows them to the periods still owed.
     *
     * @param array<int, int|null> $years
     * @return array<int, string>
     */
    private function thesisPaymentStatuses(string $registrationId, string $feeType, array $years): array
    {
        $sql = "SELECT status FROM thesis_payments
                WHERE thesis_registration_id = ? AND fee_type = ?";
        $params = [$registrationId, $feeType];

        $years = array_values(array_filter($years, fn ($year): bool => $year !== null));
        if ($years !== []) {
            $sql .= " AND thesis_year IN (" . implode(',', array_fill(0, count($years), '?')) . ")";
            $params = array_merge($params, $years);
        }

        $stmt = $this->db->prepare($sql . " ORDER BY created_at DESC");
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @return array<int, string> newest first
     */
    private function documentPaymentStatuses(string $registrationId, string $examScheduleId, string $documentTypeId): array
    {
        $stmt = $this->db->prepare(
            "SELECT status FROM document_payment
             WHERE thesis_registration_id = :registration_id
               AND exam_schedule_id = :exam_schedule_id
               AND document_type_id = :document_type_id
             ORDER BY created_at DESC"
        );
        $stmt->execute([
            'registration_id'  => $registrationId,
            'exam_schedule_id' => $examScheduleId,
            'document_type_id' => $documentTypeId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
