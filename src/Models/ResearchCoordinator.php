<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * The research coordinator of a program: reviews supervisor
 * shortlists, convenes the department meeting, writes the minutes,
 * releases approved requests to lecturers, and schedules exams.
 *
 * Scoped to a program rather than held in `user_roles`, which is flat
 * and could not say *which* program. A coordinator's session role
 * stays 'lecturer' — this row is what grants the extra powers.
 */
class ResearchCoordinator
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Every program with its coordinator, if it has one — programs
     * without a coordinator must stay visible, since an unstaffed
     * program is exactly what an admin needs to see.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allProgramsWithCoordinator(): array
    {
        return $this->db->query(
            "SELECT p.program_id, p.name AS program_name, d.name AS department_name,
                    rc.coordinator_id, rc.user_id, rc.assigned_at,
                    CONCAT(u.first_name, ' ', u.last_name) AS coordinator_name
             FROM programs p
             JOIN departments d ON d.department_id = p.department_id
             LEFT JOIN research_coordinators rc ON rc.program_id = p.program_id
             LEFT JOIN users u ON u.user_id = rc.user_id
             ORDER BY d.name, p.name"
        )->fetchAll();
    }

    /**
     * A program has exactly one coordinator, so assigning replaces
     * whoever held it rather than stacking a second row.
     */
    public function assign(string $programId, string $userId, string $assignedBy): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO research_coordinators (coordinator_id, program_id, user_id, assigned_by)
             VALUES (UUID(), :program_id, :user_id, :assigned_by)
             ON DUPLICATE KEY UPDATE
                user_id     = VALUES(user_id),
                assigned_by = VALUES(assigned_by),
                assigned_at = NOW()"
        );
        $stmt->execute([
            'program_id'  => $programId,
            'user_id'     => $userId,
            'assigned_by' => $assignedBy,
        ]);
    }

    public function remove(string $programId): void
    {
        $stmt = $this->db->prepare("DELETE FROM research_coordinators WHERE program_id = :program_id");
        $stmt->execute(['program_id' => $programId]);
    }

    /**
     * The authorization check: may this user act as coordinator for
     * this program? Every coordinator-only action should ask this
     * rather than trusting a session role.
     */
    public function isCoordinatorFor(string $userId, string $programId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM research_coordinators
             WHERE user_id = :user_id AND program_id = :program_id LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId, 'program_id' => $programId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Programs this user coordinates — a user may hold more than one,
     * so any coordinator screen has to name which program it is acting
     * on rather than assuming a single queue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function programsForUser(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.program_id, p.name AS program_name, d.name AS department_name
             FROM research_coordinators rc
             JOIN programs p ON p.program_id = rc.program_id
             JOIN departments d ON d.department_id = p.department_id
             WHERE rc.user_id = :user_id
             ORDER BY d.name, p.name"
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }
}
