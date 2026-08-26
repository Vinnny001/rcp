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

    /**
     * students no longer carries department or program columns — a
     * student's program is reached through their active thesis
     * registration's schedule, and the department through that
     * program. Both breakdowns below follow that path.
     *
     * Students with no active thesis registration have no programme
     * yet and so fall outside these counts.
     */
    private const STUDENT_PROGRAM_JOIN =
        "JOIN student_thesis_registrations str ON str.student_id = s.student_id AND str.status = 'active'
         JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
         JOIN programs pr ON pr.program_id = ts.program_id";

    public function byDepartment(): array
    {
        $stmt = $this->db->query(
            "SELECT
                d.name AS department,
                COUNT(DISTINCT s.student_id) AS active_students,
                COUNT(DISTINCT CASE WHEN tp.status IN ('submitted','under_review') THEN tp.proposal_id END) AS open_proposals,
                COALESCE((
                    SELECT AVG(load_per_lecturer.active_count)
                    FROM (
                        SELECT COUNT(sa2.assignment_id) AS active_count
                        FROM lecturers l
                        JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
                        LEFT JOIN supervision_assignments sa2
                               ON sa2.supervisor_id = l.lecturer_id AND sa2.is_active = TRUE
                        WHERE il.department_id = d.department_id
                        GROUP BY l.lecturer_id
                    ) AS load_per_lecturer
                ), 0) AS avg_supervisor_load
             FROM students s
             " . self::STUDENT_PROGRAM_JOIN . "
             JOIN departments d ON d.department_id = pr.department_id
             LEFT JOIN thesis_proposals tp ON tp.student_id = s.student_id
             WHERE s.current_status = 'active'
             GROUP BY d.department_id, d.name
             ORDER BY d.name"
        );
        return $stmt->fetchAll();
    }


    public function byProgram(): array
    {
        $stmt = $this->db->query(
            "SELECT
                pr.name AS program,
                COUNT(DISTINCT s.student_id) AS active_students,
                COUNT(DISTINCT CASE WHEN tp.status IN ('submitted','under_review') THEN tp.proposal_id END) AS open_proposals
             FROM students s
             " . self::STUDENT_PROGRAM_JOIN . "
             LEFT JOIN thesis_proposals tp ON tp.student_id = s.student_id
             WHERE s.current_status = 'active'
             GROUP BY pr.program_id, pr.name
             ORDER BY pr.name"
        );
        return $stmt->fetchAll();
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