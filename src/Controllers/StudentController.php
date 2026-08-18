<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Proposal;
use App\Models\Payment;
use App\Models\StudentEnrollment;
use App\Models\ProgramSchedule;
use App\Models\Meeting;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\Twig;
use PDO;

class StudentController
{
    private PDO $db;
    private Twig $view;

    private const FEE_PRIORITY = ['registration', 'thesis_fee', 'tuition', 'examination_fee'];

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
     * Registration (step 1) is "done" once confirmed registration payments
     * for the student's ACTIVE enrollment meet or exceed that cohort's
     * registration rate — OR the registration-fee waiver applies (most
     * recent enrollment ended via approved leave, under the 3-consecutive
     * cap). Partial payments keep it "current", not "done".
     */
    private function registrationStatus(array $student, array $activeEnrollment): array
    {
        $enrollmentModel = new StudentEnrollment($this->db);
        $scheduleModel = new ProgramSchedule($this->db);

        if ($enrollmentModel->registrationFeeWaived($student['student_id'])) {
            return ['done' => true, 'paid' => 0.0, 'required' => 0.0, 'currency' => null, 'waived' => true];
        }

        $rate = $scheduleModel->findRateForType($activeEnrollment['schedule_id'], 'registration');

        if (!$rate) {
            return ['done' => false, 'paid' => 0.0, 'required' => null, 'currency' => null, 'waived' => false];
        }

        $paymentModel = new Payment($this->db);
        $paid = $paymentModel->sumConfirmedByType($student['student_id'], 'registration');

        return [
            'done'     => $paid >= (float) $rate['amount'],
            'paid'     => $paid,
            'required' => (float) $rate['amount'],
            'currency' => $rate['currency'],
            'waived'   => false,
        ];
    }

    /**
     * Returns the next fee type the student owes, walked in a fixed
     * priority order (registration, then thesis_fee, then tuition, then
     * examination_fee). The first type where confirmed payments fall
     * short of the cohort's rate is "next due". Returns null if every
     * fee type is fully paid, or if no rate exists to compare against.
     */
    private function nextFeeDue(array $student, array $activeEnrollment): ?array
    {
        $scheduleModel = new ProgramSchedule($this->db);
        $paymentModel = new Payment($this->db);

        foreach (self::FEE_PRIORITY as $type) {
            $rate = $scheduleModel->findRateForType($activeEnrollment['schedule_id'], $type);
            if (!$rate) {
                continue;
            }

            if ($type === 'registration') {
                $enrollmentModel = new StudentEnrollment($this->db);
                if ($enrollmentModel->registrationFeeWaived($student['student_id'])) {
                    continue;
                }
            }

            $paid = $paymentModel->sumConfirmedByType($student['student_id'], $type);
            $required = (float) $rate['amount'];

            if ($paid < $required) {
                return [
                    'type'      => $type,
                    'paid'      => $paid,
                    'required'  => $required,
                    'remaining' => $required - $paid,
                    'currency'  => $rate['currency'],
                    'due_date'  => $rate['due_date'],
                ];
            }
        }

        return null;
    }

    /**
     * Maps proposal status onto the six-step journey rail. Step 2
     * (Requirements Validation) still has no dedicated tracking model —
     * treated as complete once step 1 clears, same placeholder as before.
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

        $enrollmentModel = new StudentEnrollment($this->db);
        $activeEnrollment = $enrollmentModel->findActive($student['student_id']);

        if (!$activeEnrollment) {
            return $this->view->render($response, 'students/dashboard.twig', [
                'first_name'      => $_SESSION['first_name'] ?? '',
                'active_page'     => 'overview',
                'student_number'  => $student['student_number'] ?? null,
                'no_enrollment'   => true,
            ]);
        }

        $proposalModel = new Proposal($this->db);
        $proposal = $proposalModel->findActiveByStudentId($student['student_id']);

        $meetingModel = new Meeting($this->db);
        $upcomingMeetings = $meetingModel->findUpcomingForStudent($student['student_id'], $userId);

        $registration = $this->registrationStatus($student, $activeEnrollment);
        $nextDue = $this->nextFeeDue($student, $activeEnrollment);

        $supervisorName = null;
        $supervisorStatus = null;
        $supervisorDepartment = null;
        if ($proposal) {
            if (!empty($proposal['assigned_supervisor_id'])) {
                $supervisorStatus = 'assigned';
                $stmt = $this->db->prepare(
                    "SELECT CONCAT(u.first_name, ' ', u.last_name) AS name, l.department
                     FROM lecturers l JOIN users u ON u.user_id = l.user_id
                     WHERE l.lecturer_id = :lecturer_id LIMIT 1"
                );
                $stmt->execute(['lecturer_id' => $proposal['assigned_supervisor_id']]);
                $row = $stmt->fetch();
                $supervisorName = $row['name'] ?? null;
                $supervisorDepartment = $row['department'] ?? null;
            } elseif (!empty($proposal['proposed_supervisor_name'])) {
                $supervisorStatus = 'proposed';
                $supervisorName = $proposal['proposed_supervisor_name'];
            }
        }

        return $this->view->render($response, 'students/dashboard.twig', [
            'first_name'            => $_SESSION['first_name'] ?? '',
            'active_page'           => 'overview',
            'student_number'        => $student['student_number'] ?? null,
            'no_enrollment'         => false,
            'active_enrollment'     => $activeEnrollment,
            'proposal'              => $proposal,
            'rail_step'             => $this->currentRailStep($registration['done'], $proposal),
            'registration'          => $registration,
            'next_due'              => $nextDue,
            'supervisor_name'       => $supervisorName,
            'supervisor_status'     => $supervisorStatus,
            'supervisor_department' => $supervisorDepartment,
            'next_meeting'          => $upcomingMeetings[0] ?? null,
        ]);
    }
}