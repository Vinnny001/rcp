<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Direct text chat between a student and a supervisor. A conversation
 * is the (student_user_id, lecturer_user_id) pair — every read here is
 * scoped to that exact pair, which is what keeps a dual-role user's two
 * hats from ever seeing each other's messages: the caller always
 * queries by whichever column matches their current session role.
 */
class Chat
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function sendMessage(
        string $studentUserId,
        string $lecturerUserId,
        string $senderUserId,
        string $message,
        ?string $replyToChatId
    ): string {
        $chatId = $this->generateUuid();
        $stmt = $this->db->prepare(
            "INSERT INTO chats (chat_id, student_user_id, lecturer_user_id, sender_user_id, message, reply_to_chat_id)
             VALUES (:chat_id, :student_user_id, :lecturer_user_id, :sender_user_id, :message, :reply_to_chat_id)"
        );
        $stmt->execute([
            'chat_id'          => $chatId,
            'student_user_id'  => $studentUserId,
            'lecturer_user_id' => $lecturerUserId,
            'sender_user_id'   => $senderUserId,
            'message'          => $message,
            'reply_to_chat_id' => $replyToChatId,
        ]);
        return $chatId;
    }

    /**
     * Every message in one conversation, oldest first, with the
     * replied-to message's own text/sender attached (for the inline
     * quote preview) when a message is a reply.
     */
    public function findConversation(string $studentUserId, string $lecturerUserId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, parent.message AS reply_message, parent.sender_user_id AS reply_sender_id
             FROM chats c
             LEFT JOIN chats parent ON parent.chat_id = c.reply_to_chat_id
             WHERE c.student_user_id = :student_user_id AND c.lecturer_user_id = :lecturer_user_id
             ORDER BY c.created_at ASC"
        );
        $stmt->execute(['student_user_id' => $studentUserId, 'lecturer_user_id' => $lecturerUserId]);
        return $stmt->fetchAll();
    }

    /**
     * Marks every message in this conversation not sent by the reader
     * as read, if not already — called whenever a thread is opened, so
     * a chat is "marked automatically read once it is opened."
     */
    public function markConversationRead(string $studentUserId, string $lecturerUserId, string $readerUserId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE chats SET read_at = NOW()
             WHERE student_user_id = :student_user_id AND lecturer_user_id = :lecturer_user_id
               AND sender_user_id != :reader_user_id AND read_at IS NULL"
        );
        $stmt->execute([
            'student_user_id'  => $studentUserId,
            'lecturer_user_id' => $lecturerUserId,
            'reader_user_id'   => $readerUserId,
        ]);
    }

    /**
     * Lecturer user ids this student has ever exchanged a message with
     * — the "history" half of the student's thread-list union (a
     * relationship that may no longer be active still keeps its thread
     * visible, read-only).
     *
     * @return array<int, string>
     */
    public function distinctLecturerPartnersForStudent(string $studentUserId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT lecturer_user_id FROM chats WHERE student_user_id = :student_user_id"
        );
        $stmt->execute(['student_user_id' => $studentUserId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @return array<int, string>
     */
    public function distinctStudentPartnersForLecturer(string $lecturerUserId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT student_user_id FROM chats WHERE lecturer_user_id = :lecturer_user_id"
        );
        $stmt->execute(['lecturer_user_id' => $lecturerUserId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Whether a chat_id being replied to actually belongs to this exact
     * conversation — a crafted reply_to_chat_id pointing at a message
     * from an unrelated conversation must never be accepted.
     */
    public function belongsToConversation(string $chatId, string $studentUserId, string $lecturerUserId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM chats
             WHERE chat_id = :chat_id AND student_user_id = :student_user_id AND lecturer_user_id = :lecturer_user_id
             LIMIT 1"
        );
        $stmt->execute([
            'chat_id'          => $chatId,
            'student_user_id'  => $studentUserId,
            'lecturer_user_id' => $lecturerUserId,
        ]);
        return (bool) $stmt->fetchColumn();
    }

    public function unreadCountForThread(string $studentUserId, string $lecturerUserId, string $viewerUserId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM chats
             WHERE student_user_id = :student_user_id AND lecturer_user_id = :lecturer_user_id
               AND sender_user_id != :viewer_user_id AND read_at IS NULL"
        );
        $stmt->execute([
            'student_user_id'  => $studentUserId,
            'lecturer_user_id' => $lecturerUserId,
            'viewer_user_id'   => $viewerUserId,
        ]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Total unread across every thread this user is party to, for the
     * sidebar badge. $role picks which column identifies "this user."
     */
    public function countUnreadForUser(string $userId, string $role): int
    {
        $column = $role === 'lecturer' ? 'lecturer_user_id' : 'student_user_id';
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM chats
             WHERE {$column} = :user_id AND sender_user_id != :user_id2 AND read_at IS NULL"
        );
        $stmt->execute(['user_id' => $userId, 'user_id2' => $userId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Whether this user has any conversation at all (any message row
     * either side of), regardless of read state — used for the
     * "had chat history before" sidebar-visibility exception.
     */
    public function hasAnyThreadForUser(string $userId, string $role): bool
    {
        $column = $role === 'lecturer' ? 'lecturer_user_id' : 'student_user_id';
        $stmt = $this->db->prepare("SELECT 1 FROM chats WHERE {$column} = :user_id LIMIT 1");
        $stmt->execute(['user_id' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Escapes a raw chat message and turns URLs and phone numbers into
     * clickable links. Escaping always runs first, on the plain-text
     * segments only — a message can never inject markup through this
     * method, regardless of what it contains.
     */
    public static function linkify(string $text): string
    {
        $urlPattern = '/(https?:\/\/[^\s<]+)/i';
        $telPattern = '/(\+?\d[\d\-\s]{6,}\d)/';

        $segments = preg_split($urlPattern, $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        $html = '';

        foreach ($segments as $i => $segment) {
            if ($i % 2 === 1) {
                // A captured URL — trim trailing punctuation that's more
                // likely to be sentence punctuation than part of the link.
                $trailingPunctuation = '';
                if (preg_match('/([.,;:!?)]+)$/', $segment, $m)) {
                    $trailingPunctuation = $m[1];
                    $segment = substr($segment, 0, -strlen($trailingPunctuation));
                }
                $escapedUrl = htmlspecialchars($segment, ENT_QUOTES, 'UTF-8');
                $html .= '<a href="' . $escapedUrl . '" target="_blank" rel="noopener noreferrer">' . $escapedUrl . '</a>'
                       . htmlspecialchars($trailingPunctuation, ENT_QUOTES, 'UTF-8');
                continue;
            }

            $escaped = htmlspecialchars($segment, ENT_QUOTES, 'UTF-8');
            $html .= preg_replace_callback($telPattern, function (array $m): string {
                $digitCount = strlen(preg_replace('/\D/', '', $m[1]));
                if ($digitCount < 7) {
                    return $m[1]; // too short to confidently be a phone number
                }
                $href = preg_replace('/[^\d+]/', '', $m[1]);
                return '<a href="tel:' . $href . '">' . $m[1] . '</a>';
            }, $escaped);
        }

        return $html;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
