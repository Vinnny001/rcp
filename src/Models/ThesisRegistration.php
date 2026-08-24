<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class ThesisRegistration
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findActiveByStudentId(string $studentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM student_thesis_registrations
             WHERE student_id = :student_id AND status = 'active'
             LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findById(string $thesisRegistrationId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM student_thesis_registrations WHERE thesis_registration_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $thesisRegistrationId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

        public function create(string $studentId, string $thesisScheduleId): string
    {
        $id = $this->generateUuid();
        $stmt = $this->db->prepare(
            "INSERT INTO student_thesis_registrations (thesis_registration_id, student_id, thesis_schedule_id, status)
             VALUES (:id, :student_id, :thesis_schedule_id, 'active')"
        );
        $stmt->execute(['id' => $id, 'student_id' => $studentId, 'thesis_schedule_id' => $thesisScheduleId]);
        return $id;
    }

    /**
     * Full years elapsed since a given anchor date (e.g. proposal
     * submission_date). Year 0 = not yet at the first anniversary.
     * Year 1 = first anniversary passed, one review fee owed. Uncapped.
     */
        private function yearsElapsedSince(string $anchorDate): int
    {
        $anchor = new \DateTimeImmutable($anchorDate);
        $now = new \DateTimeImmutable();
        $diff = $anchor->diff($now);
        return (int) $diff->y;
    }

    /**
     * Computes everything owed right now.
     *
     * 1. Registration fee — one-time, via thesis_schedules ->
     *    thesis_registration_rates. Must clear before review fees are
     *    evaluated at all.
     *
     * 2. Review fees — one per document type under exam_schedule for
     *    this thesis_schedule. A review fee becomes "due" once
     *    NOW() >= document_submission_starts_at + due_after_weeks
     *    (weeks counted from when document submission opens, not from
     *    registration or any other anchor). Each is tracked against its
     *    own exam_schedule_id, so two document types due in the same
     *    year no longer collide.
     */
    public function computeOwed(array $registration): array
    {
        $scheduleStmt = $this->db->prepare(
            "SELECT ts.schedule_id, ts.program_id, ts.thesis_registration_rates_id
             FROM thesis_schedules ts
             WHERE ts.schedule_id = :schedule_id LIMIT 1"
        );
        $scheduleStmt->execute(['schedule_id' => $registration['thesis_schedule_id']]);
        $schedule = $scheduleStmt->fetch();

        if (!$schedule) {
            return [];
        }

        $paymentModel = new ThesisPayment($this->db);
        $owed = [];

        // 1. Registration fee
        $regRateStmt = $this->db->prepare(
            "SELECT amount, currency, due_date FROM thesis_registration_rates WHERE rate_id = :rate_id LIMIT 1"
        );
        $regRateStmt->execute(['rate_id' => $schedule['thesis_registration_rates_id']]);
        $regRate = $regRateStmt->fetch();

        $regPaid = $paymentModel->sumConfirmed($registration['thesis_registration_id'], 'thesis_registration');
        $regRequired = $regRate ? (float) $regRate['amount'] : null;

        if ($regRequired !== null && $regPaid < $regRequired) {
            $owed[] = [
                'fee_type'  => 'thesis_registration',
                'year'      => null,
                'paid'      => $regPaid,
                'required'  => $regRequired,
                'remaining' => $regRequired - $regPaid,
                'currency'  => $regRate['currency'],
                'due_date'  => $regRate['due_date'],
            ];
            return $owed;
        }

        if ($registration['status'] !== 'active') {
            return $owed;
        }

        // 2. Review fees — per exam_schedule (document type), weeks
        // counted from document_submission_starts_at.
        $examSchedules = $this->db->prepare(
            "SELECT es.exam_schedule_id, es.document_type_id, es.document_submission_starts_at,
                    drr.amount, drr.currency, drr.due_after_weeks
             FROM exam_schedule es
             JOIN document_review_rates drr
                    ON drr.document_type_id = es.document_type_id
                   AND drr.program_id = :program_id
             WHERE es.thesis_schedule_id = :schedule_id
               AND es.document_submission_starts_at IS NOT NULL"
        );
        $examSchedules->execute([
            'program_id'  => $schedule['program_id'],
            'schedule_id' => $schedule['schedule_id'],
        ]);

        $now = new \DateTimeImmutable();

        foreach ($examSchedules->fetchAll() as $es) {
            $startsAt = new \DateTimeImmutable($es['document_submission_starts_at']);
            $dueAt = $startsAt->modify('+' . (int) $es['due_after_weeks'] . ' weeks');

            if ($now < $dueAt) {
                continue; // due_after_weeks hasn't elapsed yet
            }

            $year = $this->yearsElapsedSince($registration['registered_at']) ?: 1;
            $paid = $paymentModel->sumConfirmed(
                $registration['thesis_registration_id'], 'thesis_review_fee', $es['exam_schedule_id']
            );
            $required = (float) $es['amount'];

            if ($paid < $required) {
                $owed[] = [
                    'fee_type'         => 'thesis_review_fee',
                    'exam_schedule_id' => $es['exam_schedule_id'],
                    'year'             => $year,
                    'paid'             => $paid,
                    'required'         => $required,
                    'remaining'        => $required - $paid,
                    'currency'         => $es['currency'],
                    'due_date'         => $dueAt->format('Y-m-d'),
                ];
            }
        }

        return $owed;
    }




    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }



        /**
     * A student counts as registered for thesis if EITHER:
     *  - they have an active row in student_thesis_registrations, OR
     *  - they already have a thesis proposal on record (submitting a
     *    proposal implies registration must have happened, even if the
     *    registration row is somehow missing/out of sync).
     *
     * Use this for the "have they registered at all" check. Fee
     * calculations (computeOwed) still require the actual registration
     * row, since that's where program_id and registered_at live.
     */
    public function isRegisteredForThesis(string $studentId): bool
    {
        if ($this->findActiveByStudentId($studentId)) {
            return true;
        }

        $stmt = $this->db->prepare(
            "SELECT 1 FROM thesis_proposals WHERE student_id = :student_id LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);
        return (bool) $stmt->fetchColumn();
    }



}