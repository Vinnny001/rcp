<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Lecturer;
use App\Models\Meeting;
use App\Models\SupervisionRequest;
use App\Models\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

class LecturerProfileController
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

    /**
     * Reasons this lecturer cannot turn availability off right now, in
     * plain language. Empty means they're free to switch it off.
     *
     * @return array<int, string>
     */
    private function unavailabilityBlockers(string $lecturerId, string $userId): array
    {
        $blockers = [];

        $activeCount = (new Lecturer($this->db))->countActiveSupervisions($lecturerId);
        if ($activeCount > 0) {
            $blockers[] = 'You currently supervise ' . $activeCount . ' student' . ($activeCount === 1 ? '' : 's') . ' — availability cannot be turned off while supervising.';
        }

        $pending = (new SupervisionRequest($this->db))->findPendingByLecturerId($lecturerId);
        if ($pending) {
            $blockers[] = 'You have ' . count($pending) . ' pending supervision request' . (count($pending) === 1 ? '' : 's') . ' — decline it before turning off availability.';
        }

        if ((new Meeting($this->db))->hasActiveMeetingForUser($userId)) {
            $blockers[] = 'You have a scheduled or in-progress meeting invite — availability cannot be turned off until it is resolved.';
        }

        return $blockers;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $userId = $_SESSION['user_id'];
        $user = (new User($this->db))->findById($userId);
        $lecturerModel = new Lecturer($this->db);
        $lecturer = $lecturerModel->findByUserId($userId);

        $blockers = $lecturer ? $this->unavailabilityBlockers($lecturer['lecturer_id'], $userId) : [];

        $error = $_SESSION['flash_error'] ?? null;
        $success = $_SESSION['flash_success'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);

        return $this->twig->render($response, 'lecturers/profile.twig', [
            'active_page'      => 'l-profile',
            'first_name'       => $_SESSION['first_name'] ?? '',
            'last_name'        => $_SESSION['last_name'] ?? '',
            'user'             => $user,
            'lecturer'         => $lecturer,
            'unavailability_blockers' => $blockers,
            'csrf_token'       => $this->csrfToken(),
            'error'            => $error,
            'success'          => $success,
        ]);
    }

    public function toggleAvailability(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/lecturer/profile')->withStatus(302);
        }

        $userId = $_SESSION['user_id'];
        $lecturerModel = new Lecturer($this->db);
        $lecturer = $lecturerModel->findByUserId($userId);

        if (!$lecturer) {
            $_SESSION['flash_error'] = 'Your lecturer profile could not be found.';
            return $response->withHeader('Location', '/lecturer/profile')->withStatus(302);
        }

        // Only turning availability OFF is restricted. Re-checked here
        // server-side regardless of what the form claimed, since the
        // form's disabled state is just a courtesy.
        if ($lecturer['is_available']) {
            $blockers = $this->unavailabilityBlockers($lecturer['lecturer_id'], $userId);
            if ($blockers) {
                $_SESSION['flash_error'] = implode(' ', $blockers);
                return $response->withHeader('Location', '/lecturer/profile')->withStatus(302);
            }
        }

        $lecturerModel->toggleAvailability($lecturer['lecturer_id']);
        $_SESSION['flash_success'] = $lecturer['is_available']
            ? 'You are now marked unavailable.'
            : 'You are now marked available.';

        return $response->withHeader('Location', '/lecturer/profile')->withStatus(302);
    }
}
