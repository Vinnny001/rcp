<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Department heads attend the supervision-review meetings a
 * coordinator convenes, and cast one approve/reject vote each on a
 * student's supervisor shortlist.
 *
 * A department can have several heads holding different positions; the
 * position list itself is a table so a new title is a row rather than
 * an ENUM migration.
 */
class DepartmentHead
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function positions(): array
    {
        return $this->db->query(
            "SELECT position_id, code, name FROM department_positions
             WHERE is_active = 1 ORDER BY display_order, name"
        )->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->query(
            "SELECT dh.dept_head_id, dh.department_id, dh.user_id, dh.is_active, dh.assigned_at,
                    d.name AS department_name,
                    dp.name AS position_name, dp.position_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS head_name,
                    u.email
             FROM department_heads dh
             JOIN departments d ON d.department_id = dh.department_id
             JOIN department_positions dp ON dp.position_id = dh.position_id
             JOIN users u ON u.user_id = dh.user_id
             ORDER BY d.name, dp.display_order, u.last_name"
        )->fetchAll();
    }

    /**
     * The heads a coordinator can invite to vote on a shortlist.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeForDepartment(string $departmentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT dh.dept_head_id, dh.user_id, dp.name AS position_name,
                    CONCAT(u.first_name, ' ', u.last_name) AS head_name
             FROM department_heads dh
             JOIN department_positions dp ON dp.position_id = dh.position_id
             JOIN users u ON u.user_id = dh.user_id
             WHERE dh.department_id = :department_id AND dh.is_active = 1
             ORDER BY dp.display_order, u.last_name"
        );
        $stmt->execute(['department_id' => $departmentId]);

        return $stmt->fetchAll();
    }

    /**
     * Re-assigning someone already on a department updates their
     * position rather than failing — changing a chair to a member is a
     * normal edit, not an error.
     */
    public function assign(string $departmentId, string $userId, string $positionId, string $assignedBy): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO department_heads (dept_head_id, department_id, user_id, position_id, assigned_by)
             VALUES (UUID(), :department_id, :user_id, :position_id, :assigned_by)
             ON DUPLICATE KEY UPDATE
                position_id = VALUES(position_id),
                assigned_by = VALUES(assigned_by),
                assigned_at = NOW(),
                is_active   = 1"
        );
        $stmt->execute([
            'department_id' => $departmentId,
            'user_id'       => $userId,
            'position_id'   => $positionId,
            'assigned_by'   => $assignedBy,
        ]);
    }

    /**
     * Stood down rather than deleted: their past votes on shortlist
     * meetings reference this row.
     */
    public function setActive(string $deptHeadId, bool $active): void
    {
        $stmt = $this->db->prepare(
            "UPDATE department_heads SET is_active = :active WHERE dept_head_id = :id"
        );
        $stmt->execute(['id' => $deptHeadId, 'active' => $active ? 1 : 0]);
    }
}
