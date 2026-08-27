<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;

class DashboardController
{
    private Twig $view;

    public function __construct(Twig $view)
    {
        $this->view = $view;
    }

    public function show(Request $request, Response $response): Response
    {

        if (empty($_SESSION['user_id'])) {
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        return $this->view->render($response, 'dashboard.twig', [
            'first_name' => $_SESSION['first_name'] ?? '',
            'role' => $_SESSION['role'] ?? '',
        ]);
    }
}
