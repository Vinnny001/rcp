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

    public function create(string $studentId, string $programId): string
    {
        $id = $this->generateUuid();
        $stmt = $this->db->prepare(
            "INSERT INTO student_thesis_registrations (thesis_registration_id, student_id, program_id, status)
             VALUES (:id, :student_id, :program_id, 'active')"
        );
        $stmt->execute(['id' => $id, 'student_id' => $studentId, 'program_id' => $programId]);
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
     * Computes everything owed right now:
     *
     *  1. Registration fee — must clear before anything else is owed.
     *  2. Review fees — only start accruing once the student's thesis
     *     proposal has reached 'under_review' (or a later status:
     *     approved/rejected/revision_required). The "clock" for review
     *     fees starts from the proposal's submission_date (closest
     *     recorded anchor we have to "went under review"), and one fee
     *     is owed per full year elapsed since then — e.g. submitted for
     *     review this year -> Year 1 review fee owed now; still active a
     *     year later -> Year 2 also owed, and so on.
     *
     * @return array<int, array{fee_type:string, year:?int, paid:float, required:float, remaining:float, currency:string, due_date:?string}>
     */
    public function computeOwed(array $registration): array
    {
        $rateModel = new ThesisFeeRate($this->db);
        $paymentModel = new ThesisPayment($this->db);

        $owed = [];
        $programId = $registration['program_id'];

        // --- 1. Registration fee (blocks everything else until paid) ---
        $regRate = $rateModel->findRegistrationRate($programId);
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
            return $owed; // registration must clear before review fees apply
        }

        if ($registration['status'] !== 'active') {
            return $owed;
        }

        // --- 2. Review fees (gated on proposal reaching under_review) ---
        $proposalModel = new Proposal($this->db);
        $proposal = $proposalModel->findActiveByStudentId($registration['student_id']);

        $reviewGateStatuses = ['under_review', 'approved', 'rejected', 'revision_required'];

        if (!$proposal || !in_array($proposal['status'], $reviewGateStatuses, true) || !$proposal['submission_date']) {
            return $owed; // proposal hasn't reached review stage yet — no review fees due
        }

        $yearsElapsed = $this->yearsElapsedSince($proposal['submission_date']);
        $currentAcademicYear = (int) date('Y');

        for ($year = 1; $year <= max($yearsElapsed, 1); $year++) {
            $reviewRate = $rateModel->findReviewFeeRate($programId, $currentAcademicYear);
            if (!$reviewRate) {
                continue;
            }

            $paid = $paymentModel->sumConfirmed($registration['thesis_registration_id'], 'thesis_review_fee', $year);
            $required = (float) $reviewRate['amount'];

            if ($paid < $required) {
                $owed[] = [
                    'fee_type'  => 'thesis_review_fee',
                    'year'      => $year,
                    'paid'      => $paid,
                    'required'  => $required,
                    'remaining' => $required - $paid,
                    'currency'  => $reviewRate['currency'],
                    'due_date'  => $reviewRate['due_date'],
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
}