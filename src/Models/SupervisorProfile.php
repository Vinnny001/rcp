<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * What a student reads before shortlisting a supervisor.
 *
 * Internal lecturers only — external lecturers examine but never
 * supervise, which the join to internal_lecturers enforces rather than
 * a flag anyone has to remember to set.
 *
 * The "previously supervised" list is read straight from real
 * supervision history rather than being something a lecturer writes
 * about themselves, so it cannot drift from the record.
 */
class SupervisorProfile
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function browsable(): array
    {
        $lecturers = $this->db->query(
            "SELECT l.lecturer_id, u.user_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS name,
                    u.email,
                    d.name AS department_name,
                    il.specialization,
                    il.research_interests,
                    -- The same limit Lecturer::hasSupervisionCapacity()
                    -- enforces, so at capacity here means what it means
                    -- when the request is sent.
                    l.max_supervision_load AS max_load,
                    l.is_available,
                    (SELECT COUNT(*) FROM supervision_assignments sa
                      WHERE sa.supervisor_id = l.lecturer_id AND sa.is_active = 1) AS current_load
             FROM lecturers l
             JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
             JOIN users u ON u.user_id = l.user_id
             JOIN departments d ON d.department_id = il.department_id
             WHERE u.is_active = 1
             ORDER BY u.last_name, u.first_name"
        )->fetchAll();

        if ($lecturers === []) {
            return [];
        }

        $links = [];
        foreach ($this->db->query(
            "SELECT lecturer_id, platform, label, url FROM lecturer_publication_links ORDER BY created_at"
        )->fetchAll() as $row) {
            $links[$row['lecturer_id']][] = $row;
        }

        $theses = [];
        foreach ($this->db->query(
            "SELECT sa.supervisor_id, tp.title, tp.synopsis, sa.role
             FROM supervision_assignments sa
             JOIN thesis_proposals tp ON tp.proposal_id = sa.proposal_id
             ORDER BY sa.appointment_date DESC"
        )->fetchAll() as $row) {
            $theses[$row['supervisor_id']][] = $row;
        }

        foreach ($lecturers as &$lecturer) {
            $id = $lecturer['lecturer_id'];
            $lecturer['links']  = $links[$id] ?? [];
            $lecturer['theses'] = $theses[$id] ?? [];
            $lecturer['has_capacity'] = (int) $lecturer['current_load'] < (int) $lecturer['max_load'];
        }

        return $lecturers;
    }
}
