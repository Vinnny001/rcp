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


    private const GRADE_BANDS = [
        // [minimum score inclusive, letter, outcome]
        [80, 'A', 'distinction'],
        [70, 'B', 'pass'],
        [60, 'C', 'pass'],
        [50, 'D', 'pass'],
        [40, 'E', 'resubmit'],
        [0,  'F', 'fail'],
    ];

    public function findPendingGradingForLecturer(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT eg.grader_id, eg.examiner_type,
                    e.examination_id, e.exam_type, e.exam_date,
                    p.title,
                    s.student_number,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name
             FROM examination_graders eg
             JOIN examinations e ON e.examination_id = eg.examination_id
             JOIN thesis_proposals p ON p.proposal_id = e.proposal_id
             JOIN students s ON s.student_id = p.student_id
             JOIN users u ON u.user_id = s.user_id
             WHERE eg.examiner_id = :user_id
               AND eg.score IS NULL
             ORDER BY e.exam_date ASC"
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function submitGrade(string $graderId, string $examinerUserId, float $score, ?string $feedback): ?string
    {
        $lookup = $this->db->prepare(
            "SELECT examination_id FROM examination_graders
             WHERE grader_id = :grader_id AND examiner_id = :examiner_id AND score IS NULL
             LIMIT 1"
        );
        $lookup->execute(['grader_id' => $graderId, 'examiner_id' => $examinerUserId]);
        $row = $lookup->fetch();

        if (!$row) {
            return null;
        }

        $update = $this->db->prepare(
            "UPDATE examination_graders
             SET score = :score, feedback = :feedback, graded_at = NOW()
             WHERE grader_id = :grader_id"
        );
        $update->execute(['score' => $score, 'feedback' => $feedback, 'grader_id' => $graderId]);

        return $row['examination_id'];
    }

    /**
     * If every grader assigned to this examination has now submitted a
     * score, average them and finalize the examination's overall grade.
     * PLACEHOLDER POLICY: simple average + fixed grade bands. Replace
     * with the institution's actual grading rules once known.
     */
    public function maybeFinalize(string $examinationId): void
    {
        $stmt = $this->db->prepare(
            "SELECT score FROM examination_graders WHERE examination_id = :examination_id"
        );
        $stmt->execute(['examination_id' => $examinationId]);
        $scores = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($scores) || in_array(null, $scores, true)) {
            return; // still waiting on at least one grader
        }

        $average = array_sum($scores) / count($scores);
        [$letter, $outcome] = $this->bandFor($average);

        $update = $this->db->prepare(
            "UPDATE examinations
             SET overall_grade = :overall_grade, grade_letter = :grade_letter,
                 outcome = :outcome, graded_at = NOW()
             WHERE examination_id = :examination_id"
        );
        $update->execute([
            'overall_grade' => round($average, 2),
            'grade_letter'  => $letter,
            'outcome'       => $outcome,
            'examination_id' => $examinationId,
        ]);
    }

    private function bandFor(float $score): array
    {
        foreach (self::GRADE_BANDS as [$min, $letter, $outcome]) {
            if ($score >= $min) {
                return [$letter, $outcome];
            }
        }
        return ['F', 'fail'];
    }


}