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
    /**
     * $search matches name/email/student number; $roleFilter narrows to
     * users currently holding one named role. Both are applied before
     * the GROUP BY (a WHERE/EXISTS check), never as a HAVING against the
     * aggregated roles string, so they compose correctly regardless of
     * how many roles a user holds.
     */
    public function all(?string $search = null, ?string $roleFilter = null): array
    {
        $where = [];
        $params = [];

        if ($search !== null && $search !== '') {
            $where[] = '(u.first_name LIKE :search OR u.last_name LIKE :search OR u.email LIKE :search OR s.student_number LIKE :search)';
            $params['search'] = '%' . $search . '%';
        }

        if ($roleFilter !== null && $roleFilter !== '') {
            $where[] = 'EXISTS (
                SELECT 1 FROM user_roles ur2
                JOIN roles r2 ON r2.role_id = ur2.role_id
                WHERE ur2.user_id = u.user_id AND ur2.revoked_at IS NULL AND r2.role_name = :role_filter
            )';
            $params['role_filter'] = $roleFilter;
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        // Aggregated with MAX() rather than listed in GROUP BY: the
        // server runs with only_full_group_by, and a user has at most
        // one students row and one lecturer record, so MAX() over that
        // single value is just "the value".
        $stmt = $this->db->prepare(
            "SELECT u.user_id, u.first_name, u.last_name, u.email, u.is_active, u.last_login,
                    MAX(s.student_number) AS student_number,
                    MAX(d.name)           AS department,
                    MAX(l.lecturer_id)    AS lecturer_id,
                    MAX(l.is_examiner)    AS is_examiner,
                    GROUP_CONCAT(DISTINCT r.role_name ORDER BY r.role_name SEPARATOR ', ') AS roles
             FROM users u
             LEFT JOIN students s ON s.user_id = u.user_id
             LEFT JOIN lecturers l ON l.user_id = u.user_id
             LEFT JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
             LEFT JOIN departments d ON d.department_id = il.department_id
             LEFT JOIN user_roles ur ON ur.user_id = u.user_id AND ur.revoked_at IS NULL
             LEFT JOIN roles r ON r.role_id = ur.role_id
             {$whereSql}
             GROUP BY u.user_id, u.first_name, u.last_name, u.email, u.is_active, u.last_login, u.created_at
             ORDER BY u.created_at DESC"
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function toggleActive(string $userId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE users SET is_active = NOT is_active WHERE user_id = :user_id"
        );
        $stmt->execute(['user_id' => $userId]);
    }

    public function toggleExaminer(string $lecturerId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE lecturers SET is_examiner = NOT is_examiner WHERE lecturer_id = :lecturer_id"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
    }
}