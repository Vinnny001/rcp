<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class AdminUser
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Roles moved out of users into user_roles, and department out of
     * students/lecturers into the internal_lecturers join — so both are
     * resolved here rather than read off the users row. Roles come back
     * as a comma-separated list because a user can hold several
     * (a lecturer who also supervises as an admin, a student who also
     * lectures).
     */
    public function all(): array
    {
        // Aggregated with MAX() rather than listed in GROUP BY: the
        // server runs with only_full_group_by, and a user has at most
        // one students row and one lecturer department, so MAX() over
        // that single value is just "the value".
        $stmt = $this->db->query(
            "SELECT u.user_id, u.first_name, u.last_name, u.email, u.is_active, u.last_login,
                    MAX(s.student_number) AS student_number,
                    MAX(d.name)           AS department,
                    GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ', ') AS roles
             FROM users u
             LEFT JOIN students s ON s.user_id = u.user_id
             LEFT JOIN lecturers l ON l.user_id = u.user_id
             LEFT JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
             LEFT JOIN departments d ON d.department_id = il.department_id
             LEFT JOIN user_roles ur ON ur.user_id = u.user_id AND ur.revoked_at IS NULL
             LEFT JOIN roles r ON r.role_id = ur.role_id
             GROUP BY u.user_id, u.first_name, u.last_name, u.email, u.is_active, u.last_login, u.created_at
             ORDER BY u.created_at DESC"
        );
        return $stmt->fetchAll();
    }

    public function toggleActive(string $userId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE users SET is_active = NOT is_active WHERE user_id = :user_id"
        );
        $stmt->execute(['user_id' => $userId]);
    }
}