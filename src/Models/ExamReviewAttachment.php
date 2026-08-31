<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Links a document an examiner uploaded during exam-document review to
 * the exam_document it explains — admin-facing only. Per explicit
 * instruction, the uploaded evidence file itself is visible to admin
 * alone; a lecturer never sees another examiner's attachment through
 * this model (their own uploads still show up on their own My
 * Documents page via Document::findByOwner(), which is a different,
 * self-scoped view).
 */
class ExamReviewAttachment
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(string $examDocumentId, string $meetingId, string $examinerId, string $documentId): string
    {
        $id = $this->generateUuid();
        $stmt = $this->db->prepare(
            "INSERT INTO exam_review_attachments (attachment_id, exam_document_id, meeting_id, examiner_id, document_id)
             VALUES (:id, :exam_document_id, :meeting_id, :examiner_id, :document_id)"
        );
        $stmt->execute([
            'id'               => $id,
            'exam_document_id' => $examDocumentId,
            'meeting_id'       => $meetingId,
            'examiner_id'      => $examinerId,
            'document_id'      => $documentId,
        ]);
        return $id;
    }

    /**
     * Every attachment on record, with enough context for admin to
     * place it: which student/proposal, which exam window, which
     * thesis schedule, which meeting, which document was under review
     * (separate from the examiner's own uploaded evidence file), and
     * who uploaded it.
     */
    public function findAll(): array
    {
        $stmt = $this->db->query(
            "SELECT era.attachment_id, era.created_at,
                    d.file_name, d.file_path,
                    rd.file_name AS reviewed_file_name, rd.file_path AS reviewed_file_path,
                    dt.doc_type_name,
                    es.exam_type, es.starts_at,
                    tp.proposal_id, tp.title AS proposal_title,
                    s.student_number,
                    CONCAT(su.first_name, ' ', su.last_name) AS student_name,
                    CONCAT(eu.first_name, ' ', eu.last_name) AS examiner_name,
                    p.name AS program_name,
                    ts.enrollment_start_date, ts.enrollment_end_date,
                    m.meeting_id, m.meeting_type, m.scheduled_at
             FROM exam_review_attachments era
             JOIN documents d ON d.document_id = era.document_id
             JOIN exam_documents ed ON ed.exam_document_id = era.exam_document_id
             JOIN documents rd ON rd.document_id = ed.document_id
             JOIN document_types dt ON dt.doc_type_id = ed.document_type_id
             JOIN exam_schedule es ON es.exam_schedule_id = ed.exam_schedule_id
             JOIN thesis_schedules ts ON ts.schedule_id = es.thesis_schedule_id
             JOIN programs p ON p.program_id = ts.program_id
             JOIN thesis_proposals tp ON tp.proposal_id = ed.proposal_id
             JOIN students s ON s.student_id = tp.student_id
             JOIN users su ON su.user_id = s.user_id
             JOIN users eu ON eu.user_id = era.examiner_id
             JOIN meetings m ON m.meeting_id = era.meeting_id
             ORDER BY era.created_at DESC"
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
