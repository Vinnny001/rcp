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

}