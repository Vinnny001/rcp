<?php

declare(strict_types=1);

namespace App\Controllers;

use Slim\Views\Twig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use App\Models\Proposal;
use App\Models\Document;
use App\Models\SupervisorProfile;
use App\Models\SupervisorShortlist;
use PDO;

/**
 * The proposal and its supervisor request, written and sent as one.
 *
 * A student drafts the proposal and the list of supervisors side by
 * side, then sends both together. From that moment neither can be
 * changed by anyone. If the request fails, the research coordinator may
 * hand the proposal, the list, or both back to the student — and only
 * the part handed back becomes editable again.
 *
 * What is editable is never taken from the form. It is worked out on
 * every request by SupervisorShortlist::studentState().
 */
class StudentProposalController
{
    private PDO $db;
    private Twig $twig;

    private const UPLOAD_DIR = __DIR__ . '/../../public/uploads/documents';
    private const ALLOWED_MIME = ['application/pdf'];
    private const MAX_SIZE_KB = 10240;

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

    /**
     * Resolves a document_types.doc_type_id by name (e.g. 'Synopsis',
     * 'Proposal'). Returns null if that type isn't seeded yet — callers
     * must handle null gracefully rather than assume it always exists.
     */
    private function resolveDocumentTypeId(string $typeName): ?string
    {
        $stmt = $this->db->prepare("SELECT doc_type_id FROM document_types WHERE doc_type_name = :name LIMIT 1");
        $stmt->execute(['name' => $typeName]);
        $id = $stmt->fetchColumn();
        return $id ?: null;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $student = $this->getStudentRecord($_SESSION['user_id']);

        $proposalModel = new Proposal($this->db);
        $documentModel = new Document($this->db);
        $requests = new SupervisorShortlist($this->db);

        // No scheduler: a response window that closed with nobody looking
        // is settled the next time anyone opens a page that shows it.
        $requests->resolveDue();

        $proposal = $student ? $proposalModel->findActiveByStudentId($student['student_id']) : null;

        $synopsisDoc = null;
        $proposalDoc = null;
        if ($proposal) {
            $synopsisTypeId = $this->resolveDocumentTypeId('Synopsis');
            $proposalTypeId = $this->resolveDocumentTypeId('Proposal');
            if ($synopsisTypeId) {
                $synopsisDoc = $documentModel->findByProposalAndType($proposal['proposal_id'], $synopsisTypeId);
            }
            if ($proposalTypeId) {
                $proposalDoc = $documentModel->findByProposalAndType($proposal['proposal_id'], $proposalTypeId);
            }
        }

        $state = $student ? $requests->studentState($student['student_id'], $proposal) : null;
        $latest = $state['latest'] ?? null;
        $old = $_SESSION['old_input'] ?? [];

        [$picked, $pickedMain] = $this->pickerStartingPoint($requests, $state, $old);

        $history = $student ? $requests->historyForStudent($student['student_id']) : [];
        if ($latest) {
            $history = array_values(array_filter(
                $history,
                static fn (array $h): bool => $h['shortlist_id'] !== $latest['shortlist_id']
            ));
        }

        $rendered = $this->twig->render($response, 'students/proposal.twig', [
            'active_page'     => 'proposal',
            'first_name'      => $_SESSION['first_name'] ?? '',
            'student_number'  => $student['student_number'] ?? null,
            'proposal'        => $proposal,
            'synopsis_doc'    => $synopsisDoc,
            'proposal_doc'    => $proposalDoc,
            'state'           => $state,
            'latest'          => $latest,
            'latest_choices'  => $latest ? $requests->choicesFor($latest['shortlist_id']) : [],
            'appointed_roles' => $proposal ? $this->appointedRoles($proposal['proposal_id']) : [],
            'history'         => $history,
            'supervisors'     => ($state['list_editable'] ?? false) ? (new SupervisorProfile($this->db))->browsable() : [],
            'picked'          => $picked,
            'picked_main'     => $pickedMain,
            'max_choices'     => SupervisorShortlist::MAX_CHOICES,
            'max_supervisors' => SupervisorShortlist::MAX_SUPERVISORS,
            'response_days'   => SupervisorShortlist::RESPONSE_DAYS,
            'csrf_token'      => $this->csrfToken(),
            'error'           => $_SESSION['flash_error'] ?? null,
            'success'         => $_SESSION['flash_success'] ?? null,
            'old'             => $old,
        ]);

        unset($_SESSION['flash_error'], $_SESSION['flash_success'], $_SESSION['old_input']);
        return $rendered;
    }

