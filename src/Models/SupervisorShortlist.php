<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use RuntimeException;

/**
 * The supervisor assignment workflow, end to end.
 *
 * A student submits one ranked shortlist. The department votes on it
 * as a whole. Only then does the system approach lecturers, one at a
 * time — the preferred main first, then the rest in the student's own
 * rank order — until the student has enough supervisors or the list
 * runs out.
 *
 * Two rules shape most of the logic here:
 *   - Minutes gate the outcome. A vote is not acted on until the
 *     coordinator has finalised the minutes.
 *   - A decline does not need a new meeting. The department approved
 *     the whole shortlist, so the next lecturer on it can be
 *     approached straight away.
 */
class SupervisorShortlist
{
    /** A student ends up with at most this many supervisors, one of them main. */
    public const MAX_SUPERVISORS = 3;

    /** How many lecturers a student may shortlist. */
    public const MAX_CHOICES = 5;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @param array<int, array{lecturer_id: string, rank: int, preferred_main: bool}> $choices
     */
    public function submit(string $studentId, string $proposalId, array $choices): string
    {
        $this->assertValidChoices($choices);
        $this->assertNotSelf($studentId, array_column($choices, 'lecturer_id'));

        // Any earlier shortlist is replaced. Supervisors already
        // appointed from it are untouched — those live in
        // supervision_assignments and outlive the shortlist that
        // produced them.
        $supersede = $this->db->prepare(
            "UPDATE supervisor_shortlists SET status = 'superseded'
             WHERE student_id = :student_id AND status <> 'superseded'"
        );
        $supersede->execute(['student_id' => $studentId]);

        $shortlistId = $this->uuid();
        $this->db->prepare(
            "INSERT INTO supervisor_shortlists (shortlist_id, student_id, proposal_id)
             VALUES (:id, :student_id, :proposal_id)"
        )->execute([
            'id'          => $shortlistId,
            'student_id'  => $studentId,
            'proposal_id' => $proposalId,
        ]);

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

        return $shortlistId;
    }

    /**
     * @param array<int, array{lecturer_id: string, rank: int, preferred_main: bool}> $choices
     */
    private function assertValidChoices(array $choices): void
    {
        if ($choices === []) {
            throw new RuntimeException('Choose at least one supervisor.');
        }
        if (count($choices) > self::MAX_CHOICES) {
            throw new RuntimeException('You can shortlist at most ' . self::MAX_CHOICES . ' supervisors.');
        }

        $preferred = array_filter($choices, static fn (array $c): bool => $c['preferred_main']);
        if (count($preferred) !== 1) {
            throw new RuntimeException('Mark exactly one supervisor as your preferred main supervisor.');
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
     * Refuses a student who shortlists their own lecturer account.
     *
     * Staff studying for their own degree hold both a student and a
     * lecturer record against one user, and the browse page lists every
     * internal lecturer without knowing who is reading it — so this is
     * reachable from the form rather than theoretical. The retired
     * direct-request path grew the same check late, but not before a
     * student had already put a request to themselves on file.
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

    public function findActiveForStudent(string $studentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM supervisor_shortlists
             WHERE student_id = :student_id AND status <> 'superseded'
             ORDER BY created_at DESC LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);

        return $stmt->fetch() ?: null;
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
             ORDER BY c.is_preferred_main DESC, c.rank_position"
        );
        $stmt->execute(['id' => $shortlistId]);

        return $stmt->fetchAll();
    }

    /**
     * The coordinator's queue: shortlists awaiting action on the
     * programs this coordinator holds.
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
            "SELECT s.*, st.student_number, tp.title AS proposal_title,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                    p.program_id, p.name AS program_name, p.department_id,
                    m.meeting_id, m.scheduled_at, m.minutes_finalized_at
             FROM supervisor_shortlists s
             JOIN students st ON st.student_id = s.student_id
             JOIN users u ON u.user_id = st.user_id
             JOIN thesis_proposals tp ON tp.proposal_id = s.proposal_id
             JOIN student_thesis_registrations str ON str.student_id = st.student_id
             JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
             JOIN programs p ON p.program_id = ts.program_id
             LEFT JOIN shortlist_meetings m ON m.shortlist_id = s.shortlist_id
             WHERE s.status IN ('pending_coordinator','meeting_scheduled')
               AND p.program_id IN ($placeholders)
             ORDER BY s.created_at"
        );
        $stmt->execute($programIds);

        return $stmt->fetchAll();
    }

    /**
     * One shortlist with everything the coordinator screen needs, plus
     * the program and department it belongs to — which is also what
     * authorises the coordinator to act on it at all.
     */
    public function findWithContext(string $shortlistId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.*, st.student_number,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                    tp.title AS proposal_title, tp.synopsis,
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
     * Shortlist meetings a department head has been invited to, with
     * how they voted if they have.
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
                    tp.title AS proposal_title
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
        if ($deptHeadIds === []) {
            throw new RuntimeException('Invite at least one department head — the shortlist needs votes.');
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
     * Applies the vote. Refuses until the minutes are finalised — they
     * are the boundary between "the meeting happened" and "the
     * decision takes effect".
     */
    public function recordOutcome(string $meetingId): string
    {
        $stmt = $this->db->prepare(
            "SELECT shortlist_id, minutes, minutes_finalized_at, minutes_approved_at
             FROM shortlist_meetings WHERE meeting_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $meetingId]);
        $meeting = $stmt->fetch();

        if (!$meeting) {
            throw new RuntimeException('That meeting no longer exists.');
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
            "UPDATE supervisor_shortlists SET status = 'approved', decided_at = NOW() WHERE shortlist_id = :id"
        )->execute(['id' => $meeting['shortlist_id']]);

        $this->contactNext($meeting['shortlist_id']);

        return 'approved';
    }

    /**
     * Approaches the next lecturer: the preferred main first, then the
     * rest in the student's rank order. Returns the choice contacted,
     * or null when there is nobody left to ask.
     */
    public function contactNext(string $shortlistId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT choice_id FROM supervisor_shortlist_choices
             WHERE shortlist_id = :id AND request_status = 'not_sent'
             ORDER BY is_preferred_main DESC, rank_position
             LIMIT 1"
        );
        $stmt->execute(['id' => $shortlistId]);
        $choiceId = $stmt->fetchColumn();

        if (!$choiceId) {
            return null;
        }

        $this->db->prepare(
            "UPDATE supervisor_shortlist_choices
             SET request_status = 'pending', sent_at = NOW()
             WHERE choice_id = :id"
        )->execute(['id' => $choiceId]);

        return (string) $choiceId;
    }

