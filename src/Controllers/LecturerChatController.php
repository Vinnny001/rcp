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

class LecturerChatController
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
     * Mirror of StudentChatController::buildThreads() — active
     * supervisees (sendable) union'd with anyone ever messaged
     * (read-only if no longer an active supervisee).
     *
     * @return array<string, array{student_user_id:string, name:string, can_send:bool}>
     */
    private function buildThreads(string $lecturerId, string $userId, Lecturer $lecturerModel, Chat $chatModel): array
    {
        $threads = [];

        foreach ($lecturerModel->findActiveSupervisions($lecturerId) as $s) {
            $threads[$s['student_user_id']] = [
                'student_user_id' => $s['student_user_id'],
                'name'            => $s['student_name'],
                'can_send'        => true,
            ];
        }

        foreach ($chatModel->distinctStudentPartnersForLecturer($userId) as $studentUserId) {
            if (isset($threads[$studentUserId])) {
                continue;
            }
            $student = (new User($this->db))->findById($studentUserId);
            $threads[$studentUserId] = [
                'student_user_id' => $studentUserId,
                'name'            => $student ? $student['first_name'] . ' ' . $student['last_name'] : 'Former supervisee',
                'can_send'        => false,
            ];
        }

        return $threads;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $userId = $_SESSION['user_id'];
        $lecturerModel = new Lecturer($this->db);
        $lecturer = $lecturerModel->findByUserId($userId);
        if (!$lecturer) {
            $_SESSION['flash_error'] = 'Your lecturer profile could not be found.';
            return $this->redirect($response, '/login');
        }

        $chatModel = new Chat($this->db);
        $threads = $this->buildThreads($lecturer['lecturer_id'], $userId, $lecturerModel, $chatModel);

        $requested = (string) ($request->getQueryParams()['with'] ?? '');
        $selectedId = isset($threads[$requested]) ? $requested : (array_key_first($threads) ?? null);

        foreach ($threads as &$thread) {
            $thread['unread_count'] = $chatModel->unreadCountForThread($thread['student_user_id'], $userId, $userId);
        }
        unset($thread);

        $messages = [];
        $canSendSelected = false;

        if ($selectedId) {
            $messages = $chatModel->findConversation($selectedId, $userId);
            $chatModel->markConversationRead($selectedId, $userId, $userId);
            $canSendSelected = $threads[$selectedId]['can_send'];
        }

        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        return $this->twig->render($response, 'lecturers/chat.twig', [
            'active_page'      => 'l-chat',
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
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/lecturer/chat');
        }

        $userId = $_SESSION['user_id'];
        $lecturerModel = new Lecturer($this->db);
        $lecturer = $lecturerModel->findByUserId($userId);
        if (!$lecturer) {
            $_SESSION['flash_error'] = 'Your lecturer profile could not be found.';
            return $this->redirect($response, '/lecturer/chat');
        }

        $studentUserId = trim((string) ($data['student_user_id'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));
        $replyToChatId = trim((string) ($data['reply_to_chat_id'] ?? '')) ?: null;

        if ($studentUserId === '' || $message === '') {
            return $this->redirect($response, '/lecturer/chat?with=' . urlencode($studentUserId));
        }

        $activeIds = array_column($lecturerModel->findActiveSupervisions($lecturer['lecturer_id']), 'student_user_id');

        if (!in_array($studentUserId, $activeIds, true)) {
            $_SESSION['flash_error'] = 'You can no longer send messages in this conversation.';
            return $this->redirect($response, '/lecturer/chat?with=' . urlencode($studentUserId));
        }

        $chatModel = new Chat($this->db);
        if ($replyToChatId !== null && !$chatModel->belongsToConversation($replyToChatId, $studentUserId, $userId)) {
            $replyToChatId = null;
        }

        $chatModel->sendMessage($studentUserId, $userId, $userId, $message, $replyToChatId);

        return $this->redirect($response, '/lecturer/chat?with=' . urlencode($studentUserId));
    }
}
