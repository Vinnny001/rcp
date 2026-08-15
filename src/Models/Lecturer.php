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
}