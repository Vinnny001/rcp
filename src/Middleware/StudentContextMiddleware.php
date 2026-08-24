<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\ThesisRegistration;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Views\Twig;
use PDO;

class StudentContextMiddleware
{
    private PDO $db;
    private Twig $twig;

    public function __construct(PDO $db, Twig $twig)
    {
        $this->db = $db;
        $this->twig = $twig;
    }

    public function __invoke(Request $request, Handler $handler)
    {
        if (($_SESSION['role'] ?? null) === 'student' && !empty($_SESSION['user_id'])) {
            $stmt = $this->db->prepare("SELECT student_id FROM students WHERE user_id = :user_id LIMIT 1");
            $stmt->execute(['user_id' => $_SESSION['user_id']]);
            $studentId = $stmt->fetchColumn();

            $hasRegistration = false;
            if ($studentId) {
                $regModel = new ThesisRegistration($this->db);
                $hasRegistration = (bool) $regModel->findActiveByStudentId($studentId);
            }

            $this->twig->getEnvironment()->addGlobal('has_thesis_registration', $hasRegistration);
            $this->twig->getEnvironment()->addGlobal('show_thesis_link', !$hasRegistration);
        }

        return $handler->handle($request);
    }
}