    /**
     * Who was actually appointed and in which role, so the tracker says
     * "main supervisor" from the assignment rather than guessing it from
     * the order.
     *
     * @return array<string, string> lecturer_id => role
     */
    private function appointedRoles(string $proposalId): array
    {
        $stmt = $this->db->prepare(
            "SELECT supervisor_id, role FROM supervision_assignments
             WHERE proposal_id = :id AND is_active = 1"
        );
        $stmt->execute(['id' => $proposalId]);

        return $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    }

    /**
     * What the picker opens with: what was just posted if a save bounced,
     * else the saved draft, else — when revising a failed request — the
     * list that failed, so the student edits it rather than starting over.
     *
     * @param array<string, mixed>|null $state
     * @param array<string, mixed> $old
     * @return array{0: array<string, int>, 1: string}
     */
    private function pickerStartingPoint(SupervisorShortlist $requests, ?array $state, array $old): array
    {
        if (isset($old['rank']) && is_array($old['rank'])) {
            return [
                array_filter($old['rank'], static fn ($r): bool => trim((string) $r) !== ''),
                (string) ($old['preferred_main'] ?? ''),
            ];
        }

        $source = $state['draft'] ?? null;
        if ($source === null && ($state['list_editable'] ?? false)) {
            $source = $state['latest'];
        }
        if ($source === null) {
            return [[], ''];
        }

        $picked = [];
        $main = '';
        foreach ($requests->choicesFor($source['shortlist_id']) as $choice) {
            $picked[$choice['lecturer_id']] = (int) $choice['rank_position'];
            if ($choice['is_preferred_main']) {
                $main = $choice['lecturer_id'];
            }
        }

        return [$picked, $main];
    }

    public function store(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = (array) $request->getParsedBody();

        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/student/proposal');
        }

        $student = $this->getStudentRecord($_SESSION['user_id']);
        if (!$student) {
            $_SESSION['flash_error'] = 'Could not find your student record.';
            return $this->redirect($response, '/student/proposal');
        }

        $sending = ($data['action'] ?? '') === 'submit';

        $proposalModel = new Proposal($this->db);
        $requests = new SupervisorShortlist($this->db);
        $proposal = $proposalModel->findActiveByStudentId($student['student_id']);
        $state = $requests->studentState($student['student_id'], $proposal);

        if (!$state['proposal_editable'] && !$state['list_editable']) {
            $_SESSION['flash_error'] = $requests->lockedMessage($state['waiting_on']);
            return $this->redirect($response, '/student/proposal');
        }

        $title     = trim((string) ($data['title'] ?? ''));
        $synopsis  = trim((string) ($data['synopsis'] ?? ''));
        $choices   = $state['list_editable'] ? $this->readChoices($data) : null;
        $preferred = (string) ($data['preferred_main'] ?? '');

        $errors = [];

        if ($state['proposal_editable']) {
            if ($title === '' || mb_strlen($title) > 255) {
                $errors[] = 'Please provide a working title (up to 255 characters).';
            }
            if ($synopsis === '' || ($sending && mb_strlen($synopsis) < 50)) {
                $errors[] = $sending
                    ? 'Please provide a synopsis of at least 50 characters before sending.'
                    : 'Please provide a synopsis.';
            }
            // Checked before anything is written: once the request is
            // sent the proposal is locked, and a file that bounced then
            // could never be added.
            foreach (['synopsis_file' => 'The synopsis', 'proposal_file' => 'The proposal'] as $field => $label) {
                if ($problem = $this->uploadProblem($request, $field, $label)) {
                    $errors[] = $problem;
                }
            }
        }

        if ($choices !== null) {
            // Marking someone preferred main without adding them to the
            // list would silently read as no preference.
            if ($preferred !== '' && !in_array($preferred, array_column($choices, 'lecturer_id'), true)) {
                $errors[] = 'You marked a preferred main supervisor who is not on your list. Add them, or choose "No preferred main".';
            }
            if ($sending && $choices === []) {
                $errors[] = 'Choose at least one supervisor to send with your proposal.';
            }
        }

        if ($sending) {
            $regStmt = $this->db->prepare(
                "SELECT thesis_schedule_id FROM student_thesis_registrations
                 WHERE student_id = :student_id AND status = 'active' LIMIT 1"
            );
            $regStmt->execute(['student_id' => $student['student_id']]);
            $thesisScheduleId = $regStmt->fetchColumn();

            if (!$thesisScheduleId || !$proposalModel->proposalSchedulingExists($thesisScheduleId)) {
                $errors[] = 'Proposals are not currently being accepted for your thesis schedule.';
            }
        }

