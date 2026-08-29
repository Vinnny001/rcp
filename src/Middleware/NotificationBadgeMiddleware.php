<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Models\Notification;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Server\RequestHandlerInterface as Handler;
use Slim\Views\Twig;
use PDO;

/**
 * Exposes the signed-in user's unread-notification count as a Twig
 * global, so the sidebar badge doesn't need every controller to fetch
 * and pass it individually — same pattern as StudentContextMiddleware's
 * profile_complete/has_thesis_registration globals.
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
            $count = (new Notification($this->db))->countUnreadForUser($_SESSION['user_id'], $role);
            $this->twig->getEnvironment()->addGlobal('unread_notifications_count', $count);
        }

        return $handler->handle($request);
    }
}
