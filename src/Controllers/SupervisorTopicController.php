<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Lecturer;
use App\Models\ProposalTopic;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

/**
 * Supervisors settling the topic their student writes under.
 *
 * The main supervisor calls the meeting and closes it; every supervisor
 * records their own decision on each topic put forward. Nobody else takes
 * part — not the coordinator, not the department, and not the student,
 * who attends but does not decide.
 */
class SupervisorTopicController
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

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $lecturer = (new Lecturer($this->db))->findByUserId($_SESSION['user_id']);
        $students = $lecturer
            ? (new ProposalTopic($this->db))->forSupervisor($lecturer['lecturer_id'], $_SESSION['user_id'])
            : [];

        $rendered = $this->twig->render($response, 'lecturers/topics.twig', [
            'active_page' => 'l-topics',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'students'    => $students,
            'csrf_token'  => $this->csrfToken(),
            'error'       => $_SESSION['flash_error'] ?? null,
            'success'     => $_SESSION['flash_success'] ?? null,
        ]);

        unset($_SESSION['flash_error'], $_SESSION['flash_success']);

        return $rendered;
    }

    /**
     * The main supervisor calling the meeting.
     */
    public function schedule(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->act($request, $response, '/lecturer/topics', function (array $data, ProposalTopic $topics): string {
            $date = trim((string) ($data['date'] ?? ''));
            $time = trim((string) ($data['time'] ?? ''));

            $topics->scheduleMeeting((string) ($data['student_id'] ?? ''), $_SESSION['user_id'], [
                'scheduled_at' => $date === '' || $time === '' ? '' : $date . ' ' . $time,
                'mode'         => (string) ($data['mode'] ?? ''),
                'location'     => (string) ($data['location'] ?? ''),
                'virtual_link' => (string) ($data['virtual_link'] ?? ''),
            ]);

            return 'Topic approval meeting scheduled. Your student and the other supervisors are invited.';
        });
    }

    public function meeting(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $page = (new ProposalTopic($this->db))->meetingPage($args['id'], $_SESSION['user_id']);

        if ($page === null) {
            $_SESSION['flash_error'] = 'That topic approval meeting is not yours.';
            return $this->redirect($response, '/lecturer/topics');
        }

        $rendered = $this->twig->render($response, 'lecturers/topic_meeting.twig', $page + [
            'active_page' => 'l-topics',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'you'         => $_SESSION['user_id'],
            'csrf_token'  => $this->csrfToken(),
            'error'       => $_SESSION['flash_error'] ?? null,
            'success'     => $_SESSION['flash_success'] ?? null,
        ]);

        unset($_SESSION['flash_error'], $_SESSION['flash_success']);

        return $rendered;
    }

    /**
     * One supervisor's decision on one topic.
     */
    public function decide(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->act($request, $response, '/lecturer/topics/' . $args['id'], function (array $data, ProposalTopic $topics) use ($args): string {
            $topics->recordDecision(
                $args['id'],
                (string) ($data['topic_id'] ?? ''),
                $_SESSION['user_id'],
                (string) ($data['decision'] ?? ''),
                (string) ($data['comment'] ?? '')
            );

            return 'Your decision is recorded.';
        });
    }

    /**
     * The main supervisor closing the meeting, approving one topic or
     * none.
     */
    public function close(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        return $this->act($request, $response, '/lecturer/topics/' . $args['id'], function (array $data, ProposalTopic $topics) use ($args): string {
            $approved = trim((string) ($data['approved_topic_id'] ?? ''));

            $topics->close($args['id'], $_SESSION['user_id'], $approved === '' ? null : $approved, (string) ($data['reason'] ?? ''));

            return $approved === ''
                ? 'Recorded: no topic approved. Your student can put new topics forward.'
                : 'Topic approved. It is now the topic on your student\'s proposal.';
        });
    }

    /**
     * The shape every write here shares: check the session, run the
     * model, and come back with what happened.
     *
     * @param callable(array<string, mixed>, ProposalTopic): string $action
     */
    private function act(
        ServerRequestInterface $request,
        ResponseInterface $response,
        string $back,
        callable $action
    ): ResponseInterface {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $data = (array) $request->getParsedBody();

        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, $back);
        }

        try {
            $_SESSION['flash_success'] = $action($data, new ProposalTopic($this->db));
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($response, $back);
    }
}