        if ($errors) {
            return $this->bounce($response, implode(' ', $errors), $data);
        }

        try {
            $this->db->beginTransaction();

            if ($state['proposal_editable']) {
                $fields = ['title' => $title, 'synopsis' => $synopsis, 'proposed_supervisor_id' => null];
                if ($proposal) {
                    $proposalModel->updateDraft($proposal['proposal_id'], $fields, $sending);
                } else {
                    $proposalModel->create($student['student_id'], $fields, $sending);
                }
                $proposal = $proposalModel->findActiveByStudentId($student['student_id']);
            }

            // One transaction: the proposal is only ever submitted together
            // with its request, and a draft saves both halves or neither.
            if ($sending) {
                $requests->sendForStudent($student['student_id'], $proposal, $choices, $_SESSION['user_id']);
            } elseif ($state['list_editable']) {
                $requests->saveDraft($student['student_id'], $proposal, $choices ?? [], $_SESSION['user_id']);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            return $this->bounce($response, $e->getMessage(), $data);
        }

        if ($state['proposal_editable']) {
            $this->handleOptionalUpload($request, $proposal['proposal_id'], 'synopsis_file', 'Synopsis', !$sending);
            $this->handleOptionalUpload($request, $proposal['proposal_id'], 'proposal_file', 'Proposal', !$sending);
        }

        if ($sending) {
            $this->submitDraftDocuments($proposal['proposal_id']);
        }

        $_SESSION['flash_success'] = $sending
            ? 'Your proposal and supervisor request were sent. Your research coordinator takes them to the department, and neither can be changed from here.'
            : 'Draft saved. Nothing is sent until you choose Send.';

        return $this->redirect($response, '/student/proposal');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function bounce(ResponseInterface $response, string $message, array $data): ResponseInterface
    {
        $_SESSION['flash_error'] = $message;
        $_SESSION['old_input'] = [
            'title'          => trim((string) ($data['title'] ?? '')),
            'synopsis'       => trim((string) ($data['synopsis'] ?? '')),
            'rank'           => is_array($data['rank'] ?? null) ? $data['rank'] : [],
            'preferred_main' => (string) ($data['preferred_main'] ?? ''),
        ];

        return $this->redirect($response, '/student/proposal');
    }

    /**
     * Turns the picker's rank[lecturer_id] and preferred_main inputs into
     * the choice list the model validates. A blank rank means not chosen.
     *
     * @param array<string, mixed> $data
     * @return array<int, array{lecturer_id: string, rank: int, preferred_main: bool}>
     */
    private function readChoices(array $data): array
    {
        $ranks = $data['rank'] ?? [];
        $preferred = (string) ($data['preferred_main'] ?? '');

        if (!is_array($ranks)) {
            return [];
        }

        $choices = [];
        foreach ($ranks as $lecturerId => $rank) {
            if (trim((string) $rank) === '') {
                continue;
            }
            $choices[] = [
                'lecturer_id'    => (string) $lecturerId,
                'rank'           => (int) $rank,
                'preferred_main' => (string) $lecturerId === $preferred,
            ];
        }

        usort($choices, static fn (array $a, array $b): int => $a['rank'] <=> $b['rank']);

        return $choices;
    }

    private function uploadProblem(ServerRequestInterface $request, string $field, string $label): ?string
    {
        $file = $request->getUploadedFiles()[$field] ?? null;

        if (!$file || $file->getError() === UPLOAD_ERR_NO_FILE) {
            return null;
        }
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

    /**
     * Documents uploaded while the proposal was a draft kept the status
     * 'draft' after it was sent. Reviewers are only offered submitted
     * documents, and a draft document could still be deleted — so
     * sending promotes them along with the proposal.
     */
    private function submitDraftDocuments(string $proposalId): void
    {
        $this->db->prepare(
            "UPDATE documents d
             JOIN exam_documents ed ON ed.document_id = d.document_id
             SET d.document_status = 'submitted'
             WHERE ed.proposal_id = :proposal_id AND d.document_status = 'draft'"
        )->execute(['proposal_id' => $proposalId]);
    }

    private function handleOptionalUpload(
        ServerRequestInterface $request,
        string $proposalId,
        string $fieldName,
        string $documentTypeName,
        bool $proposalIsDraft
    ): void {
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles[$fieldName] ?? null;

        if (!$file || $file->getError() !== UPLOAD_ERR_OK) {
            return;
        }

        $documentTypeId = $this->resolveDocumentTypeId($documentTypeName);
        if (!$documentTypeId) {
            $_SESSION['flash_error'] = "The '{$documentTypeName}' document type is not configured yet.";
            return;
        }

        $mimeType = $file->getClientMediaType();
        if (!in_array($mimeType, self::ALLOWED_MIME, true)) {
            $_SESSION['flash_error'] = $documentTypeName . ' must be a PDF.';
            return;
        }

        $sizeKb = (int) ceil($file->getSize() / 1024);
        if ($sizeKb > self::MAX_SIZE_KB) {
            $_SESSION['flash_error'] = $documentTypeName . ' exceeds the 10MB limit.';
            return;
        }

        if (!is_dir(self::UPLOAD_DIR)) {
            mkdir(self::UPLOAD_DIR, 0755, true);
        }

        $storedName = bin2hex(random_bytes(16)) . '.pdf';
        $destination = self::UPLOAD_DIR . '/' . $storedName;

        $documentModel = new Document($this->db);

        $existingDoc = $documentModel->findByProposalAndType($proposalId, $documentTypeId);
        if ($existingDoc) {
            // A file that has already been scored is held by the database
            // (examination_scores restricts the delete). That is reachable
            // now that a sent proposal can be handed back for revision, so
            // say so rather than fail the whole page.
            try {
                $documentModel->delete($existingDoc['document_id']);
            } catch (\PDOException $e) {
                $_SESSION['flash_error'] = 'The existing ' . strtolower($documentTypeName)
                    . ' has already been reviewed, so it was kept rather than replaced.';
                return;
            }
            $oldPath = __DIR__ . '/../../public/' . $existingDoc['file_path'];
            if (is_file($oldPath)) {
                unlink($oldPath);
            }
        }

        $file->moveTo($destination);

        $examScheduleId = null;
        $stmt = $this->db->prepare(
            "SELECT esd.exam_schedule_id
             FROM exam_schedule es
             JOIN exam_schedule_documents esd ON esd.exam_schedule_id = es.exam_schedule_id
             JOIN student_thesis_registrations str ON str.thesis_schedule_id = es.thesis_schedule_id
             WHERE str.student_id = (SELECT student_id FROM thesis_proposals WHERE proposal_id = :proposal_id)
               AND str.status = 'active'
               AND esd.document_type_id = :document_type_id
             LIMIT 1"
        );
        $stmt->execute(['proposal_id' => $proposalId, 'document_type_id' => $documentTypeId]);
        $examScheduleId = $stmt->fetchColumn() ?: null;

        $newDocumentId = $documentModel->create([
        'user_id'          => $_SESSION['user_id'],
        'uploaded_by'      => $_SESSION['user_id'],
        'document_type_id' => $documentTypeId,
        'document_status'  => $proposalIsDraft ? 'draft' : 'submitted',
        'file_name'        => $file->getClientFilename(),
        'file_path'        => 'uploads/documents/' . $storedName,
        'file_size_kb'     => $sizeKb,
        'mime_type'        => $mimeType,
        ]);

        $documentModel->linkToProposal($newDocumentId, $proposalId, $documentTypeId, $examScheduleId);
    }

    public function removeDocument(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireStudent()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $this->redirect($response, '/student/proposal');
        }

        $student = $this->getStudentRecord($_SESSION['user_id']);
        $documentId = $data['document_id'] ?? '';

        if (!$student || !$documentId) {
            return $this->redirect($response, '/student/proposal');
        }

        $documentModel = new Document($this->db);
        $doc = $documentModel->findById($documentId);

        $proposalModel = new Proposal($this->db);
        $proposal = $proposalModel->findActiveByStudentId($student['student_id']);

        // The link to a proposal now lives on exam_documents, not on the
        // documents row itself — look it up there instead.
        $linkStmt = $this->db->prepare(
            "SELECT proposal_id FROM exam_documents WHERE document_id = :document_id LIMIT 1"
        );
        $linkStmt->execute(['document_id' => $documentId]);
        $linkedProposalId = $linkStmt->fetchColumn();

        // The proposal has to be a draft, not just the file: a draft
        // document could otherwise be deleted from a proposal already sent.
        if (
            !$doc || !$proposal
            || $proposal['status'] !== 'draft'
            || $linkedProposalId !== $proposal['proposal_id']
            || $doc['document_status'] !== 'draft'
        ) {
            $_SESSION['flash_error'] = 'That document cannot be removed.';
            return $this->redirect($response, '/student/proposal');
        }

        $path = __DIR__ . '/../../public/' . $doc['file_path'];
        if (is_file($path)) {
            unlink($path);
        }
        $documentModel->delete($documentId);

        $_SESSION['flash_success'] = 'Document removed.';
        return $this->redirect($response, '/student/proposal');
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
