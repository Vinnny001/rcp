<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Meeting
{
    private PDO $db;

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
             WHERE m.status IN ('scheduled', 'in_progress')
               AND m.scheduled_at >= NOW()
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
             WHERE m.status IN ('completed', 'cancelled')
                OR (m.status IN ('scheduled', 'in_progress') AND m.scheduled_at < NOW())
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
                (meeting_id, proposal_id, meeting_type, scheduled_at, location, virtual_link, mode, status, ai_notes_enabled, created_by)
             VALUES
                (:meeting_id, :proposal_id, :meeting_type, :scheduled_at, :location, :virtual_link, :mode, 'scheduled', :ai_notes_enabled, :created_by)"
        );
        $stmt->execute([
            'meeting_id'        => $meetingId,
            'proposal_id'       => $proposalId,
            'meeting_type'      => $data['meeting_type'],
            'scheduled_at'      => $data['scheduled_at'],
            'location'          => $data['location'] ?: null,
            'virtual_link'      => $data['virtual_link'] ?: null,
            'mode'              => $data['mode'],
            'ai_notes_enabled'  => $data['ai_notes_enabled'] ? 1 : 0,
            'created_by'        => $createdByUserId,
        ]);

        return $meetingId;
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
            "SELECT m.*,
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
               AND m.status IN ('scheduled', 'in_progress')
               AND m.scheduled_at >= NOW()
             GROUP BY m.meeting_id
             ORDER BY m.scheduled_at ASC"
        );
        $stmt->execute(['student_id' => $studentId, 'user_id' => $userId, 'user_id2' => $userId]);
        return $stmt->fetchAll();
    }

    public function findPastForStudent(string $studentId, string $userId): array
    {
        $stmt = $this->db->prepare(
            "SELECT m.*,
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
               AND (m.status IN ('completed', 'cancelled')
                    OR (m.status IN ('scheduled', 'in_progress') AND m.scheduled_at < NOW()))
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
                (meeting_id, proposal_id, exam_schedule_id, meeting_type, scheduled_at, location, virtual_link, mode, status, ai_notes_enabled, created_by)
             VALUES
                (:meeting_id, :proposal_id, :exam_schedule_id, :meeting_type, :scheduled_at, :location, :virtual_link, :mode, 'scheduled', :ai_notes_enabled, :created_by)"
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




}