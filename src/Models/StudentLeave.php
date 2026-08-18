<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class StudentLeave
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findPendingByStudentId(string $studentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM student_leaves
             WHERE student_id = :student_id AND status = 'pending'
             ORDER BY requested_at DESC LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findHistory(string $studentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM student_leaves WHERE student_id = :student_id ORDER BY requested_at DESC"
        );
        $stmt->execute(['student_id' => $studentId]);
        return $stmt->fetchAll();
    }

    public function create(string $studentId, string $enrollmentId, string $reason, ?string $startDate, ?string $endDate): string
    {
        $leaveId = $this->generateUuid();
        $stmt = $this->db->prepare(
            "INSERT INTO student_leaves (leave_id, student_id, enrollment_id, reason, start_date, end_date, status)
             VALUES (:leave_id, :student_id, :enrollment_id, :reason, :start_date, :end_date, 'pending')"
        );
        $stmt->execute([
            'leave_id'      => $leaveId,
            'student_id'    => $studentId,
            'enrollment_id' => $enrollmentId,
            'reason'        => $reason,
            'start_date'    => $startDate,
            'end_date'      => $endDate,
        ]);
        return $leaveId;
    }

    /**
     * Approves a pending leave and closes out the associated enrollment
     * in one transaction — these two things must succeed or fail together,
     * otherwise a leave could be marked approved with no enrollment
     * actually closed (or vice versa), which is exactly the kind of
     * half-applied state that later breaks consecutiveLeaveCount().
     */
    public function approve(string $leaveId, string $decidedByUserId): bool
    {
        $lookup = $this->db->prepare(
            "SELECT enrollment_id FROM student_leaves WHERE leave_id = :leave_id AND status = 'pending' LIMIT 1 FOR UPDATE"
        );

        $this->db->beginTransaction();
        try {
            $lookup->execute(['leave_id' => $leaveId]);
            $row = $lookup->fetch();

            if (!$row) {
                $this->db->rollBack();
                return false;
            }

            $updateLeave = $this->db->prepare(
                "UPDATE student_leaves SET status = 'approved', decided_by = :decided_by, decided_at = NOW()
                 WHERE leave_id = :leave_id"
            );
            $updateLeave->execute(['decided_by' => $decidedByUserId, 'leave_id' => $leaveId]);

            if (!empty($row['enrollment_id'])) {
                $enrollmentModel = new StudentEnrollment($this->db);
                $enrollmentModel->closeWithLeave($row['enrollment_id']);
            }

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function reject(string $leaveId, string $decidedByUserId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE student_leaves SET status = 'rejected', decided_by = :decided_by, decided_at = NOW()
             WHERE leave_id = :leave_id AND status = 'pending'"
        );
        $stmt->execute(['decided_by' => $decidedByUserId, 'leave_id' => $leaveId]);
        return $stmt->rowCount() > 0;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}