<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Meeting
{
    private PDO $db;

    /**
     * Explicit column list for anything a student can see. `m.*` would
     * drag secure_code along with it, and that code is the supervisor's
     * alone — a student who could read it off their own meetings page
     * could vouch for their own attendance without being there.
     */
    private const STUDENT_SAFE_COLUMNS =
        'm.meeting_id, m.proposal_id, m.meeting_type, m.scheduled_at, m.location, m.virtual_link,
         m.mode, m.status, m.status_description, m.ai_notes_enabled, m.ai_summary,
         m.created_at, m.exam_schedule_id';

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findUpcomingForUser(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*,
                    ma_self.role_in_meeting     AS my_role,
                    ma_self.attendance_status   AS my_attendance_status,
                    p.title                     AS proposal_title,
                    (SELECT COUNT(*) FROM meeting_documents md WHERE md.meeting_id = m.meeting_id) AS document_count,
                    GROUP_CONCAT(DISTINCT CONCAT(u.first_name, ' ', u.last_name, ' (', ma_other.role_in_meeting, ')')
                                 SEPARATOR ', ') AS other_attendees
             FROM meetings m
             INNER JOIN meeting_attendees ma_self
                     ON ma_self.meeting_id = m.meeting_id
                    AND ma_self.user_id = :user_id
             LEFT JOIN thesis_proposals p
                    ON p.proposal_id = m.proposal_id
             LEFT JOIN meeting_attendees ma_other
                    ON ma_other.meeting_id = m.meeting_id
                   AND ma_other.user_id != :user_id2
             LEFT JOIN users u
                    ON u.user_id = ma_other.user_id
             WHERE m.scheduled_at >= NOW()
               AND m.status != 'completed'
             GROUP BY m.meeting_id
             ORDER BY m.scheduled_at ASC"
        );
        $stmt->execute(['user_id' => $userId, 'user_id2' => $userId]);
        return $stmt->fetchAll();
    }

    public function findPastForUser(string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*,
                    ma_self.role_in_meeting     AS my_role,
                    ma_self.attendance_status   AS my_attendance_status,
                    p.title                     AS proposal_title,
                    (SELECT COUNT(*) FROM meeting_documents md WHERE md.meeting_id = m.meeting_id) AS document_count,
                    GROUP_CONCAT(DISTINCT CONCAT(u.first_name, ' ', u.last_name, ' (', ma_other.role_in_meeting, ')')
                                 SEPARATOR ', ') AS other_attendees
             FROM meetings m
             INNER JOIN meeting_attendees ma_self
                     ON ma_self.meeting_id = m.meeting_id
                    AND ma_self.user_id = :user_id
             LEFT JOIN thesis_proposals p
                    ON p.proposal_id = m.proposal_id
             LEFT JOIN meeting_attendees ma_other
                    ON ma_other.meeting_id = m.meeting_id
                   AND ma_other.user_id != :user_id2
             LEFT JOIN users u
                    ON u.user_id = ma_other.user_id
             WHERE m.scheduled_at < NOW()
                OR m.status = 'completed'
             GROUP BY m.meeting_id
             ORDER BY m.scheduled_at DESC"
        );
        $stmt->execute(['user_id' => $userId, 'user_id2' => $userId]);
        return $stmt->fetchAll();
    }


    public function countUpcomingWithinDaysForUser(string $userId, int $days = 7): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT m.meeting_id)
             FROM meetings m
             INNER JOIN meeting_attendees ma ON ma.meeting_id = m.meeting_id
             WHERE ma.user_id = :user_id
               AND m.status IN ('scheduled', 'in_progress')
               AND m.scheduled_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL :days DAY)"
        );
        $stmt->execute(['user_id' => $userId, 'days' => $days]);
        return (int) $stmt->fetchColumn();
    }


    public function create(string $proposalId, array $data, string $createdByUserId): string
    {
        $meetingId = $this->generateUuid();

        $stmt = $this->db->prepare(
            "INSERT INTO meetings
                (meeting_id, proposal_id, meeting_type, scheduled_at, location, virtual_link, mode, status, secure_code, ai_notes_enabled, created_by)
             VALUES
                (:meeting_id, :proposal_id, :meeting_type, :scheduled_at, :location, :virtual_link, :mode, 'scheduled', :secure_code, :ai_notes_enabled, :created_by)"
        );
        $stmt->execute([
            'meeting_id'        => $meetingId,
            'proposal_id'       => $proposalId,
            'meeting_type'      => $data['meeting_type'],
            'scheduled_at'      => $data['scheduled_at'],
            'location'          => $data['location'] ?: null,
            'virtual_link'      => $data['virtual_link'] ?: null,
            'mode'              => $data['mode'],
            'secure_code'       => $this->generateSecureCode(),
            'ai_notes_enabled'  => $data['ai_notes_enabled'] ? 1 : 0,
            'created_by'        => $createdByUserId,
        ]);

        return $meetingId;
    }

    /**
     * The attendance code the supervisor reads out during the meeting.
     * Seven characters drawn from A–Z and 0–9, so it can come out all
     * letters, all digits, or a mix. Ambiguous glyphs (O/0, I/1) are
     * left in the alphabet deliberately — the code is spoken aloud and
     * typed back, and a shorter alphabet costs more entropy than the
     * occasional mis-hear costs.
     */
    public function generateSecureCode(): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
        $code = '';

        for ($i = 0; $i < 7; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }

    /**
     * Meetings created before secure codes existed have a NULL code.
     * Rather than backfilling every historic row, one is minted the
     * first time the supervisor actually needs to read it out.
     */
    public function ensureSecureCode(string $meetingId): ?string
    {
        $stmt = $this->db->prepare("SELECT secure_code FROM meetings WHERE meeting_id = :id LIMIT 1");
        $stmt->execute(['id' => $meetingId]);
        $existing = $stmt->fetchColumn();

        if ($existing === false) {
            return null;
        }
        if ($existing) {
            return (string) $existing;
        }

        $code = $this->generateSecureCode();
        $update = $this->db->prepare(
            "UPDATE meetings SET secure_code = :code WHERE meeting_id = :id AND (secure_code IS NULL OR secure_code = '')"
        );
        $update->execute(['code' => $code, 'id' => $meetingId]);

        // A concurrent request may have minted one first — re-read so
        // both callers agree on which code is authoritative.
        $stmt->execute(['id' => $meetingId]);
        return (string) $stmt->fetchColumn();
    }

    /**
     * Case-insensitive so an examiner typing the code back in lower
     * case still gets in; whitespace is trimmed for the same reason.
     */
    public function verifySecureCode(string $meetingId, string $submitted): bool
    {
        $stmt = $this->db->prepare("SELECT secure_code FROM meetings WHERE meeting_id = :id LIMIT 1");
        $stmt->execute(['id' => $meetingId]);
        $code = $stmt->fetchColumn();

        if (!$code) {
            return false;
        }

        return hash_equals(strtoupper((string) $code), strtoupper(trim($submitted)));
    }

    public function addAttendee(string $meetingId, string $userId, string $role): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO meeting_attendees (attendee_id, meeting_id, user_id, role_in_meeting, attendance_status)
             VALUES (:attendee_id, :meeting_id, :user_id, :role, 'invited')"
        );
        $stmt->execute([
            'attendee_id' => $this->generateUuid(),
            'meeting_id'  => $meetingId,
            'user_id'     => $userId,
            'role'        => $role,
        ]);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }


    public function findUpcomingForStudent(string $studentId, string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT " . self::STUDENT_SAFE_COLUMNS . ",
                    p.title AS proposal_title,
                    CASE WHEN ma_self.attendee_id IS NOT NULL THEN 1 ELSE 0 END AS is_invited,
                    ma_self.attendance_status AS my_attendance_status,
                    (SELECT COUNT(*) FROM meeting_attendees ma_count WHERE ma_count.meeting_id = m.meeting_id) AS attendee_count,
                    GROUP_CONCAT(DISTINCT CONCAT(u.first_name, ' ', u.last_name, ' (', ma_other.role_in_meeting, ')')
                                 SEPARATOR ', ') AS other_attendees
             FROM meetings m
             JOIN thesis_proposals p ON p.proposal_id = m.proposal_id
             LEFT JOIN meeting_attendees ma_self
                    ON ma_self.meeting_id = m.meeting_id AND ma_self.user_id = :user_id
             LEFT JOIN meeting_attendees ma_other
                    ON ma_other.meeting_id = m.meeting_id AND ma_other.user_id != :user_id2
             LEFT JOIN users u ON u.user_id = ma_other.user_id
             WHERE p.student_id = :student_id
               AND m.scheduled_at >= NOW()
               AND m.status != 'completed'
             GROUP BY m.meeting_id
             ORDER BY m.scheduled_at ASC"
        );
        $stmt->execute(['student_id' => $studentId, 'user_id' => $userId, 'user_id2' => $userId]);
        return $stmt->fetchAll();
    }

    public function findPastForStudent(string $studentId, string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT " . self::STUDENT_SAFE_COLUMNS . ",
                    p.title AS proposal_title,
                    CASE WHEN ma_self.attendee_id IS NOT NULL THEN 1 ELSE 0 END AS is_invited,
                    ma_self.attendance_status AS my_attendance_status,
                    (SELECT COUNT(*) FROM meeting_attendees ma_count WHERE ma_count.meeting_id = m.meeting_id) AS attendee_count,
                    GROUP_CONCAT(DISTINCT CONCAT(u.first_name, ' ', u.last_name, ' (', ma_other.role_in_meeting, ')')
                                 SEPARATOR ', ') AS other_attendees
             FROM meetings m
             JOIN thesis_proposals p ON p.proposal_id = m.proposal_id
             LEFT JOIN meeting_attendees ma_self
                    ON ma_self.meeting_id = m.meeting_id AND ma_self.user_id = :user_id
             LEFT JOIN meeting_attendees ma_other
                    ON ma_other.meeting_id = m.meeting_id AND ma_other.user_id != :user_id2
             LEFT JOIN users u ON u.user_id = ma_other.user_id
             WHERE p.student_id = :student_id
               AND (m.scheduled_at < NOW() OR m.status = 'completed')
             GROUP BY m.meeting_id
             ORDER BY m.scheduled_at DESC"
        );
        $stmt->execute(['student_id' => $studentId, 'user_id' => $userId, 'user_id2' => $userId]);
        return $stmt->fetchAll();
    }


        /**
     * Same as create(), but additionally validates the meeting's
     * scheduled_at falls before the given exam_schedule's ends_at —
     * a meeting tied to an exam window can't be booked after that
     * window has already closed. Returns null (and creates nothing)
     * if the constraint is violated.
     */
    public function createForExamSchedule(string $proposalId, string $examScheduleId, array $data, string $createdByUserId): ?string
    {
        $stmt = $this->db->prepare("SELECT ends_at FROM exam_schedule WHERE exam_schedule_id = :id LIMIT 1");
        $stmt->execute(['id' => $examScheduleId]);
        $endsAt = $stmt->fetchColumn();

        if ($endsAt && strtotime($data['scheduled_at']) > strtotime($endsAt)) {
            return null;
        }

        $meetingId = $this->generateUuid();

        $insert = $this->db->prepare(
            "INSERT INTO meetings
                (meeting_id, proposal_id, exam_schedule_id, meeting_type, scheduled_at, location, virtual_link, mode, status, secure_code, ai_notes_enabled, created_by)
             VALUES
                (:meeting_id, :proposal_id, :exam_schedule_id, :meeting_type, :scheduled_at, :location, :virtual_link, :mode, 'scheduled', :secure_code, :ai_notes_enabled, :created_by)"
        );
        $insert->execute([
            'meeting_id'       => $meetingId,
            'proposal_id'      => $proposalId,
            'exam_schedule_id' => $examScheduleId,
            'meeting_type'     => $data['meeting_type'],
            'scheduled_at'     => $data['scheduled_at'],
            'location'         => $data['location'] ?: null,
            'virtual_link'     => $data['virtual_link'] ?: null,
            'mode'             => $data['mode'],
            'secure_code'      => $this->generateSecureCode(),
            'ai_notes_enabled' => $data['ai_notes_enabled'] ? 1 : 0,
            'created_by'       => $createdByUserId,
        ]);

        return $meetingId;
    }




        public function findById(string $meetingId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM meetings WHERE meeting_id = :id LIMIT 1");
        $stmt->execute(['id' => $meetingId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function isAttendee(string $meetingId, string $userId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT role_in_meeting FROM meeting_attendees WHERE meeting_id = :meeting_id AND user_id = :user_id LIMIT 1"
        );
        $stmt->execute(['meeting_id' => $meetingId, 'user_id' => $userId]);
        $role = $stmt->fetchColumn();
        return $role ?: null;
    }

    /**
     * Only editable while status is still 'scheduled' — once in progress
     * or completed, details are locked.
     */
    public function update(string $meetingId, array $data): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE meetings SET meeting_type = :meeting_type, scheduled_at = :scheduled_at,
                    mode = :mode, location = :location, virtual_link = :virtual_link
             WHERE meeting_id = :meeting_id AND status = 'scheduled'"
        );
        $stmt->execute([
            'meeting_type' => $data['meeting_type'],
            'scheduled_at' => $data['scheduled_at'],
            'mode'         => $data['mode'],
            'location'     => $data['location'] ?: null,
            'virtual_link' => $data['virtual_link'] ?: null,
            'meeting_id'   => $meetingId,
        ]);
        return $stmt->rowCount() > 0;
    }



        public function findAttendees(string $meetingId): array
    {
        $stmt = $this->db->prepare(
            "SELECT ma.attendee_id, ma.user_id, ma.role_in_meeting, ma.attendance_status,
                    CONCAT(u.first_name, ' ', u.last_name) AS name
             FROM meeting_attendees ma
             JOIN users u ON u.user_id = ma.user_id
             WHERE ma.meeting_id = :meeting_id
             ORDER BY ma.role_in_meeting"
        );
        $stmt->execute(['meeting_id' => $meetingId]);
        return $stmt->fetchAll();
    }

    public function removeAttendee(string $meetingId, string $userId): void
    {
        $stmt = $this->db->prepare(
            "DELETE FROM meeting_attendees WHERE meeting_id = :meeting_id AND user_id = :user_id"
        );
        $stmt->execute(['meeting_id' => $meetingId, 'user_id' => $userId]);
    }

    /**
     * Moves a meeting between statuses, recording why in
     * status_description. Terminal statuses stay terminal — once a
     * meeting is completed or cancelled it isn't reopened, since
     * attendance and scores have already been recorded against it.
     * Returns false if the meeting was already in a terminal state.
     */
    public function changeStatus(string $meetingId, string $status, ?string $description): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE meetings SET status = :status, status_description = :description
             WHERE meeting_id = :meeting_id AND status IN ('scheduled', 'in_progress')"
        );
        $stmt->execute([
            'status'      => $status,
            'description' => $description,
            'meeting_id'  => $meetingId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * The student a meeting concerns, resolved through its proposal.
     * Used both to offer their documents for review and to keep them
     * out of the lecturer invite lists — a student who also holds a
     * lecturer account would otherwise appear there twice over.
     *
     * @return array{user_id:string, student_id:string, name:string, student_number:?string}|null
     */
    public function findSubjectStudent(string $meetingId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT s.user_id, s.student_id, s.student_number,
                    CONCAT(u.first_name, ' ', u.last_name) AS name
             FROM meetings m
             JOIN thesis_proposals tp ON tp.proposal_id = m.proposal_id
             JOIN students s ON s.student_id = tp.student_id
             JOIN users u ON u.user_id = s.user_id
             WHERE m.meeting_id = :meeting_id
             LIMIT 1"
        );
        $stmt->execute(['meeting_id' => $meetingId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Attaches a document to a meeting for review. Idempotent — the
     * unique key on (meeting_id, document_id) means re-adding the same
     * document is a no-op rather than an error.
     */
    public function attachDocument(string $meetingId, string $documentId, string $addedByUserId): void
    {
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO meeting_documents (meeting_document_id, meeting_id, document_id, added_by)
             VALUES (:meeting_document_id, :meeting_id, :document_id, :added_by)"
        );
        $stmt->execute([
            'meeting_document_id' => $this->generateUuid(),
            'meeting_id'          => $meetingId,
            'document_id'         => $documentId,
            'added_by'            => $addedByUserId,
        ]);
    }

    /**
     * Detaching only unlinks the document from the meeting — any scores
     * already recorded against it in document_review_scores survive,
     * because those belong to the document, not to this meeting.
     */
    public function detachDocument(string $meetingId, string $documentId): void
    {
        $stmt = $this->db->prepare(
            "DELETE FROM meeting_documents WHERE meeting_id = :meeting_id AND document_id = :document_id"
        );
        $stmt->execute(['meeting_id' => $meetingId, 'document_id' => $documentId]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findDocuments(string $meetingId): array
    {
        $stmt = $this->db->prepare(
            "SELECT md.meeting_document_id, md.added_at,
                    d.document_id, d.file_name, d.file_path, d.document_status, d.validation_status,
                    dt.doc_type_name
             FROM meeting_documents md
             JOIN documents d ON d.document_id = md.document_id
             JOIN document_types dt ON dt.doc_type_id = d.document_type_id
             WHERE md.meeting_id = :meeting_id
             ORDER BY dt.doc_type_name, d.file_name"
        );
        $stmt->execute(['meeting_id' => $meetingId]);
        return $stmt->fetchAll();
    }

    /**
     * Replaces a meeting's document set with exactly $documentIds —
     * used by the edit form, where the supervisor can add and remove
     * review documents in the same save.
     *
     * @param array<int, string> $documentIds
     */
    public function syncDocuments(string $meetingId, array $documentIds, string $addedByUserId): void
    {
        $current = array_column($this->findDocuments($meetingId), 'document_id');

        foreach (array_diff($current, $documentIds) as $stale) {
            $this->detachDocument($meetingId, $stale);
        }

        foreach (array_diff($documentIds, $current) as $fresh) {
            $this->attachDocument($meetingId, $fresh, $addedByUserId);
        }
    }




}