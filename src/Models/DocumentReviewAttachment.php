<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * The general-meeting equivalent of ExamReviewAttachment — an examiner's
 * uploaded evidence file explaining their score on a document reviewed
 * outside a formal exam window. Admin-facing only, same as the exam-side
 * table: document_id here is the examiner's own uploaded evidence file,
 * not the student document under review. Unlike exam_documents (which
 * pins a document unambiguously to one exam window), a general meeting
 * can have several documents attached for review at once, so "the
 * document(s) under review" is reconstructed via meeting_id ->
 * meeting_documents rather than a single direct column.
 */
class DocumentReviewAttachment
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(string $meetingId, string $examinerId, string $documentId): string
    {
        $id = $this->generateUuid();
        $stmt = $this->db->prepare(
            "INSERT INTO document_review_attachments (attachment_id, meeting_id, examiner_id, document_id)
             VALUES (:id, :meeting_id, :examiner_id, :document_id)"
        );
        $stmt->execute([
            'id'          => $id,
            'meeting_id'  => $meetingId,
            'examiner_id' => $examinerId,
            'document_id' => $documentId,
        ]);
        return $id;
    }

    /**
     * Every attachment on record, with the same admin-facing context as
     * ExamReviewAttachment::findAll(): who uploaded it, which meeting
     * and student/proposal/thesis schedule it was for, and the
     * document(s) that were under review in that meeting (comma-joined,
     * since a general meeting may carry more than one).
     */
    public function findAll(): array
    {
        $stmt = $this->db->query(
            "SELECT dra.attachment_id, dra.created_at,
                    d.file_name, d.file_path,
                    CONCAT(eu.first_name, ' ', eu.last_name) AS examiner_name,
                    m.meeting_id, m.meeting_type, m.scheduled_at,
                    tp.proposal_id, tp.title AS proposal_title,
                    s.student_number,
                    CONCAT(su.first_name, ' ', su.last_name) AS student_name,
                    p.name AS program_name,
                    ts.enrollment_start_date, ts.enrollment_end_date,
                    (SELECT GROUP_CONCAT(rd.file_name SEPARATOR ', ')
                       FROM meeting_documents md
                       JOIN documents rd ON rd.document_id = md.document_id
                      WHERE md.meeting_id = dra.meeting_id) AS reviewed_file_names
             FROM document_review_attachments dra
             JOIN documents d ON d.document_id = dra.document_id
             JOIN users eu ON eu.user_id = dra.examiner_id
             JOIN meetings m ON m.meeting_id = dra.meeting_id
             JOIN thesis_proposals tp ON tp.proposal_id = m.proposal_id
             JOIN students s ON s.student_id = tp.student_id
             JOIN users su ON su.user_id = s.user_id
             LEFT JOIN student_thesis_registrations str ON str.student_id = s.student_id AND str.status = 'active'
             LEFT JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
             LEFT JOIN programs p ON p.program_id = ts.program_id
             ORDER BY dra.created_at DESC"
        );
        return $stmt->fetchAll();
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
