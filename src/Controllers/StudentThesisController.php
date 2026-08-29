<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ThesisRegistration;
use App\Models\ThesisPayment;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

class StudentThesisController
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
        $stmt = $this->db->prepare(
            "SELECT student_id, student_number FROM students WHERE user_id = :user_id LIMIT 1"
        );
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

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $this->redirect($response, $redirect);
        }

        $student = $this->getStudentRecord($_SESSION['user_id']);
        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/login');
        }

        $regModel = new ThesisRegistration($this->db);
        $registration = $regModel->findActiveByStudentId($student['student_id']);

        if (!$registration && !$regModel->isRegisteredForThesis($student['student_id'])) {
            return $this->twig->render($response, 'students/thesis-fees.twig', [
                'active_page'    => 'thesis',
                'first_name'     => $_SESSION['first_name'] ?? '',
                'student_number' => $student['student_number'] ?? null,
                'not_registered' => true,
                'csrf_token'     => $this->csrfToken(),
                'error'          => $_SESSION['flash_error'] ?? null,
                'success'        => $_SESSION['flash_success'] ?? null,
            ]);
        }

        if (!$registration) {
            return $this->twig->render($response, 'students/thesis-fees.twig', [
                'active_page'    => 'thesis',
                'first_name'     => $_SESSION['first_name'] ?? '',
                'student_number' => $student['student_number'] ?? null,
                'not_registered' => true,
                'error'          => 'You have a thesis proposal on record, but no formal thesis registration was found. Please contact the registrar.',
                'csrf_token'     => $this->csrfToken(),
                'success'        => $_SESSION['flash_success'] ?? null,
            ]);
        }

        $owed = $regModel->computeOwed($registration);
        $upcoming = $regModel->computeUpcoming($registration);
        $paymentModel = new ThesisPayment($this->db);
        $docPaymentModel = new \App\Models\DocumentPayment($this->db);

        // Registration/annual fees live in thesis_payments, document
        // review fees in document_payment — merged here since a student
        // doesn't care which table produced a given payment record.
        $history = array_merge(
            $paymentModel->findByRegistrationId($registration['thesis_registration_id']),
            array_map(
                fn($p) => array_merge($p, ['fee_type' => 'document_review_fee']),
                $docPaymentModel->findByRegistrationId($registration['thesis_registration_id'])
            )
        );
        usort($history, fn($a, $b) => strcmp((string) $b['created_at'], (string) $a['created_at']));

        $proposalModel = new \App\Models\Proposal($this->db);
        $proposal = $proposalModel->findActiveByStudentId($student['student_id']);

        return $this->twig->render($response, 'students/thesis-fees.twig', [
        'active_page'    => 'thesis',
        'first_name'     => $_SESSION['first_name'] ?? '',
        'student_number' => $student['student_number'] ?? null,
        'not_registered' => false,
        'registration'   => $registration,
        'owed'           => $owed,
        'upcoming'       => $upcoming,
        'history'        => $history,
        'has_proposal'   => (bool) $proposal,
        'csrf_token'     => $this->csrfToken(),
        'error'          => $_SESSION['flash_error'] ?? null,
        'success'        => $_SESSION['flash_success'] ?? null,
        ]);
    }




        /**
     * Step 1 of thesis registration: shows departments, then (via AJAX
     * or a second form step) programs with an available schedule under
     * that department, then the schedule(s) themselves if a program has
     * more than one.
     */
    public function showRegisterPicker(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $this->redirect($response, $redirect);
        }

        $student = $this->getStudentRecord($_SESSION['user_id']);
        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/login');
        }

        $regModel = new ThesisRegistration($this->db);
        if ($regModel->findActiveByStudentId($student['student_id'])) {
            $_SESSION['flash_error'] = 'You are already registered for thesis.';
            return $this->redirect($response, '/student/thesis');
        }

        $departments = $this->db->query("SELECT department_id, name FROM departments ORDER BY name")->fetchAll();

        // Programs that actually have at least one thesis_schedules row,
        // tagged with their department, for client-side cascading —
        // same pattern as the old register.twig's department/program picker.
        $programs = $this->db->query(
            "SELECT DISTINCT p.program_id, p.name, p.department_id
             FROM programs p
             JOIN thesis_schedules ts ON ts.program_id = p.program_id
             ORDER BY p.name"
        )->fetchAll();

        $schedules = $this->db->query(
            "SELECT schedule_id, program_id, enrollment_start_date, enrollment_end_date
             FROM thesis_schedules
             ORDER BY enrollment_start_date DESC"
        )->fetchAll();

        return $this->twig->render($response, 'students/thesis_register.twig', [
            'active_page' => 'thesis',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'departments' => $departments,
            'programs'    => $programs,
            'schedules'   => $schedules,
            'csrf_token'  => $this->csrfToken(),
            'error'       => $_SESSION['flash_error'] ?? null,
        ]);
    }

    /**
     * Step 2: student has picked a specific schedule_id — register them
     * against it directly. No more resolving program_id from the
     * student's own record, since students no longer carry one.
     */
    public function register(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $this->redirect($response, $redirect);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/student/thesis/register');
        }

        $student = $this->getStudentRecord($_SESSION['user_id']);
        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/student/thesis/register');
        }

        $regModel = new ThesisRegistration($this->db);
        if ($regModel->findActiveByStudentId($student['student_id'])) {
            $_SESSION['flash_error'] = 'You are already registered for thesis.';
            return $this->redirect($response, '/student/thesis');
        }

        $thesisScheduleId = trim((string) ($data['schedule_id'] ?? ''));

        if ($thesisScheduleId === '') {
            $_SESSION['flash_error'] = 'Please select a thesis schedule.';
            return $this->redirect($response, '/student/thesis/register');
        }

        $scheduleCheck = $this->db->prepare("SELECT schedule_id FROM thesis_schedules WHERE schedule_id = :id LIMIT 1");
        $scheduleCheck->execute(['id' => $thesisScheduleId]);
        if (!$scheduleCheck->fetchColumn()) {
            $_SESSION['flash_error'] = 'That schedule no longer exists. Please choose again.';
            return $this->redirect($response, '/student/thesis/register');
        }

        $regModel->create($student['student_id'], $thesisScheduleId);

        $_SESSION['flash_success'] = 'You are now registered for thesis. Pay the registration fee to proceed.';
        return $this->redirect($response, '/student/thesis');
    }


    public function pay(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $this->redirect($response, $redirect);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/student/thesis');
        }

        $student = $this->getStudentRecord($_SESSION['user_id']);
        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/student/thesis');
        }

        $regModel = new ThesisRegistration($this->db);
        $registration = $regModel->findActiveByStudentId($student['student_id']);
        if (!$registration) {
            $_SESSION['flash_error'] = 'No active thesis registration found.';
            return $this->redirect($response, '/student/thesis');
        }

        $feeType = $data['fee_type'] ?? '';
        $year = ($data['thesis_year'] ?? '') !== '' ? (int) $data['thesis_year'] : null;
        $examScheduleId = trim((string) ($data['exam_schedule_id'] ?? '')) ?: null;
        $documentTypeId = trim((string) ($data['document_type_id'] ?? '')) ?: null;
        $amount = $data['amount'] ?? '';
        $currency = $data['currency'] ?? 'KES';
        $paymentMethod = $data['payment_method'] ?? '';
        $reference = trim((string) ($data['reference_number'] ?? ''));

        $errors = [];
        if (!in_array($feeType, ['thesis_registration', 'thesis_review_fee', 'document_review_fee'], true)) {
            $errors[] = 'Invalid fee type.';
        }
        if ($feeType === 'document_review_fee' && (!$examScheduleId || !$documentTypeId)) {
            $errors[] = 'Please choose which document this payment is for.';
        }
        if ($amount === '' || (float) $amount <= 0) {
            $errors[] = 'Invalid amount.';
        }
        if ($paymentMethod === '') {
            $errors[] = 'Please select a payment method.';
        }
        if ($reference === '') {
            $errors[] = 'Please provide a reference number.';
        }

        if ($errors) {
            $_SESSION['flash_error'] = implode(' ', $errors);
            return $this->redirect($response, '/student/thesis');
        }

        try {
            if ($feeType === 'document_review_fee') {
                (new \App\Models\DocumentPayment($this->db))->create([
                    'exam_schedule_id' => $examScheduleId,
                    'document_type_id' => $documentTypeId,
                    'amount'           => $amount,
                    'currency'         => $currency,
                    'payment_method'   => $paymentMethod,
                    'reference_number' => $reference,
                ], $registration['thesis_registration_id']);
            } else {
                (new ThesisPayment($this->db))->create([
                    'fee_type'         => $feeType,
                    'thesis_year'      => $year,
                    'amount'           => $amount,
                    'currency'         => $currency,
                    'payment_method'   => $paymentMethod,
                    'reference_number' => $reference,
                ], $registration['thesis_registration_id']);
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Payment could not be recorded: ' . $e->getMessage();
            return $this->redirect($response, '/student/thesis');
        }

        $_SESSION['flash_success'] = 'Payment submitted and awaiting confirmation.';
        return $this->redirect($response, '/student/thesis');
    }
}
