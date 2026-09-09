<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\DepartmentHead;
use App\Models\ResearchCoordinator;
use App\Models\SupervisorShortlist;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

/**
 * The research coordinator's side of supervisor assignment: pick up a
 * student's shortlist, take it to the department, record what was
 * decided, then release the decision.
 *
 * A coordinator signs in as a lecturer — the powers come from a
 * research_coordinators row, and every action here re-checks it
 * against the program that actually owns the shortlist. Holding the
 * role for one program grants nothing over another's students.
 */
class CoordinatorController
{
    private PDO $db;
    private Twig $twig;

    public function __construct(PDO $db, Twig $twig)
    {
        $this->db = $db;
        $this->twig = $twig;
    }

    public function queue(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $programs = (new ResearchCoordinator($this->db))->programsForUser($_SESSION['user_id']);
        $shortlists = (new SupervisorShortlist($this->db))
            ->queueForPrograms(array_column($programs, 'program_id'));

        return $this->twig->render($response, 'coordinators/queue.twig', [
            'active_page' => 'l-coordinator',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'last_name'   => $_SESSION['last_name'] ?? '',
            'programs'    => $programs,
            'shortlists'  => $shortlists,
            'error'       => $this->takeFlash('flash_error'),
            'success'     => $this->takeFlash('flash_success'),
        ]);
    }

    public function showShortlist(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $model = new SupervisorShortlist($this->db);
        $shortlist = $model->findWithContext((string) $args['id']);

        if (!$shortlist || !$this->coordinates($shortlist['program_id'])) {
            $_SESSION['flash_error'] = 'That shortlist is not on a program you coordinate.';
            return $this->redirect($response, '/coordinator/shortlists');
        }

        return $this->twig->render($response, 'coordinators/shortlist.twig', [
            'active_page' => 'l-coordinator',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'last_name'   => $_SESSION['last_name'] ?? '',
            'shortlist'   => $shortlist,
            'choices'     => $model->choicesFor($shortlist['shortlist_id']),
            'voters'      => $shortlist['meeting_id'] ? $model->meetingVoters($shortlist['meeting_id']) : [],
            'tally'       => $shortlist['meeting_id'] ? $model->tally($shortlist['meeting_id']) : null,
            'heads'       => (new DepartmentHead($this->db))->activeForDepartment($shortlist['department_id']),
            'session_user_id' => $_SESSION['user_id'],
            'csrf_token'  => $this->csrfToken(),
            'error'       => $this->takeFlash('flash_error'),
            'success'     => $this->takeFlash('flash_success'),
        ]);
    }

    public function scheduleMeeting(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handle($request, $response, function (array $data, SupervisorShortlist $model): string {
            $shortlist = $this->authorisedShortlist($data['shortlist_id'] ?? '', $model);

            $model->scheduleMeeting(
                $shortlist['shortlist_id'],
                (string) ($data['scheduled_at'] ?? ''),
                (string) ($data['mode'] ?? 'physical'),
                (string) ($data['location'] ?? ''),
                (string) ($data['virtual_link'] ?? ''),
                (array) ($data['dept_head_ids'] ?? []),
                $_SESSION['user_id'],
                (string) ($data['lead_user_id'] ?? '') ?: null,
                (string) ($data['secretary_user_id'] ?? '') ?: null
            );

            return 'Meeting scheduled and heads invited.';
        });
    }

