<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use RuntimeException;

/**
 * Rubric-based examiner marking.
 *
 * Each examiner scores every criterion on the scheme the exam stage is
 * marked with; their percentage is their total over the scheme's
 * maximum. Where the scheme has a panel leader, one of them also
 * confirms the average across the panel.
 *
 * The maximum is always derived from the criteria, never stored. The
 * source form for Table 1 disagreed with its own rows — a stored total
 * would let that happen here too, and a wrong denominator silently
 * changes every student's outcome.
 */
class Rubric
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findTemplate(string $templateId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT t.*, COALESCE(SUM(c.max_score), 0) AS max_total, COUNT(c.criterion_id) AS criterion_count
             FROM rubric_templates t
             LEFT JOIN rubric_criteria c ON c.template_id = t.template_id
             WHERE t.template_id = :id
             GROUP BY t.template_id"
        );
        $stmt->execute(['id' => $templateId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * The scheme a given exam stage is marked with, or null when the
     * stage has none configured.
     */
    public function templateForStage(string $stageId): ?array
    {
        $stmt = $this->db->prepare("SELECT rubric_template_id FROM exam_stages WHERE stage_id = :id LIMIT 1");
        $stmt->execute(['id' => $stageId]);
        $templateId = $stmt->fetchColumn();

        return $templateId ? $this->findTemplate((string) $templateId) : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function criteriaFor(string $templateId): array
    {
        $stmt = $this->db->prepare(
            "SELECT criterion_id, section_name, criterion_text, max_score, display_order
             FROM rubric_criteria WHERE template_id = :id ORDER BY display_order"
        );
        $stmt->execute(['id' => $templateId]);

        return $stmt->fetchAll();
    }

    public function maxTotalFor(string $templateId): float
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(max_score), 0) FROM rubric_criteria WHERE template_id = :id"
        );
        $stmt->execute(['id' => $templateId]);

        return (float) $stmt->fetchColumn();
    }

    /**
     * One examiner's marks for a meeting, keyed by criterion so a form
     * can pre-fill without a lookup per row.
     *
     * @return array<string, array{score: string, remarks: ?string}>
     */
    public function scoresFor(string $meetingId, string $examinerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT criterion_id, score, remarks FROM rubric_scores
             WHERE meeting_id = :meeting_id AND examiner_id = :examiner_id"
        );
        $stmt->execute(['meeting_id' => $meetingId, 'examiner_id' => $examinerId]);

        $byCriterion = [];
        foreach ($stmt->fetchAll() as $row) {
            $byCriterion[$row['criterion_id']] = ['score' => $row['score'], 'remarks' => $row['remarks']];
        }

        return $byCriterion;
    }

    /**
     * Records one examiner's marks. Every criterion must be scored and
     * within range — a half-marked sheet would average as though the
     * blanks were zeros, quietly failing the student.
     *
     * @param array<string, mixed> $scores  criterion_id => score
     * @param array<string, mixed> $remarks criterion_id => remark
     */
    public function saveScores(string $meetingId, string $examinerId, string $templateId, array $scores, array $remarks): void
    {
        $criteria = $this->criteriaFor($templateId);
        if ($criteria === []) {
            throw new RuntimeException('That marking scheme has no criteria yet.');
        }

        foreach ($criteria as $criterion) {
            $id = $criterion['criterion_id'];
            $raw = $scores[$id] ?? '';

            if (trim((string) $raw) === '') {
                throw new RuntimeException('Score every row before submitting — "' . $criterion['section_name'] . '" is blank.');
            }
            if (!is_numeric($raw)) {
                throw new RuntimeException('Scores must be numbers.');
            }

            $value = (float) $raw;
            if ($value < 0 || $value > (float) $criterion['max_score']) {
                throw new RuntimeException(
                    'Score for "' . $criterion['section_name'] . '" must be between 0 and ' . rtrim(rtrim($criterion['max_score'], '0'), '.') . '.'
                );
            }
        }

        $stmt = $this->db->prepare(
            "INSERT INTO rubric_scores (rubric_score_id, meeting_id, criterion_id, examiner_id, score, remarks)
             VALUES (UUID(), :meeting_id, :criterion_id, :examiner_id, :score, :remarks)
             ON DUPLICATE KEY UPDATE score = VALUES(score), remarks = VALUES(remarks), scored_at = NOW()"
        );

        foreach ($criteria as $criterion) {
            $id = $criterion['criterion_id'];
            $stmt->execute([
                'meeting_id'   => $meetingId,
                'criterion_id' => $id,
                'examiner_id'  => $examinerId,
                'score'        => (float) $scores[$id],
                'remarks'      => trim((string) ($remarks[$id] ?? '')) ?: null,
            ]);
        }
    }

    /**
     * One examiner's total and percentage, or null if they have not
     * finished marking.
     *
     * @return array{total: float, max: float, percentage: float}|null
     */
    public function examinerResult(string $meetingId, string $examinerId, string $templateId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS scored, COALESCE(SUM(score), 0) AS total
             FROM rubric_scores WHERE meeting_id = :meeting_id AND examiner_id = :examiner_id"
        );
        $stmt->execute(['meeting_id' => $meetingId, 'examiner_id' => $examinerId]);
        $row = $stmt->fetch();

        $expected = count($this->criteriaFor($templateId));
        if ($expected === 0 || (int) $row['scored'] < $expected) {
            return null;
        }

        $max = $this->maxTotalFor($templateId);

        return [
            'total'      => (float) $row['total'],
            'max'        => $max,
            'percentage' => $max > 0 ? round((float) $row['total'] / $max * 100, 2) : 0.0,
        ];
    }

    /**
     * Every examiner who has marked this meeting, with their totals.
     *
     * @return array<int, array<string, mixed>>
     */
    public function panelResults(string $meetingId, string $templateId): array
    {
        $stmt = $this->db->prepare(
            "SELECT rs.examiner_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS examiner_name,
                    COUNT(*) AS scored,
                    SUM(rs.score) AS total,
                    MAX(rs.scored_at) AS scored_at
             FROM rubric_scores rs
             JOIN users u ON u.user_id = rs.examiner_id
             WHERE rs.meeting_id = :meeting_id
             GROUP BY rs.examiner_id, examiner_name
             ORDER BY examiner_name"
        );
        $stmt->execute(['meeting_id' => $meetingId]);

        $expected = count($this->criteriaFor($templateId));
        $max = $this->maxTotalFor($templateId);

        return array_map(static function (array $row) use ($expected, $max): array {
            $complete = $expected > 0 && (int) $row['scored'] >= $expected;

            return $row + [
                'complete'   => $complete,
                'max'        => $max,
                'percentage' => $complete && $max > 0 ? round((float) $row['total'] / $max * 100, 2) : null,
            ];
        }, $stmt->fetchAll());
    }

    /**
     * The mean of the completed examiners' percentages — what the panel
     * leader is asked to attest to. Null until at least one examiner
     * has finished.
     */
    public function calculatedAverage(string $meetingId, string $templateId): ?float
    {
        $complete = array_filter($this->panelResults($meetingId, $templateId), static fn (array $r): bool => $r['complete']);
        if ($complete === []) {
            return null;
        }

        return round(array_sum(array_column($complete, 'percentage')) / count($complete), 2);
    }

    public function panelLeader(string $meetingId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT pl.*, CONCAT(u.first_name, ' ', u.last_name) AS leader_name
             FROM rubric_panel_leaders pl
             JOIN users u ON u.user_id = pl.examiner_id
             WHERE pl.meeting_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $meetingId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Designates the leader for a meeting. Keyed on the meeting, so
     * naming a second one replaces the first rather than creating a
     * panel with two.
     */
    public function designateLeader(string $meetingId, string $examinerId): void
    {
        $this->db->prepare(
            "INSERT INTO rubric_panel_leaders (meeting_id, examiner_id)
             VALUES (:meeting_id, :examiner_id)
             ON DUPLICATE KEY UPDATE examiner_id = VALUES(examiner_id),
                                     average_score = NULL, confirmed_at = NULL"
        )->execute(['meeting_id' => $meetingId, 'examiner_id' => $examinerId]);
    }

    /**
     * The leader confirms the calculated average. The figure is taken
     * from the panel's marks rather than typed, so it cannot drift from
     * them; confirming is the attestation the form's signature line is
     * asking for.
     */
    public function confirmAverage(string $meetingId, string $examinerId, string $templateId): float
    {
        $leader = $this->panelLeader($meetingId);
        if (!$leader) {
            throw new RuntimeException('No panel leader has been named for this meeting.');
        }
        if ($leader['examiner_id'] !== $examinerId) {
            throw new RuntimeException('Only ' . $leader['leader_name'] . ' can confirm the panel average.');
        }

        $average = $this->calculatedAverage($meetingId, $templateId);
        if ($average === null) {
            throw new RuntimeException('No examiner has finished marking yet.');
        }

        $this->db->prepare(
            "UPDATE rubric_panel_leaders SET average_score = :average, confirmed_at = NOW()
             WHERE meeting_id = :meeting_id"
        )->execute(['meeting_id' => $meetingId, 'average' => $average]);

        return $average;
    }

    /**
     * The coordinator accepts the leader's confirmed average, which is
     * what releases the result to the student. Confirming and
     * approving are different acts by different people: the leader
     * attests that the mark is what the panel gave, the coordinator
     * accepts it on behalf of the program.
     */
    public function approveAverage(string $meetingId, string $approvedBy): float
    {
        $leader = $this->panelLeader($meetingId);

        if (!$leader) {
            throw new RuntimeException('No panel leader has been named for this meeting.');
        }
        if ($leader['confirmed_at'] === null) {
            throw new RuntimeException($leader['leader_name'] . ' has not confirmed the average yet.');
        }
        if ($leader['approved_at'] !== null) {
            return (float) $leader['average_score'];
        }

        $this->db->prepare(
            "UPDATE rubric_panel_leaders SET approved_at = NOW(), approved_by = :by
             WHERE meeting_id = :id"
        )->execute(['id' => $meetingId, 'by' => $approvedBy]);

        return (float) $leader['average_score'];
    }

    /**
     * Whether this meeting's result may be shown to the student.
     *
     * Marking, confirming and approving are three separate steps, and
     * a result is only the student's business after the last of them.
     * Staff see the working; students see the outcome.
     */
    public function isReleasedToStudent(string $meetingId): bool
    {
        $leader = $this->panelLeader($meetingId);

        return $leader !== null && $leader['approved_at'] !== null;
    }

    /**
     * Released exam outcomes for a student's results page, in the same
     * shape ExaminationScore::findExamOutcomesForStudent() returns so
     * the two can sit side by side.
     *
     * Deliberately not written into examination_scores as derived
     * rows: that table is keyed on an exam document, and a concept
     * presentation may have no document at all. Copying the figure
     * there would invent a row and give it somewhere to drift from.
     *
     * Only approved results appear. Marking, confirming and approving
     * all happen before this returns anything.
     *
     * @return array<int, array<string, mixed>>
     */
    public function releasedOutcomesForStudent(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.meeting_id, m.scheduled_at, m.proposal_id,
                    s.name AS stage_name,
                    es.exam_type,
                    pl.average_score, pl.approved_at,
                    tp.title AS proposal_title,
                    (SELECT COUNT(DISTINCT rs.examiner_id) FROM rubric_scores rs
                      WHERE rs.meeting_id = m.meeting_id) AS reviewer_count
             FROM rubric_panel_leaders pl
             JOIN meetings m ON m.meeting_id = pl.meeting_id
             JOIN exam_stages s ON s.stage_id = m.exam_stage_id
             JOIN thesis_proposals tp ON tp.proposal_id = m.proposal_id
             JOIN students st ON st.student_id = tp.student_id
             LEFT JOIN exam_schedule es ON es.exam_schedule_id = m.exam_schedule_id
             WHERE st.user_id = :user_id
               AND pl.approved_at IS NOT NULL
             ORDER BY pl.approved_at DESC"
        );
        $stmt->execute(['user_id' => $userId]);

        $outcomes = [];
        foreach ($stmt->fetchAll() as $row) {
            $band = GradingPolicy::examOutcome($this->db, (float) $row['average_score']);

            $outcomes[] = [
                'proposal_id'    => $row['proposal_id'],
                'proposal_title' => $row['proposal_title'],
                'doc_type_name'  => $row['stage_name'],
                'exam_type'      => $row['exam_type'],
                'exam_date'      => $row['scheduled_at'],
                'graded_at'      => $row['approved_at'],
                'reviewer_count' => (int) $row['reviewer_count'],
                'outcome'        => $band['outcome'],
                'outcome_label'  => $band['label'],
                'stamp_class'    => GradingPolicy::stampClass($band['outcome']),
                'comments'       => $this->remarksFor($row['meeting_id']),
            ];
        }

        return $outcomes;
    }

    /**
     * Examiner remarks for a meeting, as the student reads them.
     * Blank remarks are dropped rather than shown as empty lines.
     *
     * @return array<int, string>
     */
    private function remarksFor(string $meetingId): array
    {
        $stmt = $this->db->prepare(
            "SELECT remarks FROM rubric_scores
             WHERE meeting_id = :id AND remarks IS NOT NULL AND TRIM(remarks) <> ''
             ORDER BY scored_at"
        );
        $stmt->execute(['id' => $meetingId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * The examiner-facing interpretation of a percentage for this
     * scheme. Null when the scheme prints no bands — Table 1 has none,
     * and falls back to the student-facing scale like everything else.
     *
     * @return array<string, mixed>|null
     */
    public function bandFor(string $templateId, float $percentage): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT label, description, min_percentage FROM rubric_template_bands
             WHERE template_id = :id AND min_percentage <= :pct
             ORDER BY min_percentage DESC LIMIT 1"
        );
        $stmt->execute(['id' => $templateId, 'pct' => $percentage]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function bandsFor(string $templateId): array
    {
        $stmt = $this->db->prepare(
            "SELECT label, description, min_percentage FROM rubric_template_bands
             WHERE template_id = :id ORDER BY min_percentage DESC"
        );
        $stmt->execute(['id' => $templateId]);

        return $stmt->fetchAll();
    }
}
