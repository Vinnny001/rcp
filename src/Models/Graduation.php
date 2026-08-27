<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Graduation
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByStudentId(string $studentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT g.*, u.first_name AS approver_first_name, u.last_name AS approver_last_name
             FROM graduation_list g
             LEFT JOIN users u ON u.user_id = g.approved_by
             WHERE g.student_id = :student_id
             ORDER BY g.ceremony_year DESC
             LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
