<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * What an examiner has been asked to mark, and what a coordinator is
 * waiting to release.
 *
 * An examiner reaches a marking sheet by being an attendee of an exam
 * meeting with the examiner role — the same invitation that put them
 * on the panel. Meetings without a stage examine nothing and never
 * appear here.
 */
class ExaminerAssignment
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Exam meetings this user is an examiner on.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forExaminer(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.meeting_id, m.scheduled_at, m.status, m.meeting_type,
                    s.stage_id, s.name AS stage_name,
                    t.template_id, t.name AS template_name, t.has_panel_leader,
                    tp.proposal_id, tp.title AS proposal_title,
                    st.student_number,
                    CONCAT(su.first_name, ' ', su.last_name) AS student_name,
                    pl.examiner_id AS panel_leader_id,
                    pl.average_score, pl.confirmed_at, pl.approved_at
             FROM meeting_attendees ma
             JOIN meetings m ON m.meeting_id = ma.meeting_id
             JOIN exam_stages s ON s.stage_id = m.exam_stage_id
             LEFT JOIN rubric_templates t ON t.template_id = s.rubric_template_id
             JOIN thesis_proposals tp ON tp.proposal_id = m.proposal_id
             JOIN students st ON st.student_id = tp.student_id
             JOIN users su ON su.user_id = st.user_id
             LEFT JOIN rubric_panel_leaders pl ON pl.meeting_id = m.meeting_id
             WHERE ma.user_id = :user_id
               AND ma.role_in_meeting = 'examiner'
               AND st.user_id <> ma.user_id
               AND m.status <> 'cancelled'
             ORDER BY m.scheduled_at DESC"
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    /**
     * One meeting, only if this user is an examiner on it — the check
     * and the fetch are the same query so a marking sheet cannot be
     * opened by someone who was never invited to mark it.
     */
    public function findForExaminer(string $meetingId, string $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT m.meeting_id, m.scheduled_at, m.status,
                    s.stage_id, s.name AS stage_name,
                    t.template_id, t.name AS template_name, t.has_panel_leader,
                    tp.proposal_id, tp.title AS proposal_title,
                    st.student_number,
                    CONCAT(su.first_name, ' ', su.last_name) AS student_name
             FROM meeting_attendees ma
             JOIN meetings m ON m.meeting_id = ma.meeting_id
             JOIN exam_stages s ON s.stage_id = m.exam_stage_id
             LEFT JOIN rubric_templates t ON t.template_id = s.rubric_template_id
             JOIN thesis_proposals tp ON tp.proposal_id = m.proposal_id
             JOIN students st ON st.student_id = tp.student_id
             JOIN users su ON su.user_id = st.user_id
             WHERE m.meeting_id = :meeting_id
               AND ma.user_id = :user_id
               AND ma.role_in_meeting = 'examiner'
               AND st.user_id <> ma.user_id
             LIMIT 1"
        );
        $stmt->execute(['meeting_id' => $meetingId, 'user_id' => $userId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Averages a coordinator can release: confirmed by the panel
     * leader, not yet approved, on a program they hold.
     *
     * @param array<int, string> $programIds
     * @return array<int, array<string, mixed>>
     */
    public function awaitingApproval(array $programIds): array
    {
        if ($programIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($programIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT m.meeting_id, m.scheduled_at,
                    s.name AS stage_name, t.template_id,
                    tp.title AS proposal_title,
                    st.student_id, st.student_number,
                    CONCAT(su.first_name, ' ', su.last_name) AS student_name,
                    pl.average_score, pl.confirmed_at,
                    CONCAT(lu.first_name, ' ', lu.last_name) AS leader_name,
                    p.name AS program_name
             FROM rubric_panel_leaders pl
             JOIN meetings m ON m.meeting_id = pl.meeting_id
             JOIN exam_stages s ON s.stage_id = m.exam_stage_id
             LEFT JOIN rubric_templates t ON t.template_id = s.rubric_template_id
             JOIN users lu ON lu.user_id = pl.examiner_id
             JOIN thesis_proposals tp ON tp.proposal_id = m.proposal_id
             JOIN students st ON st.student_id = tp.student_id
             JOIN users su ON su.user_id = st.user_id
             JOIN student_thesis_registrations str ON str.student_id = st.student_id
             JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
             JOIN programs p ON p.program_id = ts.program_id
             WHERE pl.confirmed_at IS NOT NULL
               AND pl.approved_at IS NULL
               AND p.program_id IN ($placeholders)
             ORDER BY pl.confirmed_at"
        );
        $stmt->execute($programIds);

        return $stmt->fetchAll();
    }

    /** The student a meeting examines, for the own-record check. */
    public function studentForMeeting(string $meetingId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT tp.student_id FROM meetings m
             JOIN thesis_proposals tp ON tp.proposal_id = m.proposal_id
             WHERE m.meeting_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $meetingId]);
        $studentId = $stmt->fetchColumn();

        return $studentId ? (string) $studentId : null;
    }

    /**
     * The program a meeting belongs to, for the coordinator check.
     */
    public function programForMeeting(string $meetingId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT p.program_id
             FROM meetings m
             JOIN thesis_proposals tp ON tp.proposal_id = m.proposal_id
             JOIN student_thesis_registrations str ON str.student_id = tp.student_id
             JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
             JOIN programs p ON p.program_id = ts.program_id
             WHERE m.meeting_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $meetingId]);
        $programId = $stmt->fetchColumn();

        return $programId ? (string) $programId : null;
    }
}
