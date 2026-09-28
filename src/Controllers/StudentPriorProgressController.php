<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Document;
use App\Models\PriorProgress;
use App\Models\ThesisRegistration;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

/**
 * A student continuing from where they left off.
 *
 * They name the last stage they passed and attach the evidence the
 * graduate school asks for. Nothing is granted here — the claim waits for
 * an administrator, who grants that stage and every earlier one.
 *
 * Registration comes first: the claim is about where in the journey they
 * resume, and there is no journey until they have registered.
 */
class StudentPriorProgressController
{
    private const UPLOAD_DIR = __DIR__ . '/../../public/uploads/documents';
    private const ALLOWED_MIME = ['application/pdf'];
    private const MAX_SIZE_KB = 10240;

    private PDO $db;
    private Twig $twig;

    public function __construct(PDO $db, Twig $twig)
    {
        $this->db = $db;
        $this->twig = $twig;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $this->redirect($response, $redirect);
        }

        $student = $this->studentRecord();
        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/login');
        }

        $registered = (new ThesisRegistration($this->db))->findActiveByStudentId($student['student_id']) !== null;
        $progress = new PriorProgress($this->db);
        $state = $registered
            ? $progress->studentState($student['student_id'], $_SESSION['user_id'])
            : ['stages' => [], 'claim' => null, 'already_done' => [], 'can_claim' => false];

        // What each stage asks for, so the form can swap the upload boxes
        // as the student picks a stage.
        $requirements = [];
        foreach ($state['stages'] as $stage) {
            $requirements[$stage['stage_id']] = $progress->requirementsFor($stage['stage_id']);
        }

        $rendered = $this->twig->render($response, 'students/thesis_continue.twig', [
            'active_page'    => 'thesis',
            'first_name'     => $_SESSION['first_name'] ?? '',
            'student_number' => $student['student_number'] ?? null,
            'registered'     => $registered,
            'requirements'   => $requirements,
            'csrf_token'     => $this->csrfToken(),
            'error'          => $_SESSION['flash_error'] ?? null,
            'success'        => $_SESSION['flash_success'] ?? null,
        ] + $state);

        unset($_SESSION['flash_error'], $_SESSION['flash_success']);

        return $rendered;
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $this->redirect($response, $redirect);
        }

        $data = (array) $request->getParsedBody();

        if (!$this->verifyCsrf((string) ($data['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/student/thesis/continue');
        }

        $student = $this->studentRecord();
        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/student/thesis/continue');
        }

        $stageId = (string) ($data['stage_id'] ?? '');
        $progress = new PriorProgress($this->db);

        // Every file is checked before any of it is stored, so a claim
        // never lands half-uploaded.
        $uploads = $request->getUploadedFiles()['evidence'] ?? [];
        $accepted = [];
        foreach ($progress->requirementsFor($stageId ?: null) as $requirement) {
            $file = is_array($uploads) ? ($uploads[$requirement['requirement_id']] ?? null) : null;

            if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) {
                if ($requirement['is_required']) {
                    $_SESSION['flash_error'] = 'Attach the ' . $requirement['label'] . ' — it is required.';
                    return $this->redirect($response, '/student/thesis/continue');
                }
                continue;
            }
            if ($problem = $this->uploadProblem($file, $requirement['label'])) {
                $_SESSION['flash_error'] = $problem;
                return $this->redirect($response, '/student/thesis/continue');
            }

            $accepted[] = [$requirement, $file];
        }

        try {
            $files = [];
            foreach ($accepted as [$requirement, $file]) {
                $files[] = [
                    'requirement_id' => $requirement['requirement_id'],
                    'label'          => $requirement['label'],
                    'document_id'    => $this->storeUpload($file, $requirement['document_type_id']),
                ];
            }

            $progress->submit(
                $student['student_id'],
                $stageId,
                $files,
                (string) ($data['note'] ?? ''),
                $_SESSION['user_id']
            );
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
            return $this->redirect($response, '/student/thesis/continue');
        }

        $_SESSION['flash_success'] = 'Sent to the graduate school. They check your evidence and record the stages you have already passed.';

        return $this->redirect($response, '/student/thesis/continue');
    }

    private function uploadProblem(\Psr\Http\Message\UploadedFileInterface $file, string $label): ?string
    {
        if ($file->getError() !== UPLOAD_ERR_OK) {
            return $label . ' did not upload — please try again.';
        }
        if (!in_array($file->getClientMediaType(), self::ALLOWED_MIME, true)) {
            return $label . ' must be a PDF.';
        }
        if ((int) ceil($file->getSize() / 1024) > self::MAX_SIZE_KB) {
            return $label . ' exceeds the 10MB limit.';
        }

        return null;
    }

    private function storeUpload(\Psr\Http\Message\UploadedFileInterface $file, string $documentTypeId): string
    {
        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }

        $storedName = bin2hex(random_bytes(16)) . '.pdf';
        $file->moveTo(self::UPLOAD_DIR . '/' . $storedName);

        return (new Document($this->db))->create([
            'user_id'          => $_SESSION['user_id'],
            'uploaded_by'      => $_SESSION['user_id'],
            'document_type_id' => $documentTypeId,
            'document_status'  => 'submitted',
            'file_name'        => $file->getClientFilename(),
            'file_path'        => 'uploads/documents/' . $storedName,
            'file_size_kb'     => (int) ceil($file->getSize() / 1024),
            'mime_type'        => $file->getClientMediaType(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function studentRecord(): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT student_id, student_number FROM students WHERE user_id = :user_id LIMIT 1"
        );
        $stmt->execute(['user_id' => $_SESSION['user_id']]);

        return $stmt->fetch() ?: null;
    }

    private function requireStudent(): ?string
    {
        if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'student') {
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
}
