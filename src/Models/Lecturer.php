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
            "SELECT l.lecturer_id, l.department,
                    CONCAT(u.first_name, ' ', u.last_name) AS name
             FROM lecturers l
             JOIN users u ON u.user_id = l.user_id
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
            "SELECT sa.assignment_id, sa.role,
                    s.student_number, s.program,
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


}