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

    /**
     * Retired. Supervisors are appointed from a department-voted
     * shortlist, never by a student asking a lecturer directly.
     *
     * This throws rather than being deleted outright: the table still
     * holds the requests made before the changeover, and the read
     * methods below still serve them. Leaving a working writer here
     * would let a caller appoint a supervisor with no department vote,
     * which is the whole thing the shortlist exists to prevent.
     *
     * @throws \LogicException always
     */
    public function create(string $proposalId, string $studentId, string $lecturerId, string $role = 'main'): string
    {
        throw new \LogicException(
            'Direct supervision requests are retired — a supervisor is appointed from an approved shortlist.'
        );
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

    /**
     * Declining outlives accepting on purpose.
     *
     * Accepting appointed a supervisor outright, which is exactly what
     * the department vote now decides, so it is gone. Declining only
     * closes a request that was made before the changeover, and a
     * lecturer left holding one needs some way to clear it.
     */
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
}
