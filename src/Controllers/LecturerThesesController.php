<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Lecturer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

class LecturerThesesController
{
    private PDO $db;
    private Twig $twig;

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

    private function redirect(ResponseInterface $response, string $path): ResponseInterface
    {
        return $response->withHeader('Location', $path)->withStatus(302);
    }

    /**
     * Derives the graduation_status label the view expects, from the
     * raw graduation_id / graduation_approved columns findTheses() returns.
     */
    private function graduationStatus(array $t): string
    {
        if (!$t['graduation_id']) {
            return 'not_yet';
        }
        return $t['graduation_approved'] ? 'registered' : 'eligible';
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $lecturerModel = new Lecturer($this->db);
        $lecturer = $lecturerModel->findByUserId($_SESSION['user_id']);

        if (!$lecturer) {
            $_SESSION['flash_error'] = 'Your lecturer profile could not be found. Contact the registrar.';
            return $this->redirect($response, '/login');
        }

        $theses = $lecturerModel->findTheses($lecturer['lecturer_id']);
        foreach ($theses as &$t) {
            $t['graduation_status'] = $this->graduationStatus($t);
        }
        unset($t);

        return $this->twig->render($response, 'lecturers/theses.twig', [
            'active_page'  => 'l-theses',
            'first_name'   => $_SESSION['first_name'] ?? '',
            'staff_number' => $lecturer['staff_number'] ?? null,
            'theses'       => $theses,
        ]);
    }

    public function detail(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $this->redirect($response, $redirect);
        }

        $lecturerModel = new Lecturer($this->db);
        $lecturer = $lecturerModel->findByUserId($_SESSION['user_id']);

        if (!$lecturer) {
            $_SESSION['flash_error'] = 'Your lecturer profile could not be found. Contact the registrar.';
            return $this->redirect($response, '/login');
        }

        $proposalId = $args['id'] ?? '';
        $thesis = $lecturerModel->findThesisDetail($lecturer['lecturer_id'], $proposalId);

        if (!$thesis) {
            $_SESSION['flash_error'] = 'That thesis is not under your supervision.';
            return $this->redirect($response, '/lecturer/theses');
        }

        $thesis['graduation_status'] = $this->graduationStatus($thesis);

        return $this->twig->render($response, 'lecturers/theses.twig', [
            'active_page'  => 'l-theses',
            'first_name'   => $_SESSION['first_name'] ?? '',
            'staff_number' => $lecturer['staff_number'] ?? null,
            'thesis'       => $thesis,
        ]);
    }
}