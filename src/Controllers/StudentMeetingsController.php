<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Meeting;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

class StudentMeetingsController
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

    private function getStudentNumber(string $userId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT student_number FROM students WHERE user_id = :user_id LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row['student_number'] ?? null;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $userId = $_SESSION['user_id'];
        $meetingModel = new Meeting($this->db);

        return $this->twig->render($response, 'students/meetings.twig', [
            'active_page'    => 'meetings',
            'first_name'     => $_SESSION['first_name'] ?? '',
            'student_number' => $this->getStudentNumber($userId),
            'upcoming'       => $meetingModel->findUpcomingForUser($userId),
            'past'           => $meetingModel->findPastForUser($userId),
        ]);
    }
}