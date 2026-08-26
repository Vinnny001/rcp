<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Lecturer;
use App\Models\Meeting;
use App\Models\Examination;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

class LecturerMeetingsController
{
    private PDO $db;
    private Twig $twig;

    private const VALID_MEETING_TYPES = ['approval_board', 'supervisory', 'concept_presentation', 'viva'];
    private const VALID_MODES = ['physical', 'virtual', 'hybrid'];
    private const VALID_ATTENDEE_ROLES = ['chairperson', 'examiner', 'supervisor', 'observer'];

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

        $userId = $_SESSION['user_id'];
        $lecturerModel = new Lecturer($this->db);
        $meetingModel = new Meeting($this->db);
        $examModel = new Examination($this->db);

        $lecturer = $lecturerModel->findByUserId($userId);

        if (!$lecturer) {
            $_SESSION['flash_error'] = 'Your lecturer profile could not be found. Contact the registrar.';
            return $this->redirect($response, '/login');
        }

                return $this->twig->render($response, 'lecturers/meetings.twig', [
            'active_page'           => 'l-meetings',
            'first_name'            => $_SESSION['first_name'] ?? '',
            'staff_number'          => $lecturer['staff_number'] ?? null,
            'upcoming'              => $meetingModel->findUpcomingForUser($userId),
            'past'                  => $meetingModel->findPastForUser($userId),
            'students'              => $lecturerModel->findActiveSupervisions($lecturer['lecturer_id']),
            'other_lecturers'       => $lecturerModel->listAllExcept($userId),
            'internal_lecturers'    => $lecturerModel->listInternalLecturersExcept($userId),
            'external_lecturers'    => $lecturerModel->listExternalLecturersExcept($userId),
            'upcoming_exam_windows' => $lecturerModel->findUpcomingExamSchedulesForSupervisees($lecturer['lecturer_id']),
            'pending_grading'       => $examModel->findPendingGradingForLecturer($userId),
            'csrf_token'            => $this->csrfToken(),
            'error'                 => $_SESSION['flash_error'] ?? null,
            'success'               => $_SESSION['flash_success'] ?? null,
        ]);
    }

    public function schedule(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $data = $request->getParsedBody();

        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $lecturerModel = new Lecturer($this->db);
        $lecturer = $lecturerModel->findByUserId($_SESSION['user_id']);

        if (!$lecturer) {
            $_SESSION['flash_error'] = 'Your lecturer profile could not be found.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $ownAssignments = $lecturerModel->findActiveSupervisions($lecturer['lecturer_id']);
        $studentByProposal = null;
        foreach ($ownAssignments as $a) {
            if (($a['proposal_id'] ?? null) === ($data['proposal_id'] ?? null)) {
                $studentByProposal = $a;
                break;
            }
        }

        $meetingType = $data['meeting_type'] ?? '';
        $date = trim((string) ($data['date'] ?? ''));
        $time = trim((string) ($data['time'] ?? ''));
        $mode = $data['mode'] ?? '';
        $location = trim((string) ($data['location'] ?? ''));
        $virtualLink = trim((string) ($data['virtual_link'] ?? ''));
        $aiNotes = !empty($data['ai_notes_enabled']);
        $includeStudent = !empty($data['include_student']);

        $extraLecturerUserIds = array_filter((array) ($data['attendee_lecturers'] ?? []));
        $lecturerAttendeeRole = $data['lecturer_attendee_role'] ?? 'examiner';

        $errors = [];
        if (!$studentByProposal) {
            $errors[] = 'Please select one of your supervised students.';
        }
        if (!in_array($meetingType, self::VALID_MEETING_TYPES, true)) {
            $errors[] = 'Please select a valid meeting type.';
        }
        if (!in_array($mode, self::VALID_MODES, true)) {
            $errors[] = 'Please select a valid mode.';
        }
        if ($date === '' || $time === '') {
            $errors[] = 'Please provide a date and time.';
        }
        if (in_array($mode, ['physical', 'hybrid'], true) && $location === '') {
            $errors[] = 'Please provide a location for a physical or hybrid meeting.';
        }
        if (in_array($mode, ['virtual', 'hybrid'], true) && $virtualLink === '') {
            $errors[] = 'Please provide a virtual link for a virtual or hybrid meeting.';
        }
        if ($extraLecturerUserIds && !in_array($lecturerAttendeeRole, self::VALID_ATTENDEE_ROLES, true)) {
            $errors[] = 'Please select a valid role for invited lecturers.';
        }

        if ($errors) {
            $_SESSION['flash_error'] = implode(' ', $errors);
            return $this->redirect($response, '/lecturer/meetings');
        }

        $meetingModel = new Meeting($this->db);
        $scheduledAt = $date . ' ' . $time . ':00';

        try {
            $meetingId = $meetingModel->create($studentByProposal['proposal_id'], [
                'meeting_type'     => $meetingType,
                'scheduled_at'     => $scheduledAt,
                'mode'             => $mode,
                'location'         => $location,
                'virtual_link'     => $virtualLink,
                'ai_notes_enabled' => $aiNotes,
            ], $_SESSION['user_id']);

            $meetingModel->addAttendee($meetingId, $_SESSION['user_id'], 'supervisor');

            $alreadyAdded = [$_SESSION['user_id']];

            if ($includeStudent && !empty($studentByProposal['student_user_id'])) {
                $meetingModel->addAttendee($meetingId, $studentByProposal['student_user_id'], 'student');
                $alreadyAdded[] = $studentByProposal['student_user_id'];
            }

            foreach ($extraLecturerUserIds as $lecturerUserId) {
                if (!in_array($lecturerUserId, $alreadyAdded, true)) {
                    $meetingModel->addAttendee($meetingId, $lecturerUserId, $lecturerAttendeeRole);
                    $alreadyAdded[] = $lecturerUserId;
                }
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not schedule the meeting: ' . $e->getMessage();
            return $this->redirect($response, '/lecturer/meetings');
        }

        $_SESSION['flash_success'] = 'Meeting scheduled.';
        return $this->redirect($response, '/lecturer/meetings');
    }

    /**
     * Schedules a meeting constrained to a specific exam_schedule window
     * — must occur before that exam_schedule's ends_at. Used for viva /
     * document-review meetings tied to a formal exam period, distinct
     * from schedule() above which is unconstrained.
     */
        public function scheduleForExam(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $lecturerModel = new Lecturer($this->db);
        $lecturer = $lecturerModel->findByUserId($_SESSION['user_id']);
        if (!$lecturer) {
            $_SESSION['flash_error'] = 'Your lecturer profile could not be found.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $examScheduleId = trim((string) ($data['exam_schedule_id'] ?? ''));
        $proposalId = trim((string) ($data['proposal_id'] ?? ''));

        $valid = $lecturerModel->findUpcomingExamSchedulesForSupervisees($lecturer['lecturer_id']);
        $match = null;
        foreach ($valid as $v) {
            if ($v['exam_schedule_id'] === $examScheduleId && $v['proposal_id'] === $proposalId) {
                $match = $v;
                break;
            }
        }

        if (!$match) {
            $_SESSION['flash_error'] = 'That exam schedule is not associated with one of your supervisees.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $meetingType = $data['meeting_type'] ?? 'viva';
        $date = trim((string) ($data['date'] ?? ''));
        $time = trim((string) ($data['time'] ?? ''));
        $mode = $data['mode'] ?? 'physical';
        $location = trim((string) ($data['location'] ?? ''));
        $virtualLink = trim((string) ($data['virtual_link'] ?? ''));
        $includeStudent = !empty($data['include_student']);

        $attendeeUserIds = array_values(array_filter((array) ($data['attendee_lecturers'] ?? [])));
        $attendeeRoles = array_values((array) ($data['attendee_roles'] ?? []));

        $errors = [];
        if (!in_array($meetingType, self::VALID_MEETING_TYPES, true)) {
            $errors[] = 'Please select a valid meeting type.';
        }
        if (!in_array($mode, self::VALID_MODES, true)) {
            $errors[] = 'Please select a valid mode.';
        }
        if ($date === '' || $time === '') {
            $errors[] = 'Please provide a date and time.';
        }
        if (in_array($mode, ['physical', 'hybrid'], true) && $location === '') {
            $errors[] = 'Please provide a location for a physical or hybrid meeting.';
        }
        if (in_array($mode, ['virtual', 'hybrid'], true) && $virtualLink === '') {
            $errors[] = 'Please provide a virtual link for a virtual or hybrid meeting.';
        }

        // Server-side exam_type enforcement — never trust the client-side
        // filtered list alone. internal exam -> internal examiners only,
        // external -> external only, hybrid -> either.
        foreach ($attendeeUserIds as $i => $uid) {
            $role = $attendeeRoles[$i] ?? '';
            if (!in_array($role, self::VALID_ATTENDEE_ROLES, true)) {
                $errors[] = 'Please select a valid role for every invited attendee.';
                break;
            }
            if ($match['exam_type'] !== 'hybrid') {
                $type = $lecturerModel->getTypeByUserId($uid);
                if ($type !== $match['exam_type']) {
                    $errors[] = 'Only ' . $match['exam_type'] . ' lecturers can be invited to this ' . $match['exam_type'] . ' exam.';
                    break;
                }
            }
        }

        if ($errors) {
            $_SESSION['flash_error'] = implode(' ', $errors);
            return $this->redirect($response, '/lecturer/meetings');
        }

        $meetingModel = new Meeting($this->db);
        $meetingId = $meetingModel->createForExamSchedule($proposalId, $examScheduleId, [
            'meeting_type'     => $meetingType,
            'scheduled_at'     => $date . ' ' . $time . ':00',
            'mode'             => $mode,
            'location'         => $location,
            'virtual_link'     => $virtualLink,
            'ai_notes_enabled' => !empty($data['ai_notes_enabled']),
        ], $_SESSION['user_id']);

        if (!$meetingId) {
            $_SESSION['flash_error'] = 'That time is after the exam schedule\'s deadline. Please choose an earlier time.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $meetingModel->addAttendee($meetingId, $_SESSION['user_id'], 'supervisor');

        $alreadyAdded = [$_SESSION['user_id']];

        if ($includeStudent && !empty($match['student_user_id'])) {
            $meetingModel->addAttendee($meetingId, $match['student_user_id'], 'student');
            $alreadyAdded[] = $match['student_user_id'];
        }

        foreach ($attendeeUserIds as $i => $uid) {
            if (!in_array($uid, $alreadyAdded, true)) {
                $meetingModel->addAttendee($meetingId, $uid, $attendeeRoles[$i]);
                $alreadyAdded[] = $uid;
            }
        }

        $_SESSION['flash_success'] = 'Meeting scheduled within the exam window.';
        return $this->redirect($response, '/lecturer/meetings');
    }

    public function review(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $meetingId = $args['id'] ?? '';
        $meetingModel = new Meeting($this->db);
        $meeting = $meetingModel->findById($meetingId);

        if (!$meeting) {
            $_SESSION['flash_error'] = 'Meeting not found.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $myRole = $meetingModel->isAttendee($meetingId, $_SESSION['user_id']);
        if (!$myRole || !in_array($myRole, ['examiner', 'chairperson', 'supervisor'], true)) {
            $_SESSION['flash_error'] = 'You are not authorized to review this meeting.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $scoreModel = new \App\Models\ExaminationScore($this->db);
        $documents = $scoreModel->findByMeeting($meetingId, $_SESSION['user_id']);

        return $this->twig->render($response, 'lecturers/meeting_review.twig', [
            'active_page' => 'l-meetings',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'meeting'     => $meeting,
            'my_role'     => $myRole,
            'documents'   => $documents,
            'csrf_token'  => $this->csrfToken(),
            'error'       => $_SESSION['flash_error'] ?? null,
            'success'     => $_SESSION['flash_success'] ?? null,
        ]);
    }

        public function submitReview(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $meetingId = $args['id'] ?? '';
        $meetingModel = new Meeting($this->db);
        $meeting = $meetingModel->findById($meetingId);
        $myRole = $meeting ? $meetingModel->isAttendee($meetingId, $_SESSION['user_id']) : null;

        if (!$meeting || !$myRole || !in_array($myRole, ['examiner', 'chairperson', 'supervisor'], true)) {
            $_SESSION['flash_error'] = 'You are not authorized to review this meeting.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $scoreModel = new \App\Models\ExaminationScore($this->db);
        $examDocIds = (array) ($data['exam_document_id'] ?? []);
        $scores = (array) ($data['score'] ?? []);
        $remarks = (array) ($data['remarks'] ?? []);

        $recorded = 0;
        $skipped = 0;

        foreach ($examDocIds as $i => $examDocId) {
            $score = $scores[$i] ?? null;
            if ($score === null || $score === '' || !is_numeric($score) || $score < 0 || $score > 100) {
                continue;
            }

            $pidStmt = $this->db->prepare("SELECT proposal_id FROM exam_documents WHERE exam_document_id = :id LIMIT 1");
            $pidStmt->execute(['id' => $examDocId]);
            $proposalId = $pidStmt->fetchColumn();
            if (!$proposalId) {
                continue;
            }

            $ok = $scoreModel->submit($examDocId, $proposalId, $_SESSION['user_id'], (float) $score, trim((string) ($remarks[$i] ?? '')) ?: null);
            $ok ? $recorded++ : $skipped++;
        }

        if ($recorded && $skipped) {
            $_SESSION['flash_success'] = $recorded . ' score(s) recorded. ' . $skipped . ' were skipped — you\'ve already reviewed those.';
        } elseif ($recorded) {
            $_SESSION['flash_success'] = 'Review submitted.';
        } elseif ($skipped) {
            $_SESSION['flash_error'] = 'You have already reviewed every document here — no changes were made.';
        } else {
            $_SESSION['flash_error'] = 'No valid scores were provided.';
        }

        return $this->redirect($response, '/lecturer/meetings/' . $meetingId . '/review');
    }

            public function editForm(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $meetingId = $args['id'] ?? '';
        $meetingModel = new Meeting($this->db);
        $meeting = $meetingModel->findById($meetingId);

        if (!$meeting || $meeting['created_by'] !== $_SESSION['user_id']) {
            $_SESSION['flash_error'] = 'You are not authorized to edit this meeting.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $lecturerModel = new Lecturer($this->db);
        $attendees = $meetingModel->findAttendees($meetingId);

        // Resolve the student tied to this meeting's proposal, so they
        // can be re-invited even after being removed — they won't show
        // up in "existing attendees" once unchecked+saved, and they're
        // not a lecturer, so the invite-new dropdown never includes them.
        $studentStmt = $this->db->prepare(
            "SELECT s.user_id, CONCAT(u.first_name, ' ', u.last_name) AS name
             FROM thesis_proposals tp
             JOIN students s ON s.student_id = tp.student_id
             JOIN users u ON u.user_id = s.user_id
             WHERE tp.proposal_id = :proposal_id LIMIT 1"
        );
        $studentStmt->execute(['proposal_id' => $meeting['proposal_id']]);
        $student = $studentStmt->fetch();

        $studentCurrentlyIncluded = false;
        foreach ($attendees as $att) {
            if ($student && $att['user_id'] === $student['user_id']) {
                $studentCurrentlyIncluded = true;
                break;
            }
        }

        return $this->twig->render($response, 'lecturers/meeting_edit.twig', [
            'active_page'    => 'l-meetings',
            'first_name'     => $_SESSION['first_name'] ?? '',
            'meeting'        => $meeting,
            'attendees'      => $attendees,
            'other_lecturers'=> $lecturerModel->listAllExcept($_SESSION['user_id']),
            'student'        => $student,
            'student_included' => $studentCurrentlyIncluded,
            'csrf_token'     => $this->csrfToken(),
            'error'          => $_SESSION['flash_error'] ?? null,
        ]);
    }

        public function updateMeeting(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $meetingId = $args['id'] ?? '';
        $meetingModel = new Meeting($this->db);
        $meeting = $meetingModel->findById($meetingId);

        if (!$meeting || $meeting['created_by'] !== $_SESSION['user_id']) {
            $_SESSION['flash_error'] = 'You are not authorized to edit this meeting.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        if ($meeting['status'] !== 'scheduled') {
            $_SESSION['flash_error'] = 'This meeting has already started or completed and can no longer be edited.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $detailsChanged = $meetingModel->update($meetingId, [
            'meeting_type' => $data['meeting_type'] ?? $meeting['meeting_type'],
            'scheduled_at' => trim((string) ($data['date'] ?? '')) . ' ' . trim((string) ($data['time'] ?? '')) . ':00',
            'mode'         => $data['mode'] ?? $meeting['mode'],
            'location'     => $data['location'] ?? '',
            'virtual_link' => $data['virtual_link'] ?? '',
        ]);

        // Attendee sync runs regardless of whether the core details
        // actually changed — these are independent operations, and a
        // no-op update() (nothing in date/time/mode/location differed)
        // should never block attendee changes.
        $keepUserIds = array_filter((array) ($data['keep_attendees'] ?? []));
        $existing = $meetingModel->findAttendees($meetingId);
        foreach ($existing as $att) {
            if ($att['role_in_meeting'] !== 'supervisor' && !in_array($att['user_id'], $keepUserIds, true)) {
                $meetingModel->removeAttendee($meetingId, $att['user_id']);
            }
        }

        $newUserIds = array_filter((array) ($data['new_attendees'] ?? []));
        $newRoles = (array) ($data['new_attendee_roles'] ?? []);
        foreach ($newUserIds as $i => $uid) {
            $role = $newRoles[$i] ?? 'observer';
            $meetingModel->addAttendee($meetingId, $uid, $role);
        }

        $_SESSION['flash_success'] = 'Meeting updated.';
        return $this->redirect($response, '/lecturer/meetings');
    }

    public function grade(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $data = $request->getParsedBody();

        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $graderId = $data['grader_id'] ?? '';
        $scoreRaw = $data['score'] ?? '';
        $feedback = trim((string) ($data['feedback'] ?? '')) ?: null;

        if (!$graderId || !is_numeric($scoreRaw) || (float) $scoreRaw < 0 || (float) $scoreRaw > 100) {
            $_SESSION['flash_error'] = 'Please provide a valid score between 0 and 100.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $examModel = new Examination($this->db);
        $examinationId = $examModel->submitGrade($graderId, $_SESSION['user_id'], (float) $scoreRaw, $feedback);

        if ($examinationId) {
            $examModel->maybeFinalize($examinationId);
            $_SESSION['flash_success'] = 'Grade submitted.';
        } else {
            $_SESSION['flash_error'] = 'That grading assignment could not be found, or has already been graded.';
        }

        return $this->redirect($response, '/lecturer/meetings');
    }
}