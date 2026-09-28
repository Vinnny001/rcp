<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use RuntimeException;

/**
 * A student continuing from where they left off.
 *
 * Someone who already passed stages — on paper, elsewhere, or before this
 * system existed — should not start again. They name the last stage they
 * passed and upload the evidence for it; an administrator approves the
 * claim, and that stage and every earlier one are recorded complete, so
 * the journey resumes at the next stage.
 *
 * What counts as evidence is not fixed here. An administrator sets a list
 * per stage on the Exam Stages screen — minutes, a transcript, both, or
 * anything else they add — and stages with no list of their own use the
 * default list (`stage_id IS NULL`).
 */
class PriorProgress
{
    public const MAX_LABEL_LENGTH = 150;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    // What an administrator asks for
    // ------------------------------------------------------------------

    /**
     * The evidence a claim on this stage has to carry: the stage's own
     * list if it has one, otherwise the default list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function requirementsFor(?string $stageId): array
    {
        $own = $stageId === null ? [] : $this->requirementRows($stageId);

        return $own !== [] ? $own : $this->requirementRows(null);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function requirementRows(?string $stageId): array
    {
        $stmt = $this->db->prepare(
            "SELECT r.*, dt.doc_type_name
             FROM stage_evidence_requirements r
             JOIN document_types dt ON dt.doc_type_id = r.document_type_id
             WHERE r.is_active = 1
               AND " . ($stageId === null ? "r.stage_id IS NULL" : "r.stage_id = :stage_id") . "
             ORDER BY r.display_order, r.label"
        );
        $stmt->execute($stageId === null ? [] : ['stage_id' => $stageId]);

        return $stmt->fetchAll();
    }

    /**
     * Every list an administrator maintains, keyed by stage id with the
     * default list under ''.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function allRequirements(): array
    {
        $rows = $this->db->query(
            "SELECT r.*, dt.doc_type_name
             FROM stage_evidence_requirements r
             JOIN document_types dt ON dt.doc_type_id = r.document_type_id
             WHERE r.is_active = 1
             ORDER BY r.display_order, r.label"
        )->fetchAll();

        $byStage = [];
        foreach ($rows as $row) {
            $byStage[$row['stage_id'] ?? ''][] = $row;
        }

        return $byStage;
    }

    /**
     * Adds a piece of evidence to a stage's list, or to the default list
     * when no stage is given.
     *
     * @param array<string, mixed> $data label, document_type_id, note, is_required
     */
    public function saveRequirement(?string $requirementId, ?string $stageId, array $data): void
    {
        $label = trim((string) ($data['label'] ?? ''));
        $documentTypeId = (string) ($data['document_type_id'] ?? '');

        if ($label === '' || mb_strlen($label) > self::MAX_LABEL_LENGTH) {
            throw new RuntimeException('Give the evidence a name of up to ' . self::MAX_LABEL_LENGTH . ' characters.');
        }
        if ($documentTypeId === '') {
            throw new RuntimeException('Choose what kind of document this is.');
        }

        $fields = [
            'label'            => $label,
            'document_type_id' => $documentTypeId,
            'note'             => trim((string) ($data['note'] ?? '')) ?: null,
            'is_required'      => !empty($data['is_required']) ? 1 : 0,
            'display_order'    => (int) ($data['display_order'] ?? 0) ?: $this->nextOrder($stageId),
        ];

        if ($requirementId !== null && $requirementId !== '') {
            $this->db->prepare(
                "UPDATE stage_evidence_requirements
                 SET label = :label, document_type_id = :document_type_id, note = :note,
                     is_required = :is_required, display_order = :display_order
                 WHERE requirement_id = :id"
            )->execute($fields + ['id' => $requirementId]);

            return;
        }

        $this->db->prepare(
            "INSERT INTO stage_evidence_requirements
                (requirement_id, stage_id, label, document_type_id, note, is_required, display_order)
             VALUES (UUID(), :stage_id, :label, :document_type_id, :note, :is_required, :display_order)"
        )->execute($fields + ['stage_id' => $stageId ?: null]);
    }

