<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Lecturer;
use App\Models\Meeting;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

class LecturerOverviewController
{
    private PDO $db;
    private Twig $twig;

    public function __construct(PDO $db, Twig $twig)
    {
        $this->db = $db;
        $this->twig = $twig;
    }

    private function requireLecturer(): ?string
    {
        if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'lecturer') {
            return '/login';
        }
        return null;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $userId = $_SESSION['user_id'];
        $lecturerModel = new Lecturer($this->db);
        $meetingModel = new Meeting($this->db);

        $lecturer = $lecturerModel->findByUserId($userId);

        if (!$lecturer) {
            // Account exists but has no lecturer record — misconfigured user.
            $_SESSION['flash_error'] = 'Your lecturer profile could not be found. Contact the registrar.';
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        $activeSupervisions = $lecturerModel->countActiveSupervisions($lecturer['lecturer_id']);
        $pendingReviews = $lecturerModel->findPendingProposalReviews($lecturer['lecturer_id']);
        $upcomingMeetingsCount = $meetingModel->countUpcomingWithinDaysForUser($userId, 7);

        return $this->twig->render($response, 'lecturers/overview.twig', [
            'active_page'          => 'l-overview',
            'first_name'           => $_SESSION['first_name'] ?? '',
            'staff_number'         => $lecturer['staff_number'] ?? null,
            'active_supervisions'  => $activeSupervisions,
            'max_supervision_load' => (int) $lecturer['max_supervision_load'],
            'pending_reviews'      => $pendingReviews,
            'upcoming_meetings'    => $upcomingMeetingsCount,
        ]);
    }
}