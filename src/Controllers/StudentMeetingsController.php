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

    private function getStudentRecord(string $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT student_id, student_number FROM students WHERE user_id = :user_id LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Strips location, virtual_link, and attendee names from any meeting
     * the student is not an invited attendee of. attendee_count stays —
     * the student can see how many people are involved, just not who,
     * and not where/how to join.
     */
    private function stripUninvitedDetails(array $meetings): array
    {
        return array_map(function (array $m) {
            if (empty($m['is_invited'])) {
                $m['location'] = null;
                $m['virtual_link'] = null;
                $m['other_attendees'] = null;
            }
            return $m;
        }, $meetings);
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $userId = $_SESSION['user_id'];
        $student = $this->getStudentRecord($userId);
        $meetingModel = new Meeting($this->db);

        $upcoming = $student ? $meetingModel->findUpcomingForStudent($student['student_id'], $userId) : [];
        $past = $student ? $meetingModel->findPastForStudent($student['student_id'], $userId) : [];

        return $this->twig->render($response, 'students/meetings.twig', [
            'active_page'    => 'meetings',
            'first_name'     => $_SESSION['first_name'] ?? '',
            'student_number' => $student['student_number'] ?? null,
            'upcoming'       => $this->stripUninvitedDetails($upcoming),
            'past'           => $this->stripUninvitedDetails($past),
        ]);
    }
}