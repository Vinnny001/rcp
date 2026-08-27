<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * In-app notifications. The `type` column also allows 'email'/'sms'/
 * 'whatsapp', but nothing in this app can actually send those — there's
 * no mail/SMS provider configured — so every notification created here
 * is 'in_app': a row the recipient sees on their own notifications page.
 * There's no delivery queue, so `status` only ever moves directly from
 * 'sent' to 'read'; 'queued' and 'failed' go unused.
 */
class Notification
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function create(
        string $userId,
        string $subject,
        string $message,
        ?string $relatedEntityType = null,
        ?string $relatedEntityId = null
    ): string {
        $notificationId = $this->generateUuid();

        $stmt = $this->db->prepare(
            "INSERT INTO notifications
                (notification_id, user_id, type, subject, message, related_entity_type, related_entity_id, status, sent_at)
             VALUES
                (:id, :user_id, 'in_app', :subject, :message, :related_entity_type, :related_entity_id, 'sent', NOW())"
        );
        $stmt->execute([
            'id'                  => $notificationId,
            'user_id'             => $userId,
            'subject'             => $subject,
            'message'             => $message,
            'related_entity_type' => $relatedEntityType,
            'related_entity_id'   => $relatedEntityId,
        ]);

        return $notificationId;
    }

    /**
     * Sends the same notification to every user id given, skipping
     * duplicates. Returns how many were actually created, for the
     * "sent to N people" confirmation.
     *
     * @param array<int, string> $userIds
     */
    public function createForUsers(
        array $userIds,
        string $subject,
        string $message,
        ?string $relatedEntityType = null,
        ?string $relatedEntityId = null
    ): int {
        $userIds = array_values(array_unique(array_filter($userIds)));

        foreach ($userIds as $userId) {
            $this->create($userId, $subject, $message, $relatedEntityType, $relatedEntityId);
        }

        return count($userIds);
    }

    public function findForUser(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM notifications WHERE user_id = :user_id ORDER BY created_at DESC LIMIT 100"
        );
        $stmt->execute(['user_id' => $userId]);
        return $stmt->fetchAll();
    }

    public function countUnreadForUser(string $userId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM notifications WHERE user_id = :user_id AND status != 'read'"
        );
        $stmt->execute(['user_id' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Marks one notification read — scoped to the given user id so a
     * user can only mark their own as read, never someone else's.
     */
    public function markRead(string $notificationId, string $userId): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE notifications SET status = 'read'
             WHERE notification_id = :notification_id AND user_id = :user_id"
        );
        $stmt->execute(['notification_id' => $notificationId, 'user_id' => $userId]);
        return $stmt->rowCount() > 0;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