    /**
     * A lecturer answers. On acceptance they are appointed and, if
     * slots remain, the next lecturer is approached; on a decline the
     * next is approached without another department meeting, because
     * the vote covered the whole shortlist.
     *
     * @return array{outcome: string, role: ?string, next_contacted: bool, exhausted: bool}
     */
    public function respondToRequest(string $choiceId, bool $accept, ?string $declineReason, string $appointedBy): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, s.student_id, s.proposal_id, s.status AS shortlist_status
             FROM supervisor_shortlist_choices c
             JOIN supervisor_shortlists s ON s.shortlist_id = c.shortlist_id
             WHERE c.choice_id = :id LIMIT 1"
        );
        $stmt->execute(['id' => $choiceId]);
        $choice = $stmt->fetch();

        if (!$choice) {
            throw new RuntimeException('That request no longer exists.');
        }
        if ($choice['request_status'] !== 'pending') {
            throw new RuntimeException('That request has already been answered.');
        }

        if (!$accept) {
            $this->db->prepare(
                "UPDATE supervisor_shortlist_choices
                 SET request_status = 'declined', decline_reason = :reason, decided_at = NOW()
                 WHERE choice_id = :id"
            )->execute(['id' => $choiceId, 'reason' => $declineReason]);

            $next = $this->contactNext($choice['shortlist_id']);
            $exhausted = $next === null && $this->acceptedCount($choice['shortlist_id']) === 0;

            if ($exhausted) {
                // Everyone said no. The student needs a fresh shortlist,
                // which means a fresh meeting and vote.
                $this->db->prepare(
                    "UPDATE supervisor_shortlists SET status = 'exhausted', decided_at = NOW()
                     WHERE shortlist_id = :id"
                )->execute(['id' => $choice['shortlist_id']]);
            }

            return ['outcome' => 'declined', 'role' => null, 'next_contacted' => $next !== null, 'exhausted' => $exhausted];
        }

        $this->db->prepare(
            "UPDATE supervisor_shortlist_choices
             SET request_status = 'accepted', decided_at = NOW()
             WHERE choice_id = :id"
        )->execute(['id' => $choiceId]);

        // The preferred main is always approached first and resolved
        // before anyone else is contacted, so "no main yet" reliably
        // means this acceptance is the main one — including the case
        // where the preferred main declined and the next lecturer to
        // say yes inherits the role.
        $role = $this->hasMainSupervisor($choice['proposal_id']) ? 'co_supervisor' : 'main';

        $this->db->prepare(
            "INSERT INTO supervision_assignments
                (assignment_id, proposal_id, student_id, supervisor_id, role, appointed_by, appointment_date)
             VALUES (UUID(), :proposal_id, :student_id, :supervisor_id, :role, :appointed_by, CURDATE())"
        )->execute([
            'proposal_id'   => $choice['proposal_id'],
            'student_id'    => $choice['student_id'],
            'supervisor_id' => $choice['lecturer_id'],
            'role'          => $role,
            'appointed_by'  => $appointedBy,
        ]);

        $nextContacted = false;
        if ($this->acceptedCount($choice['shortlist_id']) >= self::MAX_SUPERVISORS) {
            // The student has a full panel — stand the rest down rather
            // than leaving lecturers holding requests that can no
            // longer be filled.
            $this->db->prepare(
                "UPDATE supervisor_shortlist_choices
                 SET request_status = 'cancelled', decided_at = NOW()
                 WHERE shortlist_id = :id AND request_status IN ('not_sent','pending')"
            )->execute(['id' => $choice['shortlist_id']]);
        } else {
            $nextContacted = $this->contactNext($choice['shortlist_id']) !== null;
        }

        return ['outcome' => 'accepted', 'role' => $role, 'next_contacted' => $nextContacted, 'exhausted' => false];
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

    private function hasMainSupervisor(string $proposalId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM supervision_assignments
             WHERE proposal_id = :id AND role = 'main' AND is_active = 1 LIMIT 1"
        );
        $stmt->execute(['id' => $proposalId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Requests currently sitting with a lecturer, for their inbox.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingForLecturer(string $lecturerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT c.choice_id, c.rank_position, c.is_preferred_main, c.sent_at,
                    s.shortlist_id, tp.title AS proposal_title, tp.proposal_id,
                    st.student_number,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name
             FROM supervisor_shortlist_choices c
             JOIN supervisor_shortlists s ON s.shortlist_id = c.shortlist_id
             JOIN students st ON st.student_id = s.student_id
             JOIN users u ON u.user_id = st.user_id
             JOIN thesis_proposals tp ON tp.proposal_id = s.proposal_id
             WHERE c.lecturer_id = :lecturer_id AND c.request_status = 'pending'
             ORDER BY c.sent_at"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);

        return $stmt->fetchAll();
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
