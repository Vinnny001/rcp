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
        $roleModel = new \App\Models\Role($this->db);
        $lecturerModel = new \App\Models\Lecturer($this->db);

        $params = $request->getQueryParams();
        $search = trim((string) ($params['q'] ?? ''));
        $roleFilter = trim((string) ($params['role'] ?? ''));

        $users = $userModel->all($search ?: null, $roleFilter ?: null);

        // The table is admin-facing and small — one lookup per lecturer
        // row is simpler than folding a third affiliation table into
        // AdminUser::all()'s already-aggregated query.
        $affiliations = [];
        foreach ($users as $u) {
            if ($u['lecturer_id']) {
                $affiliations[$u['lecturer_id']] = $lecturerModel->findAffiliation($u['lecturer_id']);
            }
        }

        return $this->twig->render($response, 'admins/users.twig', [
        'active_page'      => 'users',
        'first_name'       => $_SESSION['first_name'] ?? '',
        'users'            => $users,
        'roles'            => $roleModel->all(),
        'roles_by_user'    => $roleModel->activeRolesByUser(),
        'affiliations'     => $affiliations,
        'departments'      => (new \App\Models\Department($this->db))->all(),
        'q'                => $search,
        'role_filter'      => $roleFilter,
        'csrf_token'       => $this->csrfToken(),
        'error'            => $this->takeFlash('flash_error'),
        'success'          => $this->takeFlash('flash_success'),
        ]);
    }

    /**
     * Sets or changes a lecturer's affiliation — internal (requires a
     * unique staff_number and a department) or external (everything
     * else optional). A lecturer is one or the other, never both;
     * switching types wipes the old table's row.
     */
    public function updateAffiliation(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }

        $lecturerId = (string) ($data['lecturer_id'] ?? '');
        $type = (string) ($data['affiliation_type'] ?? '');

        if ($lecturerId === '' || !in_array($type, ['internal', 'external'], true)) {
            $_SESSION['flash_error'] = 'Please choose Internal or External.';
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }

        $lecturerModel = new \App\Models\Lecturer($this->db);

        try {
            if ($type === 'internal') {
                $staffNumber = trim((string) ($data['staff_number'] ?? ''));
                $departmentId = trim((string) ($data['department_id'] ?? ''));

                if ($staffNumber === '' || $departmentId === '') {
                    $_SESSION['flash_error'] = 'Staff number and department are required for an internal lecturer.';
                    return $response->withHeader('Location', '/admin/users')->withStatus(302);
                }

                $lecturerModel->setInternal(
                    $lecturerId,
                    $staffNumber,
                    $departmentId,
                    trim((string) ($data['specialization'] ?? '')) ?: null
                );
            } else {
                $lecturerModel->setExternal(
                    $lecturerId,
                    trim((string) ($data['non_staff_number'] ?? '')) ?: null,
                    trim((string) ($data['department'] ?? '')) ?: null,
                    trim((string) ($data['specialization'] ?? '')) ?: null,
                    trim((string) ($data['institution'] ?? '')) ?: null
                );
            }

            $_SESSION['flash_success'] = 'Affiliation updated.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not update affiliation: that staff number may already be in use.';
        }

        return $response->withHeader('Location', '/admin/users')->withStatus(302);
    }

    /**
     * Flips whether a lecturer can be assigned as an examiner. Purely an
     * admin-set flag — a lecturer cannot opt themselves into it.
     */
    public function toggleExaminer(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }

        $lecturerId = (string) ($data['lecturer_id'] ?? '');
        if ($lecturerId !== '') {
            (new \App\Models\AdminUser($this->db))->toggleExaminer($lecturerId);
            $_SESSION['flash_success'] = 'Examiner status updated.';
        }

        return $response->withHeader('Location', '/admin/users')->withStatus(302);
    }

    public function updateMaxSupervisionLoad(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }

        $lecturerId = (string) ($data['lecturer_id'] ?? '');
        $value = $data['max_supervision_load'] ?? '';

        if ($lecturerId === '' || !is_numeric($value) || (int) $value < 0) {
            $_SESSION['flash_error'] = 'Please provide a valid, non-negative supervision load.';
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }

        (new \App\Models\Lecturer($this->db))->updateMaxSupervisionLoad($lecturerId, (int) $value);
        $_SESSION['flash_success'] = 'Supervision load updated.';

        return $response->withHeader('Location', '/admin/users')->withStatus(302);
    }

    /**
     * Grants or revokes one role for one user. Roles are additive — a
     * user can hold several at once, and the login screen decides which
     * hat they're wearing for a session — so this toggles a single
     * grant rather than replacing a user's role.
     */
    public function updateUserRole(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }

        $userId = (string) ($data['user_id'] ?? '');
        $roleId = (string) ($data['role_id'] ?? '');
        $action = (string) ($data['action'] ?? '');

        if ($userId === '' || $roleId === '' || !in_array($action, ['grant', 'revoke'], true)) {
            $_SESSION['flash_error'] = 'Please choose a user, a role, and whether to grant or revoke it.';
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }

        $roleModel = new \App\Models\Role($this->db);

        $role = null;
        foreach ($roleModel->all() as $candidate) {
            if ($candidate['role_id'] === $roleId) {
                $role = $candidate;
                break;
            }
        }

        if (!$role) {
            $_SESSION['flash_error'] = 'That role does not exist.';
            return $response->withHeader('Location', '/admin/users')->withStatus(302);
        }

        try {
            if ($action === 'grant') {
                $roleModel->grant($userId, $roleId, $_SESSION['user_id']);
                $roleModel->ensureProfileFor($userId, $role['role_name']);
                $_SESSION['flash_success'] = 'Granted the ' . $role['role_name'] . ' role.';
            } else {
                // Revoking the last admin would leave nobody able to
                // grant it back, since granting requires being an admin.
                if ($role['role_name'] === 'admin' && $roleModel->activeHolderCount($roleId) <= 1) {
                    $_SESSION['flash_error'] = 'This is the only admin account — grant the admin role to someone else first.';
                    return $response->withHeader('Location', '/admin/users')->withStatus(302);
                }

                $roleModel->revoke($userId, $roleId);
                $_SESSION['flash_success'] = 'Revoked the ' . $role['role_name'] . ' role.';
            }
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not update roles: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/users')->withStatus(302);
    }

    // ---------- Fees & rates ----------

    public function feeRates(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $thesisFeeModel = new \App\Models\ThesisFeeRate($this->db);
        $docRateModel = new \App\Models\DocumentReviewRate($this->db);

        return $this->twig->render($response, 'admins/fee_rates.twig', [
            'active_page'          => 'fee-rates',
            'first_name'           => $_SESSION['first_name'] ?? '',
            'programs'             => (new \App\Models\Program($this->db))->all(),
            'document_types'       => $this->db->query("SELECT doc_type_id, doc_type_name FROM document_types ORDER BY doc_type_name")->fetchAll(),
            'registration_rates'   => $thesisFeeModel->allRegistrationRates(),
            'review_fee_rates'     => $thesisFeeModel->allReviewFeeRates(),
            'document_review_rates' => $docRateModel->all(),
            'csrf_token'           => $this->csrfToken(),
            'error'                => $this->takeFlash('flash_error'),
            'success'              => $this->takeFlash('flash_success'),
        ]);
    }

    public function createRegistrationRate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
        }

        if ($error = $this->validateRate($data, ['program_id', 'amount'])) {
            $_SESSION['flash_error'] = $error;
            return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
        }

        try {
            (new \App\Models\ThesisFeeRate($this->db))->createRegistrationRate($data, $_SESSION['user_id']);
            $_SESSION['flash_success'] = 'Registration fee rate added.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not add the rate: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
    }

    public function updateRegistrationRate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
        }

        $rateId = (string) ($data['rate_id'] ?? '');
        $error = $rateId === '' ? 'Please choose a rate to update.' : $this->validateRate($data, ['program_id', 'amount']);

        if ($error) {
            $_SESSION['flash_error'] = $error;
            return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
        }

        try {
            (new \App\Models\ThesisFeeRate($this->db))->updateRegistrationRate($rateId, $data, $_SESSION['user_id']);
            $_SESSION['flash_success'] = 'Registration fee rate updated.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not update the rate: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
    }

    public function deleteRegistrationRate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
        }

        try {
            (new \App\Models\ThesisFeeRate($this->db))->deleteRegistrationRate((string) ($data['rate_id'] ?? ''));
            $_SESSION['flash_success'] = 'Registration fee rate deleted.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not delete: this rate is in use by a thesis schedule.';
        }

        return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
    }

    public function createReviewFeeRate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
        }

        if ($error = $this->validateRate($data, ['program_id', 'amount', 'academic_year'])) {
            $_SESSION['flash_error'] = $error;
            return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
        }

        try {
            (new \App\Models\ThesisFeeRate($this->db))->createReviewFeeRate($data, $_SESSION['user_id']);
            $_SESSION['flash_success'] = 'Review fee rate added.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not add the rate: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
    }

    public function updateReviewFeeRate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
        }

        $rateId = (string) ($data['rate_id'] ?? '');
        $error = $rateId === '' ? 'Please choose a rate to update.' : $this->validateRate($data, ['program_id', 'amount', 'academic_year']);

        if ($error) {
            $_SESSION['flash_error'] = $error;
            return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
        }

        try {
            (new \App\Models\ThesisFeeRate($this->db))->updateReviewFeeRate($rateId, $data, $_SESSION['user_id']);
            $_SESSION['flash_success'] = 'Review fee rate updated.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not update the rate: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
    }

    public function deleteReviewFeeRate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
        }

        try {
            (new \App\Models\ThesisFeeRate($this->db))->deleteReviewFeeRate((string) ($data['rate_id'] ?? ''));
            $_SESSION['flash_success'] = 'Review fee rate deleted.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not delete: this rate is in use by a thesis schedule.';
        }

        return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
    }

    public function createDocumentReviewRate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
        }

        if (empty($data['program_id']) || empty($data['document_type_id']) || $this->validateRate($data, ['amount'])) {
            $_SESSION['flash_error'] = 'Please choose a program, a document type, and a valid amount.';
            return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
        }

        try {
            (new \App\Models\DocumentReviewRate($this->db))->create($data, $_SESSION['user_id']);
            $_SESSION['flash_success'] = 'Document review rate added.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not add the rate: a rate for that program and document type may already exist.';
        }

        return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
    }

    public function updateDocumentReviewRate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
        }

        $rateId = (string) ($data['rate_id'] ?? '');

        if ($rateId === '' || empty($data['program_id']) || empty($data['document_type_id']) || $this->validateRate($data, ['amount'])) {
            $_SESSION['flash_error'] = 'Please choose a program, a document type, and a valid amount.';
            return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
        }

        try {
            (new \App\Models\DocumentReviewRate($this->db))->update($rateId, $data, $_SESSION['user_id']);
            $_SESSION['flash_success'] = 'Document review rate updated.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not update the rate: a rate for that program and document type may already exist.';
        }

        return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
    }

    public function deleteDocumentReviewRate(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
        }

        (new \App\Models\DocumentReviewRate($this->db))->delete((string) ($data['rate_id'] ?? ''));
        $_SESSION['flash_success'] = 'Document review rate deleted.';

        return $response->withHeader('Location', '/admin/fee-rates')->withStatus(302);
    }

    // ---------- Grading bands ----------

    public function gradingBands(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        return $this->twig->render($response, 'admins/grading_bands.twig', [
            'active_page' => 'grading-bands',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'bands'       => (new \App\Models\GradingBand($this->db))->all(),
            'csrf_token'  => $this->csrfToken(),
            'error'       => $this->takeFlash('flash_error'),
            'success'     => $this->takeFlash('flash_success'),
        ]);
    }

    public function createGradingBand(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/grading-bands')->withStatus(302);
        }

        if ($error = $this->validateGradingBand($data)) {
            $_SESSION['flash_error'] = $error;
            return $response->withHeader('Location', '/admin/grading-bands')->withStatus(302);
        }

        try {
            (new \App\Models\GradingBand($this->db))->create($data, $_SESSION['user_id']);
            $_SESSION['flash_success'] = 'Grading band added.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not add the band: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/grading-bands')->withStatus(302);
    }

    public function updateGradingBand(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/grading-bands')->withStatus(302);
        }

        $bandId = (string) ($data['band_id'] ?? '');
        $error = $bandId === '' ? 'Please choose a band to update.' : $this->validateGradingBand($data);

        if ($error) {
            $_SESSION['flash_error'] = $error;
            return $response->withHeader('Location', '/admin/grading-bands')->withStatus(302);
        }

        try {
            (new \App\Models\GradingBand($this->db))->update($bandId, $data);
            $_SESSION['flash_success'] = 'Grading band updated.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not update the band: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/grading-bands')->withStatus(302);
    }

    public function deleteGradingBand(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/grading-bands')->withStatus(302);
        }

        (new \App\Models\GradingBand($this->db))->delete((string) ($data['band_id'] ?? ''));
        $_SESSION['flash_success'] = 'Grading band deleted.';

        return $response->withHeader('Location', '/admin/grading-bands')->withStatus(302);
    }

    private function validateGradingBand(array $data): ?string
    {
        if (!is_numeric($data['min_score'] ?? null) || !is_numeric($data['max_score'] ?? null)) {
            return 'Please provide valid numeric scores.';
        }
        if ((float) $data['min_score'] < 0 || (float) $data['max_score'] > 100) {
            return 'Scores must be between 0 and 100.';
        }
        if ((float) $data['min_score'] > (float) $data['max_score']) {
            return 'The minimum score cannot be greater than the maximum score.';
        }
        if (!in_array($data['outcome'] ?? null, ['pass', 'fail', 'resubmit', 'distinction'], true)) {
            return 'Please choose a valid outcome.';
        }
        return null;
    }

    /**
     * @param array<int, string> $required
     * @return string|null an error message, or null if the data is fine
     */
    private function validateRate(array $data, array $required): ?string
    {
        foreach ($required as $field) {
            if ($field === 'amount') {
                if (!is_numeric($data['amount'] ?? null) || (float) $data['amount'] < 0) {
                    return 'Please provide a valid, non-negative amount.';
                }
                continue;
            }
            if (empty($data[$field])) {
                return 'Please fill in every required field.';
            }
        }

        return null;
    }

    // ---------- Audit log ----------

    public function auditLog(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $params = $request->getQueryParams();
        $entityType = $params['entity_type'] ?? '';
        $action = $params['action'] ?? '';

        $auditModel = new \App\Models\AuditLog($this->db);

        return $this->twig->render($response, 'admins/audit.twig', [
            'active_page'   => 'audit',
            'first_name'    => $_SESSION['first_name'] ?? '',
            'logs'          => $auditModel->recent(['entity_type' => $entityType ?: null, 'action' => $action ?: null]),
            'entity_types'  => $auditModel->distinctEntityTypes(),
            'actions'       => $auditModel->distinctActions(),
            'selected_entity_type' => $entityType,
            'selected_action'      => $action,
        ]);
    }

    // ---------- Exam review attachments ----------

    public function examReviewAttachments(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        return $this->twig->render($response, 'admins/exam_review_attachments.twig', [
            'active_page' => 'exam-review-attachments',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'attachments' => (new \App\Models\ExamReviewAttachment($this->db))->findAll(),
            'document_review_attachments' => (new \App\Models\DocumentReviewAttachment($this->db))->findAll(),
        ]);
    }

    // ---------- Notifications ----------

    private const NOTIFICATION_AUDIENCES = ['all_lecturers', 'all_students', 'active_supervisors', 'examiners'];

    public function notifications(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        return $this->twig->render($response, 'admins/notifications.twig', [
            'active_page' => 'notifications',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'csrf_token'  => $this->csrfToken(),
            'error'       => $this->takeFlash('flash_error'),
            'success'     => $this->takeFlash('flash_success'),
        ]);
    }

    /**
     * Broadcasts one notification to every user in the chosen audience.
     * Every recipient gets an identical in-app notification — there's no
     * per-user personalization here, just fan-out.
     */
    public function sendNotification(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/notifications')->withStatus(302);
        }

        $audience = (string) ($data['audience'] ?? '');
        $subject = trim((string) ($data['subject'] ?? ''));
        $message = trim((string) ($data['message'] ?? ''));

        if (!in_array($audience, self::NOTIFICATION_AUDIENCES, true) || $subject === '' || $message === '') {
            $_SESSION['flash_error'] = 'Please choose an audience and provide both a subject and a message.';
            return $response->withHeader('Location', '/admin/notifications')->withStatus(302);
        }

        $lecturerModel = new \App\Models\Lecturer($this->db);
        $roleModel = new \App\Models\Role($this->db);

        $userIds = match ($audience) {
            'all_lecturers'      => $roleModel->activeUserIdsForRole('lecturer'),
            'all_students'       => $roleModel->activeUserIdsForRole('student'),
            'active_supervisors' => $lecturerModel->activeSupervisorUserIds(),
            'examiners'          => $lecturerModel->examinerUserIds(),
        };

        // Every audience but all_students addresses the lecturer hat —
        // active_supervisors/examiners are both lecturer-only concepts.
        $role = $audience === 'all_students' ? 'student' : 'lecturer';

        $sent = (new \App\Models\Notification($this->db))->createForUsers($userIds, $role, $subject, $message, 'broadcast', null);

        $_SESSION['flash_success'] = $sent > 0
            ? 'Notification sent to ' . $sent . ' recipient' . ($sent === 1 ? '' : 's') . '.'
            : 'No recipients matched that audience — nothing was sent.';

        return $response->withHeader('Location', '/admin/notifications')->withStatus(302);
    }

    /**
     * Reads a flash message and clears it, so it shows once.
     */
    private function takeFlash(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;
        unset($_SESSION[$key]);
        return $value;
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

    // ---------- Thesis schedules ----------

    public function thesisSchedules(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $scheduleModel = new \App\Models\ThesisSchedule($this->db);
        $rates = $scheduleModel->ratesByProgram();

        return $this->twig->render($response, 'admins/thesis_schedules.twig', [
            'active_page'        => 'thesis-schedules',
            'first_name'         => $_SESSION['first_name'] ?? '',
            'schedules'          => $scheduleModel->all(),
            'programs'           => (new \App\Models\Program($this->db))->all(),
            'registration_rates' => $rates['registration'],
            'review_rates'       => $rates['review'],
            'csrf_token'         => $this->csrfToken(),
            'error'              => $this->takeFlash('flash_error'),
            'success'            => $this->takeFlash('flash_success'),
        ]);
    }

    /**
     * Students enrolled under one thesis schedule, with their latest
     * proposal status — lets admin spot a rejected (failed
     * approval-board) proposal and know that student needs a brand-new
     * exam schedule to attempt a fresh one.
     */
    public function thesisScheduleStudents(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $scheduleId = $args['id'] ?? '';
        $scheduleModel = new \App\Models\ThesisSchedule($this->db);
        $schedule = null;
        foreach ($scheduleModel->all() as $s) {
            if ($s['schedule_id'] === $scheduleId) {
                $schedule = $s;
                break;
            }
        }

        if (!$schedule) {
            $_SESSION['flash_error'] = 'That thesis schedule could not be found.';
            return $response->withHeader('Location', '/admin/thesis-schedules')->withStatus(302);
        }

        $regModel = new \App\Models\ThesisRegistration($this->db);
        $students = $regModel->findStudentsByScheduleId($scheduleId);

        // Same lazy substitute for a cron as StudentController::dashboard() —
        // viewing a schedule's roster is a natural, cheap place to also
        // settle any proposal left dangling by an unresponsive supervisor
        // once that schedule's enrollment window has closed.
        $proposalModel = new \App\Models\Proposal($this->db);
        $needsRefresh = false;
        foreach ($students as $s) {
            if (!empty($s['proposal_id']) && empty($s['assigned_supervisor_id'])) {
                $proposalModel->autoAssignSupervisorIfEligible($s['proposal_id']);
                $needsRefresh = true;
            }
        }
        if ($needsRefresh) {
            $students = $regModel->findStudentsByScheduleId($scheduleId);
        }

        return $this->twig->render($response, 'admins/thesis_schedule_students.twig', [
            'active_page' => 'thesis-schedules',
            'first_name'  => $_SESSION['first_name'] ?? '',
            'schedule'    => $schedule,
            'students'    => $students,
        ]);
    }

    public function createThesisSchedule(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/thesis-schedules')->withStatus(302);
        }

        if ($error = $this->validateThesisSchedule($data)) {
            $_SESSION['flash_error'] = $error;
            return $response->withHeader('Location', '/admin/thesis-schedules')->withStatus(302);
        }

        try {
            (new \App\Models\ThesisSchedule($this->db))->create($data, $_SESSION['user_id']);
            $_SESSION['flash_success'] = 'Thesis schedule created.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not create the schedule: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/thesis-schedules')->withStatus(302);
    }

    public function updateThesisSchedule(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/thesis-schedules')->withStatus(302);
        }

        $scheduleId = (string) ($data['schedule_id'] ?? '');
        $error = $scheduleId === '' ? 'Please choose a schedule to update.' : $this->validateThesisSchedule($data);

        if ($error) {
            $_SESSION['flash_error'] = $error;
            return $response->withHeader('Location', '/admin/thesis-schedules')->withStatus(302);
        }

        try {
            (new \App\Models\ThesisSchedule($this->db))->update($scheduleId, $data);
            $_SESSION['flash_success'] = 'Thesis schedule updated.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not update the schedule: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/thesis-schedules')->withStatus(302);
    }

    public function deleteThesisSchedule(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/thesis-schedules')->withStatus(302);
        }

        $blocked = (new \App\Models\ThesisSchedule($this->db))->delete((string) ($data['schedule_id'] ?? ''));

        if ($blocked) {
            $_SESSION['flash_error'] = 'Could not delete: ' . $blocked;
        } else {
            $_SESSION['flash_success'] = 'Thesis schedule deleted.';
        }

        return $response->withHeader('Location', '/admin/thesis-schedules')->withStatus(302);
    }

    /**
     * @return string|null an error message, or null if the data is fine
     */
    private function validateThesisSchedule(array $data): ?string
    {
        if (empty($data['program_id'])) {
            return 'Please choose a program.';
        }

        $start = trim((string) ($data['enrollment_start_date'] ?? ''));
        $end = trim((string) ($data['enrollment_end_date'] ?? ''));

        if ($start !== '' && $end !== '' && strtotime($end) < strtotime($start)) {
            return 'Enrollment cannot close before it opens.';
        }

        return null;
    }

    // ---------- Exam schedules ----------

    public function examSchedules(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $examModel = new \App\Models\ExamSchedule($this->db);

        $schedules = [];
        foreach ($examModel->all() as $schedule) {
            $schedule['document_slots'] = $examModel->documentSlots($schedule['exam_schedule_id']);
            $schedules[] = $schedule;
        }

        return $this->twig->render($response, 'admins/exam_schedules.twig', [
            'active_page'      => 'exam-schedules',
            'first_name'       => $_SESSION['first_name'] ?? '',
            'exam_schedules'   => $schedules,
            'thesis_schedules' => (new \App\Models\ThesisSchedule($this->db))->all(),
            'document_types'   => $this->db->query("SELECT doc_type_id, doc_type_name FROM document_types ORDER BY doc_type_name")->fetchAll(),
            'exam_types'       => \App\Models\ExamSchedule::VALID_EXAM_TYPES,
            'csrf_token'       => $this->csrfToken(),
            'error'            => $this->takeFlash('flash_error'),
            'success'          => $this->takeFlash('flash_success'),
        ]);
    }

    public function createExamSchedule(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/exam-schedules')->withStatus(302);
        }

        if ($error = $this->validateExamSchedule($data)) {
            $_SESSION['flash_error'] = $error;
            return $response->withHeader('Location', '/admin/exam-schedules')->withStatus(302);
        }

        try {
            (new \App\Models\ExamSchedule($this->db))->create($data);
            $_SESSION['flash_success'] = 'Exam schedule created.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not create the exam schedule: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/exam-schedules')->withStatus(302);
    }

    public function updateExamSchedule(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/exam-schedules')->withStatus(302);
        }

        $examScheduleId = (string) ($data['exam_schedule_id'] ?? '');
        $error = $examScheduleId === '' ? 'Please choose an exam schedule to update.' : $this->validateExamSchedule($data);

        if ($error) {
            $_SESSION['flash_error'] = $error;
            return $response->withHeader('Location', '/admin/exam-schedules')->withStatus(302);
        }

        try {
            (new \App\Models\ExamSchedule($this->db))->update($examScheduleId, $data);
            $_SESSION['flash_success'] = 'Exam schedule updated.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not update the exam schedule: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/exam-schedules')->withStatus(302);
    }

    public function deleteExamSchedule(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/exam-schedules')->withStatus(302);
        }

        $blocked = (new \App\Models\ExamSchedule($this->db))->delete((string) ($data['exam_schedule_id'] ?? ''));

        if ($blocked) {
            $_SESSION['flash_error'] = 'Could not delete: ' . $blocked;
        } else {
            $_SESSION['flash_success'] = 'Exam schedule deleted.';
        }

        return $response->withHeader('Location', '/admin/exam-schedules')->withStatus(302);
    }

    public function addExamScheduleDocument(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/exam-schedules')->withStatus(302);
        }

        $examScheduleId = (string) ($data['exam_schedule_id'] ?? '');

        if ($examScheduleId === '' || empty($data['document_type_id'])) {
            $_SESSION['flash_error'] = 'Please choose an exam schedule and a document type.';
            return $response->withHeader('Location', '/admin/exam-schedules')->withStatus(302);
        }

        try {
            (new \App\Models\ExamSchedule($this->db))->addDocumentSlot($examScheduleId, $data);
            $_SESSION['flash_success'] = 'Required document added to the exam schedule.';
        } catch (\Throwable $e) {
            $_SESSION['flash_error'] = 'Could not add the document requirement: ' . $e->getMessage();
        }

        return $response->withHeader('Location', '/admin/exam-schedules')->withStatus(302);
    }

    public function removeExamScheduleDocument(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireAdmin()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/admin/exam-schedules')->withStatus(302);
        }

        (new \App\Models\ExamSchedule($this->db))->removeDocumentSlot((string) ($data['esd_id'] ?? ''));
        $_SESSION['flash_success'] = 'Document requirement removed.';

        return $response->withHeader('Location', '/admin/exam-schedules')->withStatus(302);
    }

    /**
     * @return string|null an error message, or null if the data is fine
     */
    private function validateExamSchedule(array $data): ?string
    {
        if (empty($data['thesis_schedule_id'])) {
            return 'Please choose the thesis schedule this exam window belongs to.';
        }

        if (!in_array($data['exam_type'] ?? '', \App\Models\ExamSchedule::VALID_EXAM_TYPES, true)) {
            return 'Please choose a valid exam type.';
        }

        $starts = trim((string) ($data['starts_at'] ?? ''));
        $ends = trim((string) ($data['ends_at'] ?? ''));

        if ($starts === '' || $ends === '') {
            return 'An exam window needs both a start and an end.';
        }

        if (strtotime($ends) < strtotime($starts)) {
            return 'The exam window cannot end before it starts.';
        }

        return null;
    }
}