    public function saveMinutes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handle($request, $response, function (array $data, SupervisorShortlist $model): string {
            $shortlist = $this->authorisedShortlist($data['shortlist_id'] ?? '', $model);
            if (!$shortlist['meeting_id']) {
                throw new \RuntimeException('Schedule the meeting before writing minutes.');
            }

            // Coordinating the program is not enough on its own: the
            // minutes belong to the meeting's secretary, or its lead
            // when no secretary was appointed.
            if ($shortlist['minutes_author_id'] !== $_SESSION['user_id']) {
                throw new \RuntimeException(
                    'The minutes for this meeting are written by ' .
                    ($shortlist['secretary_name'] ?: $shortlist['lead_name']) . '.'
                );
            }

            $finalize = ($data['finalize'] ?? '') === '1';
            $model->saveMinutes($shortlist['meeting_id'], (string) ($data['minutes'] ?? ''), $finalize);

            return $finalize
                ? 'Minutes finalised. The decision can now be applied.'
                : 'Minutes saved as a draft.';
        });
    }

    /**
     * A scheduled exam: its minutes, and the documents this candidate
     * has been asked for.
     */
    public function examMeeting(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $model = new \App\Models\ExamMeeting($this->db);
        $meeting = $model->find((string) $args['id']);
        $programId = $meeting
            ? (new \App\Models\ExaminerAssignment($this->db))->programForMeeting($meeting['meeting_id'])
            : null;

        if (!$meeting || $programId === null || !$this->coordinates($programId)) {
            $_SESSION['flash_error'] = 'That exam is not on a program you coordinate.';
            return $this->redirect($response, '/coordinator/exams');
        }

        $rubric = new \App\Models\Rubric($this->db);

        return $this->twig->render($response, 'coordinators/exam_meeting.twig', [
            'active_page'  => 'l-coordinator-exams',
            'first_name'   => $_SESSION['first_name'] ?? '',
            'last_name'    => $_SESSION['last_name'] ?? '',
            'meeting'      => $meeting,
            'documents'    => $model->requiredDocumentsFor($meeting['meeting_id']),
            'extras'       => $model->extraDocumentsFor($meeting['meeting_id']),
            'doc_types'    => $this->db->query("SELECT doc_type_id, doc_type_name FROM document_types ORDER BY doc_type_name")->fetchAll(),
            'panel'        => $meeting['exam_stage_id']
                ? $rubric->panelResults($meeting['meeting_id'], (string) ($rubric->templateForStage($meeting['exam_stage_id'])['template_id'] ?? ''))
                : [],
            'attendees'    => $this->examinersFor($meeting['meeting_id']),
            'session_user_id' => $_SESSION['user_id'],
            'csrf_token'   => $this->csrfToken(),
            'error'        => $this->takeFlash('flash_error'),
            'success'      => $this->takeFlash('flash_success'),
        ]);
    }

    public function saveExamMinutes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handleExamMeeting($request, $response, function (array $data, array $meeting, \App\Models\ExamMeeting $model): string {
            // Coordinating is not by itself permission to write: the
            // minutes belong to the secretary, or the lead.
            if ($meeting['minutes_author_id'] !== $_SESSION['user_id']) {
                throw new \RuntimeException(
                    'The minutes for this exam are written by ' .
                    ($meeting['secretary_name'] ?: $meeting['lead_name']) . '.'
                );
            }

            $finalize = ($data['finalize'] ?? '') === '1';
            $model->saveMinutes($meeting['meeting_id'], (string) ($data['minutes'] ?? ''), $finalize);

            return $finalize ? 'Minutes finalised.' : 'Minutes saved as a draft.';
        });
    }

    public function approveExamMinutes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handleExamMeeting($request, $response, function (array $data, array $meeting, \App\Models\ExamMeeting $model): string {
            $model->approveMinutes($meeting['meeting_id'], $_SESSION['user_id']);

            return 'Minutes approved.';
        });
    }

    public function setExamRoles(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handleExamMeeting($request, $response, function (array $data, array $meeting, \App\Models\ExamMeeting $model): string {
            $model->setRoles(
                $meeting['meeting_id'],
                (string) ($data['lead_user_id'] ?? '') ?: null,
                (string) ($data['secretary_user_id'] ?? '') ?: null
            );

            return 'Meeting roles saved.';
        });
    }

    public function addExamDocument(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handleExamMeeting($request, $response, function (array $data, array $meeting, \App\Models\ExamMeeting $model): string {
            $model->addExtraDocument(
                $meeting['meeting_id'],
                (string) ($data['document_type_id'] ?? ''),
                (string) ($data['note'] ?? ''),
                $_SESSION['user_id']
            );

            return 'Document added. The student sees it among what they owe for this exam.';
        });
    }

    public function removeExamDocument(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handleExamMeeting($request, $response, function (array $data, array $meeting, \App\Models\ExamMeeting $model): string {
            $model->removeExtraDocument((string) ($data['extra_doc_id'] ?? ''));

            return 'No longer required.';
        });
    }

    /**
     * Shared guard for exam-meeting actions: authenticate, verify CSRF,
     * then confirm this coordinator holds the meeting's program.
     */
    private function handleExamMeeting(
        ServerRequestInterface $request,
        ResponseInterface $response,
        callable $write
    ): ResponseInterface {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $data = (array) $request->getParsedBody();
        $meetingId = (string) ($data['meeting_id'] ?? '');
        $back = $meetingId !== '' ? '/coordinator/exams/' . $meetingId : '/coordinator/exams';

        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, $back);
        }

        $model = new \App\Models\ExamMeeting($this->db);
        $meeting = $model->find($meetingId);
        $programId = $meeting
            ? (new \App\Models\ExaminerAssignment($this->db))->programForMeeting($meetingId)
            : null;

        if (!$meeting || $programId === null || !$this->coordinates($programId)) {
            $_SESSION['flash_error'] = 'That exam is not on a program you coordinate.';
            return $this->redirect($response, '/coordinator/exams');
        }

        try {
            $_SESSION['flash_success'] = $write($data, $meeting, $model);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($response, $back);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function examinersFor(string $meetingId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ma.user_id, CONCAT(u.first_name, ' ', u.last_name) AS name
             FROM meeting_attendees ma
             JOIN users u ON u.user_id = ma.user_id
             WHERE ma.meeting_id = :id AND ma.role_in_meeting = 'examiner'
             ORDER BY u.last_name"
        );
        $stmt->execute(['id' => $meetingId]);

        return $stmt->fetchAll();
    }

    /**
     * Students who have declared themselves ready, with the qualified
     * examiners available for each one's program.
     */
    public function examQueue(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $programs = (new ResearchCoordinator($this->db))->programsForUser($_SESSION['user_id']);
        $queue = (new \App\Models\ExamReadiness($this->db))
            ->queueForPrograms(array_column($programs, 'program_id'));

        $qualifications = new \App\Models\ExaminerQualification($this->db);
        foreach ($queue as &$row) {
            // Only lecturers qualified for this program can be offered.
            $row['examiners'] = $qualifications->qualifiedForProgram($row['program_id']);
        }
        unset($row);

        return $this->twig->render($response, 'coordinators/exams.twig', [
            'active_page' => 'l-coordinator-exams',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'last_name'   => $_SESSION['last_name'] ?? '',
            'programs'    => $programs,
            'queue'       => $queue,
            // Already scheduled, so no longer in the queue above — but
            // still the coordinator's to minute and document.
            'scheduled'   => (new \App\Models\ExamMeeting($this->db))
                                ->forPrograms(array_column($programs, 'program_id')),
            'csrf_token'  => $this->csrfToken(),
            'error'       => $this->takeFlash('flash_error'),
            'success'     => $this->takeFlash('flash_success'),
        ]);
    }

    public function scheduleExam(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $data = (array) $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/coordinator/exams');
        }

        try {
            $programId = (string) ($data['program_id'] ?? '');
            if (!$this->coordinates($programId)) {
                throw new \RuntimeException('That student is not on a program you coordinate.');
            }

            (new \App\Models\ExamReadiness($this->db))->scheduleExam(
                (string) ($data['readiness_id'] ?? ''),
                $programId,
                array_values((array) ($data['examiner_ids'] ?? [])),
                (string) ($data['panel_leader_id'] ?? '') ?: null,
                (string) ($data['scheduled_at'] ?? ''),
                (string) ($data['mode'] ?? 'physical'),
                (string) ($data['location'] ?? ''),
                (string) ($data['virtual_link'] ?? ''),
                $_SESSION['user_id']
            );

            $_SESSION['flash_success'] = 'Exam scheduled and the panel invited.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($response, '/coordinator/exams');
    }

    /**
     * Exam results the panel leader has confirmed and the coordinator
     * has not yet released to the student.
     */
    public function resultsQueue(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $programs = (new ResearchCoordinator($this->db))->programsForUser($_SESSION['user_id']);
        $pending = (new \App\Models\ExaminerAssignment($this->db))
            ->awaitingApproval(array_column($programs, 'program_id'));

        $rubric = new \App\Models\Rubric($this->db);
        foreach ($pending as &$row) {
            $row['band'] = $row['template_id']
                ? $rubric->bandFor($row['template_id'], (float) $row['average_score'])
                : null;
            $row['panel'] = $row['template_id']
                ? $rubric->panelResults($row['meeting_id'], $row['template_id'])
                : [];
        }
        unset($row);

        return $this->twig->render($response, 'coordinators/results.twig', [
            'active_page' => 'l-coordinator-results',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'last_name'   => $_SESSION['last_name'] ?? '',
            'programs'    => $programs,
            'pending'     => $pending,
            'csrf_token'  => $this->csrfToken(),
            'error'       => $this->takeFlash('flash_error'),
            'success'     => $this->takeFlash('flash_success'),
        ]);
    }

    public function approveAverage(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $data = (array) $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/coordinator/results');
        }

        try {
            $meetingId = (string) ($data['meeting_id'] ?? '');
            $assignments = new \App\Models\ExaminerAssignment($this->db);
            $programId = $assignments->programForMeeting($meetingId);

            if ($programId === null || !$this->coordinates($programId)) {
                throw new \RuntimeException('That exam is not on a program you coordinate.');
            }

            $average = (new \App\Models\Rubric($this->db))->approveAverage($meetingId, $_SESSION['user_id']);
            $_SESSION['flash_success'] = 'Released. The student can now see their ' . $average . '% outcome.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($response, '/coordinator/results');
    }

    public function approveMinutes(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handle($request, $response, function (array $data, SupervisorShortlist $model): string {
            $shortlist = $this->authorisedShortlist($data['shortlist_id'] ?? '', $model);
            if (!$shortlist['meeting_id']) {
                throw new \RuntimeException('There is no meeting to approve minutes for.');
            }

            $model->approveMinutes($shortlist['meeting_id'], $_SESSION['user_id']);

            return 'Minutes approved. You can now apply the decision.';
        });
    }

    public function applyOutcome(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handle($request, $response, function (array $data, SupervisorShortlist $model): string {
            $shortlist = $this->authorisedShortlist($data['shortlist_id'] ?? '', $model);
            if (!$shortlist['meeting_id']) {
                throw new \RuntimeException('There is no meeting to apply.');
            }

            return $model->recordOutcome($shortlist['meeting_id']) === 'approved'
                ? 'Approved. The preferred main supervisor has been sent the request.'
                : 'Recorded as not approved. The student has been told why and can submit a new shortlist.';
        });
    }

    /**
     * Loads a shortlist and refuses it unless this coordinator holds
     * the program it belongs to.
     *
     * @return array<string, mixed>
     */
    private function authorisedShortlist(string $shortlistId, SupervisorShortlist $model): array
    {
        $shortlist = $model->findWithContext($shortlistId);
        if (!$shortlist || !$this->coordinates($shortlist['program_id'])) {
            throw new \RuntimeException('That shortlist is not on a program you coordinate.');
        }

        return $shortlist;
    }

    private function coordinates(string $programId): bool
    {
        return (new ResearchCoordinator($this->db))->isCoordinatorFor($_SESSION['user_id'], $programId);
    }

    private function handle(
        ServerRequestInterface $request,
        ResponseInterface $response,
        callable $write
    ): ResponseInterface {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $data = (array) $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/coordinator/shortlists');
        }

        $back = isset($data['shortlist_id'])
            ? '/coordinator/shortlists/' . $data['shortlist_id']
            : '/coordinator/shortlists';

        try {
            $_SESSION['flash_success'] = $write($data, new SupervisorShortlist($this->db));
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($response, $back);
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

    private function takeFlash(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);

        return $value;
    }

    private function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withHeader('Location', $path)->withStatus(302);
    }
}
