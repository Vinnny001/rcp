<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use RuntimeException;

/**
 * The topic a student writes their thesis on, and the supervisors'
 * approval of it.
 *
 * Once a student has supervisors, the topic and its synopsis are settled
 * before the Proposal Approval exam: the main supervisor calls a meeting,
 * every supervisor records a decision on each topic put forward, and the
 * main supervisor closes the meeting by naming the one topic approved —
 * only ever one, and only one every supervisor approved.
 *
 * Until then the student may put forward as many topics as they like. If
 * a meeting approves none, all of them stay on record with the reason and
 * the student writes fresh ones. The title sent with the supervisor
 * request is the first topic, so nobody retypes what the supervisors
 * already agreed to take on.
 *
 * The approved topic is written back onto the proposal, so every page
 * that shows a title shows the approved one.
 */
class ProposalTopic
{
    public const MAX_TITLE_LENGTH = 255;
    public const MIN_SYNOPSIS_LENGTH = 50;

    /** What a supervisor may record on a topic. */
    public const DECISIONS = ['approve', 'reject'];

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    // ------------------------------------------------------------------
    // The student's side
    // ------------------------------------------------------------------

    /**
     * What this student may do about their topic right now, worked out
     * from the data rather than trusted from a form.
     *
     * @param array<string, mixed>|null $proposal the student's active proposal
     * @return array{
     *     applies: bool, supervisors: array<int, array<string, mixed>>,
     *     topics: array<int, array<string, mixed>>, approved: ?array,
     *     meeting: ?array, can_submit: bool, waiting_on: string
     * }
     */
    public function studentState(string $studentId, ?array $proposal): array
    {
        $supervisors = $this->supervisorsOf($studentId);
        $applies = $supervisors !== [] && Proposal::isSubmitted($proposal);

        if (!$applies) {
            return [
                'applies' => false, 'supervisors' => $supervisors, 'topics' => [],
                'approved' => null, 'meeting' => null, 'can_submit' => false,
                'waiting_on' => $supervisors === [] ? 'supervisors' : 'nobody',
            ];
        }

        $this->ensureFirstTopic($studentId, $proposal);

        $topics = $this->forStudent($studentId);
        $approved = $this->approvedFor($studentId);
        $meeting = $this->openMeetingFor($studentId);

        return [
            'applies'     => true,
            'supervisors' => $supervisors,
            'topics'      => $topics,
            'approved'    => $approved,
            'meeting'     => $meeting,
            // While the panel is sitting on them, the list is theirs.
            'can_submit'  => $approved === null && $meeting === null,
            'waiting_on'  => match (true) {
                $approved !== null => 'nobody',
                $meeting !== null  => 'meeting',
                default            => 'supervisors',
            },
        ];
    }

    /**
     * Records the title the student was given supervisors for as their
     * first topic, so the first meeting has something to consider.
     * Idempotent, and never touched once anything else exists.
     *
     * @param array<string, mixed> $proposal
     */
    public function ensureFirstTopic(string $studentId, array $proposal): void
    {
        $this->db->prepare(
            "INSERT INTO proposal_topics (topic_id, student_id, proposal_id, title, synopsis, origin, submitted_by)
             SELECT UUID(), :student_id, :proposal_id, :title, :synopsis, 'proposal', s.user_id
             FROM students s
             WHERE s.student_id = :student_id2
               AND NOT EXISTS (SELECT 1 FROM proposal_topics t WHERE t.proposal_id = :proposal_id2)"
        )->execute([
            'student_id'   => $studentId,
            'student_id2'  => $studentId,
            'proposal_id'  => $proposal['proposal_id'],
            'proposal_id2' => $proposal['proposal_id'],
            'title'        => $proposal['title'],
            'synopsis'     => $proposal['synopsis'],
        ]);
    }

