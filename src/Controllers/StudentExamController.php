<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Examination;
use App\Models\Graduation;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

class StudentExamController
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

    private function getLatestProposal(string $studentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT proposal_id, title, status
             FROM thesis_proposals
             WHERE student_id = :student_id
             ORDER BY submission_date DESC, created_at DESC
             LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    private function findByType(array $examinations, string $type): ?array
    {
        foreach ($examinations as $exam) {
            if ($exam['exam_type'] === $type) {
                return $exam;
            }
        }
        return null;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $student = $this->getStudentRecord($_SESSION['user_id']);
        $proposal = $student ? $this->getLatestProposal($student['student_id']) : null;

        $examModel = new Examination($this->db);
        $gradModel = new Graduation($this->db);

        // findStudentSafeByProposalId, not findByProposalId — the latter
        // carries overall_grade, grade_letter and every examiner's raw
        // score, none of which a student may see.
        $examinations = $proposal
            ? $examModel->findStudentSafeByProposalId($proposal['proposal_id'])
            : [];

        return $this->twig->render($response, 'students/exam.twig', [
            'active_page'    => 'exam',
            'first_name'     => $_SESSION['first_name'] ?? '',
            'student_number' => $student['student_number'] ?? null,
            'proposal'       => $proposal,
            'internal_exam'  => $this->findByType($examinations, 'internal'),
            'external_exam'  => $this->findByType($examinations, 'external'),
            'graduation'     => $student ? $gradModel->findByStudentId($student['student_id']) : null,
        ]);
    }
}