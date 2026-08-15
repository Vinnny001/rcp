<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class StudentController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    private function requireStudent(): ?string
    {
        if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'student') {
            return '/login';
        }
        return null;
    }

    public function dashboard(Request $request, Response $response): Response
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        return $this->view->render($response, 'students/dashboard.twig', [
            'first_name' => $_SESSION['first_name'] ?? '',
            'active_page' => 'overview',
        ]);
    }
}