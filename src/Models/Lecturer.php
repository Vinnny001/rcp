<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Lecturer
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Lecturers a student can propose as supervisor.
     * Assumes a `department` column on `lecturers` and name fields on `users`.
     * Adjust the WHERE clause if you gate this by "accepting students" / load.
     *
     * @return array<int, array{lecturer_id:string, name:string, department:string}>
     */
        public function listAvailableSupervisors(): array
    {
        $stmt = $this->db->query(
            "SELECT l.lecturer_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS name,
                    COALESCE(d.name, el.department) AS department
             FROM lecturers l
             JOIN users u ON u.user_id = l.user_id
             LEFT JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
             LEFT JOIN departments d ON d.department_id = il.department_id
             LEFT JOIN external_lecturers el ON el.lecturer_id = l.lecturer_id
             WHERE l.is_available = 1
             ORDER BY u.first_name, u.last_name"
        );

        return $stmt->fetchAll() ?: [];
    }


    public function findByUserId(string $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM lecturers WHERE user_id = :user_id LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function countActiveSupervisions(string $lecturerId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM supervision_assignments
             WHERE supervisor_id = :lecturer_id AND is_active = 1"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
        return (int) $stmt->fetchColumn();
    }

    public function findPendingProposalReviews(string $lecturerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.proposal_id, p.title, p.status, p.submission_date,
                    s.student_number,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name
             FROM thesis_proposals p
             JOIN students s ON s.student_id = p.student_id
             JOIN users u ON u.user_id = s.user_id
             WHERE p.proposed_supervisor_id = :lecturer_id
               AND p.status IN ('submitted', 'under_review')
             ORDER BY p.submission_date ASC"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
        return $stmt->fetchAll();
    }


            public function findActiveSupervisions(string $lecturerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT sa.assignment_id, sa.role, sa.proposal_id, sa.student_id,
                    s.student_number, s.user_id AS student_user_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                    p.status AS proposal_status
             FROM supervision_assignments sa
             JOIN students s ON s.student_id = sa.student_id
             JOIN users u ON u.user_id = s.user_id
             LEFT JOIN thesis_proposals p ON p.proposal_id = sa.proposal_id
             WHERE sa.supervisor_id = :lecturer_id
               AND sa.is_active = 1
             ORDER BY u.first_name, u.last_name"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
        return $stmt->fetchAll();
    }

    public function listAllExcept(string $excludeUserId): array
    {
        $stmt = $this->db->prepare(
            "SELECT l.lecturer_id, l.user_id,
                    COALESCE(d.name, el.department) AS department,
                    CONCAT(u.first_name, ' ', u.last_name) AS name
             FROM lecturers l
             JOIN users u ON u.user_id = l.user_id
             LEFT JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
             LEFT JOIN departments d ON d.department_id = il.department_id
             LEFT JOIN external_lecturers el ON el.lecturer_id = l.lecturer_id
             WHERE l.user_id != :exclude_user_id
             ORDER BY u.first_name, u.last_name"
        );
        $stmt->execute(['exclude_user_id' => $excludeUserId]);
        return $stmt->fetchAll();
    }


        /**
     * Exam schedules relevant to this lecturer's active supervisees —
     * one row per (student, exam_schedule) pair, only upcoming ones
     * (ends_at in the future or null). Used both to show the lecturer
     * what's coming up, and to constrain meeting creation to a valid
     * window (must happen before ends_at).
     */
            public function findUpcomingExamSchedulesForSupervisees(string $lecturerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT es.exam_schedule_id, es.thesis_schedule_id, es.starts_at, es.ends_at,
                    es.exam_type, es.exam_schedule_description,
                    sa.student_id, sa.proposal_id,
                    s.student_number, s.user_id AS student_user_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name
             FROM supervision_assignments sa
             JOIN students s ON s.student_id = sa.student_id
             JOIN users u ON u.user_id = s.user_id
             JOIN student_thesis_registrations str
                    ON str.student_id = sa.student_id AND str.status = 'active'
             JOIN exam_schedule es ON es.thesis_schedule_id = str.thesis_schedule_id
             WHERE sa.supervisor_id = :lecturer_id
               AND sa.is_active = 1
               AND (es.ends_at IS NULL OR es.ends_at >= NOW())
               AND NOT EXISTS (
                   SELECT 1 FROM meetings m2
                   WHERE m2.exam_schedule_id = es.exam_schedule_id
                     AND m2.proposal_id = sa.proposal_id
                     AND m2.status != 'cancelled'
               )
             ORDER BY es.ends_at ASC"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
        return $stmt->fetchAll();
    }

    public function listInternalLecturersExcept(string $excludeUserId): array
    {
        $stmt = $this->db->prepare(
            "SELECT l.lecturer_id, l.user_id, CONCAT(u.first_name, ' ', u.last_name) AS name,
                    d.name AS affiliation
             FROM lecturers l
             JOIN users u ON u.user_id = l.user_id
             JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
             LEFT JOIN departments d ON d.department_id = il.department_id
             WHERE l.user_id != :exclude
             ORDER BY u.first_name, u.last_name"
        );
        $stmt->execute(['exclude' => $excludeUserId]);
        return $stmt->fetchAll();
    }

    public function listExternalLecturersExcept(string $excludeUserId): array
    {
        $stmt = $this->db->prepare(
            "SELECT l.lecturer_id, l.user_id, CONCAT(u.first_name, ' ', u.last_name) AS name,
                    el.institution AS affiliation
             FROM lecturers l
             JOIN users u ON u.user_id = l.user_id
             JOIN external_lecturers el ON el.lecturer_id = l.lecturer_id
             WHERE l.user_id != :exclude
             ORDER BY u.first_name, u.last_name"
        );
        $stmt->execute(['exclude' => $excludeUserId]);
        return $stmt->fetchAll();
    }

    /**
     * 'internal', 'external', or null if the user isn't in either table
     * (or isn't a lecturer at all) — used to enforce exam_type-based
     * invite restrictions server-side, not just in the UI.
     */
    public function getTypeByUserId(string $userId): ?string
    {
        $stmt = $this->db->prepare("SELECT lecturer_id FROM lecturers WHERE user_id = :user_id LIMIT 1");
        $stmt->execute(['user_id' => $userId]);
        $lecturerId = $stmt->fetchColumn();
        if (!$lecturerId) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT 1 FROM internal_lecturers WHERE lecturer_id = :id LIMIT 1");
        $stmt->execute(['id' => $lecturerId]);
        if ($stmt->fetchColumn()) {
            return 'internal';
        }

        $stmt = $this->db->prepare("SELECT 1 FROM external_lecturers WHERE lecturer_id = :id LIMIT 1");
        $stmt->execute(['id' => $lecturerId]);
        if ($stmt->fetchColumn()) {
            return 'external';
        }

        return null;
    }


    
}
