<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Lecturer;
use App\Models\Meeting;
use App\Models\SupervisionRequest;
use App\Models\User;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Views\Twig;
use PDO;

class LecturerProfileController
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

    /**
     * Reasons this lecturer cannot turn availability off right now, in
     * plain language. Empty means they're free to switch it off.
     *
     * @return array<int, string>
     */
    private function unavailabilityBlockers(string $lecturerId, string $userId): array
    {
        $blockers = [];

        $activeCount = (new Lecturer($this->db))->countActiveSupervisions($lecturerId);
        if ($activeCount > 0) {
            $blockers[] = 'You currently supervise ' . $activeCount . ' student' . ($activeCount === 1 ? '' : 's') . ' — availability cannot be turned off while supervising.';
        }

        $pending = (new SupervisionRequest($this->db))->findPendingByLecturerId($lecturerId);
        if ($pending) {
            $blockers[] = 'You have ' . count($pending) . ' pending supervision request' . (count($pending) === 1 ? '' : 's') . ' — decline it before turning off availability.';
        }

        // A student's request is waiting on this lecturer's answer. Going
        // unavailable would leave it unanswerable until the window closed.
        $asked = (new \App\Models\SupervisorShortlist($this->db))->pendingForLecturer($lecturerId);
        if ($asked) {
            $blockers[] = 'You have ' . count($asked) . ' supervisor request' . (count($asked) === 1 ? '' : 's')
                . ' from a student waiting on your answer — accept or decline '
                . (count($asked) === 1 ? 'it' : 'them') . ' before turning off availability.';
        }

        if ((new Meeting($this->db))->hasActiveMeetingForUser($userId)) {
            $blockers[] = 'You have a scheduled or in-progress meeting invite — availability cannot be turned off until it is resolved.';
        }

        return $blockers;
    }

    public function show(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $userId = $_SESSION['user_id'];
        $user = (new User($this->db))->findById($userId);
        $lecturerModel = new Lecturer($this->db);
        $lecturer = $lecturerModel->findByUserId($userId);

        $blockers = $lecturer ? $this->unavailabilityBlockers($lecturer['lecturer_id'], $userId) : [];

        $error = $_SESSION['flash_error'] ?? null;
        $success = $_SESSION['flash_success'] ?? null;
        unset($_SESSION['flash_error'], $_SESSION['flash_success']);

        return $this->twig->render($response, 'lecturers/profile.twig', [
            'active_page'      => 'l-profile',
            'first_name'       => $_SESSION['first_name'] ?? '',
            'last_name'        => $_SESSION['last_name'] ?? '',
            'user'             => $user,
            'lecturer'         => $lecturer,
            'unavailability_blockers' => $blockers,
            // The profile students read before shortlisting. Only
            // internal lecturers supervise, so only they have one.
            'profile'          => $lecturer ? $this->supervisorProfile($lecturer['lecturer_id']) : null,
            'links'            => $lecturer ? $this->publicationLinks($lecturer['lecturer_id']) : [],
            'link_platforms'   => self::LINK_PLATFORMS,
            'csrf_token'       => $this->csrfToken(),
            'error'            => $error,
            'success'          => $success,
        ]);
    }

    /**
     * Where a lecturer can list a public profile. "Other" carries its
     * own label, so the list is not a closed set.
     */
    public const LINK_PLATFORMS = [
        'researchgate'   => 'ResearchGate',
        'google_scholar' => 'Google Scholar',
        'linkedin'       => 'LinkedIn',
        'orcid'          => 'ORCID',
        'other'          => 'Other',
    ];

    /**
     * Saves what a student reads before shortlisting this supervisor.
     * Interests live on internal_lecturers because only internal
     * lecturers supervise — an external one has no row to write to,
     * which is why a miss here reports that rather than failing.
     */
    public function updateResearchInterests(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/lecturer/profile')->withStatus(302);
        }

        $lecturer = (new Lecturer($this->db))->findByUserId($_SESSION['user_id']);
        $stmt = $this->db->prepare(
            "UPDATE internal_lecturers SET research_interests = :interests WHERE lecturer_id = :lecturer_id"
        );
        $stmt->execute([
            'lecturer_id' => $lecturer['lecturer_id'] ?? '',
            'interests'   => trim((string) ($data['research_interests'] ?? '')) ?: null,
        ]);

        $_SESSION['flash_success'] = 'Research interests saved. Students see these when choosing a supervisor.';

        return $response->withHeader('Location', '/lecturer/profile')->withStatus(302);
    }

    public function addPublicationLink(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/lecturer/profile')->withStatus(302);
        }

        $lecturer = (new Lecturer($this->db))->findByUserId($_SESSION['user_id']);
        $platform = (string) ($data['platform'] ?? '');
        $url = trim((string) ($data['url'] ?? ''));
        $label = trim((string) ($data['label'] ?? ''));

        if (!array_key_exists($platform, self::LINK_PLATFORMS)) {
            $_SESSION['flash_error'] = 'Choose where the profile lives.';
        } elseif (!filter_var($url, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $url)) {
            // Students click these, so anything that is not a real web
            // address is refused rather than rendered as a dead link.
            $_SESSION['flash_error'] = 'That does not look like a web address — it should start with http:// or https://.';
        } elseif ($platform === 'other' && $label === '') {
            $_SESSION['flash_error'] = 'Give the link a name, so students know what they are opening.';
        } else {
            $this->db->prepare(
                "INSERT INTO lecturer_publication_links (link_id, lecturer_id, platform, label, url)
                 VALUES (UUID(), :lecturer_id, :platform, :label, :url)"
            )->execute([
                'lecturer_id' => $lecturer['lecturer_id'] ?? '',
                'platform'    => $platform,
                'label'       => $label ?: null,
                'url'         => $url,
            ]);
            $_SESSION['flash_success'] = 'Link added.';
        }

        return $response->withHeader('Location', '/lecturer/profile')->withStatus(302);
    }

    public function removePublicationLink(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = (array) $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/lecturer/profile')->withStatus(302);
        }

        $lecturer = (new Lecturer($this->db))->findByUserId($_SESSION['user_id']);

        // Scoped to this lecturer, so a posted id cannot delete
        // somebody else's link.
        $this->db->prepare(
            "DELETE FROM lecturer_publication_links WHERE link_id = :id AND lecturer_id = :lecturer_id"
        )->execute([
            'id'          => (string) ($data['link_id'] ?? ''),
            'lecturer_id' => $lecturer['lecturer_id'] ?? '',
        ]);

        $_SESSION['flash_success'] = 'Link removed.';

        return $response->withHeader('Location', '/lecturer/profile')->withStatus(302);
    }

    private function supervisorProfile(string $lecturerId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT il.specialization, il.research_interests, d.name AS department_name
             FROM internal_lecturers il
             JOIN departments d ON d.department_id = il.department_id
             WHERE il.lecturer_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $lecturerId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function publicationLinks(string $lecturerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT link_id, platform, label, url FROM lecturer_publication_links
             WHERE lecturer_id = :id ORDER BY created_at"
        );
        $stmt->execute(['id' => $lecturerId]);

        return $stmt->fetchAll();
    }

    public function toggleAvailability(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($redirect = $this->requireLecturer()) {
            return $response->withHeader('Location', $redirect)->withStatus(302);
        }

        $data = $request->getParsedBody();
        if (!$this->verifyCsrf($data['csrf_token'] ?? '')) {
            $_SESSION['flash_error'] = 'Your session expired — please try again.';
            return $response->withHeader('Location', '/lecturer/profile')->withStatus(302);
        }

        $userId = $_SESSION['user_id'];
        $lecturerModel = new Lecturer($this->db);
        $lecturer = $lecturerModel->findByUserId($userId);

        if (!$lecturer) {
            $_SESSION['flash_error'] = 'Your lecturer profile could not be found.';
            return $response->withHeader('Location', '/lecturer/profile')->withStatus(302);
        }

        // Only turning availability OFF is restricted. Re-checked here
        // server-side regardless of what the form claimed, since the
        // form's disabled state is just a courtesy.
        if ($lecturer['is_available']) {
            $blockers = $this->unavailabilityBlockers($lecturer['lecturer_id'], $userId);
            if ($blockers) {
                $_SESSION['flash_error'] = implode(' ', $blockers);
                return $response->withHeader('Location', '/lecturer/profile')->withStatus(302);
            }
        }

        $lecturerModel->toggleAvailability($lecturer['lecturer_id']);
        $_SESSION['flash_success'] = $lecturer['is_available']
            ? 'You are now marked unavailable.'
            : 'You are now marked available.';

        return $response->withHeader('Location', '/lecturer/profile')->withStatus(302);
    }
}
