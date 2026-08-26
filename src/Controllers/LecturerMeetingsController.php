<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\DocumentReviewScore;
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

    /**
     * Statuses a supervisor may move a meeting into by hand. 'scheduled'
     * is absent deliberately: it's the starting state, and a meeting
     * that has been cancelled or completed isn't reopened.
     */
    private const SETTABLE_STATUSES = ['in_progress', 'completed', 'cancelled'];

    /**
     * Who may score a document attached to a general meeting. Narrower
     * than the exam-document rule below — a chairperson runs the room
     * but isn't scoring the work.
     */
    private const DOCUMENT_REVIEW_ROLES = ['examiner', 'supervisor'];

    /** Who may score documents submitted against a formal exam window. */
    private const EXAM_REVIEW_ROLES = ['examiner', 'chairperson', 'supervisor'];

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
     * The secure code is the supervisor's to read out — it proves the
     * people scoring were actually in the room. Any meeting this user
     * isn't the supervisor of gets the code stripped before it reaches
     * a template; the ones they do supervise get a code minted if the
     * meeting predates the feature.
     *
     * @param array<int, array<string, mixed>> $meetings
     * @return array<int, array<string, mixed>>
     */
    private function applySecureCodeVisibility(array $meetings, Meeting $meetingModel): array
    {
        return array_map(function (array $meeting) use ($meetingModel) {
            if (($meeting['my_role'] ?? null) === 'supervisor') {
                $meeting['secure_code'] = $meetingModel->ensureSecureCode($meeting['meeting_id']);
            } else {
                unset($meeting['secure_code']);
            }
            return $meeting;
        }, $meetings);
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

        // Supervisees who also hold a lecturer account must not appear
        // in any invite dropdown — they're the subject of the meeting,
        // not a colleague attending it.
        $superviseeLecturers = $lecturerModel->findSuperviseeUserIdsWhoAreLecturers($lecturer['lecturer_id']);

        // Flashes are read once and cleared, so a message doesn't
        // linger on the next visit to the page.
        $error = $_SESSION['flash_error'] ?? null;
        $success = $_SESSION['flash_success'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);

        return $this->twig->render($response, 'lecturers/meetings.twig', [
            'active_page'           => 'l-meetings',
            'first_name'            => $_SESSION['first_name'] ?? '',
            'staff_number'          => $lecturer['staff_number'] ?? null,
            'upcoming'              => $this->applySecureCodeVisibility($meetingModel->findUpcomingForUser($userId), $meetingModel),
            'past'                  => $this->applySecureCodeVisibility($meetingModel->findPastForUser($userId), $meetingModel),
            'students'              => $lecturerModel->findActiveSupervisions($lecturer['lecturer_id']),
            'supervisee_documents'  => $lecturerModel->findSuperviseeDocuments($lecturer['lecturer_id']),
            'other_lecturers'       => $lecturerModel->listAllExcept($userId, $superviseeLecturers),
            'internal_lecturers'    => $lecturerModel->listInternalLecturersExcept($userId, $superviseeLecturers),
            'external_lecturers'    => $lecturerModel->listExternalLecturersExcept($userId, $superviseeLecturers),
            'upcoming_exam_windows' => $lecturerModel->findUpcomingExamSchedulesForSupervisees($lecturer['lecturer_id']),
            'pending_grading'       => $examModel->findPendingGradingForLecturer($userId),
            'csrf_token'            => $this->csrfToken(),
            'error'                 => $error,
            'success'               => $success,
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

        $attendeeUserIds = array_values(array_filter((array) ($data['attendee_lecturers'] ?? [])));
        $attendeeRoles = array_values((array) ($data['attendee_roles'] ?? []));
        $documentIds = array_values(array_filter((array) ($data['review_documents'] ?? [])));

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

        $studentUserId = $studentByProposal['student_user_id'] ?? null;

        foreach ($attendeeUserIds as $i => $uid) {
            if (!in_array($attendeeRoles[$i] ?? '', self::VALID_ATTENDEE_ROLES, true)) {
                $errors[] = 'Please select a valid role for every invited attendee.';
                break;
            }
            // The student may hold a lecturer account. The invite list
            // already hides them, but a hand-crafted POST must not get
            // them in as an examiner of their own work.
            if ($studentUserId && $uid === $studentUserId) {
                $errors[] = 'The student this meeting is about cannot be invited as a lecturer attendee.';
                break;
            }
        }

        // Only the selected student's own documents may be attached.
        if ($documentIds && $studentUserId) {
            $ownedIds = array_column($lecturerModel->findDocumentsForStudentUser($studentUserId), 'document_id');
            if (array_diff($documentIds, $ownedIds)) {
                $errors[] = 'One or more selected documents do not belong to that student.';
            }
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

            if ($includeStudent && $studentUserId) {
                $meetingModel->addAttendee($meetingId, $studentUserId, 'student');
                $alreadyAdded[] = $studentUserId;
            }

            foreach ($attendeeUserIds as $i => $lecturerUserId) {
                if (!in_array($lecturerUserId, $alreadyAdded, true)) {
                    $meetingModel->addAttendee($meetingId, $lecturerUserId, $attendeeRoles[$i]);
                    $alreadyAdded[] = $lecturerUserId;
                }
            }

            foreach ($documentIds as $documentId) {
                $meetingModel->attachDocument($meetingId, $documentId, $_SESSION['user_id']);
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not schedule the meeting: ' . $e->getMessage();
            return $this->redirect($response, '/lecturer/meetings');
        }

        $_SESSION['flash_success'] = 'Meeting scheduled. Your attendance code is shown on the meeting card — share it with attendees during the meeting.';
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
            if (!empty($match['student_user_id']) && $uid === $match['student_user_id']) {
                $errors[] = 'The student this meeting is about cannot be invited as a lecturer attendee.';
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

        $_SESSION['flash_success'] = 'Meeting scheduled within the exam window. Your attendance code is shown on the meeting card.';
        return $this->redirect($response, '/lecturer/meetings');
    }

    /**
     * Moves a meeting to a new status, recording why. Cancelling is the
     * common case and the reason is mandatory there — the student sees
     * it on their meetings page, so "cancelled" alone isn't an answer.
     */
    public function changeStatus(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
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
            $_SESSION['flash_error'] = 'You are not authorized to change this meeting.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $status = (string) ($data['status'] ?? 'cancelled');
        $reason = trim((string) ($data['status_description'] ?? ''));

        if (!in_array($status, self::SETTABLE_STATUSES, true)) {
            $_SESSION['flash_error'] = 'That is not a status a meeting can be moved into.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        if ($status === 'cancelled' && $reason === '') {
            $_SESSION['flash_error'] = 'Please give a reason for cancelling — the student will see it.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        if (!$meetingModel->changeStatus($meetingId, $status, $reason ?: null)) {
            $_SESSION['flash_error'] = 'This meeting has already been completed or cancelled — its status can no longer be changed.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $_SESSION['flash_success'] = $status === 'cancelled'
            ? 'Meeting cancelled. The reason has been recorded and is visible to the student.'
            : 'Meeting marked as ' . str_replace('_', ' ', $status) . '.';

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
        if (!$myRole || !in_array($myRole, self::EXAM_REVIEW_ROLES, true)) {
            $_SESSION['flash_error'] = 'You are not authorized to review this meeting.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        $scoreModel = new \App\Models\ExaminationScore($this->db);
        $reviewModel = new DocumentReviewScore($this->db);

        // Documents attached directly to the meeting — the general
        // scheduler's optional picks, scored into document_review_scores
        // because they have no exam window to hang an exam_document off.
        $meetingDocuments = [];
        foreach ($meetingModel->findDocuments($meetingId) as $doc) {
            $doc['my_review'] = $reviewModel->findMine($doc['document_id'], $_SESSION['user_id']);
            $meetingDocuments[] = $doc;
        }

        // Mint the code for everyone who opens this page, not just the
        // supervisor: an examiner reaching a pre-secure-code meeting
        // before the supervisor ever opened it would otherwise face a
        // form whose code check can never pass. Minting is separate
        // from showing — only the supervisor is handed the value.
        $code = $meetingModel->ensureSecureCode($meetingId);
        $secureCode = $myRole === 'supervisor' ? $code : null;
        unset($meeting['secure_code']);

        $error = $_SESSION['flash_error'] ?? null;
        $success = $_SESSION['flash_success'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);

        return $this->twig->render($response, 'lecturers/meeting_review.twig', [
            'active_page'       => 'l-meetings',
            'first_name'        => $_SESSION['first_name'] ?? '',
            'meeting'           => $meeting,
            'my_role'           => $myRole,
            'documents'         => $scoreModel->findByMeeting($meetingId, $_SESSION['user_id']),
            'meeting_documents' => $meetingDocuments,
            'can_score_documents' => in_array($myRole, self::DOCUMENT_REVIEW_ROLES, true),
            'secure_code'       => $secureCode,
            'csrf_token'        => $this->csrfToken(),
            'error'             => $error,
            'success'           => $success,
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

        if (!$meeting || !$myRole || !in_array($myRole, self::EXAM_REVIEW_ROLES, true)) {
            $_SESSION['flash_error'] = 'You are not authorized to review this meeting.';
            return $this->redirect($response, '/lecturer/meetings');
        }

        // Attendance gate: the code is only known to people the
        // supervisor read it out to, which is to say people who were
        // actually in the meeting.
        if (!$meetingModel->verifySecureCode($meetingId, (string) ($data['secure_code'] ?? ''))) {
            $_SESSION['flash_error'] = 'That attendance code is not correct. Ask the supervisor for the code given out during the meeting.';
            return $this->redirect($response, '/lecturer/meetings/' . $meetingId . '/review');
        }

        $recorded = 0;
        $skipped = 0;

        $this->recordExamDocumentScores($data, $recorded, $skipped);
        $this->recordMeetingDocumentScores($meetingId, $myRole, $meetingModel, $data, $recorded, $skipped);

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

    /**
     * Scores for documents submitted against a formal exam window,
     * which land in examination_scores keyed by exam_document.
     */
    private function recordExamDocumentScores(array $data, int &$recorded, int &$skipped): void
    {
        $scoreModel = new \App\Models\ExaminationScore($this->db);
        $examDocIds = (array) ($data['exam_document_id'] ?? []);
        $scores = (array) ($data['score'] ?? []);
        $remarks = (array) ($data['remarks'] ?? []);

        foreach ($examDocIds as $i => $examDocId) {
            $score = $scores[$i] ?? null;
            if (!$this->isValidScore($score)) {
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
    }

    /**
     * Scores for documents attached to the meeting itself. Only an
     * invited examiner or the supervisor may record these — a
     * chairperson or observer sitting in on the meeting cannot.
     */
    private function recordMeetingDocumentScores(
        string $meetingId,
        string $myRole,
        Meeting $meetingModel,
        array $data,
        int &$recorded,
        int &$skipped
    ): void {
        $documentIds = (array) ($data['review_document_id'] ?? []);
        if (!$documentIds) {
            return;
        }

        if (!in_array($myRole, self::DOCUMENT_REVIEW_ROLES, true)) {
            return;
        }

        // Only documents actually attached to this meeting are scorable,
        // so a forged document_id can't be scored through this form.
        $attached = array_column($meetingModel->findDocuments($meetingId), 'document_id');

        $reviewModel = new DocumentReviewScore($this->db);
        $scores = (array) ($data['review_score'] ?? []);
        $comments = (array) ($data['review_comment'] ?? []);

        foreach ($documentIds as $i => $documentId) {
            $score = $scores[$i] ?? null;
            if (!$this->isValidScore($score) || !in_array($documentId, $attached, true)) {
                continue;
            }

            $ok = $reviewModel->submit(
                $documentId,
                $_SESSION['user_id'],
                (float) $score,
                trim((string) ($comments[$i] ?? '')) ?: null
            );
            $ok ? $recorded++ : $skipped++;
        }
    }

    private function isValidScore(mixed $score): bool
    {
        return $score !== null && $score !== '' && is_numeric($score) && $score >= 0 && $score <= 100;
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

        // The student tied to this meeting's proposal. Needed twice
        // over: so they can be re-invited after being removed (they
        // aren't a lecturer, so the invite dropdown never lists them),
        // and so that if they DO hold a lecturer account they're kept
        // out of that dropdown as a colleague.
        $student = $meetingModel->findSubjectStudent($meetingId);

        $studentCurrentlyIncluded = false;
        foreach ($attendees as $att) {
            if ($student && $att['user_id'] === $student['user_id']) {
                $studentCurrentlyIncluded = true;
                break;
            }
        }

        $attachedIds = array_column($meetingModel->findDocuments($meetingId), 'document_id');

        $error = $_SESSION['flash_error'] ?? null;
        unset($_SESSION['flash_error']);

        $secureCode = $meetingModel->ensureSecureCode($meetingId);
        unset($meeting['secure_code']);

        return $this->twig->render($response, 'lecturers/meeting_edit.twig', [
            'active_page'      => 'l-meetings',
            'first_name'       => $_SESSION['first_name'] ?? '',
            'meeting'          => $meeting,
            'attendees'        => $attendees,
            'other_lecturers'  => $lecturerModel->listAllExcept($_SESSION['user_id'], $student ? [$student['user_id']] : []),
            'student'          => $student,
            'student_included' => $studentCurrentlyIncluded,
            'student_documents' => $student ? $lecturerModel->findDocumentsForStudentUser($student['user_id']) : [],
            'attached_document_ids' => $attachedIds,
            'secure_code'      => $secureCode,
            'csrf_token'       => $this->csrfToken(),
            'error'            => $error,
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

        $student = $meetingModel->findSubjectStudent($meetingId);
        $studentUserId = $student['user_id'] ?? null;

        $meetingModel->update($meetingId, [
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

            if (!in_array($role, [...self::VALID_ATTENDEE_ROLES, 'student'], true)) {
                continue;
            }

            // The student attends as the student or not at all, even if
            // they also hold a lecturer account.
            if ($uid === $studentUserId && $role !== 'student') {
                continue;
            }

            $meetingModel->addAttendee($meetingId, $uid, $role);
        }

        // Review documents are re-synced wholesale: whatever is ticked
        // on the form is the meeting's document set afterwards.
        $documentIds = array_values(array_filter((array) ($data['review_documents'] ?? [])));
        if ($studentUserId) {
            $lecturerModel = new Lecturer($this->db);
            $ownedIds = array_column($lecturerModel->findDocumentsForStudentUser($studentUserId), 'document_id');
            $documentIds = array_values(array_intersect($documentIds, $ownedIds));
        } else {
            $documentIds = [];
        }
        $meetingModel->syncDocuments($meetingId, $documentIds, $_SESSION['user_id']);

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

        if (!$graderId || !$this->isValidScore($scoreRaw)) {
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
