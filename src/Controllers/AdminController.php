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



}