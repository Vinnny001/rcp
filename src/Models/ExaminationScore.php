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
     * The examination outcome a student sees for each formal exam
     * window their proposal was scored against: the banded verdict
     * (fail/resubmit/pass/distinction — GradingPolicy::examOutcome, not
     * the document scale) from the average of every examiner's score on
     * one document type within that window, plus their remarks. Grouped
     * per (proposal, exam_schedule, document_type) — an exam window can
     * carry more than one document type (a proposal and a report, say),
     * each examined and banded on its own, rather than blended into a
     * single verdict for the window.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findExamOutcomesForStudent(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ed.proposal_id, ed.exam_schedule_id, ed.document_type_id, dt.doc_type_name,
                    es.exam_type, es.starts_at,
                    AVG(sc.score_percentage)      AS average_score,
                    COUNT(DISTINCT sc.examiner_id) AS reviewer_count,
                    MAX(sc.graded_at)              AS last_graded_at
             FROM exam_documents ed
             JOIN exam_schedule es ON es.exam_schedule_id = ed.exam_schedule_id
             JOIN document_types dt ON dt.doc_type_id = ed.document_type_id
             JOIN thesis_proposals p ON p.proposal_id = ed.proposal_id
             JOIN students s ON s.student_id = p.student_id
             JOIN examination_scores sc ON sc.exam_document_id = ed.exam_document_id
             WHERE s.user_id = :user_id
               AND sc.score_percentage IS NOT NULL
             GROUP BY ed.proposal_id, ed.exam_schedule_id, ed.document_type_id
             ORDER BY MAX(sc.graded_at) DESC"
        );
        $stmt->execute(['user_id' => $userId]);

        $outcomes = [];
        foreach ($stmt->fetchAll() as $row) {
            $band = GradingPolicy::examOutcome((float) $row['average_score']);

            $outcomes[] = [
                'proposal_id'      => $row['proposal_id'],
                'exam_schedule_id' => $row['exam_schedule_id'],
                'doc_type_name'    => $row['doc_type_name'],
                'exam_type'        => $row['exam_type'],
                'exam_date'        => $row['starts_at'],
                'graded_at'        => $row['last_graded_at'],
                'reviewer_count'   => (int) $row['reviewer_count'],
                'outcome'          => $band['outcome'],
                'outcome_label'    => $band['label'],
                'stamp_class'      => GradingPolicy::stampClass($band['outcome']),
                'comments'         => $this->examRemarksFor($row['proposal_id'], $row['exam_schedule_id'], $row['document_type_id']),
            ];
        }

        return $outcomes;
    }

    /**
     * Examiner remarks for every document of one type scored within one
     * exam window, with the examiner's name and score stripped — same
     * rule as DocumentReviewScore::commentsForStudent.
     *
     * @return array<int, string>
     */
    private function examRemarksFor(string $proposalId, string $examScheduleId, string $documentTypeId): array
    {
        $stmt = $this->db->prepare(
            "SELECT sc.remarks
             FROM examination_scores sc
             JOIN exam_documents ed ON ed.exam_document_id = sc.exam_document_id
             WHERE ed.proposal_id = :proposal_id AND ed.exam_schedule_id = :exam_schedule_id
               AND ed.document_type_id = :document_type_id
               AND sc.remarks IS NOT NULL AND sc.remarks != ''
             ORDER BY sc.graded_at ASC"
        );
        $stmt->execute([
            'proposal_id'      => $proposalId,
            'exam_schedule_id' => $examScheduleId,
            'document_type_id' => $documentTypeId,
        ]);
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