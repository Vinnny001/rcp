<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use RuntimeException;

/**
 * The supervisor request workflow, end to end.
 *
 * A student writes a proposal and a ranked list of supervisors and sends
 * them together. From that moment neither can be edited by anyone. The
 * department meets and votes on the request. If it approves, the
 * coordinator sends it to every lecturer on the list at once, and the
 * lecturers have RESPONSE_DAYS to answer.
 *
 * Each row in supervisor_shortlists is one attempt, and its id is the
 * request id. A failed attempt — the department said no, or nobody could
 * be appointed — keeps its outcome on record. What happens next is the
 * coordinator's call: hand the proposal, the list, or both back to the
 * student to revise, or revise the list themselves. Either way the next
 * attempt is a new row pointing back at the failed one.
 *
 * Three rules shape most of the logic here:
 *   - The student's order decides who is appointed, never who replied
 *     first. With more acceptances than places, the highest-ranked take
 *     them, and the highest-ranked of those is the main supervisor.
 *   - An outcome is decided as soon as it can no longer change — when
 *     every lecturer who could still displace an appointment has
 *     answered — and otherwise when the window closes. Waiting longer
 *     could never produce a different result.
 *   - Minutes gate the department's decision. A vote is not acted on
 *     until the minutes are finalised and approved.
 *
 * There is no scheduler, so a window that closes with nobody touching
 * the request is settled lazily: resolveDue() is called by the pages
 * that show requests.
 */
class SupervisorShortlist
{
    /** A student ends up with at most this many supervisors, one of them main. */
    public const MAX_SUPERVISORS = 3;

    /** How many lecturers a student may shortlist. */
    public const MAX_CHOICES = 5;

    /** How long lecturers have to answer once the coordinator sends a request. */
    public const RESPONSE_DAYS = 14;

