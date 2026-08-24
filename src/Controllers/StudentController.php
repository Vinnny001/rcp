<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Proposal;
use App\Models\Meeting;
use App\Models\ThesisRegistration;
use App\Models\ThesisPayment;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use PDO;

class StudentController
{
    private PDO $db;
    private Twig $view;

    public function __construct(PDO $db, Twig $view)
    {
        $this->db = $db;
        $this->view = $view;
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
        $stmt = $this->db->prepare(
            "SELECT student_id, student_number FROM students WHERE user_id = :user_id LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Registration (step 1) now means THESIS registration, not program
     * enrollment — there is no program registration in this flow anymore.
     * "Done" means the thesis_registration fee is fully paid. If the
     * student hasn't registered for thesis at all yet, this is naturally
     * "not done" — computeOwed() on a null registration isn't called;
     * we short-circuit to "not done" directly.
     */
    private function registrationStatus(?array $thesisRegistration): array
    {
        if (!$thesisRegistration) {
            return ['done' => false, 'paid' => 0.0, 'required' => null, 'currency' => null, 'registered' => false];
        }

        $regModel = new ThesisRegistration($this->db);
        $owed = $regModel->computeOwed($thesisRegistration);

        // If the first owed item is thesis_registration, it's still unpaid.
        // If owed is empty, or the first item is a review fee, registration
        // itself is fully settled.
        $regStillOwed = null;
        foreach ($owed as $item) {
            if ($item['fee_type'] === 'thesis_registration') {
                $regStillOwed = $item;
                break;
            }
        }

        if ($regStillOwed) {
            return [
                'done'       => false,
                'paid'       => $regStillOwed['paid'],
                'required'   => $regStillOwed['required'],
                'currency'   => $regStillOwed['currency'],
                'registered' => true,
            ];
        }

        return ['done' => true, 'paid' => 0.0, 'required' => 0.0, 'currency' => null, 'registered' => true];
    }

    /**
     * Maps status onto the six-step journey rail. Step 1 is now thesis
     * registration (paid). Step 2 (Requirements Validation) still has no
     * dedicated tracking — treated as complete once step 1 clears.
     */
    private function currentRailStep(bool $registrationDone, ?array $proposal): int
    {
        if (!$registrationDone) {
            return 1;
        }
        if (!$proposal) {
            return 3;
        }
        return match ($proposal['status']) {
            'draft', 'submitted', 'under_review', 'revision_required', 'rejected' => 3,
            'approved' => 4,
            default => 3,
        };
    }

    public function dashboard(Request $request, Response $response): Response
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $userId = $_SESSION['user_id'];
        $student = $this->getStudentRecord($userId);

        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $response->withHeader('Location', '/login')->withStatus(302);
        }

        // Thesis registration — the new "registration" step. No longer
        // gated on program enrollment; a student can have proposal,
        // supervisor, and meeting data with zero program enrollment.
        $thesisRegModel = new ThesisRegistration($this->db);
        $thesisRegistration = $thesisRegModel->findActiveByStudentId($student['student_id']);

        $registration = $this->registrationStatus($thesisRegistration);

        $thesisOwed = null;
        if ($thesisRegistration) {
            $owedList = $thesisRegModel->computeOwed($thesisRegistration);
            $thesisOwed = $owedList[0] ?? null;
        }

        $proposalModel = new Proposal($this->db);
        $proposal = $proposalModel->findActiveByStudentId($student['student_id']);

        $meetingModel = new Meeting($this->db);
        $upcomingMeetings = $meetingModel->findUpcomingForStudent($student['student_id'], $userId);

        $supervisorName = null;
        $supervisorStatus = null;
        $supervisorDepartment = null;
        if ($proposal) {
            if (!empty($proposal['assigned_supervisor_id'])) {
                $supervisorStatus = 'assigned';
                                $stmt = $this->db->prepare(
                    "SELECT
                        CONCAT(u.first_name, ' ', u.last_name) AS name,
                        d.name AS internal_department,
                        el.department AS external_department
                     FROM lecturers l
                     JOIN users u ON u.user_id = l.user_id
                     LEFT JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
                     LEFT JOIN departments d ON d.department_id = il.department_id
                     LEFT JOIN external_lecturers el ON el.lecturer_id = l.lecturer_id
                     WHERE l.lecturer_id = :lecturer_id LIMIT 1"
                );
                $stmt->execute(['lecturer_id' => $proposal['assigned_supervisor_id']]);
                $row = $stmt->fetch();
                $supervisorName = $row['name'] ?? null;
                $supervisorDepartment = $row['internal_department'] ?? $row['external_department'] ?? null;
            } elseif (!empty($proposal['proposed_supervisor_name'])) {
                $supervisorStatus = 'proposed';
                $supervisorName = $proposal['proposed_supervisor_name'];
            }
        }

        return $this->view->render($response, 'students/dashboard.twig', [
            'first_name'              => $_SESSION['first_name'] ?? '',
            'active_page'             => 'overview',
            'student_number'          => $student['student_number'] ?? null,
            'proposal'                => $proposal,
            'rail_step'               => $this->currentRailStep($registration['done'], $proposal),
            'registration'            => $registration,
            'thesis_owed'             => $thesisOwed,
            'not_registered_for_thesis' => !$thesisRegistration,
            'supervisor_name'         => $supervisorName,
            'supervisor_status'       => $supervisorStatus,
            'supervisor_department'   => $supervisorDepartment,
            'next_meeting'            => $upcomingMeetings[0] ?? null,
        ]);
    }
}