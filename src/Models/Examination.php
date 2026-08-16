<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Examination
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByProposalId(string $proposalId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM examinations
             WHERE proposal_id = :proposal_id
             ORDER BY exam_type ASC, exam_date ASC"
        );
        $stmt->execute(['proposal_id' => $proposalId]);
        return $stmt->fetchAll();
    }

    public function findGradersByExaminationId(string $examinationId): array
    {
        $stmt = $this->db->prepare(
            "SELECT eg.*, u.first_name, u.last_name
             FROM examination_graders eg
             LEFT JOIN users u ON u.user_id = eg.examiner_id
             WHERE eg.examination_id = :examination_id
             ORDER BY eg.examiner_type ASC"
        );
        $stmt->execute(['examination_id' => $examinationId]);
        return $stmt->fetchAll();
    }
}