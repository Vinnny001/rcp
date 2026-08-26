<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class ExaminationScore
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByMeeting(string $meetingId, string $examinerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ed.exam_document_id, ed.document_id, ed.proposal_id, d.file_name, d.file_path, dt.doc_type_name,
                    sc.score_id, sc.score_percentage, sc.remarks, sc.graded_at
             FROM meetings m
             JOIN exam_documents ed ON ed.proposal_id = m.proposal_id AND ed.exam_schedule_id = m.exam_schedule_id
             JOIN documents d ON d.document_id = ed.document_id
             JOIN document_types dt ON dt.doc_type_id = ed.document_type_id
             LEFT JOIN examination_scores sc
                    ON sc.exam_document_id = ed.exam_document_id AND sc.examiner_id = :examiner_id
             WHERE m.meeting_id = :meeting_id"
        );
        $stmt->execute(['meeting_id' => $meetingId, 'examiner_id' => $examinerId]);
        return $stmt->fetchAll();
    }

    /**
     * Records a score for a document, once. If this examiner has
     * already scored this document, the call is refused — no edits
     * after the fact, matching the "review/grade only once" rule.
     * Returns true if recorded, false if already scored.
     */
    public function submit(string $examDocumentId, string $proposalId, string $examinerId, float $score, ?string $remarks): bool
    {
        $existing = $this->db->prepare(
            "SELECT score_id FROM examination_scores WHERE exam_document_id = :edoc AND examiner_id = :examiner LIMIT 1"
        );
        $existing->execute(['edoc' => $examDocumentId, 'examiner' => $examinerId]);
        if ($existing->fetchColumn()) {
            return false;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO examination_scores (score_id, exam_document_id, proposal_id, examiner_id, score_percentage, remarks, graded_at)
             VALUES (:id, :edoc, :proposal_id, :examiner, :score, :remarks, NOW())"
        );
        $stmt->execute([
            'id'          => $this->generateUuid(),
            'edoc'        => $examDocumentId,
            'proposal_id' => $proposalId,
            'examiner'    => $examinerId,
            'score'       => $score,
            'remarks'     => $remarks,
        ]);
        return true;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}