    /** Attempts that ended without anyone appointed. */
    private const FAILED = ['rejected', 'exhausted'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    // The student's side: composing and sending
    // ------------------------------------------------------------------

    /**
     * What this student may do right now, worked out from the data
     * rather than trusted from a form.
     *
     * @param array<string, mixed>|null $proposal the student's active proposal, if any
     * @return array{
     *     latest: ?array, draft: ?array, proposal_editable: bool,
     *     list_editable: bool, can_send: bool, waiting_on: string
     * }
     */
    public function studentState(string $studentId, ?array $proposal): array
    {
        $latest = $this->latestSentForStudent($studentId);
        $draft = $this->draftForStudent($studentId);
        $status = $latest['status'] ?? null;
        $failed = in_array($status, self::FAILED, true);
        $granted = $failed && $latest['edit_granted_at'] !== null;

        if ($latest === null) {
            // A first attempt. A student with no proposal yet writes one;
            // a proposal submitted before requests travelled with it stays
            // locked, but still needs a list to go out with.
            $proposalEditable = $proposal === null || $proposal['status'] === 'draft';
            $listEditable = true;
            $waitingOn = 'student';
        } elseif ($granted) {
            $proposalEditable = (bool) $latest['edit_proposal_allowed'] && ($proposal['status'] ?? null) === 'draft';
            $listEditable = (bool) $latest['edit_shortlist_allowed'];
            $waitingOn = 'student';
        } else {
            $proposalEditable = false;
            $listEditable = false;
            $waitingOn = match ($status) {
                'pending_coordinator', 'approved' => 'coordinator',
                'meeting_scheduled'               => 'department',
                'requests_sent'                   => 'lecturers',
                'rejected', 'exhausted'           => 'coordinator',
                default                           => 'nobody',
            };
        }

        return [
            'latest'            => $latest,
            'draft'             => $draft,
            'proposal_editable' => $proposalEditable,
            'list_editable'     => $listEditable,
            'can_send'          => $latest === null || $granted,
            'waiting_on'        => $waitingOn,
        ];
    }

    /**
     * Keeps a list the student is still working on. Allowed only while
     * the list is theirs to edit; an empty list is fine for a draft.
     *
     * @param array<string, mixed> $proposal
     * @param array<int, array{lecturer_id: string, rank: int, preferred_main: bool}> $choices
     */
    public function saveDraft(string $studentId, array $proposal, array $choices, string $userId): string
    {
        return $this->atomically(function () use ($studentId, $proposal, $choices, $userId): string {
            $state = $this->studentState($studentId, $proposal);
            if (!$state['list_editable']) {
                throw new RuntimeException($this->lockedMessage($state['waiting_on']));
            }

            $this->assertValidChoices($choices, true);
            $this->assertNotSelf($studentId, array_column($choices, 'lecturer_id'));
            $choices = $this->withPreferredMainFirst($choices);

            $draftId = $state['draft']['shortlist_id'] ?? null;
            if ($draftId === null) {
                $draftId = $this->uuid();
                $this->db->prepare(
                    "INSERT INTO supervisor_shortlists
                        (shortlist_id, student_id, proposal_id, previous_shortlist_id, created_by, status)
                     VALUES (:id, :student_id, :proposal_id, :previous, :created_by, 'draft')"
                )->execute([
                    'id'          => $draftId,
                    'student_id'  => $studentId,
                    'proposal_id' => $proposal['proposal_id'],
                    'previous'    => $state['latest']['shortlist_id'] ?? null,
                    'created_by'  => $userId,
                ]);
            } else {
                $this->db->prepare("DELETE FROM supervisor_shortlist_choices WHERE shortlist_id = :id")
                    ->execute(['id' => $draftId]);
            }

            $this->insertChoices($draftId, $choices);

            return $draftId;
        });
    }

    /**
     * Sends the student's request. The proposal must already be out of
     * draft — the caller submits it in the same transaction — and from
     * here nothing in the request can be changed by anyone.
     *
     * When the coordinator handed back only the proposal, the list goes
     * out again exactly as it was; $choices is ignored.
     *
     * @param array<string, mixed> $proposal
     * @param array<int, array{lecturer_id: string, rank: int, preferred_main: bool}>|null $choices
     */
    public function sendForStudent(string $studentId, array $proposal, ?array $choices, string $userId): string
    {
        return $this->atomically(function () use ($studentId, $proposal, $choices, $userId): string {
            $state = $this->studentState($studentId, $proposal);
            if (!$state['can_send']) {
                throw new RuntimeException($this->lockedMessage($state['waiting_on']));
            }

            if ($this->activeSupervisorCount($studentId) >= self::MAX_SUPERVISORS) {
                throw new RuntimeException('You already have the most supervisors a student can have.');
            }

            $latest = $state['latest'];
            $send = $state['list_editable']
                ? ($choices ?? [])
                : array_map(static fn (array $c): array => [
                    'lecturer_id'    => $c['lecturer_id'],
                    'rank'           => (int) $c['rank_position'],
                    'preferred_main' => (bool) $c['is_preferred_main'],
                ], $this->choicesFor($latest['shortlist_id']));

            if ($state['draft'] !== null) {
                // The draft was only ever a working copy; the request that
                // goes on record is created below.
                $this->db->prepare("DELETE FROM supervisor_shortlists WHERE shortlist_id = :id")
                    ->execute(['id' => $state['draft']['shortlist_id']]);
            }

            return $this->submit(
                $studentId,
                $proposal['proposal_id'],
                $send,
                $userId,
                $latest['shortlist_id'] ?? null
            );
        });
    }

    /**
     * Writes a sent request: validated, ordered with any preferred main
     * first, and carrying a copy of the proposal as it stood.
     *
     * This is the raw write. Who may send, and when, is decided by
     * sendForStudent() and reviseList(), which both call it.
     *
     * @param array<int, array{lecturer_id: string, rank: int, preferred_main: bool}> $choices
     */
    public function submit(
        string $studentId,
        string $proposalId,
        array $choices,
        ?string $createdBy = null,
        ?string $previousId = null
    ): string {
        $this->assertValidChoices($choices);
        $this->assertNotSelf($studentId, array_column($choices, 'lecturer_id'));
        $choices = $this->withPreferredMainFirst($choices);

        $proposal = $this->db->prepare(
            "SELECT tp.title, tp.synopsis, tp.status, st.user_id
             FROM thesis_proposals tp JOIN students st ON st.student_id = tp.student_id
             WHERE tp.proposal_id = :id LIMIT 1"
        );
        $proposal->execute(['id' => $proposalId]);
        $proposal = $proposal->fetch();

        if (!$proposal) {
            throw new RuntimeException('That proposal no longer exists.');
        }
        if ($proposal['status'] === 'draft') {
            throw new RuntimeException('The proposal is still a draft. It is sent together with the supervisor request.');
        }

        $shortlistId = $this->uuid();
        $this->db->prepare(
            "INSERT INTO supervisor_shortlists
                (shortlist_id, student_id, proposal_id, previous_shortlist_id, created_by, status,
                 submitted_at, proposal_title_snapshot, proposal_synopsis_snapshot)
             VALUES (:id, :student_id, :proposal_id, :previous, :created_by, 'pending_coordinator',
                     NOW(), :title, :synopsis)"
        )->execute([
            'id'          => $shortlistId,
            'student_id'  => $studentId,
            'proposal_id' => $proposalId,
            'previous'    => $previousId,
            'created_by'  => $createdBy ?? $proposal['user_id'],
            'title'       => $proposal['title'],
            'synopsis'    => $proposal['synopsis'],
        ]);

        $this->insertChoices($shortlistId, $choices);

        return $shortlistId;
    }

    /**
     * @param array<int, array{lecturer_id: string, rank: int, preferred_main: bool}> $choices
     */
    private function insertChoices(string $shortlistId, array $choices): void
    {
        $insert = $this->db->prepare(
            "INSERT INTO supervisor_shortlist_choices
                (choice_id, shortlist_id, lecturer_id, rank_position, is_preferred_main)
             VALUES (UUID(), :shortlist_id, :lecturer_id, :rank_position, :preferred)"
        );
        foreach ($choices as $choice) {
            $insert->execute([
                'shortlist_id'  => $shortlistId,
                'lecturer_id'   => $choice['lecturer_id'],
                'rank_position' => $choice['rank'],
                'preferred'     => $choice['preferred_main'] ? 1 : 0,
            ]);
        }
    }

    /**
     * Why the student cannot change their request right now, in words
     * that say who has it.
     */
    public function lockedMessage(string $waitingOn): string
    {
        return match ($waitingOn) {
            'coordinator' => 'Your request is with your research coordinator, and cannot be changed while it is.',
            'department'  => 'Your request is before the department, and cannot be changed while it is.',
            'lecturers'   => 'Your request is with the supervisors on your list, and cannot be changed while it is.',
            default       => 'Your request has been decided and cannot be changed.',
        };
    }

    /**
     * @param array<int, array{lecturer_id: string, rank: int, preferred_main: bool}> $choices
     */
    private function assertValidChoices(array $choices, bool $allowEmpty = false): void
    {
        if ($choices === [] && !$allowEmpty) {
            throw new RuntimeException('Choose at least one supervisor.');
        }
        if (count($choices) > self::MAX_CHOICES) {
            throw new RuntimeException('You can shortlist at most ' . self::MAX_CHOICES . ' supervisors.');
        }

        // Naming a preferred main is optional; more than one is the only
        // nonsense.
        $preferred = array_filter($choices, static fn (array $c): bool => $c['preferred_main']);
        if (count($preferred) > 1) {
            throw new RuntimeException('Only one supervisor can be marked as your preferred main supervisor.');
        }

        $lecturerIds = array_column($choices, 'lecturer_id');
        if (count(array_unique($lecturerIds)) !== count($lecturerIds)) {
            throw new RuntimeException('The same lecturer cannot appear twice on a shortlist.');
        }

        $ranks = array_column($choices, 'rank');
        if (count(array_unique($ranks)) !== count($ranks)) {
            throw new RuntimeException('Each supervisor needs a different position in your ranking.');
        }
    }

    /**
     * Puts the preferred main at the head of the list and renumbers
     * from there.
     *
     * The order is what decides who is appointed and who becomes main,
     * so a preferred main belongs at position 1: storing them at 4 would
     * rank three other lecturers above the person the student most wants.
     *
     * @param array<int, array{lecturer_id: string, rank: int, preferred_main: bool}> $choices
     * @return array<int, array{lecturer_id: string, rank: int, preferred_main: bool}>
     */
    private function withPreferredMainFirst(array $choices): array
    {
        usort($choices, static function (array $a, array $b): int {
            return [$b['preferred_main'], $a['rank']] <=> [$a['preferred_main'], $b['rank']];
        });

        foreach (array_keys($choices) as $i) {
            $choices[$i]['rank'] = $i + 1;
        }

        return $choices;
    }

    /**
     * Refuses a student who shortlists their own lecturer account.
     *
     * Staff studying for their own degree hold both a student and a
     * lecturer record against one user, and the browse list shows every
     * internal lecturer without knowing who is reading it.
     *
     * @param array<int, string> $lecturerIds
     */
    private function assertNotSelf(string $studentId, array $lecturerIds): void
    {
        if ($lecturerIds === []) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($lecturerIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM lecturers l
             JOIN students s ON s.user_id = l.user_id
             WHERE s.student_id = ? AND l.lecturer_id IN ($placeholders)"
        );
        $stmt->execute(array_merge([$studentId], array_values($lecturerIds)));

        if ((int) $stmt->fetchColumn() > 0) {
            throw new RuntimeException('You cannot shortlist yourself as your own supervisor.');
        }
    }

    // ------------------------------------------------------------------
    // Reading requests
    // ------------------------------------------------------------------

    /**
     * The student's most recent attempt that has left their hands: the
     * head of the chain, i.e. one that no later attempt follows on from.
     * Following the chain rather than sorting by created_at keeps this
     * right when two attempts land in the same second.
     */
    public function latestSentForStudent(string $studentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.* FROM supervisor_shortlists s
             WHERE s.student_id = :student_id
               AND s.status NOT IN ('draft', 'superseded')
               AND NOT EXISTS (
                   SELECT 1 FROM supervisor_shortlists n
                   WHERE n.previous_shortlist_id = s.shortlist_id
                     AND n.status NOT IN ('draft', 'superseded')
               )
             ORDER BY s.created_at DESC
             LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);

        return $stmt->fetch() ?: null;
    }

