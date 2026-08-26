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

    /**
     * The outcome a student sees for each of their exam-window
     * documents: the banded verdict from the examiners' average score,
     * plus their remarks. Mirrors DocumentReviewScore::findOutcomesForStudent
     * — same shape, same banding — because from a student's chair these
     * are the same kind of decision, just sourced from documents that
     * happened to be submitted against a formal exam_schedule rather
     * than attached to a general meeting.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findOutcomesForStudent(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT d.document_id, d.file_name, d.uploaded_at,
                    dt.doc_type_name,
                    AVG(sc.score_percentage) AS average_score,
                    COUNT(sc.score_id)       AS reviewer_count,
                    MAX(sc.graded_at)        AS last_reviewed_at
             FROM documents d
             JOIN exam_documents ed ON ed.document_id = d.document_id
             JOIN document_types dt ON dt.doc_type_id = d.document_type_id
             JOIN examination_scores sc ON sc.exam_document_id = ed.exam_document_id
             WHERE d.user_id = :user_id
               AND sc.score_percentage IS NOT NULL
             GROUP BY d.document_id
             ORDER BY MAX(sc.graded_at) DESC"
        );
        $stmt->execute(['user_id' => $userId]);

        $outcomes = [];
        foreach ($stmt->fetchAll() as $row) {
            $band = GradingPolicy::documentOutcome((float) $row['average_score']);

            $outcomes[] = [
                'document_id'      => $row['document_id'],
                'file_name'        => $row['file_name'],
                'doc_type_name'    => $row['doc_type_name'],
                'uploaded_at'      => $row['uploaded_at'],
                'last_reviewed_at' => $row['last_reviewed_at'],
                'reviewer_count'   => (int) $row['reviewer_count'],
                'outcome'          => $band['outcome'],
                'outcome_label'    => $band['label'],
                'stamp_class'      => GradingPolicy::stampClass($band['outcome']),
                'comments'         => $this->remarksForStudent($row['document_id']),
            ];
        }

        return $outcomes;
    }

    /**
     * Examiner remarks with the examiner's name and score stripped —
     * same rule as DocumentReviewScore::commentsForStudent.
     *
     * @return array<int, string>
     */
    private function remarksForStudent(string $documentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT sc.remarks
             FROM examination_scores sc
             JOIN exam_documents ed ON ed.exam_document_id = sc.exam_document_id
             WHERE ed.document_id = :document_id AND sc.remarks IS NOT NULL AND sc.remarks != ''
             ORDER BY sc.graded_at ASC"
        );
        $stmt->execute(['document_id' => $documentId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}