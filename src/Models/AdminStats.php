<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\ThesisRegistration;


use PDO;

class AdminStats
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function activeStudentCount(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM students WHERE current_status = 'active'");
        return (int) $stmt->fetchColumn();
    }

    public function lecturerCount(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM lecturers");
        return (int) $stmt->fetchColumn();
    }

    public function proposalsUnderReviewCount(): int
    {
        $stmt = $this->db->query("SELECT COUNT(*) FROM thesis_proposals WHERE status = 'under_review'");
        return (int) $stmt->fetchColumn();
    }

    /**
     * Sums pending payments across BOTH payment systems — the older
     * program-side `payments` table (registration/tuition/exam, mostly
     * dormant now per the thesis-first pivot) and the newer `thesis_payments`
     * table (thesis_registration/thesis_review_fee, where real activity
     * is happening currently). Assumes both are single-currency (KES) —
     * if either table ever holds mixed currencies, this sum becomes
     * meaningless and would need to be split per-currency instead.
     */
    public function pendingPaymentsTotal(): float
    {
        $stmt = $this->db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'pending'");
        $programPending = (float) $stmt->fetchColumn();

        $stmt = $this->db->query("SELECT COALESCE(SUM(amount), 0) FROM thesis_payments WHERE status = 'pending'");
        $thesisPending = (float) $stmt->fetchColumn();

        return $programPending + $thesisPending;
    }

    public function meetingsThisWeekCount(): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) FROM meetings
             WHERE scheduled_at BETWEEN NOW() AND NOW() + INTERVAL 7 DAY
               AND status = 'scheduled'"
        );
        return (int) $stmt->fetchColumn();
    }

    public function graduationPendingApprovalCount(): int
    {
        $stmt = $this->db->query(
            "SELECT COUNT(*) FROM graduation_list WHERE graduate_school_approved = FALSE"
        );
        return (int) $stmt->fetchColumn();
    }

    public function byDepartment(): array
    {
        $stmt = $this->db->query(
            "SELECT
                s.department,
                COUNT(DISTINCT s.student_id) AS active_students,
                COUNT(DISTINCT CASE WHEN tp.status IN ('submitted','under_review') THEN tp.proposal_id END) AS open_proposals,
                COALESCE(AVG(sub.active_count), 0) AS avg_supervisor_load
             FROM students s
             LEFT JOIN thesis_proposals tp ON tp.student_id = s.student_id
             LEFT JOIN (
                SELECT l.lecturer_id, l.department, COUNT(sa.assignment_id) AS active_count
                FROM lecturers l
                LEFT JOIN supervision_assignments sa ON sa.supervisor_id = l.lecturer_id AND sa.is_active = TRUE
                GROUP BY l.lecturer_id, l.department
             ) sub ON sub.department = s.department
             WHERE s.current_status = 'active'
             GROUP BY s.department"
        );
        return $stmt->fetchAll();
    }


    public function byProgram(): array
    {
        $studentCounts = [];
        $stmt = $this->db->query(
            "SELECT program, COUNT(*) AS cnt
             FROM students
             WHERE current_status = 'active'
             GROUP BY program"
        );
        foreach ($stmt->fetchAll() as $row) {
            $studentCounts[$row['program']] = (int) $row['cnt'];
        }

        $proposalCounts = [];
        $stmt = $this->db->query(
            "SELECT s.program, COUNT(tp.proposal_id) AS cnt
             FROM students s
             JOIN thesis_proposals tp ON tp.student_id = s.student_id
             WHERE tp.status IN ('submitted', 'under_review')
             GROUP BY s.program"
        );
        foreach ($stmt->fetchAll() as $row) {
            $proposalCounts[$row['program']] = (int) $row['cnt'];
        }

        $programs = array_unique(array_merge(
            array_keys($studentCounts),
            array_keys($proposalCounts)
        ));
        sort($programs);

        $result = [];
        foreach ($programs as $program) {
            $result[] = [
                'program'          => $program,
                'active_students'  => $studentCounts[$program] ?? 0,
                'open_proposals'   => $proposalCounts[$program] ?? 0,
            ];
        }

        return $result;
    }


    /**
     * Unpaid thesis fees across all actively-registered students — split
     * by fee type (registration vs review fee) so admin can see which
     * kind of arrears dominates, plus the combined total. Reuses
     * ThesisRegistration::computeOwed() rather than reimplementing the
     * elapsed-years/priority logic in SQL — that logic already exists
     * and is tested, duplicating it here would risk drifting out of sync.
     *
     * N+1 by nature (one computeOwed() call per active registration) —
     * fine at current scale, would need rework if thesis registrations
     * grow into the thousands.
     */
    public function unpaidThesisFeesSummary(): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM student_thesis_registrations WHERE status = 'active'"
        );
        $registrations = $stmt->fetchAll();

        $regModel = new ThesisRegistration($this->db);

        $totalUnpaid = 0.0;
        $registrationUnpaidCount = 0;
        $registrationUnpaidAmount = 0.0;
        $reviewUnpaidCount = 0;
        $reviewUnpaidAmount = 0.0;
        $currency = 'KES'; // assumes single-currency, same caveat as pendingPaymentsTotal()

        foreach ($registrations as $reg) {
            $owed = $regModel->computeOwed($reg);

            foreach ($owed as $item) {
                $totalUnpaid += $item['remaining'];

                if ($item['fee_type'] === 'thesis_registration') {
                    $registrationUnpaidCount++;
                    $registrationUnpaidAmount += $item['remaining'];
                } else {
                    $reviewUnpaidCount++;
                    $reviewUnpaidAmount += $item['remaining'];
                }
            }
        }

        return [
            'total_unpaid'              => $totalUnpaid,
            'currency'                  => $currency,
            'registration_unpaid_count' => $registrationUnpaidCount,
            'registration_unpaid_amount'=> $registrationUnpaidAmount,
            'review_unpaid_count'       => $reviewUnpaidCount,
            'review_unpaid_amount'      => $reviewUnpaidAmount,
        ];
    }

    
}