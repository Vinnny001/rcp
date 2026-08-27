<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class StudentEnrollment
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findActive(string $studentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT se.*, ps.department, ps.program, ps.enrollment_year
             FROM student_enrollments se
             JOIN program_schedules ps ON ps.schedule_id = se.schedule_id
             WHERE se.student_id = :student_id AND se.status = 'active'
             LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findHistory(string $studentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT se.*, ps.department, ps.program, ps.enrollment_year
             FROM student_enrollments se
             JOIN program_schedules ps ON ps.schedule_id = se.schedule_id
             WHERE se.student_id = :student_id
             ORDER BY se.enrolled_at DESC"
        );
        $stmt->execute(['student_id' => $studentId]);
        return $stmt->fetchAll();
    }

    public function consecutiveLeaveCount(string $studentId): int
    {
        $stmt = $this->db->prepare(
            "SELECT se.ended_reason
             FROM student_enrollments se
             WHERE se.student_id = :student_id
               AND se.ended_reason IS NOT NULL
             ORDER BY se.enrolled_at DESC"
        );
        $stmt->execute(['student_id' => $studentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $count = 0;
        foreach ($rows as $reason) {
            if ($reason === 'on_leave') {
                $count++;
            } else {
                break;
            }
        }
        return $count;
    }

    public function registrationFeeWaived(string $studentId): bool
    {
        $history = $this->findHistory($studentId);
        if (empty($history)) {
            return false;
        }

        $mostRecentEndedViaLeave = ($history[0]['ended_reason'] ?? null) === 'on_leave';

        if (!$mostRecentEndedViaLeave) {
            return false;
        }

        return $this->consecutiveLeaveCount($studentId) < 3;
    }

    public function create(string $studentId, string $scheduleId): string
    {
        $enrollmentId = $this->generateUuid();
        $stmt = $this->db->prepare(
            "INSERT INTO student_enrollments (enrollment_id, student_id, schedule_id, status)
             VALUES (:enrollment_id, :student_id, :schedule_id, 'active')"
        );
        $stmt->execute([
            'enrollment_id' => $enrollmentId,
            'student_id'    => $studentId,
            'schedule_id'   => $scheduleId,
        ]);
        return $enrollmentId;
    }

    public function closeWithLeave(string $enrollmentId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE student_enrollments
             SET status = 'on_leave', ended_at = NOW(), ended_reason = 'on_leave'
             WHERE enrollment_id = :enrollment_id"
        );
        $stmt->execute(['enrollment_id' => $enrollmentId]);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }


    public function setCurrentSemester(string $enrollmentId, string $semesterId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE student_enrollments SET current_semester_id = :semester_id WHERE enrollment_id = :enrollment_id"
        );
        $stmt->execute(['semester_id' => $semesterId, 'enrollment_id' => $enrollmentId]);
    }
}
