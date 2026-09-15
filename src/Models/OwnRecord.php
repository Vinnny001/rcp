<?php

declare(strict_types=1);

namespace App\Models;

use PDO;
use RuntimeException;

/**
 * Nobody holds a role over their own studies.
 *
 * Staff studying for their own degree have a student record and a
 * lecturer record against one user. Wherever a role is exercised over a
 * student — research coordinator, supervisor, examiner, a head invited
 * to decide their supervisor request, a voter — the person holding it
 * must not be that student. This class is where "that student" is
 * worked out, so every guard asks the same question.
 */
class OwnRecord
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /** The user behind a student record, or null if there is no such student. */
    public function userForStudent(string $studentId): ?string
    {
        $stmt = $this->db->prepare("SELECT user_id FROM students WHERE student_id = :id LIMIT 1");
        $stmt->execute(['id' => $studentId]);
        $userId = $stmt->fetchColumn();

        return $userId ? (string) $userId : null;
    }

    /** The user behind the lecturer record, or null. */
    public function userForLecturer(string $lecturerId): ?string
    {
        $stmt = $this->db->prepare("SELECT user_id FROM lecturers WHERE lecturer_id = :id LIMIT 1");
        $stmt->execute(['id' => $lecturerId]);
        $userId = $stmt->fetchColumn();

        return $userId ? (string) $userId : null;
    }

    public function isStudent(string $userId, string $studentId): bool
    {
        return $this->userForStudent($studentId) === $userId;
    }

    /**
     * The student records this user holds — normally none, or one.
     *
     * @return array<int, string>
     */
    public function studentIdsFor(string $userId): array
    {
        $stmt = $this->db->prepare("SELECT student_id FROM students WHERE user_id = :id");
        $stmt->execute(['id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** Whether this user is currently studying on this program. */
    public function studiesOnProgram(string $userId, string $programId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM students st
             JOIN student_thesis_registrations str ON str.student_id = st.student_id AND str.status = 'active'
             JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
             WHERE st.user_id = :user_id AND ts.program_id = :program_id
             LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId, 'program_id' => $programId]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Refuses when the user is the student the record belongs to.
     *
     * @param string $what what they would be doing, finishing "You cannot …"
     */
    public function refuse(string $userId, string $studentId, string $what): void
    {
        if ($this->isStudent($userId, $studentId)) {
            throw new RuntimeException('You cannot ' . $what . ' — it is your own.');
        }
    }
}
