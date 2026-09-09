<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use RuntimeException;

/**
 * An exam meeting after it has been scheduled: its minutes, and the
 * one-off documents a coordinator asks this candidate for.
 *
 * Minutes follow the same rule as shortlist meetings — the secretary
 * writes them, the lead does when no secretary was named, and the
 * coordinator approves. The rule lives in one shape in two places
 * because the two meeting kinds are separate tables; if a third
 * appears, this is the pair to reconcile.
 */
class ExamMeeting
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Exam meetings on the programs this coordinator holds.
     *
     * @param array<int, string> $programIds
     * @return array<int, array<string, mixed>>
     */
    public function forPrograms(array $programIds): array
    {
        if ($programIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($programIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT m.meeting_id, m.scheduled_at, m.mode, m.location, m.virtual_link, m.status,
                    m.minutes, m.minutes_finalized_at, m.minutes_approved_at,
                    m.lead_user_id, m.secretary_user_id,
                    COALESCE(m.secretary_user_id, m.lead_user_id) AS minutes_author_id,
                    CONCAT(lu.first_name, ' ', lu.last_name) AS lead_name,
                    CONCAT(su2.first_name, ' ', su2.last_name) AS secretary_name,
                    s.stage_id, s.name AS stage_name,
                    tp.proposal_id, tp.title AS proposal_title,
                    st.student_id, st.student_number,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                    p.program_id, p.name AS program_name,
                    pl.average_score, pl.confirmed_at, pl.approved_at
             FROM meetings m
             JOIN exam_stages s ON s.stage_id = m.exam_stage_id
             JOIN thesis_proposals tp ON tp.proposal_id = m.proposal_id
             JOIN students st ON st.student_id = tp.student_id
             JOIN users u ON u.user_id = st.user_id
             JOIN student_thesis_registrations str ON str.student_id = st.student_id
             JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
             JOIN programs p ON p.program_id = ts.program_id
             LEFT JOIN users lu ON lu.user_id = m.lead_user_id
             LEFT JOIN users su2 ON su2.user_id = m.secretary_user_id
             LEFT JOIN rubric_panel_leaders pl ON pl.meeting_id = m.meeting_id
             WHERE m.status <> 'cancelled'
               AND p.program_id IN ($placeholders)
             ORDER BY m.scheduled_at DESC"
        );
        $stmt->execute($programIds);

        return $stmt->fetchAll();
    }

    public function find(string $meetingId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, COALESCE(m.secretary_user_id, m.lead_user_id) AS minutes_author_id,
                    CONCAT(lu.first_name, ' ', lu.last_name) AS lead_name,
                    CONCAT(su.first_name, ' ', su.last_name) AS secretary_name,
                    s.name AS stage_name,
                    tp.title AS proposal_title, tp.student_id,
                    st.student_number,
                    CONCAT(stu.first_name, ' ', stu.last_name) AS student_name
             FROM meetings m
             LEFT JOIN exam_stages s ON s.stage_id = m.exam_stage_id
             JOIN thesis_proposals tp ON tp.proposal_id = m.proposal_id
             JOIN students st ON st.student_id = tp.student_id
             JOIN users stu ON stu.user_id = st.user_id
             LEFT JOIN users lu ON lu.user_id = m.lead_user_id
             LEFT JOIN users su ON su.user_id = m.secretary_user_id
             WHERE m.meeting_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $meetingId]);

        return $stmt->fetch() ?: null;
    }

    public function minutesAuthorId(string $meetingId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(secretary_user_id, lead_user_id) FROM meetings WHERE meeting_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $meetingId]);
        $author = $stmt->fetchColumn();

        return $author ? (string) $author : null;
    }

    public function setRoles(string $meetingId, ?string $leadUserId, ?string $secretaryUserId): void
    {
        $this->db->prepare(
            "UPDATE meetings
             SET lead_user_id = COALESCE(:lead, lead_user_id, created_by),
                 secretary_user_id = :secretary
             WHERE meeting_id = :id"
        )->execute([
            'id'        => $meetingId,
            'lead'      => $leadUserId ?: null,
            'secretary' => $secretaryUserId ?: null,
        ]);
    }

    public function saveMinutes(string $meetingId, string $minutes, bool $finalize): void
    {
        $this->db->prepare(
            "UPDATE meetings
             SET minutes = :minutes,
                 minutes_finalized_at = CASE WHEN :finalize = 1
                    THEN COALESCE(minutes_finalized_at, NOW()) ELSE minutes_finalized_at END
             WHERE meeting_id = :id"
        )->execute(['id' => $meetingId, 'minutes' => $minutes, 'finalize' => $finalize ? 1 : 0]);
    }

    public function approveMinutes(string $meetingId, string $approvedBy): void
    {
        $meeting = $this->find($meetingId);

        if (!$meeting) {
            throw new RuntimeException('That meeting no longer exists.');
        }
        if ($meeting['minutes_finalized_at'] === null) {
            throw new RuntimeException('These minutes have not been finalised yet.');
        }
        if ($meeting['minutes_approved_at'] !== null) {
            return;
        }

        $this->db->prepare(
            "UPDATE meetings SET minutes_approved_at = NOW(), minutes_approved_by = :by WHERE meeting_id = :id"
        )->execute(['id' => $meetingId, 'by' => $approvedBy]);
    }

    /**
     * Everything this candidate must submit for this exam: the window's
     * standard set plus anything asked of them specifically, with
     * whether it has arrived.
     *
     * @return array<int, array<string, mixed>>
     */
    public function requiredDocumentsFor(string $meetingId): array
    {
        $stmt = $this->db->prepare(
            "SELECT dt.doc_type_id, dt.doc_type_name, src.note, src.is_extra,
                    (SELECT COUNT(*) FROM exam_documents ed
                      WHERE ed.proposal_id = m.proposal_id
                        AND ed.document_type_id = dt.doc_type_id
                        AND (ed.exam_schedule_id = m.exam_schedule_id OR m.exam_schedule_id IS NULL)) AS submitted
             FROM meetings m
             JOIN (
                 SELECT esd.exam_schedule_id AS scope_id, esd.document_type_id, NULL AS note, 0 AS is_extra, NULL AS meeting_id
                 FROM exam_schedule_documents esd
                 UNION ALL
                 SELECT NULL, emd.document_type_id, emd.note, 1, emd.meeting_id
                 FROM exam_meeting_extra_documents emd
             ) src ON (src.scope_id = m.exam_schedule_id OR src.meeting_id = m.meeting_id)
             JOIN document_types dt ON dt.doc_type_id = src.document_type_id
             WHERE m.meeting_id = :id
             GROUP BY dt.doc_type_id, dt.doc_type_name, src.note, src.is_extra
             ORDER BY src.is_extra, dt.doc_type_name"
        );
        $stmt->execute(['id' => $meetingId]);

        return array_map(
            static fn (array $r): array => $r + ['is_submitted' => (int) $r['submitted'] > 0],
            $stmt->fetchAll()
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function extraDocumentsFor(string $meetingId): array
    {
        $stmt = $this->db->prepare(
            "SELECT emd.extra_doc_id, emd.document_type_id, emd.note, emd.added_at, dt.doc_type_name
             FROM exam_meeting_extra_documents emd
             JOIN document_types dt ON dt.doc_type_id = emd.document_type_id
             WHERE emd.meeting_id = :id
             ORDER BY dt.doc_type_name"
        );
        $stmt->execute(['id' => $meetingId]);

        return $stmt->fetchAll();
    }

    public function addExtraDocument(string $meetingId, string $documentTypeId, ?string $note, string $addedBy): void
    {
        if ($documentTypeId === '') {
            throw new RuntimeException('Choose a document type.');
        }

        // Asking again for something the window already requires would
        // show the student the same row twice.
        $already = $this->db->prepare(
            "SELECT 1 FROM meetings m
             JOIN exam_schedule_documents esd ON esd.exam_schedule_id = m.exam_schedule_id
             WHERE m.meeting_id = :id AND esd.document_type_id = :doc LIMIT 1"
        );
        $already->execute(['id' => $meetingId, 'doc' => $documentTypeId]);
        if ($already->fetchColumn()) {
            throw new RuntimeException('This exam window already requires that document of everyone.');
        }

        $this->db->prepare(
            "INSERT INTO exam_meeting_extra_documents (extra_doc_id, meeting_id, document_type_id, note, added_by)
             VALUES (UUID(), :meeting_id, :doc, :note, :by)
             ON DUPLICATE KEY UPDATE note = VALUES(note), added_by = VALUES(added_by), added_at = NOW()"
        )->execute([
            'meeting_id' => $meetingId,
            'doc'        => $documentTypeId,
            'note'       => trim((string) $note) ?: null,
            'by'         => $addedBy,
        ]);
    }

    public function removeExtraDocument(string $extraDocId): void
    {
        $this->db->prepare("DELETE FROM exam_meeting_extra_documents WHERE extra_doc_id = :id")
            ->execute(['id' => $extraDocId]);
    }
}
