<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\AdminStats;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

class AdminController
{
    private PDO $db;
    private Twig $twig;

    public function __construct(PDO $db, Twig $twig)
    {
        $this->db = $db;
        $this->twig = $twig;
    }

    private function requireAdmin(): ?string
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'admin') {
            return '/login';
        }
        return null;
    }

    public function dashboard(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $stats = new AdminStats($this->db);

                return $this->twig->render($response, 'admins/dashboard.twig', [
            'active_page'            => 'overview',
            'first_name'             => $_SESSION['first_name'] ?? '',
            'active_students'        => $stats->activeStudentCount(),
            'lecturer_count'         => $stats->lecturerCount(),
            'proposals_under_review' => $stats->proposalsUnderReviewCount(),
            'pending_payments'       => $stats->pendingPaymentsTotal(),
            'unpaid_thesis'          => $stats->unpaidThesisFeesSummary(),
            'meetings_this_week'     => $stats->meetingsThisWeekCount(),
            'graduation_pending'     => $stats->graduationPendingApprovalCount(),
            'by_department'          => $stats->byDepartment(),
            'by_program'             => $stats->byProgram(),
        ]);
    }



        public function users(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $userModel = new \App\Models\AdminUser($this->db);

        return $this->twig->render($response, 'admins/users.twig', [
            'active_page' => 'users',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'users'       => $userModel->all(),
            'success'     => $_SESSION['flash_success'] ?? null,
        ]);
    }

    public function toggleUser(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        $userId = $data['user_id'] ?? '';

        if ($userId) {
            $userModel = new \App\Models\AdminUser($this->db);
            $userModel->toggleActive($userId);
            $_SESSION['flash_success'] = 'User status updated.';
        }

        return $response->withHeader('Location', '/admin/users')->withStatus(302);
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

    // ---------- Departments ----------

    public function departments(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $deptModel = new \App\Models\Department($this->db);

        return $this->twig->render($response, 'admins/departments.twig', [
            'active_page' => 'departments',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'departments' => $deptModel->all(),
            'csrf_token'  => $this->csrfToken(),
            'error'       => $_SESSION['flash_error'] ?? null,
            'success'     => $_SESSION['flash_success'] ?? null,
        ]);
    }

    public function createDepartment(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/departments')->withStatus(302);
        }

        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            $_SESSION['flash_error'] = 'Department name is required.';
            return $response->withHeader('Location', '/admin/departments')->withStatus(302);
        }

        try {
            (new \App\Models\Department($this->db))->create($name);
            $_SESSION['flash_success'] = 'Department added.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not add department: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/departments')->withStatus(302);
    }

    public function updateDepartment(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/departments')->withStatus(302);
        }

        $id = $data['department_id'] ?? '';
        $name = trim((string) ($data['name'] ?? ''));

        if ($id === '' || $name === '') {
            $_SESSION['flash_error'] = 'Department name is required.';
            return $response->withHeader('Location', '/admin/departments')->withStatus(302);
        }

        try {
            (new \App\Models\Department($this->db))->update($id, $name);
            $_SESSION['flash_success'] = 'Department updated.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not update department: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/departments')->withStatus(302);
    }

    public function deleteDepartment(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/departments')->withStatus(302);
        }

        $id = $data['department_id'] ?? '';

        try {
            (new \App\Models\Department($this->db))->delete($id);
            $_SESSION['flash_success'] = 'Department deleted.';
        } catch (\Throwable $e) {
            // Likely an FK constraint (programs still reference it)
            $_SESSION['flash_error'] = 'Could not delete: this department still has programs linked to it.';
        }

        return $response->withHeader('Location', '/admin/departments')->withStatus(302);
    }

    // ---------- Programs ----------

    public function programs(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $progModel = new \App\Models\Program($this->db);
        $deptModel = new \App\Models\Department($this->db);

        return $this->twig->render($response, 'admins/programs.twig', [
            'active_page' => 'programs',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'programs'    => $progModel->all(),
            'departments' => $deptModel->all(),
            'csrf_token'  => $this->csrfToken(),
            'error'       => $_SESSION['flash_error'] ?? null,
            'success'     => $_SESSION['flash_success'] ?? null,
        ]);
    }

    public function createProgram(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/programs')->withStatus(302);
        }

        $departmentId = $data['department_id'] ?? '';
        $name = trim((string) ($data['name'] ?? ''));

        if ($departmentId === '' || $name === '') {
            $_SESSION['flash_error'] = 'Department and program name are required.';
            return $response->withHeader('Location', '/admin/programs')->withStatus(302);
        }

        try {
            (new \App\Models\Program($this->db))->create($departmentId, $name);
            $_SESSION['flash_success'] = 'Program added.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not add program: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/programs')->withStatus(302);
    }

    public function updateProgram(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/programs')->withStatus(302);
        }

        $id = $data['program_id'] ?? '';
        $departmentId = $data['department_id'] ?? '';
        $name = trim((string) ($data['name'] ?? ''));

        if ($id === '' || $departmentId === '' || $name === '') {
            $_SESSION['flash_error'] = 'Department and program name are required.';
            return $response->withHeader('Location', '/admin/programs')->withStatus(302);
        }

        try {
            (new \App\Models\Program($this->db))->update($id, $departmentId, $name);
            $_SESSION['flash_success'] = 'Program updated.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not update program: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/programs')->withStatus(302);
    }

    public function deleteProgram(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/programs')->withStatus(302);
        }

        $id = $data['program_id'] ?? '';

        try {
            (new \App\Models\Program($this->db))->delete($id);
            $_SESSION['flash_success'] = 'Program deleted.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not delete: this program still has students or rate records linked to it.';
        }

        return $response->withHeader('Location', '/admin/programs')->withStatus(302);
    }




}