<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Proposal
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Latest non-rejected proposal for a student, with the review-timeline
     * columns the proposal page needs (created_at / submission_date /
     * reviewed_at / decided_at). If your `thesis_proposals` table doesn't
     * have reviewed_at / decided_at yet, add them:
     *
     *   ALTER TABLE thesis_proposals
     *     ADD COLUMN reviewed_at DATETIME NULL,
     *     ADD COLUMN decided_at  DATETIME NULL;
     *
     * and set them when a reviewer/board updates the status.
     */
    public function findActiveByStudentId(string $studentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT tp.*, l.lecturer_id AS ps_id, u.first_name AS ps_first, u.last_name AS ps_last
             FROM thesis_proposals tp
             LEFT JOIN lecturers l ON l.lecturer_id = tp.proposed_supervisor_id
             LEFT JOIN users u ON u.user_id = l.user_id
             WHERE tp.student_id = :student_id
               AND tp.status <> 'rejected'
             ORDER BY tp.created_at DESC
             LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        if ($row['ps_first']) {
            $row['proposed_supervisor_name'] = $row['ps_first'] . ' ' . $row['ps_last'];
        }

        return $row;
    }

    public function create(string $studentId, array $data): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO thesis_proposals (student_id, title, synopsis, proposed_supervisor_id, status, submission_date)
             VALUES (:student_id, :title, :synopsis, :proposed_supervisor_id, 'submitted', CURDATE())"
        );

        $stmt->execute([
            'student_id'              => $studentId,
            'title'                   => $data['title'],
            'synopsis'                => $data['synopsis'],
            'proposed_supervisor_id'  => $data['proposed_supervisor_id'],
        ]);
    }
}