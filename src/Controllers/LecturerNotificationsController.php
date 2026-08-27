<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Lecturer;
use App\Models\Meeting;
use App\Models\Notification;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

class LecturerNotificationsController
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

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $userId = $_SESSION['user_id'];
        $lecturerModel = new Lecturer($this->db);
        $lecturer = $lecturerModel->findByUserId($userId);

        $error = $_SESSION['flash_error'] ?? null;
        $success = $_SESSION['flash_success'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);

        return $this->twig->render($response, 'lecturers/notifications.twig', [
            'active_page'  => 'l-notifications',
            'first_name'   => $_SESSION['first_name'] ?? '',
            'inbox'        => (new Notification($this->db))->findForUser($userId),
            'meetings'     => (new Meeting($this->db))->findUpcomingForUser($userId),
            'supervisees'  => $lecturer ? $lecturerModel->findActiveSupervisions($lecturer['lecturer_id']) : [],
            'csrf_token'   => $this->csrfToken(),
            'error'        => $error,
            'success'      => $success,
        ]);
    }

    /**
     * mode=meeting notifies every other attendee of one of this
     * lecturer's own meetings; mode=student reminds one or more of
     * their own active supervisees. Both recipient lists are resolved
     * server-side from the lecturer's own meetings/supervisions — a
     * posted meeting_id or student_user_id that doesn't belong to them
     * is silently dropped rather than trusted.
     */
    public function send(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/lecturer/notifications')->withStatus(302);
        }

        $userId = $_SESSION['user_id'];
        $mode = (string) ($data['mode'] ?? '');
        $subject = trim((string) ($data['subject'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));

        if (!in_array($mode, ['meeting', 'student'], true) || $subject === '' || $message === '') {
            $_SESSION['flash_error'] = 'Please choose recipients and provide both a subject and a message.';
            return $response->withHeader('Location', '/lecturer/notifications')->withStatus(302);
        }

        $meetingModel = new Meeting($this->db);
        $recipientIds = [];
        $relatedEntityType = null;
        $relatedEntityId = null;

        if ($mode === 'meeting') {
            $meetingId = (string) ($data['meeting_id'] ?? '');
            if ($meetingId === '' || !$meetingModel->isAttendee($meetingId, $userId)) {
                $_SESSION['flash_error'] = 'Please choose one of your own meetings.';
                return $response->withHeader('Location', '/lecturer/notifications')->withStatus(302);
            }

            foreach ($meetingModel->findAttendees($meetingId) as $attendee) {
                if ($attendee['user_id'] !== $userId) {
                    $recipientIds[] = $attendee['user_id'];
                }
            }

            $relatedEntityType = 'meeting';
            $relatedEntityId = $meetingId;
        } else {
            $lecturerModel = new Lecturer($this->db);
            $lecturer = $lecturerModel->findByUserId($userId);
            $superviseeIds = $lecturer
                ? array_column($lecturerModel->findActiveSupervisions($lecturer['lecturer_id']), 'student_user_id')
                : [];

            $requested = (array) ($data['student_user_id'] ?? []);
            $recipientIds = array_values(array_intersect($requested, $superviseeIds));

            if (!$recipientIds) {
                $_SESSION['flash_error'] = 'Please choose at least one of your own supervisees.';
                return $response->withHeader('Location', '/lecturer/notifications')->withStatus(302);
            }

            $relatedEntityType = 'supervision_reminder';
        }

        $sent = (new Notification($this->db))->createForUsers($recipientIds, $subject, $message, $relatedEntityType, $relatedEntityId);

        $_SESSION['flash_success'] = 'Notification sent to ' . $sent . ' recipient' . ($sent === 1 ? '' : 's') . '.';

        return $response->withHeader('Location', '/lecturer/notifications')->withStatus(302);
    }

    public function markRead(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if ($this->verifyCsrf($data['csrf_token'] ?? '')) {
            (new Notification($this->db))->markRead($args['id'] ?? '', $_SESSION['user_id']);
        }

        return $response->withHeader('Location', '/lecturer/notifications')->withStatus(302);
    }
}
