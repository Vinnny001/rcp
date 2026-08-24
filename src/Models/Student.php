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
            "INSERT INTO students (student_id, user_id, student_number)
             VALUES (:student_id, :user_id, :student_number)"
        );
        $stmt->execute([
            'student_id'     => $this->generateUuid(),
            'user_id'        => $userId,
            'student_number' => $data['student_number'],
        ]);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}