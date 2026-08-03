<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\User;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class AuthController
{
    private User $userModel;
    private Twig $view;

    public function __construct(User $userModel, Twig $view)
    {
        $this->userModel = $userModel;
        $this->view = $view;
    }

    public function showLoginForm(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'auth/login.twig');
    }

    public function login(Request $request, Response $response): Response
    {
        $data = $request->getParsedBody();
        $username = trim($data['username'] ?? '');
        $password = $data['password'] ?? '';

        $user = $this->userModel->findByUsername($username);

        if (!$user || !$this->userModel->verifyPassword($password, $user['password_hash'])) {
            return $this->view->render($response->withStatus(401), 'auth/login.twig', [
                'error' => 'Invalid username or password.',
            ]);
        }

        if (!$user['is_active']) {
            return $this->view->render($response, 'auth/login.twig', [
                'error' => 'This account has been deactivated.',
            ]);
        }

        $this->userModel->updateLastLogin($user['user_id']);

        // Start session and store user info
        session_start();
        $_SESSION['user_id'] = $user['user_id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['username'] = $user['username'];

        return $response
            ->withHeader('Location', '/dashboard')
            ->withStatus(302);
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
            return $this->view->render($response, 'auth/register.twig', [
                'errors' => $errors,
                'old' => $data,
            ]);
        }

        if ($this->userModel->findByUsername($data['username'])) {
            return $this->view->render($response, 'auth/register.twig', [
                'errors' => ['Username already taken.'],
                'old' => $data,
            ]);
        }

        if ($this->userModel->findByEmail($data['email'])) {
            return $this->view->render($response, 'auth/register.twig', [
                'errors' => ['Email already registered.'],
                'old' => $data,
            ]);
        }

        $this->userModel->create([
            'username'   => $data['username'],
            'email'      => $data['email'],
            'password'   => $data['password'],
            'role'       => $data['role'],
            'first_name' => $data['first_name'],
            'last_name'  => $data['last_name'],
            'phone'      => $data['phone'] ?? null,
        ]);

        return $response
            ->withHeader('Location', '/login')
            ->withStatus(302);
    }

    private function validateRegistration(array $data): array
    {
        $errors = [];

        if (empty($data['username'])) {
            $errors[] = 'Username is required.';
        }
        if (empty($data['email']) || !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'A valid email is required.';
        }
        if (empty($data['password']) || strlen($data['password']) < 8) {
            $errors[] = 'Password must be at least 8 characters.';
        }
        if (empty($data['role']) || !in_array($data['role'], ['admin', 'lecturer', 'student'])) {
            $errors[] = 'A valid role is required.';
        }
        if (empty($data['first_name']) || empty($data['last_name'])) {
            $errors[] = 'First and last name are required.';
        }

        return $errors;
    }

    public function logout(Request $request, Response $response): Response
    {
        session_start();
        session_destroy();
        return $response->withHeader('Location', '/login')->withStatus(302);
    }
}