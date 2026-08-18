<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\StudentEnrollment;
use App\Models\StudentLeave;
use App\Models\ProgramSchedule;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

class StudentEnrollmentController
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

    private function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withHeader('Location', $path)->withStatus(302);
    }

    /**
     * Shows the "pick a schedule to re-enroll" page. Only reachable when
     * the student has no active enrollment — students who already have
     * one are redirected to the dashboard instead.
     */
    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $this->redirect($response, $redirect);
        }

        $userId = $_SESSION['user_id'];
        $student = $this->getStudentRecord($userId);

        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/login');
        }

        $enrollmentModel = new StudentEnrollment($this->db);
        $scheduleModel = new ProgramSchedule($this->db);

        if ($enrollmentModel->findActive($student['student_id'])) {
            return $this->redirect($response, '/student/dashboard');
        }

        return $this->twig->render($response, 'students/enroll.twig', [
            'active_page'         => 'overview',
            'first_name'          => $_SESSION['first_name'] ?? '',
            'student_number'      => $student['student_number'] ?? null,
            'schedules'           => $scheduleModel->findAllOpen(),
            'fee_waived'          => $enrollmentModel->registrationFeeWaived($student['student_id']),
            'history'             => $enrollmentModel->findHistory($student['student_id']),
            'csrf_token'          => $this->csrfToken(),
            'error'               => $_SESSION['flash_error'] ?? null,
        ]);
    }

    public function enroll(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $this->redirect($response, $redirect);
        }

        $data = $request->getParsedBody();

        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/student/enroll');
        }

        $student = $this->getStudentRecord($_SESSION['user_id']);
        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/student/enroll');
        }

        $scheduleId = $data['schedule_id'] ?? '';
        $scheduleModel = new ProgramSchedule($this->db);
        $enrollmentModel = new StudentEnrollment($this->db);

        if (!$scheduleId || !$scheduleModel->findById($scheduleId)) {
            $_SESSION['flash_error'] = 'Please select a valid program schedule.';
            return $this->redirect($response, '/student/enroll');
        }

        if ($enrollmentModel->findActive($student['student_id'])) {
            $_SESSION['flash_error'] = 'You already have an active enrollment.';
            return $this->redirect($response, '/student/dashboard');
        }

        $enrollmentModel->create($student['student_id'], $scheduleId);

        $_SESSION['flash_success'] = 'You have been enrolled in the selected program.';
        return $this->redirect($response, '/student/dashboard');
    }

    public function requestLeave(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $this->redirect($response, $redirect);
        }

        $data = $request->getParsedBody();

        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/student/dashboard');
        }

        $student = $this->getStudentRecord($_SESSION['user_id']);
        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/student/dashboard');
        }

        $enrollmentModel = new StudentEnrollment($this->db);
        $active = $enrollmentModel->findActive($student['student_id']);

        if (!$active) {
            $_SESSION['flash_error'] = 'You have no active enrollment to take leave from.';
            return $this->redirect($response, '/student/dashboard');
        }

        $leaveModel = new StudentLeave($this->db);

        if ($leaveModel->findPendingByStudentId($student['student_id'])) {
            $_SESSION['flash_error'] = 'You already have a pending leave request.';
            return $this->redirect($response, '/student/dashboard');
        }

        $reason = trim((string) ($data['reason'] ?? ''));
        $startDate = trim((string) ($data['start_date'] ?? '')) ?: null;
        $endDate = trim((string) ($data['end_date'] ?? '')) ?: null;

        if ($reason === '') {
            $_SESSION['flash_error'] = 'Please provide a reason for your leave request.';
            return $this->redirect($response, '/student/dashboard');
        }

        $leaveModel->create($student['student_id'], $active['enrollment_id'], $reason, $startDate, $endDate);

        $_SESSION['flash_success'] = 'Your leave request has been submitted for review.';
        return $this->redirect($response, '/student/dashboard');
    }
}