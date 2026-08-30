<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Chat;
use App\Models\Lecturer;
use App\Models\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

class StudentChatController
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
        $stmt = $this->db->prepare("SELECT student_id, student_number FROM students WHERE user_id = :user_id LIMIT 1");
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
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

    private function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withHeader('Location', $path)->withStatus(302);
    }

    /**
     * A short label for the other party in a reply quote — last_name if
     * it's set to something other than blank/whitespace, else
     * first_name. Never the generic "Them".
     */
    private function partnerLabel(?string $userId): string
    {
        if (!$userId) {
            return '';
        }
        $user = (new User($this->db))->findById($userId);
        if (!$user) {
            return '';
        }
        $lastName = trim((string) ($user['last_name'] ?? ''));
        return $lastName !== '' ? $lastName : (string) ($user['first_name'] ?? '');
    }

    /**
     * The thread list: active supervisors (sendable) union'd with
     * anyone ever messaged (read-only if no longer active) — a
     * relationship with zero messages yet must still appear as an open
     * thread, and a former relationship with history must still appear,
     * just locked.
     *
     * @return array<string, array{lecturer_user_id:string, name:string, can_send:bool}>
     */
    private function buildThreads(string $studentId, string $userId, Lecturer $lecturerModel, Chat $chatModel): array
    {
        $threads = [];

        foreach ($lecturerModel->findActiveSupervisorsForStudent($studentId) as $s) {
            $threads[$s['lecturer_user_id']] = [
                'lecturer_user_id' => $s['lecturer_user_id'],
                'name'             => $s['lecturer_name'],
                'can_send'         => true,
            ];
        }

        foreach ($chatModel->distinctLecturerPartnersForStudent($userId) as $lecturerUserId) {
            if (isset($threads[$lecturerUserId])) {
                continue;
            }
            $lecturer = (new User($this->db))->findById($lecturerUserId);
            $threads[$lecturerUserId] = [
                'lecturer_user_id' => $lecturerUserId,
                'name'             => $lecturer ? $lecturer['first_name'] . ' ' . $lecturer['last_name'] : 'Former supervisor',
                'can_send'         => false,
            ];
        }

        return $threads;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $this->redirect($response, $redirect);
        }

        $userId = $_SESSION['user_id'];
        $student = $this->getStudentRecord($userId);
        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/login');
        }

        $lecturerModel = new Lecturer($this->db);
        $chatModel = new Chat($this->db);
        $threads = $this->buildThreads($student['student_id'], $userId, $lecturerModel, $chatModel);

        $requested = (string) ($request->getQueryParams()['with'] ?? '');
        $selectedId = isset($threads[$requested]) ? $requested : (array_key_first($threads) ?? null);

        foreach ($threads as &$thread) {
            $thread['unread_count'] = $chatModel->unreadCountForThread($userId, $thread['lecturer_user_id'], $userId);
        }
        unset($thread);

        $messages = [];
        $canSendSelected = false;

        if ($selectedId) {
            $messages = $chatModel->findConversation($userId, $selectedId);
            $chatModel->markConversationRead($userId, $selectedId, $userId);
            $canSendSelected = $threads[$selectedId]['can_send'];
        }

        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        return $this->twig->render($response, 'students/chat.twig', [
            'active_page'      => 'chat',
            'first_name'       => $_SESSION['first_name'] ?? '',
            'threads'          => $threads,
            'selected_id'      => $selectedId,
            'messages'         => $messages,
            'my_user_id'       => $userId,
            'partner_label'    => $this->partnerLabel($selectedId),
            'can_send_selected' => $canSendSelected,
            'csrf_token'       => $this->csrfToken(),
            'error'            => $error,
        ]);
    }

    public function send(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $this->redirect($response, $redirect);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/student/chat');
        }

        $userId = $_SESSION['user_id'];
        $student = $this->getStudentRecord($userId);
        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/student/chat');
        }

        $lecturerUserId = trim((string) ($data['lecturer_user_id'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));
        $replyToChatId = trim((string) ($data['reply_to_chat_id'] ?? '')) ?: null;

        if ($lecturerUserId === '' || $message === '') {
            return $this->redirect($response, '/student/chat?with=' . urlencode($lecturerUserId));
        }

        $lecturerModel = new Lecturer($this->db);
        $activeIds = array_column($lecturerModel->findActiveSupervisorsForStudent($student['student_id']), 'lecturer_user_id');

        if (!in_array($lecturerUserId, $activeIds, true)) {
            $_SESSION['flash_error'] = 'You can no longer send messages in this conversation.';
            return $this->redirect($response, '/student/chat?with=' . urlencode($lecturerUserId));
        }

        $chatModel = new Chat($this->db);
        if ($replyToChatId !== null && !$chatModel->belongsToConversation($replyToChatId, $userId, $lecturerUserId)) {
            $replyToChatId = null;
        }

        $chatModel->sendMessage($userId, $lecturerUserId, $userId, $message, $replyToChatId);

        return $this->redirect($response, '/student/chat?with=' . urlencode($lecturerUserId));
    }
}
