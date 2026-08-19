<?php

declare(strict_types=1);

namespace App\Models;

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

    public function pendingPaymentsTotal(): float
    {
        $stmt = $this->db->query("SELECT COALESCE(SUM(amount), 0) FROM payments WHERE status = 'pending'");
        return (float) $stmt->fetchColumn();
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

    
}