    /** Kept for callers that predate drafts; the same as latestSentForStudent(). */
    public function findActiveForStudent(string $studentId): ?array
    {
        return $this->latestSentForStudent($studentId);
    }

    public function draftForStudent(string $studentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM supervisor_shortlists
             WHERE student_id = :student_id AND status = 'draft'
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Every attempt this student has sent, newest first — the record
     * of what was asked for and how each one ended.
     *
     * @return array<int, array<string, mixed>>
     */
    public function historyForStudent(string $studentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*,
                    (SELECT COUNT(*) FROM supervisor_shortlist_choices c WHERE c.shortlist_id = s.shortlist_id) AS choice_count
             FROM supervisor_shortlists s
             WHERE s.student_id = :student_id AND s.status NOT IN ('draft', 'superseded')
             ORDER BY s.created_at DESC"
        );
        $stmt->execute(['student_id' => $studentId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function choicesFor(string $shortlistId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, CONCAT(u.first_name, ' ', u.last_name) AS lecturer_name
             FROM supervisor_shortlist_choices c
             JOIN lecturers l ON l.lecturer_id = c.lecturer_id
             JOIN users u ON u.user_id = l.user_id
             WHERE c.shortlist_id = :id
             ORDER BY c.rank_position"
        );
        $stmt->execute(['id' => $shortlistId]);

        return $stmt->fetchAll();
    }

    /**
     * The coordinator's queue: every request on their programs that is
     * still moving, plus failed ones nobody has followed up yet.
     *
     * @param array<int, string> $programIds
     * @return array<int, array<string, mixed>>
     */
    public function queueForPrograms(array $programIds): array
    {
        if ($programIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($programIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT s.*, st.student_number,
                    COALESCE(s.proposal_title_snapshot, tp.title) AS proposal_title,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                    p.program_id, p.name AS program_name, p.department_id,
                    m.meeting_id, m.scheduled_at, m.minutes_finalized_at, m.minutes_approved_at,
                    (SELECT COUNT(*) FROM supervisor_shortlist_choices c
                      WHERE c.shortlist_id = s.shortlist_id AND c.request_status = 'pending') AS pending_count,
                    (SELECT COUNT(*) FROM supervisor_shortlist_choices c
                      WHERE c.shortlist_id = s.shortlist_id AND c.request_status = 'accepted') AS accepted_count
             FROM supervisor_shortlists s
             JOIN students st ON st.student_id = s.student_id
             JOIN users u ON u.user_id = st.user_id
             JOIN thesis_proposals tp ON tp.proposal_id = s.proposal_id
             JOIN student_thesis_registrations str ON str.student_id = st.student_id
             JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
             JOIN programs p ON p.program_id = ts.program_id
             LEFT JOIN shortlist_meetings m ON m.shortlist_id = s.shortlist_id
             WHERE p.program_id IN ($placeholders)
               AND (
                   s.status IN ('pending_coordinator', 'meeting_scheduled', 'approved', 'requests_sent')
                   OR (s.status IN ('rejected', 'exhausted')
                       AND NOT EXISTS (
                           SELECT 1 FROM supervisor_shortlists n
                           WHERE n.previous_shortlist_id = s.shortlist_id
                             AND n.status NOT IN ('draft', 'superseded')
                       ))
               )
             ORDER BY s.created_at"
        );
        $stmt->execute($programIds);

        return $stmt->fetchAll();
    }

    /**
     * One request with everything the coordinator screen needs, plus
     * the program and department it belongs to — which is also what
     * authorises the coordinator to act on it at all.
     */
    public function findWithContext(string $shortlistId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, st.student_number,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                    COALESCE(s.proposal_title_snapshot, tp.title) AS proposal_title,
                    COALESCE(s.proposal_synopsis_snapshot, tp.synopsis) AS synopsis,
                    tp.status AS proposal_status,
                    p.program_id, p.name AS program_name,
                    d.department_id, d.name AS department_name,
                    m.meeting_id, m.scheduled_at, m.mode, m.location, m.virtual_link,
                    m.minutes, m.minutes_finalized_at, m.minutes_approved_at,
                    m.lead_user_id, m.secretary_user_id,
                    COALESCE(m.secretary_user_id, m.lead_user_id) AS minutes_author_id,
                    CONCAT(lu.first_name, ' ', lu.last_name) AS lead_name,
                    CONCAT(su.first_name, ' ', su.last_name) AS secretary_name
             FROM supervisor_shortlists s
             JOIN students st ON st.student_id = s.student_id
             JOIN users u ON u.user_id = st.user_id
             JOIN thesis_proposals tp ON tp.proposal_id = s.proposal_id
             JOIN student_thesis_registrations str ON str.student_id = st.student_id
             JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
             JOIN programs p ON p.program_id = ts.program_id
             JOIN departments d ON d.department_id = p.department_id
             LEFT JOIN shortlist_meetings m ON m.shortlist_id = s.shortlist_id
             LEFT JOIN users lu ON lu.user_id = m.lead_user_id
             LEFT JOIN users su ON su.user_id = m.secretary_user_id
             WHERE s.shortlist_id = :id
             LIMIT 1"
        );
        $stmt->execute(['id' => $shortlistId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Where a failed request stands: waiting for the coordinator to
     * decide, handed back to the student, or already followed up.
     *
     * @param array<string, mixed> $shortlist
     * @return array{failed: bool, follow_up: ?array, grant_open: bool, can_decide: bool}
     */
    public function decisionState(array $shortlist): array
    {
        $failed = in_array($shortlist['status'], self::FAILED, true);

        $stmt = $this->db->prepare(
            "SELECT shortlist_id, status, created_by, created_at FROM supervisor_shortlists
             WHERE previous_shortlist_id = :id AND status NOT IN ('draft', 'superseded')
             LIMIT 1"
        );
        $stmt->execute(['id' => $shortlist['shortlist_id']]);
        $followUp = $stmt->fetch() ?: null;

        return [
            'failed'     => $failed,
            'follow_up'  => $followUp,
            'grant_open' => $failed && $followUp === null && $shortlist['edit_granted_at'] !== null,
            'can_decide' => $failed && $followUp === null && $shortlist['edit_granted_at'] === null,
        ];
    }

    /**
     * Who was invited to a meeting and how they voted, for the tally
     * the coordinator reads before applying the outcome.
     *
     * @return array<int, array<string, mixed>>
     */
    public function meetingVoters(string $meetingId): array
    {
        $stmt = $this->db->prepare(
            "SELECT i.dept_head_id, dp.name AS position_name,
                    CONCAT(u.first_name, ' ', u.last_name) AS head_name,
                    v.vote, v.comment, v.voted_at
             FROM shortlist_meeting_invitees i
             JOIN department_heads dh ON dh.dept_head_id = i.dept_head_id
             JOIN department_positions dp ON dp.position_id = dh.position_id
             JOIN users u ON u.user_id = dh.user_id
             LEFT JOIN shortlist_meeting_votes v
                    ON v.meeting_id = i.meeting_id AND v.dept_head_id = i.dept_head_id
             WHERE i.meeting_id = :id
             ORDER BY dp.display_order, u.last_name"
        );
        $stmt->execute(['id' => $meetingId]);

        return $stmt->fetchAll();
    }

    /**
     * Request meetings a department head has been invited to, with how
     * they voted if they have.
     *
     * @return array<int, array<string, mixed>>
     */
    public function meetingsForHead(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.meeting_id, m.scheduled_at, m.mode, m.location, m.virtual_link,
                    m.minutes, m.minutes_finalized_at, m.minutes_approved_at,
                    COALESCE(m.secretary_user_id, m.lead_user_id) AS minutes_author_id,
                    s.shortlist_id, s.status,
                    i.dept_head_id, v.vote, v.comment,
                    st.student_number,
                    CONCAT(su.first_name, ' ', su.last_name) AS student_name,
                    COALESCE(s.proposal_title_snapshot, tp.title) AS proposal_title,
                    COALESCE(s.proposal_synopsis_snapshot, tp.synopsis) AS proposal_synopsis
             FROM shortlist_meeting_invitees i
             JOIN department_heads dh ON dh.dept_head_id = i.dept_head_id
             JOIN shortlist_meetings m ON m.meeting_id = i.meeting_id
             JOIN supervisor_shortlists s ON s.shortlist_id = m.shortlist_id
             JOIN students st ON st.student_id = s.student_id
             JOIN users su ON su.user_id = st.user_id
             JOIN thesis_proposals tp ON tp.proposal_id = s.proposal_id
             LEFT JOIN shortlist_meeting_votes v
                    ON v.meeting_id = m.meeting_id AND v.dept_head_id = i.dept_head_id
             WHERE dh.user_id = :user_id AND dh.is_active = 1
             ORDER BY m.scheduled_at DESC"
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll();
    }

    // ------------------------------------------------------------------
    // The department meeting
    // ------------------------------------------------------------------

    /**
     * @param array<int, string> $deptHeadIds
     */
    public function scheduleMeeting(
        string $shortlistId,
        string $scheduledAt,
        string $mode,
        ?string $location,
        ?string $virtualLink,
        array $deptHeadIds,
        string $createdBy,
        ?string $leadUserId = null,
        ?string $secretaryUserId = null
    ): string {
        return $this->atomically(function () use (
            $shortlistId, $scheduledAt, $mode, $location, $virtualLink,
            $deptHeadIds, $createdBy, $leadUserId, $secretaryUserId
        ): string {
            $request = $this->lock($shortlistId);

            // A meeting is only ever about a request that has been sent
            // and is locked. A proposal back in draft means the student is
            // still revising, and there is nothing settled to meet about.
            if ($request['status'] !== 'pending_coordinator') {
                throw new RuntimeException('A meeting can only be scheduled for a request that is waiting for one.');
            }
            if ($this->proposalStatus($request['proposal_id']) === 'draft') {
                throw new RuntimeException('The student is revising the proposal. A meeting can be scheduled once they send it.');
            }
            if ($deptHeadIds === []) {
                throw new RuntimeException('Invite at least one department head — the request needs votes.');
            }

            $meetingId = $this->uuid();
            $this->db->prepare(
                "INSERT INTO shortlist_meetings
                    (meeting_id, shortlist_id, scheduled_at, mode, location, virtual_link,
                     created_by, lead_user_id, secretary_user_id)
                 VALUES (:id, :shortlist_id, :scheduled_at, :mode, :location, :virtual_link,
                         :created_by, :lead_user_id, :secretary_user_id)"
            )->execute([
                'id'                => $meetingId,
                'shortlist_id'      => $shortlistId,
                'scheduled_at'      => $scheduledAt,
                'mode'              => $mode,
                'location'          => $location ?: null,
                'virtual_link'      => $virtualLink ?: null,
                'created_by'        => $createdBy,
                // Somebody always has to be able to write the minutes, so
                // the scheduler leads unless another lead was named.
                'lead_user_id'      => $leadUserId ?: $createdBy,
                'secretary_user_id' => $secretaryUserId ?: null,
            ]);

            $invite = $this->db->prepare(
                "INSERT IGNORE INTO shortlist_meeting_invitees (invitee_id, meeting_id, dept_head_id)
                 VALUES (UUID(), :meeting_id, :dept_head_id)"
            );
            foreach ($deptHeadIds as $headId) {
                $invite->execute(['meeting_id' => $meetingId, 'dept_head_id' => $headId]);
            }

            $this->db->prepare(
                "UPDATE supervisor_shortlists SET status = 'meeting_scheduled' WHERE shortlist_id = :id"
            )->execute(['id' => $shortlistId]);

            return $meetingId;
        });
    }

    /**
     * Who is responsible for the minutes of this meeting: the
     * secretary if one was appointed, otherwise the lead. Returns null
     * only if the meeting has vanished.
     */
    public function minutesAuthorId(string $meetingId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT COALESCE(secretary_user_id, lead_user_id) AS author
             FROM shortlist_meetings WHERE meeting_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $meetingId]);
        $author = $stmt->fetchColumn();

        return $author ? (string) $author : null;
    }

    public function saveMinutes(string $meetingId, string $minutes, bool $finalize): void
    {
        $stmt = $this->db->prepare(
            "UPDATE shortlist_meetings
             SET minutes = :minutes,
                 minutes_finalized_at = CASE WHEN :finalize = 1 THEN COALESCE(minutes_finalized_at, NOW()) ELSE minutes_finalized_at END
             WHERE meeting_id = :id"
        );
        $stmt->execute(['id' => $meetingId, 'minutes' => $minutes, 'finalize' => $finalize ? 1 : 0]);
    }

    /**
     * The coordinator accepts the finalised minutes on behalf of
     * the program. Separate from finalising, which is the author
     * saying what happened rather than anyone accepting it.
     */
    public function approveMinutes(string $meetingId, string $approvedBy): void
    {
        $stmt = $this->db->prepare(
            "SELECT minutes_finalized_at, minutes_approved_at FROM shortlist_meetings WHERE meeting_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $meetingId]);
        $meeting = $stmt->fetch();

        if (!$meeting) {
            throw new RuntimeException('That meeting no longer exists.');
        }
        if ($meeting['minutes_finalized_at'] === null) {
            throw new RuntimeException('These minutes have not been finalised yet.');
        }
        if ($meeting['minutes_approved_at'] !== null) {
            return; // already approved; approving again changes nothing
        }

        $this->db->prepare(
            "UPDATE shortlist_meetings SET minutes_approved_at = NOW(), minutes_approved_by = :by
             WHERE meeting_id = :id"
        )->execute(['id' => $meetingId, 'by' => $approvedBy]);
    }

    public function castVote(string $meetingId, string $deptHeadId, string $vote, ?string $comment = null): void
    {
        if (!in_array($vote, ['approve', 'reject'], true)) {
            throw new RuntimeException('A vote must be either approve or reject.');
        }

        $invited = $this->db->prepare(
            "SELECT 1 FROM shortlist_meeting_invitees
             WHERE meeting_id = :meeting_id AND dept_head_id = :head_id LIMIT 1"
        );
        $invited->execute(['meeting_id' => $meetingId, 'head_id' => $deptHeadId]);
        if (!$invited->fetchColumn()) {
            throw new RuntimeException('Only the heads invited to this meeting can vote on it.');
        }

        $this->db->prepare(
            "INSERT INTO shortlist_meeting_votes (vote_id, meeting_id, dept_head_id, vote, comment)
             VALUES (UUID(), :meeting_id, :head_id, :vote, :comment)
             ON DUPLICATE KEY UPDATE vote = VALUES(vote), comment = VALUES(comment), voted_at = NOW()"
        )->execute([
            'meeting_id' => $meetingId,
            'head_id'    => $deptHeadId,
            'vote'       => $vote,
            'comment'    => $comment,
        ]);
    }

    /**
     * @return array{approve: int, reject: int, invited: int, outcome: string}
     */
    public function tally(string $meetingId): array
    {
        $stmt = $this->db->prepare(
            "SELECT vote, COUNT(*) c FROM shortlist_meeting_votes WHERE meeting_id = :id GROUP BY vote"
        );
        $stmt->execute(['id' => $meetingId]);

        $counts = ['approve' => 0, 'reject' => 0];
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['vote']] = (int) $row['c'];
        }

        $invited = $this->db->prepare("SELECT COUNT(*) FROM shortlist_meeting_invitees WHERE meeting_id = :id");
        $invited->execute(['id' => $meetingId]);

        return [
            'approve' => $counts['approve'],
            'reject'  => $counts['reject'],
            'invited' => (int) $invited->fetchColumn(),
            // A tie rejects: approval needs a majority of those who voted.
            'outcome' => $counts['approve'] > $counts['reject'] ? 'approved' : 'rejected',
        ];
    }

    /**
     * Applies the vote. Refuses until the minutes are finalised and
     * approved — they are the boundary between "the meeting happened"
     * and "the decision takes effect".
     *
     * Approval does not contact anyone. The coordinator sends the
     * request to the lecturers as a separate, deliberate step.
     */
    public function recordOutcome(string $meetingId): string
    {
        $stmt = $this->db->prepare(
            "SELECT m.shortlist_id, m.minutes, m.minutes_finalized_at, m.minutes_approved_at, s.status
             FROM shortlist_meetings m
             JOIN supervisor_shortlists s ON s.shortlist_id = m.shortlist_id
             WHERE m.meeting_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $meetingId]);
        $meeting = $stmt->fetch();

        if (!$meeting) {
            throw new RuntimeException('That meeting no longer exists.');
        }
        if ($meeting['status'] !== 'meeting_scheduled') {
            throw new RuntimeException('The department decision on this request has already been applied.');
        }
        if ($meeting['minutes_finalized_at'] === null) {
            throw new RuntimeException('Finalise the minutes before applying the decision.');
        }
        // Finalising is the author saying "this is what happened".
        // Approving is the coordinator accepting it on behalf of the
        // program. The decision waits on the second, not the first.
        if ($meeting['minutes_approved_at'] === null) {
            throw new RuntimeException('The minutes need coordinator approval before the decision can be applied.');
        }

        $tally = $this->tally($meetingId);
        if ($tally['approve'] + $tally['reject'] === 0) {
            throw new RuntimeException('No votes have been cast yet.');
        }

        if ($tally['outcome'] === 'rejected') {
            $this->db->prepare(
                "UPDATE supervisor_shortlists
                 SET status = 'rejected', rejection_reason = :reason, decided_at = NOW()
                 WHERE shortlist_id = :id"
            )->execute(['id' => $meeting['shortlist_id'], 'reason' => $meeting['minutes']]);

            return 'rejected';
        }

        $this->db->prepare(
            "UPDATE supervisor_shortlists SET status = 'approved' WHERE shortlist_id = :id"
        )->execute(['id' => $meeting['shortlist_id']]);

        return 'approved';
    }

    // ------------------------------------------------------------------
    // Asking the lecturers
    // ------------------------------------------------------------------

    /**
     * Sends the approved request to every lecturer on the list at once.
     *
     * A lecturer already at full supervision load is marked unavailable
     * instead of being asked — they could not accept anyway. Everyone
     * else gets RESPONSE_DAYS to answer. If nobody could be asked at all
     * the outcome is decided on the spot.
     *
     * @return array{sent: int, unavailable: int, outcome: ?string}
     */
    public function sendRequests(string $shortlistId, string $sentBy): array
    {
        return $this->atomically(function () use ($shortlistId, $sentBy): array {
            $request = $this->lock($shortlistId);

            if ($request['status'] === 'requests_sent') {
                throw new RuntimeException('These requests have already been sent.');
            }
            if ($request['status'] !== 'approved') {
                throw new RuntimeException('Requests can only be sent once the department has approved this request.');
            }

            $choices = $this->db->prepare(
                "SELECT choice_id, lecturer_id FROM supervisor_shortlist_choices
                 WHERE shortlist_id = :id AND request_status = 'not_sent'
                 ORDER BY rank_position"
            );
            $choices->execute(['id' => $shortlistId]);

            $lecturers = new Lecturer($this->db);
            $markUnavailable = $this->db->prepare(
                "UPDATE supervisor_shortlist_choices
                 SET request_status = 'unavailable', decided_at = NOW(),
                     decline_reason = 'Already at full supervision load when the request was sent.'
                 WHERE choice_id = :id"
            );
            $markSent = $this->db->prepare(
                "UPDATE supervisor_shortlist_choices SET request_status = 'pending', sent_at = NOW()
                 WHERE choice_id = :id"
            );

            $sent = 0;
            $unavailable = 0;
            foreach ($choices->fetchAll() as $choice) {
                if ($lecturers->hasSupervisionCapacity($choice['lecturer_id'])) {
                    $markSent->execute(['id' => $choice['choice_id']]);
                    $sent++;
                } else {
                    $markUnavailable->execute(['id' => $choice['choice_id']]);
                    $unavailable++;
                }
            }

            $this->db->prepare(
                "UPDATE supervisor_shortlists
                 SET status = 'requests_sent',
                     requests_sent_at = NOW(),
                     requests_sent_by = :by,
                     responses_due_at = DATE_ADD(NOW(), INTERVAL " . self::RESPONSE_DAYS . " DAY)
                 WHERE shortlist_id = :id"
            )->execute(['id' => $shortlistId, 'by' => $sentBy]);

            return ['sent' => $sent, 'unavailable' => $unavailable, 'outcome' => $this->resolveLocked($shortlistId)];
        });
    }

    /**
     * A lecturer answers. The answer is final. Whether it settles the
     * outcome depends on where the lecturer sits in the student's order,
     * so the request is re-examined after every answer.
     *
     * @return array{outcome: string, decided: ?string, appointed_as: ?string}
     */
    public function respondToRequest(string $choiceId, bool $accept, ?string $declineReason): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, s.status AS shortlist_status, s.responses_due_at, s.proposal_id,
                    (s.responses_due_at IS NOT NULL AND s.responses_due_at <= NOW()) AS window_closed
             FROM supervisor_shortlist_choices c
             JOIN supervisor_shortlists s ON s.shortlist_id = c.shortlist_id
             WHERE c.choice_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $choiceId]);
        $choice = $stmt->fetch();

        if (!$choice) {
            throw new RuntimeException('That request no longer exists.');
        }

        // Settle a lapsed window before refusing, so the lecturer who
        // arrives late sees the outcome rather than a request still open.
        if ((int) $choice['window_closed'] === 1 && $choice['request_status'] === 'pending') {
            $this->resolve($choice['shortlist_id']);
            throw new RuntimeException(
                'The time to answer this request ran out on ' . date('d M Y', strtotime($choice['responses_due_at'])) . '.'
            );
        }

        return $this->atomically(function () use ($choice, $accept, $declineReason): array {
            $this->lock($choice['shortlist_id']);

            $fresh = $this->db->prepare("SELECT request_status FROM supervisor_shortlist_choices WHERE choice_id = :id");
            $fresh->execute(['id' => $choice['choice_id']]);
            $status = $fresh->fetchColumn();

            if ($status !== 'pending') {
                throw new RuntimeException(match ($status) {
                    'cancelled' => 'This request was closed — the student\'s higher choices already filled their panel.',
                    'expired'   => 'The time to answer this request has run out.',
                    default     => 'That request has already been answered.',
                });
            }

            if ($accept) {
                if (!(new Lecturer($this->db))->hasSupervisionCapacity($choice['lecturer_id'])) {
                    throw new RuntimeException(
                        'Your supervision load is full, so you cannot accept another student. You can still decline.'
                    );
                }
                $this->db->prepare(
                    "UPDATE supervisor_shortlist_choices SET request_status = 'accepted', decided_at = NOW()
                     WHERE choice_id = :id"
                )->execute(['id' => $choice['choice_id']]);
            } else {
                $this->db->prepare(
                    "UPDATE supervisor_shortlist_choices
                     SET request_status = 'declined', decline_reason = :reason, decided_at = NOW()
                     WHERE choice_id = :id"
                )->execute(['id' => $choice['choice_id'], 'reason' => $declineReason]);
            }

            $decided = $this->resolveLocked($choice['shortlist_id']);

            $appointedAs = null;
            if ($accept && $decided === 'successful') {
                $role = $this->db->prepare(
                    "SELECT role FROM supervision_assignments
                     WHERE proposal_id = :proposal_id AND supervisor_id = :lecturer_id AND is_active = 1 LIMIT 1"
                );
                $role->execute(['proposal_id' => $choice['proposal_id'], 'lecturer_id' => $choice['lecturer_id']]);
                $appointedAs = $role->fetchColumn() ?: null;
            }

            return [
                'outcome'      => $accept ? 'accepted' : 'declined',
                'decided'      => $decided,
                'appointed_as' => $appointedAs,
            ];
        });
    }

    /**
     * Decides the outcome if it can no longer change. Returns
     * 'successful' or 'exhausted' once decided, or null while it still
     * depends on someone who has not answered.
     */
    public function resolve(string $shortlistId): ?string
    {
        return $this->atomically(fn (): ?string => $this->resolveLocked($shortlistId));
    }

    /**
     * Settles every request whose window has closed. There is no
     * scheduler, so the pages that show requests call this on load.
     */
    public function resolveDue(): int
    {
        $ids = $this->db->query(
            "SELECT shortlist_id FROM supervisor_shortlists
             WHERE status = 'requests_sent' AND responses_due_at <= NOW()"
        )->fetchAll(PDO::FETCH_COLUMN);

        $settled = 0;
        foreach ($ids as $id) {
            if ($this->resolve((string) $id) !== null) {
                $settled++;
            }
        }

        return $settled;
    }

    /**
     * Walks the list in the student's order, filling the places they
     * have left.
     *
     * An acceptance takes a place. A decline, or a lecturer who could
     * not be asked, is passed over. An unanswered lecturer is the only
     * thing that can hold the outcome open: while places remain above
     * them they might yet accept and take one ahead of everyone below.
     * Once the places are filled by lecturers ranked above every silent
     * one — or once the window closes — nothing left can change who is
     * appointed, so it is decided.
     *
     * Capacity is checked again here, not only at acceptance: a lecturer
     * may accept several students' requests, and whichever is decided
     * first can fill their load.
     *
     * Expects to run inside a transaction holding the request's lock.
     */
    private function resolveLocked(string $shortlistId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT shortlist_id, student_id, proposal_id, status, requests_sent_by, created_by,
                    (responses_due_at IS NOT NULL AND responses_due_at <= NOW()) AS window_closed
             FROM supervisor_shortlists WHERE shortlist_id = :id FOR UPDATE"
        );
        $stmt->execute(['id' => $shortlistId]);
        $request = $stmt->fetch();

        if (!$request || $request['status'] !== 'requests_sent') {
            return null;
        }

        $windowClosed = (int) $request['window_closed'] === 1;
        $places = self::MAX_SUPERVISORS - $this->activeSupervisorCount($request['student_id']);

        $choices = $this->db->prepare(
            "SELECT choice_id, lecturer_id, request_status FROM supervisor_shortlist_choices
             WHERE shortlist_id = :id ORDER BY rank_position"
        );
        $choices->execute(['id' => $shortlistId]);

        $lecturers = new Lecturer($this->db);
        $appoint = [];
        $filledUp = [];

        foreach ($choices->fetchAll() as $choice) {
            if (count($appoint) >= $places) {
                break;
            }
            if ($choice['request_status'] === 'accepted') {
                if ($lecturers->hasSupervisionCapacity($choice['lecturer_id'])) {
                    $appoint[] = $choice;
                } else {
                    $filledUp[] = $choice;
                }
            } elseif ($choice['request_status'] === 'pending' && !$windowClosed) {
                return null;
            }
        }

        $appointedBy = $request['requests_sent_by'] ?? $request['created_by'];
        $role = $this->hasMainSupervisor($request['proposal_id']) ? 'co_supervisor' : 'main';
        $insert = $this->db->prepare(
            "INSERT INTO supervision_assignments
                (assignment_id, proposal_id, student_id, supervisor_id, role, appointed_by, appointment_date)
             SELECT UUID(), :proposal_id, :student_id, :supervisor_id, :role, :appointed_by, CURDATE()
             FROM DUAL
             WHERE NOT EXISTS (
                 SELECT 1 FROM supervision_assignments
                 WHERE proposal_id = :proposal_check AND supervisor_id = :supervisor_check AND is_active = 1
             )"
        );
        foreach ($appoint as $choice) {
            // The first appointment in the student's order is the main
            // supervisor — never whoever happened to reply first.
            $insert->execute([
                'proposal_id'      => $request['proposal_id'],
                'student_id'       => $request['student_id'],
                'supervisor_id'    => $choice['lecturer_id'],
                'role'             => $role,
                'appointed_by'     => $appointedBy,
                'proposal_check'   => $request['proposal_id'],
                'supervisor_check' => $choice['lecturer_id'],
            ]);
            $role = 'co_supervisor';
        }

        $markFilledUp = $this->db->prepare(
            "UPDATE supervisor_shortlist_choices
             SET request_status = 'unavailable',
                 decline_reason = 'Their supervision load filled up before the outcome was decided.'
             WHERE choice_id = :id"
        );
        foreach ($filledUp as $choice) {
            $markFilledUp->execute(['id' => $choice['choice_id']]);
        }

        $appointedIds = array_column($appoint, 'choice_id');
        $keep = $appointedIds === [] ? "''" : implode(',', array_map([$this->db, 'quote'], $appointedIds));

        // Everyone not appointed is closed off: an acceptance that was
        // not needed, a lecturer never asked, or one who never answered.
        $this->db->prepare(
            "UPDATE supervisor_shortlist_choices
             SET request_status = CASE
                     WHEN request_status = 'pending' AND :closed = 1 THEN 'expired'
                     ELSE 'cancelled'
                 END,
                 decided_at = COALESCE(decided_at, NOW())
             WHERE shortlist_id = :id
               AND request_status IN ('pending', 'not_sent', 'accepted')
               AND choice_id NOT IN ($keep)"
        )->execute(['id' => $shortlistId, 'closed' => $windowClosed ? 1 : 0]);

        $outcome = $appoint !== [] ? 'successful' : 'exhausted';
        $this->db->prepare(
            "UPDATE supervisor_shortlists SET status = :outcome, decided_at = NOW() WHERE shortlist_id = :id"
        )->execute(['id' => $shortlistId, 'outcome' => $outcome]);

        return $outcome;
    }

    // ------------------------------------------------------------------
    // After a failed attempt: the coordinator decides what happens next
    // ------------------------------------------------------------------

    /**
     * Hands the request back to the student to revise — the proposal,
     * the supervisor list, or both. Until the student sends it again,
     * nobody else may change anything or schedule a meeting.
     */
    public function grantEdit(string $shortlistId, bool $proposal, bool $list, string $grantedBy): void
    {
        $this->atomically(function () use ($shortlistId, $proposal, $list, $grantedBy): void {
            $request = $this->lock($shortlistId);
            $this->assertAwaitingDecision($request);

            if (!$proposal && !$list) {
                throw new RuntimeException('Choose what the student may change: the proposal, the supervisor list, or both.');
            }

            $this->db->prepare(
                "UPDATE supervisor_shortlists
                 SET edit_proposal_allowed = :proposal, edit_shortlist_allowed = :list,
                     edit_granted_at = NOW(), edit_granted_by = :by
                 WHERE shortlist_id = :id"
            )->execute([
                'id'       => $shortlistId,
                'proposal' => $proposal ? 1 : 0,
                'list'     => $list ? 1 : 0,
                'by'       => $grantedBy,
            ]);

            if ($proposal) {
                // Back to draft is what makes it the student's to edit
                // again, and only theirs.
                $this->db->prepare("UPDATE thesis_proposals SET status = 'draft' WHERE proposal_id = :id")
                    ->execute(['id' => $request['proposal_id']]);
            }
        });
    }

    /**
     * The coordinator revises the supervisor list themselves. They may
     * never touch the proposal. A new attempt is only created if the
     * list actually differs — holding the same meeting over the same
     * list could only end the same way.
     *
     * @param array<int, array{lecturer_id: string, rank: int, preferred_main: bool}> $choices
     */
    public function reviseList(string $shortlistId, array $choices, string $revisedBy): string
    {
        return $this->atomically(function () use ($shortlistId, $choices, $revisedBy): string {
            $request = $this->lock($shortlistId);
            $this->assertAwaitingDecision($request);

            $this->assertValidChoices($choices);
            $this->assertNotSelf($request['student_id'], array_column($choices, 'lecturer_id'));
            $revised = $this->withPreferredMainFirst($choices);

            $current = array_map(static fn (array $c): array => [
                'lecturer_id'    => $c['lecturer_id'],
                'rank'           => (int) $c['rank_position'],
                'preferred_main' => (bool) $c['is_preferred_main'],
            ], $this->choicesFor($shortlistId));

            $canonical = static fn (array $list): array => array_map(
                static fn (array $c): array => [(string) $c['lecturer_id'], (int) $c['rank'], (bool) $c['preferred_main']],
                $list
            );

            if ($canonical($revised) === $canonical($current)) {
                throw new RuntimeException(
                    'Nothing has changed. A new meeting can only be scheduled once the supervisor list is different.'
                );
            }

            return $this->submit($request['student_id'], $request['proposal_id'], $revised, $revisedBy, $shortlistId);
        });
    }

    /**
     * @param array<string, mixed> $request
     */
    private function assertAwaitingDecision(array $request): void
    {
        $state = $this->decisionState($request);

        if ($state['grant_open']) {
            throw new RuntimeException('The student has been given this request to revise. Wait for them to send it.');
        }
        if (!$state['can_decide']) {
            throw new RuntimeException('This request is not waiting on a decision about what happens next.');
        }
    }

    // ------------------------------------------------------------------
    // The lecturer's inbox
    // ------------------------------------------------------------------

    /**
     * Requests currently waiting on a lecturer's answer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingForLecturer(string $lecturerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.choice_id, c.rank_position, c.is_preferred_main, c.sent_at,
                    s.shortlist_id, s.responses_due_at,
                    (SELECT COUNT(*) FROM supervisor_shortlist_choices o WHERE o.shortlist_id = s.shortlist_id) AS choice_count,
                    COALESCE(s.proposal_title_snapshot, tp.title) AS proposal_title,
                    COALESCE(s.proposal_synopsis_snapshot, tp.synopsis) AS proposal_synopsis,
                    tp.proposal_id,
                    st.student_number,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name
             FROM supervisor_shortlist_choices c
             JOIN supervisor_shortlists s ON s.shortlist_id = c.shortlist_id
             JOIN students st ON st.student_id = s.student_id
             JOIN users u ON u.user_id = st.user_id
             JOIN thesis_proposals tp ON tp.proposal_id = s.proposal_id
             WHERE c.lecturer_id = :lecturer_id AND c.request_status = 'pending'
               AND s.status = 'requests_sent'
             ORDER BY s.responses_due_at"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);

        return $stmt->fetchAll();
    }

    /**
     * What became of the requests a lecturer has already answered —
     * including acceptances that were not needed, which the lecturer
     * would otherwise never hear about.
     *
     * @return array<int, array<string, mixed>>
     */
    public function recentAnswersForLecturer(string $lecturerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.request_status, c.decided_at, s.status AS request_status_overall,
                    COALESCE(s.proposal_title_snapshot, tp.title) AS proposal_title,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                    EXISTS (
                        SELECT 1 FROM supervision_assignments sa
                        WHERE sa.proposal_id = s.proposal_id AND sa.supervisor_id = c.lecturer_id AND sa.is_active = 1
                    ) AS appointed
             FROM supervisor_shortlist_choices c
             JOIN supervisor_shortlists s ON s.shortlist_id = c.shortlist_id
             JOIN students st ON st.student_id = s.student_id
             JOIN users u ON u.user_id = st.user_id
             JOIN thesis_proposals tp ON tp.proposal_id = s.proposal_id
             WHERE c.lecturer_id = :lecturer_id
               AND c.request_status IN ('accepted', 'declined', 'unavailable', 'expired', 'cancelled')
               AND c.sent_at IS NOT NULL
             ORDER BY COALESCE(c.decided_at, s.decided_at) DESC
             LIMIT 15"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);

        return $stmt->fetchAll();
    }

    public function acceptedCount(string $shortlistId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM supervisor_shortlist_choices
             WHERE shortlist_id = :id AND request_status = 'accepted'"
        );
        $stmt->execute(['id' => $shortlistId]);

        return (int) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------------

    private function hasMainSupervisor(string $proposalId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM supervision_assignments
             WHERE proposal_id = :id AND role = 'main' AND is_active = 1 LIMIT 1"
        );
        $stmt->execute(['id' => $proposalId]);

        return (bool) $stmt->fetchColumn();
    }

    private function activeSupervisorCount(string $studentId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM supervision_assignments WHERE student_id = :id AND is_active = 1"
        );
        $stmt->execute(['id' => $studentId]);

        return (int) $stmt->fetchColumn();
    }

    private function proposalStatus(string $proposalId): ?string
    {
        $stmt = $this->db->prepare("SELECT status FROM thesis_proposals WHERE proposal_id = :id LIMIT 1");
        $stmt->execute(['id' => $proposalId]);

        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Locks one request row for the rest of the transaction, so two
     * lecturers answering at the same moment cannot both decide it.
     *
     * @return array<string, mixed>
     */
    private function lock(string $shortlistId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM supervisor_shortlists WHERE shortlist_id = :id FOR UPDATE");
        $stmt->execute(['id' => $shortlistId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('That request no longer exists.');
        }

        return $row;
    }

    /**
     * Runs $work in a transaction, or inside the caller's when one is
     * already open — a controller submitting a proposal and its request
     * together needs both to land or neither.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function atomically(callable $work)
    {
        if ($this->db->inTransaction()) {
            return $work();
        }

        $this->db->beginTransaction();
        try {
            $result = $work();
            $this->db->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
