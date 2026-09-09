<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Composes the journey rail a student sees on their dashboard.
 *
 * The rail is three fixed structural steps — Registration,
 * Requirements Validation, Graduation — wrapped around however many
 * exam stages the department currently has configured. Adding a stage
 * is a row in `exam_stages`, not a template edit.
 *
 * A finished student's rail is rendered from their own snapshot rows
 * instead of the live stage list, so renaming or reordering a stage
 * can never rewrite a track someone already completed.
 */
class StudentJourney
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int, array{key: string, label: string, status: string, fixed: bool}>
     */
    public function railFor(
        string $studentId,
        string $userId,
        bool $registrationDone,
        ?array $proposal,
        ExaminationScore $examScores
    ): array {
        if ($this->isFinished($studentId)) {
            return $this->frozenRail($studentId);
        }

        return $this->liveRail($studentId, $userId, $registrationDone, $proposal, $examScores);
    }

    /**
     * Records a stage as complete, capturing the snapshot that keeps it
     * readable after the stage is renamed, reordered or retired.
     * Idempotent — the first completion wins and is never overwritten.
     */
    public function recordStageComplete(string $studentId, string $stageId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO student_stage_progress
                (progress_id, student_id, stage_id, status, completed_at,
                 stage_name_snapshot, display_order_snapshot)
             SELECT UUID(), :student_id, s.stage_id, 'complete', NOW(), s.name, s.display_order
             FROM exam_stages s
             WHERE s.stage_id = :stage_id
             ON DUPLICATE KEY UPDATE
                status               = 'complete',
                completed_at         = COALESCE(student_stage_progress.completed_at, NOW()),
                stage_name_snapshot  = COALESCE(student_stage_progress.stage_name_snapshot, VALUES(stage_name_snapshot)),
                display_order_snapshot = COALESCE(student_stage_progress.display_order_snapshot, VALUES(display_order_snapshot))"
        );
        $stmt->execute(['student_id' => $studentId, 'stage_id' => $stageId]);
    }

    /**
     * Only graduation freezes a track. A withdrawn student hasn't
     * completed anything — freezing them would render a partial rail
     * with the unreached steps missing entirely, which reads as data
     * loss rather than as a record.
     */
    private function isFinished(string $studentId): bool
    {
        $stmt = $this->db->prepare("SELECT current_status FROM students WHERE student_id = :id LIMIT 1");
        $stmt->execute(['id' => $studentId]);

        return $stmt->fetchColumn() === 'graduated';
    }

    /**
     * @return array<int, array{key: string, label: string, status: string, fixed: bool}>
     */
    private function frozenRail(string $studentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT stage_id, status, stage_name_snapshot, display_order_snapshot
             FROM student_stage_progress
             WHERE student_id = :id AND stage_name_snapshot IS NOT NULL
             ORDER BY display_order_snapshot"
        );
        $stmt->execute(['id' => $studentId]);

        $rail = [
            ['key' => 'registration',  'label' => 'Registration',            'status' => 'done', 'fixed' => true],
            ['key' => 'requirements',  'label' => 'Requirements Validation', 'status' => 'done', 'fixed' => true],
        ];

        foreach ($stmt->fetchAll() as $row) {
            $rail[] = [
                'key'    => 'stage-' . $row['stage_id'],
                'label'  => $row['stage_name_snapshot'],
                'status' => $row['status'] === 'complete' ? 'done' : 'upcoming',
                'fixed'  => false,
            ];
        }

        // Status 'graduated' and an approved graduation record are set
        // by different hands, so don't assume the second from the first.
        $rail[] = [
            'key'    => 'graduation',
            'label'  => 'Graduation',
            'status' => $this->graduationApproved($studentId) ? 'done' : 'current',
            'fixed'  => true,
        ];

        return $rail;
    }

    /**
     * @return array<int, array{key: string, label: string, status: string, fixed: bool}>
     */
    private function liveRail(
        string $studentId,
        string $userId,
        bool $registrationDone,
        ?array $proposal,
        ExaminationScore $examScores
    ): array {
        $completedStageIds = $this->completedStageIds($studentId);
        $passedDocTypes    = $this->passedDocTypes($userId, $examScores);
        $proposalApproved  = $proposal !== null && ($proposal['status'] ?? null) === 'approved';

        $rail = [
            ['key' => 'registration', 'label' => 'Registration',            'done' => $registrationDone, 'fixed' => true],
            ['key' => 'requirements', 'label' => 'Requirements Validation', 'done' => $proposal !== null, 'fixed' => true],
        ];

        foreach ((new ExamStage($this->db))->allActive() as $stage) {
            $done = isset($completedStageIds[$stage['stage_id']]);

            if (!$done && $stage['evidence_doc_type_name'] !== null) {
                $done = isset($passedDocTypes[$stage['evidence_doc_type_name']]);
            }

            // An approved proposal also clears whichever stage the
            // Proposal document evidences — proposals approved before
            // exam scoring existed have no score row to match on.
            if (!$done && $proposalApproved && $stage['evidence_doc_type_name'] === 'Proposal') {
                $done = true;
            }

            if ($done && !isset($completedStageIds[$stage['stage_id']])) {
                // Materialise the snapshot the moment we first observe
                // the stage as complete, so the record exists to freeze
                // later even though nothing wrote it at the time.
                $this->recordStageComplete($studentId, $stage['stage_id']);
            }

            $rail[] = [
                'key'   => 'stage-' . $stage['stage_id'],
                'label' => $stage['name'],
                'done'  => $done,
                'fixed' => false,
            ];
        }

        $rail[] = [
            'key'   => 'graduation',
            'label' => 'Graduation',
            'done'  => $this->graduationApproved($studentId),
            'fixed' => true,
        ];

        return $this->applyStatuses($rail);
    }

    /**
     * The first step that isn't done is the one the student is on;
     * everything after it is still ahead of them.
     *
     * @param array<int, array{key: string, label: string, done: bool, fixed: bool}> $rail
     * @return array<int, array{key: string, label: string, status: string, fixed: bool}>
     */
    private function applyStatuses(array $rail): array
    {
        $currentAssigned = false;

        return array_map(static function (array $node) use (&$currentAssigned): array {
            if ($node['done']) {
                $status = 'done';
            } elseif (!$currentAssigned) {
                $status = 'current';
                $currentAssigned = true;
            } else {
                $status = 'upcoming';
            }

            return [
                'key'    => $node['key'],
                'label'  => $node['label'],
                'status' => $status,
                'fixed'  => $node['fixed'],
            ];
        }, $rail);
    }

    /**
     * @return array<string, true>
     */
    private function completedStageIds(string $studentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT stage_id FROM student_stage_progress
             WHERE student_id = :id AND status = 'complete'"
        );
        $stmt->execute(['id' => $studentId]);

        return array_fill_keys($stmt->fetchAll(PDO::FETCH_COLUMN), true);
    }

    /**
     * Document types this student has a passing exam outcome for.
     *
     * @return array<string, true>
     */
    private function passedDocTypes(string $userId, ExaminationScore $examScores): array
    {
        $passed = [];

        foreach ($examScores->findExamOutcomesForStudent($userId) as $outcome) {
            if (GradingPolicy::isPass($outcome['outcome'])) {
                $passed[$outcome['doc_type_name']] = true;
            }
        }

        return $passed;
    }

    private function graduationApproved(string $studentId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT graduate_school_approved FROM graduation_list WHERE student_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $studentId]);

        return (bool) $stmt->fetchColumn();
    }
}
