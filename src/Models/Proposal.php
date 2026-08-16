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

    /**
     * Creates a new proposal. Pass $submit = false to save as a draft
     * (no submission_date, status stays 'draft'), or true to submit it
     * immediately for review.
     */
    public function create(string $studentId, array $data, bool $submit = true): string
    {
        $status = $submit ? 'submitted' : 'draft';
        $submissionDate = $submit ? date('Y-m-d') : null;

        $stmt = $this->db->prepare(
            "INSERT INTO thesis_proposals (student_id, title, synopsis, proposed_supervisor_id, status, submission_date)
             VALUES (:student_id, :title, :synopsis, :proposed_supervisor_id, :status, :submission_date)"
        );

        $stmt->execute([
            'student_id'              => $studentId,
            'title'                   => $data['title'],
            'synopsis'                => $data['synopsis'],
            'proposed_supervisor_id'  => $data['proposed_supervisor_id'],
            'status'                  => $status,
            'submission_date'         => $submissionDate,
        ]);

        return $this->findActiveByStudentId($studentId)['proposal_id'];
    }

    /**
     * Updates an existing DRAFT proposal's content, and optionally
     * submits it (status -> submitted, submission_date set) in the
     * same call. Only rows currently in 'draft' status can be touched
     * this way — once submitted, editing here is a no-op by design.
     */
    public function updateDraft(string $proposalId, array $data, bool $submit = false): void
    {
        if ($submit) {
            $stmt = $this->db->prepare(
                "UPDATE thesis_proposals
                 SET title = :title,
                     synopsis = :synopsis,
                     proposed_supervisor_id = :proposed_supervisor_id,
                     status = 'submitted',
                     submission_date = CURDATE()
                 WHERE proposal_id = :proposal_id
                   AND status = 'draft'"
            );
        } else {
            $stmt = $this->db->prepare(
                "UPDATE thesis_proposals
                 SET title = :title,
                     synopsis = :synopsis,
                     proposed_supervisor_id = :proposed_supervisor_id
                 WHERE proposal_id = :proposal_id
                   AND status = 'draft'"
            );
        }

        $stmt->execute([
            'title'                   => $data['title'],
            'synopsis'                => $data['synopsis'],
            'proposed_supervisor_id'  => $data['proposed_supervisor_id'],
            'proposal_id'             => $proposalId,
        ]);
    }
}