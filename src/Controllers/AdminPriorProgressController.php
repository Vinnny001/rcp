<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ExamStage;
use App\Models\PriorProgress;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

/**
 * The graduate school's side of a student continuing where they left off:
 * the claims waiting to be decided, and what each stage asks for as
 * evidence.
 *
 * Approving is the only thing that records a stage as passed without an
 * exam here, so it is an administrator's alone.
 */
class AdminPriorProgressController
{
    private PDO $db;
    private Twig $twig;

    public function __construct(PDO $db, Twig $twig)
    {
        $this->db = $db;
        $this->twig = $twig;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $this->redirect($response, $redirect);
        }

        $progress = new PriorProgress($this->db);

        $rendered = $this->twig->render($response, 'admins/prior_progress.twig', [
            'active_page'    => 'a-prior-progress',
            'first_name'     => $_SESSION['first_name'] ?? '',
            'claims'         => $progress->queue(false),
            'stages'         => (new ExamStage($this->db))->allActive(),
            'requirements'   => $progress->allRequirements(),
            'document_types' => $this->db->query("SELECT doc_type_id, doc_type_name FROM document_types ORDER BY doc_type_name")->fetchAll(),
            'csrf_token'     => $this->csrfToken(),
            'error'          => $_SESSION['flash_error'] ?? null,
            'success'        => $_SESSION['flash_success'] ?? null,
        ]);

        unset($_SESSION['flash_error'], $_SESSION['flash_success']);

        return $rendered;
    }

    /**
     * Approve or reject one claim.
     */
    public function decide(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->act($request, $response, function (array $data, PriorProgress $progress): string {
            $claimId = (string) ($data['claim_id'] ?? '');

            if (($data['decision'] ?? '') === 'approve') {
                $granted = $progress->approve($claimId, $_SESSION['user_id']);

                return 'Recorded as passed: ' . $granted . '. The student carries on from the next stage.';
            }

            $progress->reject($claimId, $_SESSION['user_id'], (string) ($data['reason'] ?? ''));

            return 'Claim rejected. The student can send fresh evidence.';
        });
    }

    /**
     * Add or change a piece of evidence on a stage's list — or on the
     * default list, when no stage is named.
     */
    public function saveRequirement(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->act($request, $response, function (array $data, PriorProgress $progress): string {
            $progress->saveRequirement(
                (string) ($data['requirement_id'] ?? '') ?: null,
                (string) ($data['stage_id'] ?? '') ?: null,
                $data
            );

            return 'Evidence list saved.';
        });
    }

    public function removeRequirement(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        return $this->act($request, $response, function (array $data, PriorProgress $progress): string {
            $progress->removeRequirement((string) ($data['requirement_id'] ?? ''));

            return 'No longer asked for. Claims that already answered it keep it on record.';
        });
    }

    /**
     * @param callable(array<string, mixed>, PriorProgress): string $action
     */
    private function act(ServerRequestInterface $request, ResponseInterface $response, callable $action): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $this->redirect($response, $redirect);
        }

        $data = (array) $request->getParsedBody();

        if (!$this->verifyCsrf((string) ($data['csrf_token'] ?? ''))) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/admin/prior-progress');
        }

        try {
            $_SESSION['flash_success'] = $action($data, new PriorProgress($this->db));
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = $e->getMessage();
        }

        return $this->redirect($response, '/admin/prior-progress');
    }

    private function requireAdmin(): ?string
    {
        if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? null) !== 'admin') {
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
