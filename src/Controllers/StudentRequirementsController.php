<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Document;
use App\Models\Payment;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

class StudentRequirementsController
{
    private PDO $db;
    private Twig $twig;

    // Where uploaded files land on disk (inside public/ so nothing else
    // needs to change to serve them back later).
    private const UPLOAD_DIR = __DIR__ . '/../../public/uploads/documents';

    private const ALLOWED_MIME = ['application/pdf', 'image/jpeg', 'image/png'];
    private const MAX_SIZE_KB = 10240; // 10MB

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

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $student = $this->getStudentRecord($_SESSION['user_id']);

        $documentModel = new Document($this->db);
        $paymentModel = new Payment($this->db);

        $rendered = $this->twig->render($response, 'students/requirements.twig', [
            'active_page'    => 'requirements',
            'first_name'     => $_SESSION['first_name'] ?? '',
            'student_number' => $student['student_number'] ?? null,
            'documents'      => $documentModel->findByUploader($_SESSION['user_id']),
            'payments'       => $student ? $paymentModel->findByStudentId($student['student_id']) : [],
            'csrf_token'     => $this->csrfToken(),
            'error'          => $_SESSION['flash_error'] ?? null,
            'success'        => $_SESSION['flash_success'] ?? null,
        ]);

        unset($_SESSION['flash_error'], $_SESSION['flash_success']);
        return $rendered;
    }

    public function uploadDocument(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();

        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/student/requirements');
        }

        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;
        $documentType = $data['document_type'] ?? '';

        $validTypes = ['synopsis', 'proposal', 'coursework_cert', 'payment_proof', 'thesis_draft', 'final_thesis', 'publication'];

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Please choose a file to upload.';
            return $this->redirect($response, '/student/requirements');
        }

        if (!in_array($documentType, $validTypes, true)) {
            $_SESSION['flash_error'] = 'Please select a valid document type.';
            return $this->redirect($response, '/student/requirements');
        }

        $mimeType = $file->getClientMediaType();
        if (!in_array($mimeType, self::ALLOWED_MIME, true)) {
            $_SESSION['flash_error'] = 'Only PDF, JPG, and PNG files are accepted.';
            return $this->redirect($response, '/student/requirements');
        }

        $sizeKb = (int) ceil($file->getSize() / 1024);
        if ($sizeKb > self::MAX_SIZE_KB) {
            $_SESSION['flash_error'] = 'File exceeds the 10MB limit.';
            return $this->redirect($response, '/student/requirements');
        }

        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }

        $extension = pathinfo($file->getClientFilename(), PATHINFO_EXTENSION);
        $storedName = bin2hex(random_bytes(16)) . '.' . $extension;
        $destination = self::UPLOAD_DIR . '/' . $storedName;

        try {
            $file->moveTo($destination);

            $documentModel = new Document($this->db);
            $documentModel->create([
                'uploaded_by'   => $_SESSION['user_id'],
                'document_type' => $documentType,
                'file_name'     => $file->getClientFilename(),
                'file_path'     => 'uploads/documents/' . $storedName,
                'file_size_kb'  => $sizeKb,
                'mime_type'     => $mimeType,
            ]);
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Upload failed: ' . $e->getMessage();
            return $this->redirect($response, '/student/requirements');
        }

        $_SESSION['flash_success'] = 'Document uploaded and awaiting validation.';
        return $this->redirect($response, '/student/requirements');
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