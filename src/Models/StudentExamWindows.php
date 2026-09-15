<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Which exam windows — and which of their required documents — a
 * student can see. Every student-facing place that lists exams or their
 * documents asks here: the exam page, the Requirements page, the fees a
 * student owes, and where an uploaded proposal is filed.
 *
 * A window on the student's thesis schedule is visible when:
 *
 *  - it falls within their time on the programme: it did not close
 *    before they registered, and it does not close after they graduated;
 *  - if it examines a stage, that stage is the one they are on now.
 *    Exams are taken in order (exam_stages.display_order — Proposal
 *    Approval, Concept Presentation, Thesis Draft, Final Thesis), so a
 *    student sees neither the stages they have passed nor the ones they
 *    have not reached. StudentJourney decides which stage that is.
 *
 * A required document is visible only through a visible window, and not
 * when its own deadline passed before the student registered.
 *
 * A window without a stage examines nothing and has no place in the
 * order, so only the dates apply to it. Such windows never reach the exam
 * page; their documents still appear on the Requirements page, where the
 * older supervisor-scheduled review meetings rely on them.
 */
class StudentExamWindows
{
    private PDO $db;

    /** @var array<string, array<string, mixed>|null> */
    private array $contexts = [];

    /** @var array<string, array<string, array<string, mixed>>> */
    private array $windows = [];

    /** @var array<string, array<int, array<string, mixed>>> */
    private array $slots = [];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<string, array<string, mixed>> keyed by exam_schedule_id
     */
    public function visibleWindows(string $studentId): array
    {
        if (isset($this->windows[$studentId])) {
            return $this->windows[$studentId];
        }

        $context = $this->context($studentId);
        if ($context === null) {
            return $this->windows[$studentId] = [];
        }

        $stmt = $this->db->prepare(
            "SELECT es.exam_schedule_id, es.exam_stage_id, es.starts_at, es.ends_at
             FROM exam_schedule es
             LEFT JOIN exam_stages s ON s.stage_id = es.exam_stage_id
             WHERE es.thesis_schedule_id = :schedule_id
               AND es.ends_at >= :registered_at
               AND (:graduated_on1 IS NULL OR DATE(es.ends_at) <= :graduated_on2)
               AND (es.exam_stage_id IS NULL OR (s.is_active = 1 AND es.exam_stage_id = :stage_id))
             ORDER BY es.starts_at"
        );
        $stmt->execute([
            'schedule_id'   => $context['thesis_schedule_id'],
            'registered_at' => $context['registered_at'],
            'graduated_on1' => $context['graduated_on'],
            'graduated_on2' => $context['graduated_on'],
            // No current stage — every stage done, or graduated — matches no staged window.
            'stage_id'      => $context['current_stage_id'],
        ]);

        $visible = [];
        foreach ($stmt->fetchAll() as $row) {
            $visible[$row['exam_schedule_id']] = $row;
        }

        return $this->windows[$studentId] = $visible;
    }

    public function isWindowVisible(string $studentId, string $examScheduleId): bool
    {
        return isset($this->visibleWindows($studentId)[$examScheduleId]);
    }

    /**
     * The document slots — window and document type — this student can see.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visibleDocumentSlots(string $studentId): array
    {
        if (isset($this->slots[$studentId])) {
            return $this->slots[$studentId];
        }

        $windowIds = array_keys($this->visibleWindows($studentId));
        if ($windowIds === []) {
            return $this->slots[$studentId] = [];
        }

        $placeholders = implode(',', array_fill(0, count($windowIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT esd.exam_schedule_id, esd.document_type_id,
                    esd.document_submission_starts_at, esd.document_submission_deadline
             FROM exam_schedule_documents esd
             WHERE esd.exam_schedule_id IN ($placeholders)
               AND (esd.document_submission_deadline IS NULL OR esd.document_submission_deadline >= ?)"
        );
        $stmt->execute(array_merge($windowIds, [$this->context($studentId)['registered_at']]));

        return $this->slots[$studentId] = $stmt->fetchAll();
    }

    public function isDocumentSlotVisible(string $studentId, string $examScheduleId, string $documentTypeId): bool
    {
        foreach ($this->visibleDocumentSlots($studentId) as $slot) {
            if ($slot['exam_schedule_id'] === $examScheduleId && $slot['document_type_id'] === $documentTypeId) {
                return true;
            }
        }

        return false;
    }

    /**
     * What the rules are measured against: the student's thesis
     * registration, their graduation date if approved, and their
     * current exam stage.
     *
     * @return array{thesis_schedule_id: string, registered_at: string, graduated_on: ?string, current_stage_id: ?string}|null
     */
    private function context(string $studentId): ?array
    {
        if (array_key_exists($studentId, $this->contexts)) {
            return $this->contexts[$studentId];
        }

        $stmt = $this->db->prepare(
            "SELECT st.user_id, str.thesis_schedule_id, str.registered_at,
                    (SELECT gl.approval_date FROM graduation_list gl
                      WHERE gl.student_id = st.student_id AND gl.graduate_school_approved = 1
                      LIMIT 1) AS graduated_on
             FROM students st
             JOIN student_thesis_registrations str
                  ON str.student_id = st.student_id AND str.status <> 'withdrawn'
             WHERE st.student_id = :student_id
             ORDER BY str.registered_at DESC
             LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);
        $row = $stmt->fetch();

        if (!$row) {
            return $this->contexts[$studentId] = null;
        }

        $stage = (new StudentJourney($this->db))->currentExamStage($studentId, $row['user_id']);

        return $this->contexts[$studentId] = [
            'thesis_schedule_id' => $row['thesis_schedule_id'],
            'registered_at'      => $row['registered_at'],
            'graduated_on'       => $row['graduated_on'],
            'current_stage_id'   => $stage['stage_id'] ?? null,
        ];
    }
}