    /**
     * Retires a piece of evidence. The row stays so claims that answered
     * it still read correctly; it is simply no longer asked for.
     */
    public function removeRequirement(string $requirementId): void
    {
        $this->db->prepare("UPDATE stage_evidence_requirements SET is_active = 0 WHERE requirement_id = :id")
            ->execute(['id' => $requirementId]);
    }

    private function nextOrder(?string $stageId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(MAX(display_order), 0) + 1 FROM stage_evidence_requirements
             WHERE " . ($stageId === null || $stageId === '' ? "stage_id IS NULL" : "stage_id = :stage_id")
        );
        $stmt->execute($stageId === null || $stageId === '' ? [] : ['stage_id' => $stageId]);

        return (int) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------------
    // The student's claim
    // ------------------------------------------------------------------

    /**
     * What the student's page shows: the stages they can claim, the
     * evidence each asks for, and the claim they already made, if any.
     *
     * @return array{
     *     stages: array<int, array<string, mixed>>, claim: ?array,
     *     can_claim: bool, already_done: array<int, string>
     * }
     */
    public function studentState(string $studentId, string $userId): array
    {
        $claim = $this->latestClaim($studentId);
        $journey = new StudentJourney($this->db);
        $stages = $journey->examStages($studentId, $userId, (new Proposal($this->db))->findActiveByStudentId($studentId), new ExaminationScore($this->db));

        $done = [];
        $claimable = [];
        foreach ($stages as $stage) {
            if ($stage['done']) {
                $done[] = $stage['name'];
                continue;
            }
            $claimable[] = $stage;
        }

        return [
            // The last stage they passed is one they have not been
            // recorded as passing here — anything already done is theirs
            // already, and the final stage is not a place to resume from.
            'stages'       => $claimable,
            'claim'        => $claim,
            'already_done' => $done,
            'can_claim'    => $claimable !== [] && ($claim === null || $claim['status'] === 'rejected'),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function latestClaim(string $studentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, s.name AS stage_name, s.display_order,
                    CONCAT(u.first_name, ' ', u.last_name) AS decided_by_name
             FROM prior_progress_claims c
             JOIN exam_stages s ON s.stage_id = c.stage_id
             LEFT JOIN users u ON u.user_id = c.decided_by
             WHERE c.student_id = :student_id
             ORDER BY c.created_at DESC LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);
        $claim = $stmt->fetch();

        if (!$claim) {
            return null;
        }

        $claim['files'] = $this->filesFor($claim['claim_id']);

        return $claim;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function filesFor(string $claimId): array
    {
        $stmt = $this->db->prepare(
            "SELECT f.*, d.file_name, d.file_path, d.uploaded_at
             FROM prior_progress_files f
             JOIN documents d ON d.document_id = f.document_id
             WHERE f.claim_id = :claim_id
             ORDER BY f.label"
        );
        $stmt->execute(['claim_id' => $claimId]);

        return $stmt->fetchAll();
    }

    /**
     * Records the claim. The files are already uploaded as documents by
     * the controller, which knows about the filesystem; this stores what
     * each one answers.
     *
     * @param array<int, array{requirement_id: ?string, label: string, document_id: string}> $files
     */
    public function submit(string $studentId, string $stageId, array $files, string $note, string $userId): string
    {
        $state = $this->studentState($studentId, $userId);

        if (!$state['can_claim']) {
            throw new RuntimeException($state['claim']['status'] === 'submitted'
                ? 'Your claim is with the graduate school. You will hear when it is decided.'
                : 'There is no stage left for you to claim.');
        }
        if (!in_array($stageId, array_column($state['stages'], 'stage_id'), true)) {
            throw new RuntimeException('Choose a stage you have not already been recorded as passing.');
        }

        $answered = array_column($files, 'requirement_id');
        foreach ($this->requirementsFor($stageId) as $requirement) {
            if ($requirement['is_required'] && !in_array($requirement['requirement_id'], $answered, true)) {
                throw new RuntimeException('Attach the ' . $requirement['label'] . ' — it is required.');
            }
        }
        if ($files === []) {
            throw new RuntimeException('Attach the evidence that you passed this stage.');
        }

        $claimId = $this->uuid();
        $this->db->prepare(
            "INSERT INTO prior_progress_claims (claim_id, student_id, stage_id, student_note)
             VALUES (:claim_id, :student_id, :stage_id, :note)"
        )->execute([
            'claim_id'   => $claimId,
            'student_id' => $studentId,
            'stage_id'   => $stageId,
            'note'       => trim($note) === '' ? null : trim($note),
        ]);

        $attach = $this->db->prepare(
            "INSERT INTO prior_progress_files (file_id, claim_id, requirement_id, label, document_id)
             VALUES (UUID(), :claim_id, :requirement_id, :label, :document_id)"
        );
        foreach ($files as $file) {
            $attach->execute([
                'claim_id'       => $claimId,
                'requirement_id' => $file['requirement_id'] ?: null,
                'label'          => mb_substr($file['label'], 0, self::MAX_LABEL_LENGTH),
                'document_id'    => $file['document_id'],
            ]);
        }

        return $claimId;
    }

    // ------------------------------------------------------------------
    // The administrator's decision
    // ------------------------------------------------------------------

    /**
     * Claims waiting to be decided, and recently decided ones.
     *
     * @return array<int, array<string, mixed>>
     */
    public function queue(bool $pendingOnly = true): array
    {
        $rows = $this->db->query(
            "SELECT c.*, s.name AS stage_name, s.display_order,
                    st.student_number, CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                    p.name AS program_name
             FROM prior_progress_claims c
             JOIN exam_stages s ON s.stage_id = c.stage_id
             JOIN students st ON st.student_id = c.student_id
             JOIN users u ON u.user_id = st.user_id
             LEFT JOIN student_thesis_registrations str
                    ON str.student_id = st.student_id AND str.status = 'active'
             LEFT JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
             LEFT JOIN programs p ON p.program_id = ts.program_id
             " . ($pendingOnly ? "WHERE c.status = 'submitted'" : "") . "
             ORDER BY c.created_at DESC"
        )->fetchAll();

        foreach ($rows as &$row) {
            $row['files'] = $this->filesFor($row['claim_id']);
        }
        unset($row);

        return $rows;
    }

    /**
     * Approves the claim: the stage named and every earlier active stage
     * are recorded complete, so the student resumes at the next one.
     */
    public function approve(string $claimId, string $adminUserId): string
    {
        $claim = $this->findClaim($claimId);
        if ($claim['status'] !== 'submitted') {
            throw new RuntimeException('That claim has already been decided.');
        }

        $journey = new StudentJourney($this->db);
        $granted = [];
        foreach ((new ExamStage($this->db))->allActive() as $stage) {
            if ((int) $stage['display_order'] > (int) $claim['display_order']) {
                break;
            }
            $journey->recordStageComplete($claim['student_id'], $stage['stage_id']);
            $granted[] = $stage['name'];
        }

        $this->decide($claimId, 'approved', $adminUserId, null);

        return implode(', ', $granted);
    }

    public function reject(string $claimId, string $adminUserId, string $reason): void
    {
        $claim = $this->findClaim($claimId);
        if ($claim['status'] !== 'submitted') {
            throw new RuntimeException('That claim has already been decided.');
        }
        if (trim($reason) === '') {
            throw new RuntimeException('Give a reason, so the student knows what to send instead.');
        }

        $this->decide($claimId, 'rejected', $adminUserId, trim($reason));
    }

    private function decide(string $claimId, string $status, string $adminUserId, ?string $reason): void
    {
        $this->db->prepare(
            "UPDATE prior_progress_claims
             SET status = :status, decision_reason = :reason, decided_by = :by, decided_at = NOW()
             WHERE claim_id = :id"
        )->execute(['status' => $status, 'reason' => $reason, 'by' => $adminUserId, 'id' => $claimId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function findClaim(string $claimId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, s.display_order FROM prior_progress_claims c
             JOIN exam_stages s ON s.stage_id = c.stage_id
             WHERE c.claim_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $claimId]);
        $claim = $stmt->fetch();

        if (!$claim) {
            throw new RuntimeException('That claim could not be found.');
        }

        return $claim;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
