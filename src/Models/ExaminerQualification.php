<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Which programs a lecturer is qualified to examine — independent of
 * who supervises whom.
 *
 * A Business lecturer with no Computer Science qualification never
 * appears as a selectable examiner for a CS exam. Both the picker and
 * the invite endpoint must go through qualifiedForProgram(), since a
 * filtered dropdown alone is not enforcement.
 */
class ExaminerQualification
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Every lecturer with the programs they may examine, internal and
     * external alike, so an admin can see who covers what.
     *
     * @return array<int, array<string, mixed>>
     */
    public function allLecturersWithPrograms(): array
    {
        $lecturers = $this->db->query(
            "SELECT l.lecturer_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS lecturer_name,
                    u.email,
                    CASE WHEN il.lecturer_id IS NOT NULL THEN 'internal' ELSE 'external' END AS kind
             FROM lecturers l
             JOIN users u ON u.user_id = l.user_id
             LEFT JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
             ORDER BY kind, u.last_name, u.first_name"
        )->fetchAll();

        $byLecturer = [];
        foreach ($this->db->query(
            "SELECT q.lecturer_id, q.program_id, p.name AS program_name
             FROM examiner_program_qualifications q
             JOIN programs p ON p.program_id = q.program_id
             ORDER BY p.name"
        )->fetchAll() as $row) {
            $byLecturer[$row['lecturer_id']][] = $row;
        }

        foreach ($lecturers as &$lecturer) {
            $lecturer['programs'] = $byLecturer[$lecturer['lecturer_id']] ?? [];
        }

        return $lecturers;
    }

    /**
     * The examiner picker for a program. This is the list, and the
     * invite endpoint must check membership of it server-side.
     *
     * @return array<int, array<string, mixed>>
     */
    public function qualifiedForProgram(string $programId): array
    {
        $stmt = $this->db->prepare(
            "SELECT l.lecturer_id, l.user_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS lecturer_name,
                    CASE WHEN il.lecturer_id IS NOT NULL THEN 'internal' ELSE 'external' END AS kind
             FROM examiner_program_qualifications q
             JOIN lecturers l ON l.lecturer_id = q.lecturer_id
             JOIN users u ON u.user_id = l.user_id
             LEFT JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
             WHERE q.program_id = :program_id
             ORDER BY u.last_name, u.first_name"
        );
        $stmt->execute(['program_id' => $programId]);

        return $stmt->fetchAll();
    }

    public function isQualified(string $lecturerId, string $programId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM examiner_program_qualifications
             WHERE lecturer_id = :lecturer_id AND program_id = :program_id LIMIT 1"
        );
        $stmt->execute(['lecturer_id' => $lecturerId, 'program_id' => $programId]);

        return (bool) $stmt->fetchColumn();
    }

    public function add(string $lecturerId, string $programId, string $addedBy): void
    {
        $stmt = $this->db->prepare(
            "INSERT IGNORE INTO examiner_program_qualifications
                (qualification_id, lecturer_id, program_id, added_by)
             VALUES (UUID(), :lecturer_id, :program_id, :added_by)"
        );
        $stmt->execute([
            'lecturer_id' => $lecturerId,
            'program_id'  => $programId,
            'added_by'    => $addedBy,
        ]);
    }

    public function remove(string $lecturerId, string $programId): void
    {
        $stmt = $this->db->prepare(
            "DELETE FROM examiner_program_qualifications
             WHERE lecturer_id = :lecturer_id AND program_id = :program_id"
        );
        $stmt->execute(['lecturer_id' => $lecturerId, 'program_id' => $programId]);
    }
}
