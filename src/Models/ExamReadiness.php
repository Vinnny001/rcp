<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use RuntimeException;

/**
 * A student declaring they are ready to be examined, and the queue a
 * coordinator schedules from.
 *
 * Readiness is refused rather than merely discouraged when fees are
 * outstanding or required documents are missing: a student who reaches
 * the room without them wastes a panel's afternoon, so the check
 * belongs here rather than in a reminder.
 */
class ExamReadiness
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Exam windows open to this student — their program's, tagged with
     * a stage, with everything blocking each one worked out.
     *
     * @return array<int, array<string, mixed>>
     */
    public function windowsFor(string $studentId, string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT es.exam_schedule_id, es.exam_type, es.starts_at, es.ends_at,
                    es.exam_schedule_description,
                    s.stage_id, s.name AS stage_name,
                    r.readiness_id, r.marked_ready_at, r.meeting_id,
                    m.scheduled_at AS meeting_at, m.mode AS meeting_mode,
                    m.location AS meeting_location, m.virtual_link AS meeting_link
             FROM student_thesis_registrations str
             JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
             JOIN exam_schedule es ON es.thesis_schedule_id = ts.schedule_id
             JOIN exam_stages s ON s.stage_id = es.exam_stage_id
             LEFT JOIN exam_readiness r
                    ON r.exam_schedule_id = es.exam_schedule_id AND r.student_id = str.student_id
             LEFT JOIN meetings m ON m.meeting_id = r.meeting_id
             WHERE str.student_id = :student_id
               AND str.status <> 'withdrawn'
               AND s.is_active = 1
             ORDER BY es.starts_at"
        );
        $stmt->execute(['student_id' => $studentId]);

        // Only the exam for the stage the student is on, within their
        // time on the programme — see StudentExamWindows.
        $visibility = new StudentExamWindows($this->db);
        $windows = array_values(array_filter(
            $stmt->fetchAll(),
            fn (array $window): bool => $visibility->isWindowVisible($studentId, $window['exam_schedule_id'])
        ));

        $fees = new ExamFeeGate($this->db);
        foreach ($windows as &$window) {
            $window['fees'] = $fees->statusFor($studentId, $window['exam_schedule_id']);
            $window['documents'] = array_values(array_filter(
                $this->documentStatus($userId, $window['exam_schedule_id']),
                fn (array $doc): bool => $visibility->isDocumentSlotVisible($studentId, $window['exam_schedule_id'], $doc['document_type_id'])
            ));
            $window['blockers'] = $this->blockersFrom($window);
        }
        unset($window);

        return $windows;
    }

    /**
     * Which documents a window requires, and where each one stands:
     * submitted, a draft, sent back for resubmission, not open yet,
     * open, or past its deadline.
     *
     * Only a submitted document counts. An uploaded draft does not, and
     * neither does one an exam sent back — the same rule the Requirements
     * page uses (Document::findLatestSubmission), looking at the latest
     * upload for the slot.
     *
     * @return array<int, array<string, mixed>>
     */
    private function documentStatus(string $userId, string $examScheduleId): array
    {
        $latest = "FROM exam_documents ed
                   JOIN documents d ON d.document_id = ed.document_id
                   WHERE ed.exam_schedule_id = esd.exam_schedule_id
                     AND ed.document_type_id = esd.document_type_id
                     AND d.user_id = %s
                   ORDER BY ed.submitted_at DESC LIMIT 1";
        $stmt = $this->db->prepare(
            "SELECT dt.doc_type_name, esd.document_type_id,
                    esd.document_submission_starts_at,
                    esd.document_submission_deadline,
                    (SELECT d.document_status " . sprintf($latest, ':user_id') . ") AS latest_status,
                    (SELECT ed.requires_resubmit " . sprintf($latest, ':user_id2') . ") AS latest_resubmit
             FROM exam_schedule_documents esd
             JOIN document_types dt ON dt.doc_type_id = esd.document_type_id
             WHERE esd.exam_schedule_id = :exam_schedule_id
             ORDER BY dt.doc_type_name"
        );
        $stmt->execute(['user_id' => $userId, 'user_id2' => $userId, 'exam_schedule_id' => $examScheduleId]);

        // Same clock as the Requirements page, which decides when a
        // document can actually be uploaded.
        $now = new \DateTimeImmutable();

        return array_map(static function (array $r) use ($now): array {
            $resubmit = (int) $r['latest_resubmit'] === 1;
            $submitted = $r['latest_status'] === 'submitted' && !$resubmit;
            $opens = $r['document_submission_starts_at'] ? new \DateTimeImmutable($r['document_submission_starts_at']) : null;
            $due = $r['document_submission_deadline'] ? new \DateTimeImmutable($r['document_submission_deadline']) : null;

            return $r + [
                'is_submitted' => $submitted,
                'state'        => match (true) {
                    $submitted                        => 'submitted',
                    // Sent back for resubmission: open again whatever the dates.
                    $resubmit                         => 'resubmit',
                    $opens !== null && $now < $opens  => 'not_open',
                    $due !== null && $now > $due      => 'closed',
                    $r['latest_status'] === 'draft'   => 'draft',
                    default                           => 'open',
                },
            ];
        }, $stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $window
     * @return array<int, string>
     */
    private function blockersFrom(array $window): array
    {
        $blockers = [];

        foreach ($window['fees']['items'] as $item) {
            if ($item['state'] === 'unpaid') {
                $blockers[] = $item['label'] . ' is not paid.';
            } elseif ($item['state'] === 'pending') {
                $blockers[] = $item['label'] . ' is awaiting confirmation by the registrar.';
            }
        }

        foreach ($window['documents'] as $doc) {
            if ($doc['is_submitted']) {
                continue;
            }
            $blockers[] = match ($doc['state']) {
                'draft'    => $doc['doc_type_name'] . ' is still a draft — submit it on the Requirements page.',
                'resubmit' => $doc['doc_type_name'] . ' has to be resubmitted.',
                default    => $doc['doc_type_name'] . ' has not been submitted.',
            };
        }

        return $blockers;
    }

    /**
     * Records the declaration, refusing it while anything is still
     * outstanding.
     */
    public function markReady(string $studentId, string $userId, string $examScheduleId): void
    {
        $window = null;
        foreach ($this->windowsFor($studentId, $userId) as $candidate) {
            if ($candidate['exam_schedule_id'] === $examScheduleId) {
                $window = $candidate;
                break;
            }
        }

        if ($window === null) {
            throw new RuntimeException('That exam window is not open to you.');
        }
        if ($window['meeting_id'] !== null) {
            throw new RuntimeException('Your exam for this window has already been scheduled.');
        }
        if ($window['blockers'] !== []) {
            throw new RuntimeException('You still have outstanding items: ' . implode(' ', $window['blockers']));
        }

        $this->db->prepare(
            "INSERT INTO exam_readiness (readiness_id, student_id, exam_schedule_id)
             VALUES (UUID(), :student_id, :exam_schedule_id)
             ON DUPLICATE KEY UPDATE marked_ready_at = NOW()"
        )->execute(['student_id' => $studentId, 'exam_schedule_id' => $examScheduleId]);
    }

    public function withdraw(string $studentId, string $examScheduleId): void
    {
        $this->db->prepare(
            "DELETE FROM exam_readiness
             WHERE student_id = :student_id AND exam_schedule_id = :exam_schedule_id
               AND meeting_id IS NULL"
        )->execute(['student_id' => $studentId, 'exam_schedule_id' => $examScheduleId]);
    }

    /**
     * Students waiting to be examined, on the programs this
     * coordinator holds. Anyone whose exam is already scheduled drops
     * out of the queue.
     *
     * @param array<int, string> $programIds
     * @return array<int, array<string, mixed>>
     */
    public function queueForPrograms(array $programIds): array
    {
        if ($programIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($programIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT r.readiness_id, r.marked_ready_at,
                    r.exam_schedule_id, es.exam_type, es.starts_at, es.ends_at,
                    s.stage_id, s.name AS stage_name, s.rubric_template_id,
                    st.student_id, st.student_number,
                    CONCAT(su.first_name, ' ', su.last_name) AS student_name,
                    tp.proposal_id, tp.title AS proposal_title,
                    p.program_id, p.name AS program_name
             FROM exam_readiness r
             JOIN exam_schedule es ON es.exam_schedule_id = r.exam_schedule_id
             JOIN exam_stages s ON s.stage_id = es.exam_stage_id
             JOIN students st ON st.student_id = r.student_id
             JOIN users su ON su.user_id = st.user_id
             JOIN thesis_proposals tp ON tp.student_id = st.student_id AND tp.status <> 'rejected'
             JOIN student_thesis_registrations str ON str.student_id = st.student_id
             JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
             JOIN programs p ON p.program_id = ts.program_id
             WHERE r.meeting_id IS NULL
               AND p.program_id IN ($placeholders)
             ORDER BY r.marked_ready_at"
        );
        $stmt->execute($programIds);

        return $stmt->fetchAll();
    }

    /**
     * Schedules the exam and invites the panel.
     *
     * Every examiner is re-checked against the program's qualified
     * list here. The picker is filtered too, but a filtered dropdown
     * is not enforcement — this is.
     *
     * @param array<int, string> $examinerLecturerIds
     */
    public function scheduleExam(
        string $readinessId,
        string $programId,
        array $examinerLecturerIds,
        ?string $panelLeaderLecturerId,
        string $scheduledAt,
        string $mode,
        ?string $location,
        ?string $virtualLink,
        string $createdBy
    ): string {
        if ($examinerLecturerIds === []) {
            throw new RuntimeException('Invite at least one examiner.');
        }

        $readiness = $this->findReadiness($readinessId);
        if ($readiness === null) {
            throw new RuntimeException('That student is no longer waiting to be examined.');
        }
        if ($readiness['meeting_id'] !== null) {
            throw new RuntimeException('This exam has already been scheduled.');
        }

        $qualifications = new ExaminerQualification($this->db);
        foreach ($examinerLecturerIds as $lecturerId) {
            if (!$qualifications->isQualified($lecturerId, $programId)) {
                throw new RuntimeException('One of the examiners is not qualified to examine this program.');
            }
        }
        if ($panelLeaderLecturerId !== null && !in_array($panelLeaderLecturerId, $examinerLecturerIds, true)) {
            throw new RuntimeException('The panel leader has to be one of the examiners.');
        }

        // Staff studying for their own degree are qualified examiners
        // too. On their own exam they are the candidate — and since the
        // panel leader must be an examiner, this covers the leader too.
        $own = new OwnRecord($this->db);
        $own->refuse($createdBy, $readiness['student_id'], 'schedule this exam');
        foreach ($examinerLecturerIds as $lecturerId) {
            if ($own->userForLecturer($lecturerId) === $own->userForStudent($readiness['student_id'])) {
                throw new RuntimeException('The student cannot examine their own work — take them off the panel.');
            }
        }

        $meeting = new Meeting($this->db);
        $meetingId = $meeting->create($readiness['proposal_id'], [
            // Which stage this examines is exam_stage_id's job, not the
            // type's — that is what lets a new stage be added without
            // touching the enum.
            'meeting_type'     => 'exam',
            'scheduled_at'     => $scheduledAt,
            'mode'             => $mode,
            'location'         => $location ?: null,
            'virtual_link'     => $virtualLink ?: null,
            'ai_notes_enabled' => false,
        ], $createdBy);

        $this->db->prepare(
            "UPDATE meetings SET exam_stage_id = :stage_id, exam_schedule_id = :exam_schedule_id
             WHERE meeting_id = :id"
        )->execute([
            'id'               => $meetingId,
            'stage_id'         => $readiness['stage_id'],
            'exam_schedule_id' => $readiness['exam_schedule_id'],
        ]);

        $userIdFor = $this->db->prepare("SELECT user_id FROM lecturers WHERE lecturer_id = :id LIMIT 1");
        foreach ($examinerLecturerIds as $lecturerId) {
            $userIdFor->execute(['id' => $lecturerId]);
            $meeting->addAttendee($meetingId, (string) $userIdFor->fetchColumn(), 'examiner');
        }

        if ($panelLeaderLecturerId !== null) {
            $userIdFor->execute(['id' => $panelLeaderLecturerId]);
            (new Rubric($this->db))->designateLeader($meetingId, (string) $userIdFor->fetchColumn());
        }

        $this->db->prepare("UPDATE exam_readiness SET meeting_id = :meeting_id WHERE readiness_id = :id")
            ->execute(['id' => $readinessId, 'meeting_id' => $meetingId]);

        return $meetingId;
    }

    private function findReadiness(string $readinessId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT r.readiness_id, r.meeting_id, r.exam_schedule_id, r.student_id,
                    es.exam_stage_id AS stage_id,
                    tp.proposal_id
             FROM exam_readiness r
             JOIN exam_schedule es ON es.exam_schedule_id = r.exam_schedule_id
             JOIN thesis_proposals tp ON tp.student_id = r.student_id AND tp.status <> 'rejected'
             WHERE r.readiness_id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $readinessId]);

        return $stmt->fetch() ?: null;
    }
}
