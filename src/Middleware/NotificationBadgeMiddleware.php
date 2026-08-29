<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\Chat;
use App\Models\Lecturer;
use App\Models\Notification;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Views\Twig;
use PDO;

/**
 * Exposes the signed-in user's unread-notification and unread-chat
 * counts, plus whether the chat sidebar tab should show at all, as
 * Twig globals — so the sidebar doesn't need every controller to fetch
 * and pass them individually. Same pattern as StudentContextMiddleware's
 * profile_complete/has_thesis_registration globals; bundled into one
 * middleware to keep this to a single extra pass per request.
 */
class NotificationBadgeMiddleware
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
        $role = $_SESSION['role'] ?? null;

        if (in_array($role, ['student', 'lecturer'], true) && !empty($_SESSION['user_id'])) {
            $userId = $_SESSION['user_id'];

            $count = (new Notification($this->db))->countUnreadForUser($userId, $role);
            $this->twig->getEnvironment()->addGlobal('unread_notifications_count', $count);

            $chatModel = new Chat($this->db);
            $lecturerModel = new Lecturer($this->db);

            // The chat tab shows if there's a live relationship to chat
            // with, OR prior chat history to look back on (read-only in
            // that case — enforced by the chat controllers themselves).
            $hasLiveRelationship = false;
            if ($role === 'student') {
                $stmt = $this->db->prepare("SELECT student_id FROM students WHERE user_id = :user_id LIMIT 1");
                $stmt->execute(['user_id' => $userId]);
                $studentId = $stmt->fetchColumn();
                $hasLiveRelationship = $studentId && $lecturerModel->findActiveSupervisorsForStudent($studentId);
            } else {
                $lecturer = $lecturerModel->findByUserId($userId);
                $hasLiveRelationship = $lecturer && $lecturerModel->findActiveSupervisions($lecturer['lecturer_id']);
            }

            $showChat = (bool) $hasLiveRelationship || $chatModel->hasAnyThreadForUser($userId, $role);
            $this->twig->getEnvironment()->addGlobal('show_chat', $showChat);

            if ($showChat) {
                $this->twig->getEnvironment()->addGlobal('unread_chats_count', $chatModel->countUnreadForUser($userId, $role));
            }
        }

        return $handler->handle($request);
    }
}
