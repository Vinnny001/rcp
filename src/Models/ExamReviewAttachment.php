<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Links a document an examiner uploaded during exam-document review to
 * the exam_document it explains — admin-facing context, not shown to
 * the student.
 */
class ExamReviewAttachment
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(string $examDocumentId, string $examinerId, string $documentId): string
    {
        $id = $this->generateUuid();
        $stmt = $this->db->prepare(
            "INSERT INTO exam_review_attachments (attachment_id, exam_document_id, examiner_id, document_id)
             VALUES (:id, :exam_document_id, :examiner_id, :document_id)"
        );
        $stmt->execute([
            'id'               => $id,
            'exam_document_id' => $examDocumentId,
            'examiner_id'      => $examinerId,
            'document_id'      => $documentId,
        ]);
        return $id;
    }

    /**
     * Every attachment on record, with enough context for admin to
     * place it: which student/proposal, which exam window, which
     * document type was under review, and who uploaded it.
     */
    public function findAll(): array
    {
        $stmt = $this->db->query(
            "SELECT era.attachment_id, era.created_at,
                    d.file_name, d.file_path,
                    dt.doc_type_name,
                    es.exam_type, es.starts_at,
                    tp.proposal_id, tp.title AS proposal_title,
                    s.student_number,
                    CONCAT(su.first_name, ' ', su.last_name) AS student_name,
                    CONCAT(eu.first_name, ' ', eu.last_name) AS examiner_name
             FROM exam_review_attachments era
             JOIN documents d ON d.document_id = era.document_id
             JOIN exam_documents ed ON ed.exam_document_id = era.exam_document_id
             JOIN document_types dt ON dt.doc_type_id = ed.document_type_id
             JOIN exam_schedule es ON es.exam_schedule_id = ed.exam_schedule_id
             JOIN thesis_proposals tp ON tp.proposal_id = ed.proposal_id
             JOIN students s ON s.student_id = tp.student_id
             JOIN users su ON su.user_id = s.user_id
             JOIN users eu ON eu.user_id = era.examiner_id
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
