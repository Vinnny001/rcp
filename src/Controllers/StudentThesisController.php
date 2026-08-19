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
            "SELECT student_id, student_number, department, program FROM students WHERE user_id = :user_id LIMIT 1"
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
        $paymentModel = new ThesisPayment($this->db);
        $history = $paymentModel->findByRegistrationId($registration['thesis_registration_id']);

        $proposalModel = new \App\Models\Proposal($this->db);
        $proposal = $proposalModel->findActiveByStudentId($student['student_id']);

        return $this->twig->render($response, 'students/thesis-fees.twig', [
            'active_page'    => 'thesis',
            'first_name'     => $_SESSION['first_name'] ?? '',
            'student_number' => $student['student_number'] ?? null,
            'not_registered' => false,
            'registration'   => $registration,
            'owed'           => $owed,
            'history'        => $history,
            'has_proposal'   => (bool) $proposal,
            'csrf_token'     => $this->csrfToken(),
            'error'          => $_SESSION['flash_error'] ?? null,
            'success'        => $_SESSION['flash_success'] ?? null,
        ]);
    }




    public function register(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $this->redirect($response, $redirect);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/student/proposal');
        }

        $student = $this->getStudentRecord($_SESSION['user_id']);
        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/student/proposal');
        }

        $regModel = new ThesisRegistration($this->db);
        if ($regModel->findActiveByStudentId($student['student_id'])) {
            $_SESSION['flash_error'] = 'You are already registered for thesis.';
            return $this->redirect($response, '/student/proposal');
        }

        $stmt = $this->db->prepare(
            "SELECT p.program_id FROM programs p
             JOIN departments d ON d.department_id = p.department_id
             WHERE d.name = :department AND p.name = :program LIMIT 1"
        );
        $stmt->execute(['department' => $student['department'], 'program' => $student['program']]);
        $programId = $stmt->fetchColumn();

        if (!$programId) {
            $_SESSION['flash_error'] = 'Your program is not yet set up for thesis registration. Contact the registrar.';
            return $this->redirect($response, '/student/proposal');
        }

        $regModel->create($student['student_id'], $programId);

        $_SESSION['flash_success'] = 'You are now registered for thesis. Pay the registration fee to proceed.';
        return $this->redirect($response, '/student/proposal');
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
        $year = $data['thesis_year'] !== '' ? (int) $data['thesis_year'] : null;
        $amount = $data['amount'] ?? '';
        $currency = $data['currency'] ?? 'KES';
        $paymentMethod = $data['payment_method'] ?? '';
        $reference = trim((string) ($data['reference_number'] ?? ''));

        $errors = [];
        if (!in_array($feeType, ['thesis_registration', 'thesis_review_fee'], true)) {
            $errors[] = 'Invalid fee type.';
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

        $paymentModel = new ThesisPayment($this->db);

        try {
            $paymentModel->create([
                'fee_type'         => $feeType,
                'thesis_year'      => $year,
                'amount'           => $amount,
                'currency'         => $currency,
                'payment_method'   => $paymentMethod,
                'reference_number' => $reference,
            ], $registration['thesis_registration_id']);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Payment could not be recorded: ' . $e->getMessage();
            return $this->redirect($response, '/student/thesis');
        }

        $_SESSION['flash_success'] = 'Payment submitted and awaiting confirmation.';
        return $this->redirect($response, '/student/thesis');
    }


    


}