<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Notification;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

class StudentNotificationsController
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

        return $this->twig->render($response, 'students/notifications.twig', [
            'active_page' => 'notifications',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'inbox'       => (new Notification($this->db))->findForUser($_SESSION['user_id'], 'student'),
            'csrf_token'  => $this->csrfToken(),
        ]);
    }

    public function markRead(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if ($this->verifyCsrf($data['csrf_token'] ?? '')) {
            (new Notification($this->db))->markRead($args['id'] ?? '', $_SESSION['user_id']);
        }

        return $response->withHeader('Location', '/student/notifications')->withStatus(302);
    }
}
