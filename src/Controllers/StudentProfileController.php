<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Student;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

class StudentProfileController
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

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $studentModel = new Student($this->db);
        $student = $studentModel->findByUserId($_SESSION['user_id']);

        return $this->twig->render($response, 'students/complete_profile.twig', [
            'first_name' => $_SESSION['first_name'] ?? '',
            'student'    => $student,
            'csrf_token' => $this->csrfToken(),
            'error'      => $_SESSION['flash_error'] ?? null,
        ]);
    }

    public function update(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/student/profile/complete')->withStatus(302);
        }

        $studentModel = new Student($this->db);
        $student = $studentModel->findByUserId($_SESSION['user_id']);

        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $response->withHeader('Location', '/student/profile/complete')->withStatus(302);
        }

        $studentNumber = trim((string) ($data['student_number'] ?? ''));
        $studentEmail = trim((string) ($data['student_email'] ?? ''));
        $erpRef = trim((string) ($data['erp_student_ref'] ?? '')) ?: null;

        $errors = [];
        if ($studentNumber === '') {
            $errors[] = 'Student number is required.';
        }
        if ($studentEmail === '' || !filter_var($studentEmail, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid student email is required.';
        }

        if ($errors) {
            $_SESSION['flash_error'] = implode(' ', $errors);
            return $response->withHeader('Location', '/student/profile/complete')->withStatus(302);
        }

        $studentModel->completeProfile($student['student_id'], $studentNumber, $studentEmail, $erpRef);

        $_SESSION['flash_success'] = 'Profile completed.';
        return $response->withHeader('Location', '/student/dashboard')->withStatus(302);
    }
}