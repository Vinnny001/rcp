<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class SupervisionRequest
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(string $proposalId, string $studentId, string $lecturerId, string $role = 'main'): string
    {
        $requestId = $this->generateUuid();

        $stmt = $this->db->prepare(
            "INSERT INTO supervision_requests (request_id, proposal_id, student_id, lecturer_id, role, status)
             VALUES (:request_id, :proposal_id, :student_id, :lecturer_id, :role, 'pending')"
        );
        $stmt->execute([
            'request_id'  => $requestId,
            'proposal_id' => $proposalId,
            'student_id'  => $studentId,
            'lecturer_id' => $lecturerId,
            'role'        => $role,
        ]);

        return $requestId;
    }

    public function findPendingByLecturerId(string $lecturerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT sr.request_id, sr.role, sr.requested_at,
                    p.proposal_id, p.title,
                    s.student_id, s.student_number,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name
             FROM supervision_requests sr
             JOIN thesis_proposals p ON p.proposal_id = sr.proposal_id
             JOIN students s ON s.student_id = sr.student_id
             JOIN users u ON u.user_id = s.user_id
             WHERE sr.lecturer_id = :lecturer_id
               AND sr.status = 'pending'
             ORDER BY sr.requested_at ASC"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
        return $stmt->fetchAll();
    }

    public function findHistoryByLecturerId(string $lecturerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT sr.*, p.title,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name
             FROM supervision_requests sr
             JOIN thesis_proposals p ON p.proposal_id = sr.proposal_id
             JOIN students s ON s.student_id = sr.student_id
             JOIN users u ON u.user_id = s.user_id
             WHERE sr.lecturer_id = :lecturer_id
               AND sr.status != 'pending'
             ORDER BY sr.decided_at DESC
             LIMIT 20"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
        return $stmt->fetchAll();
    }

    public function accept(string $requestId, string $lecturerId, string $decidedByUserId): bool
    {
        $lookup = $this->db->prepare(
            "SELECT proposal_id, student_id FROM supervision_requests
             WHERE request_id = :request_id AND lecturer_id = :lecturer_id AND status = 'pending'
             LIMIT 1 FOR UPDATE"
        );

        $this->db->beginTransaction();
        try {
            $lookup->execute(['request_id' => $requestId, 'lecturer_id' => $lecturerId]);
            $row = $lookup->fetch();

            if (!$row) {
                $this->db->rollBack();
                return false;
            }

            $updateRequest = $this->db->prepare(
                "UPDATE supervision_requests
                 SET status = 'accepted', decided_at = NOW(), decided_by = :decided_by
                 WHERE request_id = :request_id"
            );
            $updateRequest->execute(['decided_by' => $decidedByUserId, 'request_id' => $requestId]);

            $updateProposal = $this->db->prepare(
                "UPDATE thesis_proposals SET assigned_supervisor_id = :lecturer_id
                 WHERE proposal_id = :proposal_id"
            );
            $updateProposal->execute(['lecturer_id' => $lecturerId, 'proposal_id' => $row['proposal_id']]);

            $insertAssignment = $this->db->prepare(
                "INSERT INTO supervision_assignments
                    (assignment_id, proposal_id, student_id, supervisor_id, role, appointed_by, appointment_date, is_active)
                 VALUES
                    (:assignment_id, :proposal_id, :student_id, :supervisor_id, 'main', :appointed_by, CURDATE(), 1)"
            );
            $insertAssignment->execute([
                'assignment_id' => $this->generateUuid(),
                'proposal_id'   => $row['proposal_id'],
                'student_id'    => $row['student_id'],
                'supervisor_id' => $lecturerId,
                'appointed_by'  => $decidedByUserId,
            ]);

            $this->db->commit();
            return true;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Assigns $lecturerId (already chosen by Lecturer::findBestAvailableInternalCandidate())
     * as this proposal's supervisor, and marks any still-pending
     * request as declined-by-timeout for a clean audit trail — same
     * transactional shape as accept(), just without a specific request
     * driving the choice of lecturer.
     */
    public function autoAssignIfUnresponsive(
        string $proposalId,
        string $studentId,
        string $lecturerId,
        string $attributedToUserId
    ): void {
        $this->db->beginTransaction();
        try {
            $updateProposal = $this->db->prepare(
                "UPDATE thesis_proposals SET assigned_supervisor_id = :lecturer_id
                 WHERE proposal_id = :proposal_id"
            );
            $updateProposal->execute(['lecturer_id' => $lecturerId, 'proposal_id' => $proposalId]);

            $insertAssignment = $this->db->prepare(
                "INSERT INTO supervision_assignments
                    (assignment_id, proposal_id, student_id, supervisor_id, role, appointed_by, appointment_date, is_active)
                 VALUES
                    (:assignment_id, :proposal_id, :student_id, :supervisor_id, 'main', :appointed_by, CURDATE(), 1)"
            );
            $insertAssignment->execute([
                'assignment_id' => $this->generateUuid(),
                'proposal_id'   => $proposalId,
                'student_id'    => $studentId,
                'supervisor_id' => $lecturerId,
                'appointed_by'  => $attributedToUserId,
            ]);

            $declineStale = $this->db->prepare(
                "UPDATE supervision_requests
                 SET status = 'declined', decided_at = NOW(), decided_by = :decided_by,
                     decline_reason = 'No response before enrollment closed — another supervisor was auto-assigned.'
                 WHERE proposal_id = :proposal_id AND status = 'pending'"
            );
            $declineStale->execute(['decided_by' => $attributedToUserId, 'proposal_id' => $proposalId]);

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function decline(string $requestId, string $lecturerId, string $decidedByUserId, ?string $reason): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE supervision_requests
             SET status = 'declined', decided_at = NOW(), decided_by = :decided_by, decline_reason = :reason
             WHERE request_id = :request_id AND lecturer_id = :lecturer_id AND status = 'pending'"
        );
        $stmt->execute([
            'decided_by'  => $decidedByUserId,
            'reason'      => $reason,
            'request_id'  => $requestId,
            'lecturer_id' => $lecturerId,
        ]);
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
