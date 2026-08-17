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
            'active_page'      => 'l-meetings',
            'first_name'       => $_SESSION['first_name'] ?? '',
            'staff_number'     => $lecturer['staff_number'] ?? null,
            'upcoming'         => $meetingModel->findUpcomingForUser($userId),
            'past'             => $meetingModel->findPastForUser($userId),
            'students'         => $lecturerModel->findActiveSupervisions($lecturer['lecturer_id']),
            'other_lecturers'  => $lecturerModel->listAllExcept($userId),
            'pending_grading'  => $examModel->findPendingGradingForLecturer($userId),
            'csrf_token'       => $this->csrfToken(),
            'error'            => $_SESSION['flash_error'] ?? null,
            'success'          => $_SESSION['flash_success'] ?? null,
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

        // Ownership check: only allow scheduling against a proposal that
        // actually belongs to one of this lecturer's active supervisions —
        // a POSTed proposal_id for someone else's student must not work.
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