    /**
     * Every topic this student has put forward, newest first, each with
     * the decisions recorded on it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forStudent(string $studentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT t.*, m.scheduled_at AS meeting_at, m.status AS meeting_status
             FROM proposal_topics t
             LEFT JOIN meetings m ON m.meeting_id = t.meeting_id
             WHERE t.student_id = :student_id
             ORDER BY t.created_at DESC, t.title"
        );
        $stmt->execute(['student_id' => $studentId]);
        $topics = $stmt->fetchAll();

        $decisions = $this->decisionsByTopic(array_column($topics, 'topic_id'));
        foreach ($topics as &$topic) {
            $topic['decisions'] = $decisions[$topic['topic_id']] ?? [];
        }
        unset($topic);

        return $topics;
    }

    /**
     * The topic the supervisors approved, if they have.
     *
     * @return array<string, mixed>|null
     */
    public function approvedFor(string $studentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM proposal_topics
             WHERE student_id = :student_id AND status = 'approved'
             ORDER BY decided_at DESC LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * Puts a topic forward. Allowed while nothing is approved and no
     * meeting is sitting on the list — as many as the student likes.
     */
    public function submit(string $studentId, string $proposalId, string $title, string $synopsis, string $userId): string
    {
        $title = trim($title);
        $synopsis = trim($synopsis);

        if ($title === '' || mb_strlen($title) > self::MAX_TITLE_LENGTH) {
            throw new RuntimeException('Give the topic a title of up to ' . self::MAX_TITLE_LENGTH . ' characters.');
        }
        if (mb_strlen($synopsis) < self::MIN_SYNOPSIS_LENGTH) {
            throw new RuntimeException('Write a synopsis of at least ' . self::MIN_SYNOPSIS_LENGTH . ' characters, so your supervisors can judge the topic.');
        }
        if ($this->supervisorsOf($studentId) === []) {
            throw new RuntimeException('Your topic is approved by your supervisors, so you need supervisors first.');
        }
        if ($this->approvedFor($studentId) !== null) {
            throw new RuntimeException('Your topic has already been approved.');
        }
        if ($this->openMeetingFor($studentId) !== null) {
            throw new RuntimeException('Your supervisors are meeting about the topics you have already put forward. You can add another if none of them is approved.');
        }

        $topicId = $this->uuid();
        $this->db->prepare(
            "INSERT INTO proposal_topics (topic_id, student_id, proposal_id, title, synopsis, origin, submitted_by)
             VALUES (:topic_id, :student_id, :proposal_id, :title, :synopsis, 'student', :submitted_by)"
        )->execute([
            'topic_id'     => $topicId,
            'student_id'   => $studentId,
            'proposal_id'  => $proposalId,
            'title'        => $title,
            'synopsis'     => $synopsis,
            'submitted_by' => $userId,
        ]);

        return $topicId;
    }

    // ------------------------------------------------------------------
    // The supervisors' side
    // ------------------------------------------------------------------

    /**
     * The students this lecturer supervises whose topic is not settled,
     * plus those whose topic they approved — each with their topics, the
     * meeting if one is called, and whether this lecturer is the main
     * supervisor, who alone calls and closes it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forSupervisor(string $lecturerId, string $userId): array
    {
        $rows = (new Lecturer($this->db))->findActiveSupervisions($lecturerId);

        $students = [];
        foreach ($rows as $row) {
            if (!in_array($row['proposal_status'], ['submitted', 'under_review', 'approved'], true)) {
                continue;
            }

            $meeting = $this->openMeetingFor($row['student_id']);
            $approved = $this->approvedFor($row['student_id']);

            $students[] = $row + [
                'is_main'       => $row['role'] === 'main',
                'topics'        => $this->forStudent($row['student_id']),
                'open_topics'   => $this->topicsAwaitingDecision($row['student_id']),
                'approved'      => $approved,
                'meeting'       => $meeting,
                'your_decision' => $meeting ? $this->decisionsOf($meeting['meeting_id'], $userId) : [],
            ];
        }

        return $students;
    }

    /**
     * Calls the meeting that settles the topic. The main supervisor
     * alone calls it, every supervisor is invited, and so is the student,
     * whose topic it is.
     *
     * @param array{scheduled_at: string, mode: string, location: string, virtual_link: string} $details
     */
    public function scheduleMeeting(string $studentId, string $userId, array $details): string
    {
        $supervisors = $this->supervisorsOf($studentId);
        $main = array_values(array_filter($supervisors, static fn (array $s): bool => $s['role'] === 'main'));

        if ($main === [] || $main[0]['lecturer_user_id'] !== $userId) {
            throw new RuntimeException('Only the main supervisor calls the topic approval meeting.');
        }
        if ($this->approvedFor($studentId) !== null) {
            throw new RuntimeException('This student\'s topic is already approved.');
        }
        if ($this->openMeetingFor($studentId) !== null) {
            throw new RuntimeException('A topic approval meeting is already scheduled for this student.');
        }
        if ($this->topicsAwaitingDecision($studentId) === []) {
            throw new RuntimeException('There is no topic to approve yet — the student has to put one forward.');
        }
        if ($problem = self::detailsError($details)) {
            throw new RuntimeException($problem);
        }

        $student = $this->studentRow($studentId);
        $meetings = new Meeting($this->db);
        $meetingId = $meetings->create($student['proposal_id'], [
            'meeting_type'     => 'topic_approval',
            'scheduled_at'     => date('Y-m-d H:i:s', (int) strtotime($details['scheduled_at'])),
            'mode'             => $details['mode'],
            'location'         => $details['location'],
            'virtual_link'     => $details['virtual_link'],
            'ai_notes_enabled' => false,
        ], $userId);

        foreach ($supervisors as $supervisor) {
            $meetings->addAttendee($meetingId, $supervisor['lecturer_user_id'], 'supervisor');
        }
        // The student presents the topic; they never decide it.
        $meetings->addAttendee($meetingId, $student['user_id'], 'student');

        // The topics on the table are fixed when the meeting is called:
        // anything written afterwards belongs to the next one.
        $this->db->prepare(
            "UPDATE proposal_topics SET meeting_id = :meeting_id
             WHERE student_id = :student_id AND status = 'submitted' AND meeting_id IS NULL"
        )->execute(['meeting_id' => $meetingId, 'student_id' => $studentId]);

        return $meetingId;
    }

    /**
     * Why these meeting details will not do, or null if they will.
     *
     * @param array{scheduled_at: string, mode: string, location: string, virtual_link: string} $details
     */
    public static function detailsError(array $details): ?string
    {
        if (trim($details['scheduled_at']) === '' || strtotime($details['scheduled_at']) === false) {
            return 'Give the meeting a date and time.';
        }
        if (!in_array($details['mode'], ['physical', 'virtual', 'hybrid'], true)) {
            return 'Say whether the meeting is physical, virtual or hybrid.';
        }
        if (in_array($details['mode'], ['physical', 'hybrid'], true) && trim($details['location']) === '') {
            return 'A ' . $details['mode'] . ' meeting needs a location.';
        }
        if (in_array($details['mode'], ['virtual', 'hybrid'], true) && trim($details['virtual_link']) === '') {
            return 'A ' . $details['mode'] . ' meeting needs a meeting link.';
        }

        return null;
    }

    /**
     * The meeting, its topics and who has decided what — everything the
     * supervisors' meeting page shows. Null if this user has no part in
     * it.
     *
     * @return array<string, mixed>|null
     */
    public function meetingPage(string $meetingId, string $userId): ?array
    {
        $meeting = $this->findMeeting($meetingId);
        if ($meeting === null) {
            return null;
        }

        $supervisors = $this->supervisorsOf($meeting['student_id']);
        $isSupervisor = in_array($userId, array_column($supervisors, 'lecturer_user_id'), true);
        if (!$isSupervisor) {
            return null;
        }

        $topics = $this->topicsOfMeeting($meetingId);
        $voters = array_column($supervisors, 'lecturer_user_id');

        foreach ($topics as &$topic) {
            $approvedBy = array_keys(array_filter(
                $topic['decisions'],
                static fn (array $d): bool => $d['decision'] === 'approve'
            ));
            // Only a topic every supervisor approved can be the one.
            $topic['approvable'] = array_diff($voters, $approvedBy) === [];
            $topic['undecided'] = array_values(array_diff($voters, array_keys($topic['decisions'])));
        }
        unset($topic);

        $main = array_values(array_filter($supervisors, static fn (array $s): bool => $s['role'] === 'main'));

        return [
            'meeting'     => $meeting,
            'topics'      => $topics,
            'supervisors' => $supervisors,
            'you_are_main' => $main !== [] && $main[0]['lecturer_user_id'] === $userId,
            'open'        => $meeting['status'] === 'scheduled',
        ];
    }

    /**
     * One supervisor's decision on one topic. Recorded and changed freely
     * while the meeting is open — it is closing that settles anything.
     */
    public function recordDecision(string $meetingId, string $topicId, string $userId, string $decision, string $comment): void
    {
        $meeting = $this->findMeeting($meetingId);
        if ($meeting === null || $meeting['status'] !== 'scheduled') {
            throw new RuntimeException('This meeting is not open.');
        }
        if (!in_array($decision, self::DECISIONS, true)) {
            throw new RuntimeException('Approve the topic or reject it.');
        }
        if (!in_array($userId, array_column($this->supervisorsOf($meeting['student_id']), 'lecturer_user_id'), true)) {
            throw new RuntimeException('Only this student\'s supervisors decide their topic.');
        }
        if (!in_array($topicId, array_column($this->topicsOfMeeting($meetingId), 'topic_id'), true)) {
            throw new RuntimeException('That topic is not on this meeting\'s list.');
        }

        $this->db->prepare(
            "INSERT INTO topic_decisions (decision_id, meeting_id, topic_id, voter_user_id, decision, comment)
             VALUES (UUID(), :meeting_id, :topic_id, :voter, :decision, :comment)
             ON DUPLICATE KEY UPDATE decision = VALUES(decision), comment = VALUES(comment), recorded_at = NOW()"
        )->execute([
            'meeting_id' => $meetingId,
            'topic_id'   => $topicId,
            'voter'      => $userId,
            'decision'   => $decision,
            'comment'    => trim($comment) === '' ? null : trim($comment),
        ]);
    }

    /**
     * Closes the meeting, approving one topic or none.
     *
     * The approved topic is written onto the proposal, since that is the
     * topic the thesis is now written under. Approving none keeps every
     * topic on record with the reason, and the student is free to write
     * new ones.
     */
    public function close(string $meetingId, string $userId, ?string $approvedTopicId, string $reason): void
    {
        $page = $this->meetingPage($meetingId, $userId);
        if ($page === null) {
            throw new RuntimeException('Only this student\'s supervisors decide their topic.');
        }
        if (!$page['you_are_main']) {
            throw new RuntimeException('Only the main supervisor closes the topic approval meeting.');
        }
        if (!$page['open']) {
            throw new RuntimeException('This meeting is already closed.');
        }

        $reason = trim($reason);
        $topics = $page['topics'];

        if ($approvedTopicId === null && $reason === '') {
            throw new RuntimeException('Say why none of the topics was approved, so the student knows what to change.');
        }

        if ($approvedTopicId !== null) {
            $approved = array_values(array_filter($topics, static fn (array $t): bool => $t['topic_id'] === $approvedTopicId));
            if ($approved === []) {
                throw new RuntimeException('That topic is not on this meeting\'s list.');
            }
            if (!$approved[0]['approvable']) {
                throw new RuntimeException('Every supervisor has to approve the topic before it can be the approved one.');
            }
        }

        $decide = $this->db->prepare(
            "UPDATE proposal_topics SET status = :status, decision_reason = :reason, decided_at = NOW()
             WHERE topic_id = :topic_id"
        );
        foreach ($topics as $topic) {
            $decide->execute([
                'status'   => $topic['topic_id'] === $approvedTopicId ? 'approved' : 'not_approved',
                'reason'   => $reason === '' ? null : $reason,
                'topic_id' => $topic['topic_id'],
            ]);
        }

        if ($approvedTopicId !== null) {
            $this->db->prepare(
                "UPDATE thesis_proposals tp
                 JOIN proposal_topics t ON t.topic_id = :topic_id
                 SET tp.title = t.title, tp.synopsis = t.synopsis
                 WHERE tp.proposal_id = t.proposal_id"
            )->execute(['topic_id' => $approvedTopicId]);
        }

        // Approving none demanded a reason above, so there is always
        // something to say on the record.
        (new Meeting($this->db))->changeStatus(
            $meetingId,
            'completed',
            $approvedTopicId !== null ? ($reason !== '' ? $reason : 'Topic approved.') : $reason
        );
    }

    // ------------------------------------------------------------------
    // What the topic gates
    // ------------------------------------------------------------------

    /**
     * Why this student cannot sit the exam for this stage yet, or null.
     *
     * The Proposal Approval exam examines a topic the supervisors have
     * agreed to — booking it before that would put an unsettled topic in
     * front of a panel. Every other stage is past that point.
     */
    public function examBlocker(string $studentId, ?string $stageId): ?string
    {
        if ($stageId === null || !$this->isProposalStage($stageId)) {
            return null;
        }
        if ($this->supervisorsOf($studentId) === [] || $this->approvedFor($studentId) !== null) {
            return null;
        }

        $meeting = $this->openMeetingFor($studentId);

        return $meeting !== null
            ? 'Your supervisors meet on ' . date('d M Y, g:i A', (int) strtotime($meeting['scheduled_at'])) . ' to approve your topic. You can book your exam once they have.'
            : 'Your topic has to be approved by your supervisors before you book this exam.';
    }

    /**
     * The stage the Proposal evidences — the one the approved topic
     * gates. Stages are admin-editable, so this is asked of the data
     * rather than hard-coded.
     */
    private function isProposalStage(string $stageId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM exam_stages s
             JOIN document_types dt ON dt.doc_type_id = s.evidence_doc_type_id
             WHERE s.stage_id = :stage_id AND dt.doc_type_name = 'Proposal' LIMIT 1"
        );
        $stmt->execute(['stage_id' => $stageId]);

        return (bool) $stmt->fetchColumn();
    }

