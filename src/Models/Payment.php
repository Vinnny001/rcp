<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Payment
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByStudentId(string $studentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM payments
             WHERE student_id = :student_id
             ORDER BY payment_date DESC"
        );
        $stmt->execute(['student_id' => $studentId]);
        return $stmt->fetchAll();
    }
}