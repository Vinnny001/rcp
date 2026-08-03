<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Student
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByStudentNumber(string $studentNumber): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM students WHERE student_number = :student_number LIMIT 1"
        );
        $stmt->execute(['student_number' => $studentNumber]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $userId, array $data): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO students (user_id, student_number, department, program, enrollment_year)
             VALUES (:user_id, :student_number, :department, :program, :enrollment_year)"
        );

        $stmt->execute([
            'user_id'         => $userId,
            'student_number'  => $data['student_number'],
            'department'      => $data['department'],
            'program'         => $data['program'],
            'enrollment_year' => $data['enrollment_year'],
        ]);
    }
}