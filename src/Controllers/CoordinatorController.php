<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\DepartmentHead;
use App\Models\Lecturer;
use App\Models\OwnRecord;
use App\Models\ResearchCoordinator;
use App\Models\SupervisorProfile;
use App\Models\SupervisorShortlist;
use App\Services\MeetingMinutes;
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
    private const OWN_RECORD = 'This is your own record. You cannot coordinate your own studies — an administrator needs to assign another coordinator for your program.';

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
        $model = new SupervisorShortlist($this->db);
        // No scheduler: windows that closed unseen are settled here.
        $model->resolveDue();
        $all = $model->queueForPrograms(array_column($programs, 'program_id'));
        $shortlists = $this->withoutOwn($all);

        return $this->twig->render($response, 'coordinators/queue.twig', [
            'active_page' => 'l-coordinator',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'last_name'   => $_SESSION['last_name'] ?? '',
            'programs'    => $programs,
            'shortlists'  => $shortlists,
            'own_hidden'  => count($all) !== count($shortlists),
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
        $model->resolveDue();
        $shortlist = $model->findWithContext((string) $args['id']);

        if (!$shortlist || !$this->coordinates($shortlist['program_id'])) {
            $_SESSION['flash_error'] = 'That request is not on a program you coordinate.';
            return $this->redirect($response, '/coordinator/shortlists');
        }
        if ($shortlist['student_user_id'] === $_SESSION['user_id']) {
            $_SESSION['flash_error'] = self::OWN_RECORD;
            return $this->redirect($response, '/coordinator/shortlists');
        }

        $choices = $model->choicesFor($shortlist['shortlist_id']);
        $decision = $model->decisionState($shortlist);

        // Who on the list could not be asked if it were sent now — not
        // taking students, or at full load — so the coordinator sees it
        // before pressing Send rather than after.
        $lecturers = new Lecturer($this->db);
        foreach ($choices as &$choice) {
            $choice['blocker'] = $lecturers->newStudentBlocker($choice['lecturer_id']);
        }
        unset($choice);

        $picked = [];
        $pickedMain = '';
        foreach ($choices as $choice) {
            $picked[$choice['lecturer_id']] = (int) $choice['rank_position'];
            if ($choice['is_preferred_main']) {
                $pickedMain = $choice['lecturer_id'];
            }
        }

        return $this->twig->render($response, 'coordinators/shortlist.twig', [
            'active_page'   => 'l-coordinator',
            'first_name'    => $_SESSION['first_name'] ?? '',
            'last_name'     => $_SESSION['last_name'] ?? '',
            'shortlist'     => $shortlist,
            'choices'       => $choices,
            'files'         => $model->filesFor($shortlist['shortlist_id']),
            'decision'      => $decision,
            'previous'      => $shortlist['previous_shortlist_id'] ? $model->findWithContext($shortlist['previous_shortlist_id']) : null,
            'supervisors'   => $decision['can_decide'] ? (new SupervisorProfile($this->db))->browsable($shortlist['student_user_id']) : [],
            'picked'        => $picked,
            'picked_main'   => $pickedMain,
            'max_choices'   => SupervisorShortlist::MAX_CHOICES,
            'response_days' => SupervisorShortlist::RESPONSE_DAYS,
            'voters'      => $shortlist['meeting_id'] ? $model->meetingVoters($shortlist['meeting_id']) : [],
            'tally'       => $shortlist['meeting_id'] ? $model->tally($shortlist['meeting_id']) : null,
            // A head who is this student is not offered: they cannot be
            // invited to decide their own request.
            'heads'       => array_values(array_filter(
                (new DepartmentHead($this->db))->activeForDepartment($shortlist['department_id']),
                fn (array $head): bool => $head['user_id'] !== $shortlist['student_user_id']
            )),
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
        return $this->handle($request, $response, function (array $data, SupervisorShortlist $model) use ($request): string {
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

            $finalized = (new MeetingMinutes($model))->save(
                $shortlist['meeting_id'],
                $data,
                $request->getUploadedFiles()['minutes_file'] ?? null
            );

            return $finalized
                ? 'Minutes finalised. Once you approve them the decision can be applied.'
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
        if ((new OwnRecord($this->db))->isStudent($_SESSION['user_id'], $meeting['student_id'])) {
            $_SESSION['flash_error'] = self::OWN_RECORD;
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
            // Examiners enter it to submit a review; on an exam meeting
            // only the coordinator holds it, to read out in the room.
            'secure_code'  => (new \App\Models\Meeting($this->db))->ensureSecureCode($meeting['meeting_id']),
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
        if ((new OwnRecord($this->db))->isStudent($_SESSION['user_id'], $meeting['student_id'])) {
            $_SESSION['flash_error'] = self::OWN_RECORD;
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

    // ------------------------------------------------------------------
    // Exam schedules — the windows students book their exams in
    // ------------------------------------------------------------------

    /**
     * The exam schedules on the programs this coordinator holds. A
     * window hangs off one of the program's thesis schedules; a program
     * with none cannot have exams scheduled until an administrator
     * creates one.
     */
    public function examSchedules(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $programs = (new ResearchCoordinator($this->db))->programsForUser($_SESSION['user_id']);
        $programIds = array_column($programs, 'program_id');

        $thesisSchedules = array_values(array_filter(
            (new \App\Models\ThesisSchedule($this->db))->all(),
            fn (array $schedule): bool => in_array($schedule['program_id'], $programIds, true)
        ));

        $examModel = new \App\Models\ExamSchedule($this->db);
        $windows = $examModel->forThesisSchedules(array_column($thesisSchedules, 'schedule_id'));
        foreach ($windows as &$window) {
            $window['document_slots'] = $examModel->documentSlots($window['exam_schedule_id']);
        }
        unset($window);

        foreach ($programs as &$program) {
            $program['thesis_schedules'] = array_values(array_filter(
                $thesisSchedules,
                fn (array $schedule): bool => $schedule['program_id'] === $program['program_id']
            ));
            $program['windows'] = array_values(array_filter(
                $windows,
                fn (array $window): bool => $window['program_id'] === $program['program_id']
            ));
        }
        unset($program);

        return $this->twig->render($response, 'coordinators/exam_schedules.twig', [
            'active_page'    => 'l-coordinator-schedules',
            'first_name'     => $_SESSION['first_name'] ?? '',
            'last_name'      => $_SESSION['last_name'] ?? '',
            'programs'       => $programs,
            'exam_types'     => \App\Models\ExamSchedule::VALID_EXAM_TYPES,
            'exam_stages'    => (new \App\Models\ExamStage($this->db))->allActive(),
            'document_types' => $this->db->query("SELECT doc_type_id, doc_type_name FROM document_types ORDER BY doc_type_name")->fetchAll(),
            'csrf_token'     => $this->csrfToken(),
            'error'          => $this->takeFlash('flash_error'),
            'success'        => $this->takeFlash('flash_success'),
        ]);
    }

    public function createExamSchedule(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handleExamSchedule($request, $response, function (array $data, \App\Models\ExamSchedule $model): string {
            $this->authoriseThesisSchedule((string) ($data['thesis_schedule_id'] ?? ''));
            if ($error = \App\Models\ExamSchedule::validationError($data, true)) {
                throw new \RuntimeException($error);
            }
            $model->create($data);

            return 'Exam schedule created. Add the documents students must submit for it, if any.';
        });
    }

    public function updateExamSchedule(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handleExamSchedule($request, $response, function (array $data, \App\Models\ExamSchedule $model): string {
            $window = $this->authoriseExamSchedule((string) ($data['exam_schedule_id'] ?? ''), $model);
            // The thesis schedule cannot be moved to another program's.
            $this->authoriseThesisSchedule((string) ($data['thesis_schedule_id'] ?? ''));
            if ($error = \App\Models\ExamSchedule::validationError($data, true)) {
                throw new \RuntimeException($error);
            }
            $model->update($window['exam_schedule_id'], $data);

            return 'Exam schedule updated.';
        });
    }

    public function deleteExamSchedule(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handleExamSchedule($request, $response, function (array $data, \App\Models\ExamSchedule $model): string {
            $window = $this->authoriseExamSchedule((string) ($data['exam_schedule_id'] ?? ''), $model);
            if ($error = $model->delete($window['exam_schedule_id'])) {
                throw new \RuntimeException($error . ' It cannot be deleted.');
            }

            return 'Exam schedule deleted.';
        });
    }

    public function addExamScheduleDocument(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handleExamSchedule($request, $response, function (array $data, \App\Models\ExamSchedule $model): string {
            $window = $this->authoriseExamSchedule((string) ($data['exam_schedule_id'] ?? ''), $model);
            if ($error = \App\Models\ExamSchedule::documentSlotError($data)) {
                throw new \RuntimeException($error);
            }
            $model->addDocumentSlot($window['exam_schedule_id'], $data);

            return 'Required document added.';
        });
    }

    public function removeExamScheduleDocument(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handleExamSchedule($request, $response, function (array $data, \App\Models\ExamSchedule $model): string {
            $esdId = (string) ($data['esd_id'] ?? '');
            $this->authoriseExamSchedule((string) $model->windowForSlot($esdId), $model);
            $model->removeDocumentSlot($esdId);

            return 'Required document removed.';
        });
    }

    /**
     * @return array<string, mixed> the exam window
     */
    private function authoriseExamSchedule(string $examScheduleId, \App\Models\ExamSchedule $model): array
    {
        $window = $examScheduleId !== '' ? $model->findById($examScheduleId) : null;
        $programId = $window ? $model->programFor($examScheduleId) : null;

        if ($programId === null || !$this->coordinates($programId)) {
            throw new \RuntimeException('That exam schedule is not on a program you coordinate.');
        }

        return $window;
    }

    private function authoriseThesisSchedule(string $thesisScheduleId): void
    {
        $schedule = $thesisScheduleId !== '' ? (new \App\Models\ThesisSchedule($this->db))->findById($thesisScheduleId) : null;

        if (!$schedule || !$this->coordinates($schedule['program_id'])) {
            throw new \RuntimeException('Choose a thesis schedule of a program you coordinate.');
        }
    }

    private function handleExamSchedule(
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
            return $this->redirect($response, '/coordinator/exam-schedules');
        }

        try {
            $_SESSION['flash_success'] = $write($data, new \App\Models\ExamSchedule($this->db));
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($response, '/coordinator/exam-schedules');
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
        $allReady = (new \App\Models\ExamReadiness($this->db))
            ->queueForPrograms(array_column($programs, 'program_id'));
        $queue = $this->withoutOwn($allReady);
        $allScheduled = (new \App\Models\ExamMeeting($this->db))->forPrograms(array_column($programs, 'program_id'));
        $scheduled = $this->withoutOwn($allScheduled);

        $qualifications = new \App\Models\ExaminerQualification($this->db);
        $own = new OwnRecord($this->db);
        $bookings = new \App\Models\ExamReadiness($this->db);
        foreach ($queue as &$row) {
            // Only lecturers qualified for this program can be offered —
            // and never the candidate's own lecturer account.
            $candidate = $own->userForStudent($row['student_id']);
            $row['examiners'] = array_values(array_filter(
                $qualifications->qualifiedForProgram($row['program_id']),
                fn (array $examiner): bool => $examiner['user_id'] !== $candidate
            ));
            // A booking is in the queue from the start; the exam can be
            // scheduled once the student's fees and documents are settled.
            $booked = $bookings->window($row['student_id'], $row['student_user_id'], $row['exam_schedule_id']);
            $row['blockers'] = $booked === null
                ? ['This booking is no longer for the stage the student is on.']
                : $booked['blockers'];
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
            'scheduled'   => $scheduled,
            'own_hidden'  => count($allReady) !== count($queue) || count($allScheduled) !== count($scheduled),
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
        $allPending = (new \App\Models\ExaminerAssignment($this->db))
            ->awaitingApproval(array_column($programs, 'program_id'));
        $pending = $this->withoutOwn($allPending);

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
            'own_hidden'  => count($allPending) !== count($pending),
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
            if ((new OwnRecord($this->db))->isStudent($_SESSION['user_id'], (string) $assignments->studentForMeeting($meetingId))) {
                throw new \RuntimeException(self::OWN_RECORD);
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
                ? 'Approved. Nobody has been contacted yet — send the request to the supervisors when you are ready.'
                : 'Recorded as not approved. It stays on record; decide below what happens next.';
        });
    }

    /**
     * The coordinator's own vote at the department meeting. A coordinator
     * who was also invited as a head votes once, as that head.
     */
    public function castVote(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handle($request, $response, function (array $data, SupervisorShortlist $model): string {
            $shortlist = $this->authorisedShortlist($data['shortlist_id'] ?? '', $model);
            if (!$shortlist['meeting_id']) {
                throw new \RuntimeException('Schedule the meeting before voting.');
            }

            $model->castVoteAs(
                $shortlist['meeting_id'],
                $_SESSION['user_id'],
                (string) ($data['vote'] ?? ''),
                trim((string) ($data['comment'] ?? '')) ?: null
            );

            return 'Your vote has been recorded.';
        });
    }

    /**
     * Sends an approved request to every supervisor on the list at once.
     */
    public function sendRequests(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handle($request, $response, function (array $data, SupervisorShortlist $model): string {
            $shortlist = $this->authorisedShortlist($data['shortlist_id'] ?? '', $model);
            $result = $model->sendRequests($shortlist['shortlist_id'], $_SESSION['user_id']);

            $message = $result['sent'] . ' supervisor' . ($result['sent'] === 1 ? '' : 's')
                . ' asked, with ' . SupervisorShortlist::RESPONSE_DAYS . ' days to answer.';
            if ($result['unavailable'] > 0) {
                $message .= ' ' . $result['unavailable'] . ' who could not take a new student '
                    . ($result['unavailable'] === 1 ? 'was' : 'were') . ' passed over.';
            }
            if ($result['outcome'] === 'exhausted') {
                $message .= ' Nobody on the list could be asked, so it is recorded as unsuccessful.';
            }

            return $message;
        });
    }

    /**
     * After a failed request, hands the proposal, the list, or both back
     * to the student to revise.
     */
    public function grantEdit(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->handle($request, $response, function (array $data, SupervisorShortlist $model): string {
            $shortlist = $this->authorisedShortlist($data['shortlist_id'] ?? '', $model);

            $model->grantEdit(
                $shortlist['shortlist_id'],
                ($data['edit_proposal'] ?? '') === '1',
                ($data['edit_shortlist'] ?? '') === '1',
                $_SESSION['user_id']
            );

            return 'Handed back to the student. Nothing more can happen until they revise and send it.';
        });
    }

    /**
     * After a failed request, the coordinator changes the supervisor list
     * themselves. The proposal is never theirs to change.
     */
    public function reviseList(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $data = (array) $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/coordinator/shortlists');
        }

        $model = new SupervisorShortlist($this->db);
        $back = '/coordinator/shortlists/' . ($data['shortlist_id'] ?? '');

        try {
            $shortlist = $this->authorisedShortlist($data['shortlist_id'] ?? '', $model);
            $newId = $model->reviseList($shortlist['shortlist_id'], $this->readChoices($data), $_SESSION['user_id']);
            $_SESSION['flash_success'] = 'Revised list saved as a new request. Schedule a department meeting for it.';
            $back = '/coordinator/shortlists/' . $newId;
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($response, $back);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<int, array{lecturer_id: string, rank: int, preferred_main: bool}>
     */
    private function readChoices(array $data): array
    {
        $ranks = is_array($data['rank'] ?? null) ? $data['rank'] : [];
        $preferred = (string) ($data['preferred_main'] ?? '');

        $choices = [];
        foreach ($ranks as $lecturerId => $rank) {
            if (trim((string) $rank) === '') {
                continue;
            }
            $choices[] = [
                'lecturer_id'    => (string) $lecturerId,
                'rank'           => (int) $rank,
                'preferred_main' => (string) $lecturerId === $preferred,
            ];
        }

        return $choices;
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
            throw new \RuntimeException('That request is not on a program you coordinate.');
        }
        if ($shortlist['student_user_id'] === $_SESSION['user_id']) {
            throw new \RuntimeException(self::OWN_RECORD);
        }

        return $shortlist;
    }

    /**
     * Rows about the signed-in coordinator's own studies, left out.
     * Staff studying for their own degree may be on a program they
     * coordinate; nobody coordinates their own request, exam or result.
     *
     * @param array<int, array<string, mixed>> $rows each with a student_id
     * @return array<int, array<string, mixed>>
     */
    private function withoutOwn(array $rows): array
    {
        $own = (new OwnRecord($this->db))->studentIdsFor($_SESSION['user_id']);
        if ($own === []) {
            return $rows;
        }

        return array_values(array_filter($rows, fn (array $row): bool => !in_array($row['student_id'], $own, true)));
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
