<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Scores and comments on documents reviewed inside a meeting that is
 * not tied to a formal exam window.
 *
 * examination_scores covers the other case — a document submitted
 * against an exam_schedule, scored per exam_document. Documents whose
 * exam schedule isn't set yet have no exam_document row to hang a score
 * on, so they're scored here, keyed on the document itself.
 */
class DocumentReviewScore
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Records one reviewer's verdict on one document, once. A second
     * attempt by the same reviewer is refused rather than overwriting —
     * same rule the exam-document scores follow, and the table's
     * uniq_document_examiner key enforces it at the database too.
     */
    public function submit(string $documentId, string $examinerId, float $score, ?string $comment): bool
    {
        $existing = $this->db->prepare(
            "SELECT review_score_id FROM document_review_scores
             WHERE document_id = :document_id AND examiner_id = :examiner_id LIMIT 1"
        );
        $existing->execute(['document_id' => $documentId, 'examiner_id' => $examinerId]);

        if ($existing->fetchColumn()) {
            return false;
        }

        $stmt = $this->db->prepare(
            "INSERT INTO document_review_scores
                (review_score_id, document_id, examiner_id, score_percentage, comment, reviewed_at)
             VALUES (:id, :document_id, :examiner_id, :score, :comment, NOW())"
        );
        $stmt->execute([
            'id'          => $this->generateUuid(),
            'document_id' => $documentId,
            'examiner_id' => $examinerId,
            'score'       => $score,
            'comment'     => $comment,
        ]);

        return true;
    }

    /**
     * This reviewer's own submission for a document, if they've made
     * one — used to lock the form once they have.
     */
    public function findMine(string $documentId, string $examinerId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM document_review_scores
             WHERE document_id = :document_id AND examiner_id = :examiner_id LIMIT 1"
        );
        $stmt->execute(['document_id' => $documentId, 'examiner_id' => $examinerId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Every reviewer's verdict on a document, with names. Staff-facing
     * only — this carries raw scores.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findByDocument(string $documentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT drs.*, CONCAT(u.first_name, ' ', u.last_name) AS examiner_name
             FROM document_review_scores drs
             JOIN users u ON u.user_id = drs.examiner_id
             WHERE drs.document_id = :document_id
             ORDER BY drs.reviewed_at ASC"
        );
        $stmt->execute(['document_id' => $documentId]);
        return $stmt->fetchAll();
    }

    /**
     * The outcome a student sees for each of their reviewed documents:
     * the banded verdict from the average score, plus the reviewers'
     * comments. The average itself never leaves this method — callers
     * get the band, the reviewer count, and the comments only.
     *
     * @return array<int, array<string, mixed>>
     */
    public function findOutcomesForStudent(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT d.document_id, d.file_name, d.uploaded_at, d.document_status,
                    dt.doc_type_name,
                    AVG(drs.score_percentage) AS average_score,
                    COUNT(drs.review_score_id) AS reviewer_count,
                    MAX(drs.reviewed_at)      AS last_reviewed_at
             FROM documents d
             JOIN document_types dt ON dt.doc_type_id = d.document_type_id
             JOIN document_review_scores drs ON drs.document_id = d.document_id
             WHERE d.user_id = :user_id
               AND drs.score_percentage IS NOT NULL
             GROUP BY d.document_id
             ORDER BY MAX(drs.reviewed_at) DESC"
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
                'comments'         => $this->commentsForStudent($row['document_id']),
            ];
        }

        return $outcomes;
    }

    /**
     * Reviewer comments with the reviewer's name and score stripped —
     * a student reads the feedback without learning who said what or
     * what they scored it.
     *
     * @return array<int, string>
     */
    private function commentsForStudent(string $documentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT comment FROM document_review_scores
             WHERE document_id = :document_id AND comment IS NOT NULL AND comment != ''
             ORDER BY reviewed_at ASC"
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
