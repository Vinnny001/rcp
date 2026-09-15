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

        $booking = $student
            ? (new \App\Models\ExamReadiness($this->db))->bookingPage($student['student_id'], $_SESSION['user_id'])
            : ['current_stage' => null, 'exam_windows' => [], 'booked' => null, 'calendar' => []];

        return $this->twig->render($response, 'students/exam.twig', $booking + [
            'active_page'    => 'exam',
            'first_name'     => $_SESSION['first_name'] ?? '',
            'student_number' => $student['student_number'] ?? null,
            'proposal'       => $proposal,
            'internal_exam'  => $this->findByType($examinations, 'internal'),
            'external_exam'  => $this->findByType($examinations, 'external'),
            'graduation'     => $student ? $gradModel->findByStudentId($student['student_id']) : null,
            'csrf_token'     => $this->csrfToken(),
            'error'          => $this->takeFlash('flash_error'),
            'success'        => $this->takeFlash('flash_success'),
        ]);
    }

    /**
     * The student booking one of the dates open for their stage — or
     * switching to it from another. The model re-checks that the date is
     * theirs to book rather than trusting the button.
     */
    public function book(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->bookingAction($request, $response, function (\App\Models\ExamReadiness $readiness, string $studentId, string $scheduleId): string {
            $readiness->book($studentId, $_SESSION['user_id'], $scheduleId);

            return 'Booked. Submit its documents on the Requirements page — your coordinator schedules your exam once fees and documents are settled.';
        });
    }

    public function cancelBooking(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->bookingAction($request, $response, function (\App\Models\ExamReadiness $readiness, string $studentId, string $scheduleId): string {
            $readiness->cancelBooking($studentId, $scheduleId);

            return 'Booking cancelled. You can book another date at any time.';
        });
    }

    private function bookingAction(ServerRequestInterface $request, ResponseInterface $response, callable $action): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/student/exam')->withStatus(302);
        }

        $student = $this->getStudentRecord($_SESSION['user_id']);
        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $response->withHeader('Location', '/student/exam')->withStatus(302);
        }

        try {
            $_SESSION['flash_success'] = $action(
                new \App\Models\ExamReadiness($this->db),
                $student['student_id'],
                (string) ($data['exam_schedule_id'] ?? '')
            );
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $response->withHeader('Location', '/student/exam')->withStatus(302);
    }

    private function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }

        return $_SESSION['csrf_token'];
    }

    private function verifyCsrf(string $token): bool
    {
        return !empty($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
    }

    private function takeFlash(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return $value;
    }
}
