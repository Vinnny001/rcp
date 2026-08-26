<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\DocumentReviewScore;
use App\Models\Examination;
use App\Models\ExaminationScore;
use App\Models\GradingPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

/**
 * A student's results in one place: examination outcomes and document
 * review outcomes.
 *
 * Everything on this page is banded. A student is told they passed, or
 * that a document needs resubmitting — never the number behind it, and
 * never which examiner gave what. Both the raw percentages and the
 * per-examiner breakdown stay in the lecturer and admin areas.
 */
class StudentOutcomesController
{
    private PDO $db;
    private Twig $twig;

    public function __construct(PDO $db, Twig $twig)
    {
        $this->db = $db;
        $this->twig = $twig;
    }

    private function requireStudent(): ?string
    {
        if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'student') {
            return '/login';
        }
        return null;
    }

    private function getStudentRecord(string $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT student_id, student_number FROM students WHERE user_id = :user_id LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function proposalsFor(string $studentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT proposal_id, title, status
             FROM thesis_proposals
             WHERE student_id = :student_id
             ORDER BY submission_date DESC, created_at DESC"
        );
        $stmt->execute(['student_id' => $studentId]);
        return $stmt->fetchAll();
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $userId = $_SESSION['user_id'];
        $student = $this->getStudentRecord($userId);

        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $examModel = new Examination($this->db);
        $reviewModel = new DocumentReviewScore($this->db);
        $examScoreModel = new ExaminationScore($this->db);

        // A student can have more than one proposal over time, so exam
        // outcomes are grouped under the proposal they belong to rather
        // than flattened into one list.
        $examOutcomes = [];
        foreach ($this->proposalsFor($student['student_id']) as $proposal) {
            $examinations = $examModel->findStudentSafeByProposalId($proposal['proposal_id']);

            if ($examinations) {
                $examOutcomes[] = [
                    'proposal_title' => $proposal['title'],
                    'examinations'   => $examinations,
                ];
            }
        }

        // Two sources feed the same "document review" outcome list:
        // documents scored via a formal exam window (examination_scores,
        // keyed through exam_documents) and documents scored inside a
        // general meeting (document_review_scores, keyed directly on the
        // document). Both are banded and shaped identically, so they're
        // merged into one list rather than shown as separate sections —
        // a student doesn't care which table produced the verdict.
        $documentOutcomes = array_merge(
            $examScoreModel->findOutcomesForStudent($userId),
            $reviewModel->findOutcomesForStudent($userId)
        );
        usort($documentOutcomes, fn($a, $b) => strcmp((string) $b['last_reviewed_at'], (string) $a['last_reviewed_at']));

        return $this->twig->render($response, 'students/outcomes.twig', [
            'active_page'      => 'outcomes',
            'first_name'       => $_SESSION['first_name'] ?? '',
            'student_number'   => $student['student_number'] ?? null,
            'exam_outcomes'    => $examOutcomes,
            'document_outcomes' => $documentOutcomes,
            'exam_scale'       => GradingPolicy::examScale(),
            'document_scale'   => GradingPolicy::documentScale(),
        ]);
    }
}
