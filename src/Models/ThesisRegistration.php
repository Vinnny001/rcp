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

        // 1. Registration fee — due_after_weeks counts from
        // registered_at, the moment the fee becomes payable, the same
        // anchor pattern review fees use with document_submission_starts_at.
        $regRateStmt = $this->db->prepare(
            "SELECT amount, currency, due_after_weeks FROM thesis_registration_rates WHERE rate_id = :rate_id LIMIT 1"
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
                'due_date'  => $this->dueDateFromWeeks($registration['registered_at'], $regRate['due_after_weeks']),
            ];
            return $owed;
        }

        if ($registration['status'] !== 'active') {
            return $owed;
        }

        // Review fees only apply once the student has an assigned
        // supervisor — no point charging for document review before
        // anyone is actually in place to review it.
        $supervisorStmt = $this->db->prepare(
            "SELECT tp.assigned_supervisor_id
             FROM thesis_proposals tp
             WHERE tp.student_id = :student_id
               AND tp.assigned_supervisor_id IS NOT NULL
             ORDER BY tp.created_at DESC
             LIMIT 1"
        );
        $supervisorStmt->execute(['student_id' => $registration['student_id']]);
        $hasSupervisor = (bool) $supervisorStmt->fetchColumn();

        if (!$hasSupervisor) {
            return $owed;
        }

        // 2. Review fees — per exam_schedule_documents row (document
        // type), weeks counted from document_submission_starts_at,
        // which now lives on exam_schedule_documents, not exam_schedule.
        $examSchedules = $this->db->prepare(
            "SELECT esd.exam_schedule_id, esd.document_type_id, esd.document_submission_starts_at,
                    drr.amount, drr.currency, drr.due_after_weeks
             FROM exam_schedule es
             JOIN exam_schedule_documents esd ON esd.exam_schedule_id = es.exam_schedule_id
             JOIN document_review_rates drr
                    ON drr.document_type_id = esd.document_type_id
                   AND drr.program_id = :program_id
             WHERE es.thesis_schedule_id = :schedule_id
               AND esd.document_submission_starts_at IS NOT NULL"
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




    /**
     * due_after_weeks is null-safe: a rate with no due_after_weeks set
     * has no deadline, matching the old due_date-can-be-null behaviour.
     */
    private function dueDateFromWeeks(string $anchorDate, ?int $dueAfterWeeks): ?string
    {
        if ($dueAfterWeeks === null) {
            return null;
        }

        return (new \DateTimeImmutable($anchorDate))
            ->modify('+' . $dueAfterWeeks . ' weeks')
            ->format('Y-m-d');
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



        /**
     * Everything scheduled to become due, informational only — does NOT
     * filter by whether the due date has passed (unlike computeOwed()).
     * Lets a student see what's coming before it's actionable. Still
     * respects the same gates as computeOwed(): registration must be
     * paid, and review fees only show once a supervisor is assigned —
     * otherwise this would leak fee amounts for stages the student
     * hasn't reached yet, which isn't useful, just confusing.
     */
    public function computeUpcoming(array $registration): array
    {
        $scheduleStmt = $this->db->prepare(
            "SELECT ts.schedule_id, ts.program_id, ts.thesis_registration_rates_id
             FROM thesis_schedules ts
             WHERE ts.schedule_id = :schedule_id LIMIT 1"
        );
        $scheduleStmt->execute(['schedule_id' => $registration['thesis_schedule_id']]);
        $schedule = $scheduleStmt->fetch();

        if (!$schedule || $registration['status'] !== 'active') {
            return [];
        }

        $paymentModel = new ThesisPayment($this->db);

        // Registration must already be paid — otherwise "upcoming" fees
        // would show before the student has even cleared the first gate.
        $regRateStmt = $this->db->prepare(
            "SELECT amount, due_after_weeks FROM thesis_registration_rates WHERE rate_id = :rate_id LIMIT 1"
        );
        $regRateStmt->execute(['rate_id' => $schedule['thesis_registration_rates_id']]);
        $regRate = $regRateStmt->fetch();
        $regPaid = $paymentModel->sumConfirmed($registration['thesis_registration_id'], 'thesis_registration');
        $regRequired = $regRate ? (float) $regRate['amount'] : null;

        if ($regRequired !== null && $regPaid < $regRequired) {
            return [];
        }

        // Same supervisor gate as computeOwed().
        $supervisorStmt = $this->db->prepare(
            "SELECT tp.assigned_supervisor_id
             FROM thesis_proposals tp
             WHERE tp.student_id = :student_id
               AND tp.assigned_supervisor_id IS NOT NULL
             ORDER BY tp.created_at DESC
             LIMIT 1"
        );
        $supervisorStmt->execute(['student_id' => $registration['student_id']]);
        if (!$supervisorStmt->fetchColumn()) {
            return [];
        }

        $examSchedules = $this->db->prepare(
            "SELECT esd.exam_schedule_id, esd.document_type_id, esd.document_submission_starts_at,
                    drr.amount, drr.currency, drr.due_after_weeks, dt.doc_type_name
             FROM exam_schedule es
             JOIN exam_schedule_documents esd ON esd.exam_schedule_id = es.exam_schedule_id
             JOIN document_review_rates drr
                    ON drr.document_type_id = esd.document_type_id
                   AND drr.program_id = :program_id
             JOIN document_types dt ON dt.doc_type_id = esd.document_type_id
             WHERE es.thesis_schedule_id = :schedule_id
               AND esd.document_submission_starts_at IS NOT NULL"
        );
        $examSchedules->execute([
            'program_id'  => $schedule['program_id'],
            'schedule_id' => $schedule['schedule_id'],
        ]);

        $now = new \DateTimeImmutable();
        $upcoming = [];

        foreach ($examSchedules->fetchAll() as $es) {
            $startsAt = new \DateTimeImmutable($es['document_submission_starts_at']);
            $dueAt = $startsAt->modify('+' . (int) $es['due_after_weeks'] . ' weeks');

            // Only show it here if it hasn't become due yet — once due,
            // it belongs in computeOwed()'s list instead, not duplicated here.
            if ($now >= $dueAt) {
                continue;
            }

            $paid = $paymentModel->sumConfirmed(
                $registration['thesis_registration_id'], 'thesis_review_fee', $es['exam_schedule_id']
            );
            $required = (float) $es['amount'];

            if ($paid < $required) {
                $upcoming[] = [
                    'fee_type'         => 'thesis_review_fee',
                    'doc_type_name'    => $es['doc_type_name'],
                    'exam_schedule_id' => $es['exam_schedule_id'],
                    'required'         => $required,
                    'currency'         => $es['currency'],
                    'due_date'         => $dueAt->format('Y-m-d'),
                ];
            }
        }

        return $upcoming;
    }



}