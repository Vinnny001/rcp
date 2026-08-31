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
     * A proposal by its own id, with the student's identity attached —
     * for a lecturer/examiner reading someone else's proposal (e.g. the
     * "Proposal overview" button during exam-document review), not the
     * student's own session-scoped view.
     */
    public function findById(string $proposalId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT tp.*, s.student_number, CONCAT(u.first_name, ' ', u.last_name) AS student_name
             FROM thesis_proposals tp
             JOIN students s ON s.student_id = tp.student_id
             JOIN users u ON u.user_id = s.user_id
             WHERE tp.proposal_id = :proposal_id
             LIMIT 1"
        );
        $stmt->execute(['proposal_id' => $proposalId]);
        $row = $stmt->fetch();
        return $row ?: null;
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



        /**
     * Auto-assigns a supervisor once a schedule's enrollment window has
     * closed and the proposed supervisor never responded (or declined)
     * — every condition is re-checked here directly against the DB, so
     * this is safe to call speculatively on every dossier/roster load
     * (there's no cron in this app; this is the lazy substitute) without
     * worrying about acting twice or acting too early:
     *   - no supervisor assigned yet, but one was proposed
     *   - the proposing student's active registration's schedule has
     *     already closed enrollment
     *   - that registration's fee is paid (no point auto-assigning a
     *     student who never actually completed registration)
     *   - the proposed supervisor's request is not 'accepted'
     * A no-op (no exception) if any condition fails, or if no eligible
     * internal lecturer has remaining capacity.
     */
    public function autoAssignSupervisorIfEligible(string $proposalId): void
    {
        $stmt = $this->db->prepare(
            "SELECT student_id, proposed_supervisor_id, assigned_supervisor_id
             FROM thesis_proposals WHERE proposal_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $proposalId]);
        $proposal = $stmt->fetch();

        if (!$proposal || $proposal['assigned_supervisor_id'] || !$proposal['proposed_supervisor_id']) {
            return;
        }

        $requestStmt = $this->db->prepare(
            "SELECT status FROM supervision_requests
             WHERE proposal_id = :proposal_id AND lecturer_id = :lecturer_id
             ORDER BY requested_at DESC LIMIT 1"
        );
        $requestStmt->execute([
            'proposal_id'  => $proposalId,
            'lecturer_id'  => $proposal['proposed_supervisor_id'],
        ]);
        $requestStatus = $requestStmt->fetchColumn();
        if ($requestStatus === 'accepted') {
            return;
        }

        $regModel = new ThesisRegistration($this->db);
        $registration = $regModel->findActiveByStudentId($proposal['student_id']);
        if (!$registration) {
            return;
        }

        $scheduleStmt = $this->db->prepare(
            "SELECT enrollment_end_date, created_by FROM thesis_schedules WHERE schedule_id = :id LIMIT 1"
        );
        $scheduleStmt->execute(['id' => $registration['thesis_schedule_id']]);
        $schedule = $scheduleStmt->fetch();

        if (!$schedule || new \DateTimeImmutable($schedule['enrollment_end_date']) >= new \DateTimeImmutable()) {
            return; // window still open — not eligible yet
        }

        foreach ($regModel->computeOwed($registration) as $item) {
            if ($item['fee_type'] === 'thesis_registration') {
                return; // registration fee still unpaid — never completed registration
            }
        }

        $lecturerModel = new Lecturer($this->db);
        $candidate = $lecturerModel->findBestAvailableInternalCandidate();
        if (!$candidate) {
            return; // nobody with remaining capacity right now
        }

        (new SupervisionRequest($this->db))->autoAssignIfUnresponsive(
            $proposalId,
            $proposal['student_id'],
            $candidate['lecturer_id'],
            $schedule['created_by']
        );
    }

    /**
     * Whether the given thesis_schedule_id has an exam_schedule entry
     * for the 'proposal' document type — a proposal can only be
     * submitted if it will actually be checked/reviewed under some
     * scheduled exam window, not just accepted into a void.
     */
    public function proposalSchedulingExists(string $thesisScheduleId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM exam_schedule es
             JOIN exam_schedule_documents esd ON esd.exam_schedule_id = es.exam_schedule_id
             JOIN document_types dt ON dt.doc_type_id = esd.document_type_id
             WHERE es.thesis_schedule_id = :thesis_schedule_id
               AND dt.doc_type_name = 'Proposal'
             LIMIT 1"
        );
        $stmt->execute(['thesis_schedule_id' => $thesisScheduleId]);
        return (bool) $stmt->fetchColumn();
    }
}