    // ------------------------------------------------------------------
    // Shared queries
    // ------------------------------------------------------------------

    /**
     * The meeting called for this student's topic and still sitting.
     *
     * @return array<string, mixed>|null
     */
    public function openMeetingFor(string $studentId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT m.* FROM meetings m
             JOIN thesis_proposals tp ON tp.proposal_id = m.proposal_id
             WHERE tp.student_id = :student_id
               AND m.meeting_type = 'topic_approval'
               AND m.status IN ('scheduled', 'in_progress')
             ORDER BY m.scheduled_at DESC LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function topicsAwaitingDecision(string $studentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM proposal_topics
             WHERE student_id = :student_id AND status = 'submitted'
             ORDER BY created_at"
        );
        $stmt->execute(['student_id' => $studentId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function topicsOfMeeting(string $meetingId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM proposal_topics WHERE meeting_id = :meeting_id ORDER BY created_at"
        );
        $stmt->execute(['meeting_id' => $meetingId]);
        $topics = $stmt->fetchAll();

        $decisions = $this->decisionsByTopic(array_column($topics, 'topic_id'));
        foreach ($topics as &$topic) {
            $topic['decisions'] = $decisions[$topic['topic_id']] ?? [];
        }
        unset($topic);

        return $topics;
    }

    /**
     * Decisions on these topics, keyed topic id => voter user id.
     *
     * @param array<int, string> $topicIds
     * @return array<string, array<string, array<string, mixed>>>
     */
    private function decisionsByTopic(array $topicIds): array
    {
        if ($topicIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($topicIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT d.*, CONCAT(u.first_name, ' ', u.last_name) AS voter_name
             FROM topic_decisions d
             JOIN users u ON u.user_id = d.voter_user_id
             WHERE d.topic_id IN ($placeholders)
             ORDER BY d.recorded_at"
        );
        $stmt->execute($topicIds);

        $byTopic = [];
        foreach ($stmt->fetchAll() as $row) {
            $byTopic[$row['topic_id']][$row['voter_user_id']] = $row;
        }

        return $byTopic;
    }

    /**
     * One supervisor's decisions at a meeting, keyed by topic.
     *
     * @return array<string, array<string, mixed>>
     */
    private function decisionsOf(string $meetingId, string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM topic_decisions WHERE meeting_id = :meeting_id AND voter_user_id = :user_id"
        );
        $stmt->execute(['meeting_id' => $meetingId, 'user_id' => $userId]);

        $byTopic = [];
        foreach ($stmt->fetchAll() as $row) {
            $byTopic[$row['topic_id']] = $row;
        }

        return $byTopic;
    }

    /**
     * A topic approval meeting with the student it is about.
     *
     * @return array<string, mixed>|null
     */
    public function findMeeting(string $meetingId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*, tp.student_id, s.student_number, s.user_id AS student_user_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name
             FROM meetings m
             JOIN thesis_proposals tp ON tp.proposal_id = m.proposal_id
             JOIN students s ON s.student_id = tp.student_id
             JOIN users u ON u.user_id = s.user_id
             WHERE m.meeting_id = :meeting_id AND m.meeting_type = 'topic_approval' LIMIT 1"
        );
        $stmt->execute(['meeting_id' => $meetingId]);

        return $stmt->fetch() ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function supervisorsOf(string $studentId): array
    {
        return (new Lecturer($this->db))->findActiveSupervisorsForStudent($studentId);
    }

    /**
     * @return array<string, mixed>
     */
    private function studentRow(string $studentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT s.student_id, s.user_id, tp.proposal_id
             FROM students s
             JOIN thesis_proposals tp ON tp.student_id = s.student_id AND tp.status <> 'rejected'
             WHERE s.student_id = :student_id
             ORDER BY tp.created_at DESC LIMIT 1"
        );
        $stmt->execute(['student_id' => $studentId]);
        $row = $stmt->fetch();

        if (!$row) {
            throw new RuntimeException('That student has no proposal to approve a topic for.');
        }

        return $row;
    }

    private function uuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
