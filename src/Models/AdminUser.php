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

    public function all(): array
    {
        $stmt = $this->db->query(
            "SELECT u.user_id, u.first_name, u.last_name, u.email, u.role, u.is_active, u.last_login,
                    COALESCE(s.department, l.department) AS department
             FROM users u
             LEFT JOIN students s ON s.user_id = u.user_id
             LEFT JOIN lecturers l ON l.user_id = u.user_id
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