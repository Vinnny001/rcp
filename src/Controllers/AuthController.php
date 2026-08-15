<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Student;
use App\Models\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AuthController
{
    private User $userModel;
    private Student $studentModel;
    private Twig $view;

    public function __construct(User $userModel, Student $studentModel, Twig $view)
    {
        $this->userModel = $userModel;
        $this->studentModel = $studentModel;
        $this->view = $view;
    }

    public function showLoginForm(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'auth/login.twig');
    }

    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $identifier = trim($data['identifier'] ?? '');
        $password = $data['password'] ?? '';
        $selectedRole = $data['role'] ?? 'student';

        $user = $this->userModel->findByEmailOrStudentNumber($identifier);

        if (!$user || !$this->userModel->verifyPassword($password, $user['password_hash'])) {
            return $this->view->render($response, 'auth/login.twig', [
                'error' => 'Invalid credentials.',
                'old' => ['identifier' => $identifier],
            ]);
        }

        if ($user['role'] !== $selectedRole) {
            return $this->view->render($response, 'auth/login.twig', [
                'error' => 'This account is not registered as a ' . $selectedRole . '.',
                'old' => ['identifier' => $identifier],
            ]);
        }

        if (!$user['is_active']) {
            return $this->view->render($response, 'auth/login.twig', [
                'error' => 'This account has been deactivated.',
                'old' => ['identifier' => $identifier],
            ]);
        }

        $this->userModel->updateLastLogin($user['user_id']);

        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['first_name'] = $user['first_name'];
        $_SESSION['last_name'] = $user['last_name'];
        if ($user['role'] === 'student') {
    $_SESSION['student_number'] = $user['student_number'];
}
        $redirect = match ($user['role']) {
            'student'  => '/student/dashboard',
            'lecturer' => '/lecturer/dashboard',
            'admin'    => '/admin/dashboard',
            default    => '/login',
        };

        return $response->withHeader('Location', $redirect)->withStatus(302);
    }

    public function showRegisterForm(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'auth/register.twig');
    }

    public function register(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $errors = $this->validateRegistration($data);

        if (!empty($errors)) {
            return $this->view->render($response, 'auth/register.twig', ['errors' => $errors, 'old' => $data]);
        }

        if ($this->userModel->findByUsername($data['username'])) {
            return $this->view->render($response, 'auth/register.twig', ['errors' => ['Username already taken.'], 'old' => $data]);
        }

        if ($this->userModel->findByEmail($data['email'])) {
            return $this->view->render($response, 'auth/register.twig', ['errors' => ['Email already registered.'], 'old' => $data]);
        }

        if ($this->studentModel->findByStudentNumber($data['student_number'])) {
            return $this->view->render($response, 'auth/register.twig', ['errors' => ['Student number already registered.'], 'old' => $data]);
        }

        try {
            $this->userModel->createStudent(
                [
                    'username'   => $data['username'],
                    'email'      => $data['email'],
                    'password'   => $data['password'],
                    'first_name' => $data['first_name'],
                    'last_name'  => $data['last_name'],
                    'phone'      => $data['phone'] ?? null,
                ],
                [
                    'student_number'  => $data['student_number'],
                    'department'      => $data['department'],
                    'program'         => $data['program'],
                    'enrollment_year' => $data['enrollment_year'],
                ],
                $this->studentModel
            );
        } catch (\Throwable $e) {
            return $this->view->render($response, 'auth/register.twig', [
                'errors' => ['Registration failed: ' . $e->getMessage()],
                'old' => $data,
            ]);
        }

        return $response->withHeader('Location', '/login')->withStatus(302);
    }

    private function validateRegistration(array $data): array
    {
        $errors = [];
        if (empty($data['first_name']) || empty($data['last_name'])) $errors[] = 'First and last name are required.';
        if (empty($data['username'])) $errors[] = 'Username is required.';
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
        if (empty($data['password']) || strlen($data['password']) < 8) $errors[] = 'Password must be at least 8 characters.';
        if (($data['password'] ?? '') !== ($data['password_confirm'] ?? '')) $errors[] = 'Passwords do not match.';
        if (empty($data['student_number'])) $errors[] = 'Student number is required.';
        if (empty($data['department'])) $errors[] = 'Department is required.';
        if (empty($data['program'])) $errors[] = 'Program is required.';
        if (empty($data['enrollment_year']) || (int)$data['enrollment_year'] < 1990 || (int)$data['enrollment_year'] > 2100) $errors[] = 'A valid enrollment year is required.';
        if (empty($data['consent'])) $errors[] = 'You must consent to validation of your details.';
        return $errors;
    }

    public function logout(Request $request, Response $response): Response
    {
        session_destroy();
        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}