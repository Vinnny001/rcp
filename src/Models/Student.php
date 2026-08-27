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



    public function isProfileComplete(string $studentId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT student_number, student_email FROM students WHERE student_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $studentId]);
        $row = $stmt->fetch();
        return $row && !empty($row['student_number']) && !empty($row['student_email']);
    }

    public function completeProfile(string $studentId, string $studentNumber, string $studentEmail, ?string $erpRef): void
    {
        $stmt = $this->db->prepare(
            "UPDATE students SET student_number = :student_number, student_email = :student_email, erp_student_ref = :erp_ref
             WHERE student_id = :id"
        );
        $stmt->execute([
            'student_number' => $studentNumber,
            'student_email'  => $studentEmail,
            'erp_ref'        => $erpRef,
            'id'             => $studentId,
        ]);
    }

    public function findByUserId(string $userId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM students WHERE user_id = :user_id LIMIT 1");
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }




    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
