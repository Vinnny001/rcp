<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Lecturer
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Lecturers a student can propose as supervisor.
     * Assumes a `department` column on `lecturers` and name fields on `users`.
     * Adjust the WHERE clause if you gate this by "accepting students" / load.
     *
     * $excludeUserId leaves out the proposing student's own identity —
     * a student who also holds a lecturer account must never be able to
     * propose themselves as their own supervisor.
     *
     * @return array<int, array{lecturer_id:string, name:string, department:string}>
     */
    public function listAvailableSupervisors(?string $excludeUserId = null): array
    {
        $stmt = $this->db->prepare(
            "SELECT l.lecturer_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS name,
                    COALESCE(d.name, el.department) AS department
             FROM lecturers l
             JOIN users u ON u.user_id = l.user_id
             LEFT JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
             LEFT JOIN departments d ON d.department_id = il.department_id
             LEFT JOIN external_lecturers el ON el.lecturer_id = l.lecturer_id
             WHERE l.is_available = 1" . ($excludeUserId !== null ? " AND l.user_id != :exclude_user_id" : "") . "
             ORDER BY u.first_name, u.last_name"
        );

        $stmt->execute($excludeUserId !== null ? ['exclude_user_id' => $excludeUserId] : []);

        return $stmt->fetchAll() ?: [];
    }


    public function findByUserId(string $userId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM lecturers WHERE user_id = :user_id LIMIT 1"
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * User ids of lecturers currently supervising at least one active
     * assignment — the admin's "active supervisors only" notification
     * audience.
     *
     * @return array<int, string>
     */
    public function activeSupervisorUserIds(): array
    {
        $stmt = $this->db->query(
            "SELECT DISTINCT u.user_id
             FROM supervision_assignments sa
             JOIN lecturers l ON l.lecturer_id = sa.supervisor_id
             JOIN users u ON u.user_id = l.user_id
             WHERE sa.is_active = 1"
        );
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * User ids of lecturers flagged as examiners — the admin's
     * "examiners only" notification audience.
     *
     * @return array<int, string>
     */
    public function examinerUserIds(): array
    {
        $stmt = $this->db->query(
            "SELECT u.user_id
             FROM lecturers l
             JOIN users u ON u.user_id = l.user_id
             WHERE l.is_examiner = 1"
        );
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * A lecturer's affiliation, whichever table actually has a row for
     * them — internal_lecturers checked first, since a lecturer can
     * only be one or the other, not both, at a time. Null if neither
     * has been recorded yet (the gap the admin Users page's own notice
     * already describes).
     *
     * @return array{type:string}|null
     */
    public function findAffiliation(string $lecturerId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT il.staff_id, il.staff_number, il.department_id, il.specialization, d.name AS department_name
             FROM internal_lecturers il
             LEFT JOIN departments d ON d.department_id = il.department_id
             WHERE il.lecturer_id = :lecturer_id LIMIT 1"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
        $row = $stmt->fetch();
        if ($row) {
            return array_merge(['type' => 'internal'], $row);
        }

        $stmt = $this->db->prepare(
            "SELECT non_staff_id, non_staff_number, department, specialization, institution
             FROM external_lecturers WHERE lecturer_id = :lecturer_id LIMIT 1"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
        $row = $stmt->fetch();
        if ($row) {
            return array_merge(['type' => 'external'], $row);
        }

        return null;
    }

    /**
     * Marks a lecturer internal, wiping any prior external record —
     * a lecturer is one or the other, never both. staff_number and
     * department_id are required by the table itself (NOT NULL).
     */
    public function setInternal(string $lecturerId, string $staffNumber, string $departmentId, ?string $specialization): void
    {
        // Wrapped in a transaction: if the insert/update below fails
        // (e.g. a duplicate staff_number), the delete must roll back
        // too — otherwise a failed switch would leave the lecturer with
        // neither an internal nor an external record at all.
        $this->db->beginTransaction();

        try {
            $this->db->prepare("DELETE FROM external_lecturers WHERE lecturer_id = :lecturer_id")
                ->execute(['lecturer_id' => $lecturerId]);

            $existing = $this->db->prepare("SELECT staff_id FROM internal_lecturers WHERE lecturer_id = :lecturer_id LIMIT 1");
            $existing->execute(['lecturer_id' => $lecturerId]);
            $staffId = $existing->fetchColumn();

            if ($staffId) {
                $stmt = $this->db->prepare(
                    "UPDATE internal_lecturers
                     SET staff_number = :staff_number, department_id = :department_id, specialization = :specialization
                     WHERE staff_id = :staff_id"
                );
                $stmt->execute([
                    'staff_number'   => $staffNumber,
                    'department_id'  => $departmentId,
                    'specialization' => $specialization,
                    'staff_id'       => $staffId,
                ]);
            } else {
                $stmt = $this->db->prepare(
                    "INSERT INTO internal_lecturers (staff_id, lecturer_id, staff_number, department_id, specialization)
                     VALUES (:staff_id, :lecturer_id, :staff_number, :department_id, :specialization)"
                );
                $stmt->execute([
                    'staff_id'       => $this->generateUuid(),
                    'lecturer_id'    => $lecturerId,
                    'staff_number'   => $staffNumber,
                    'department_id'  => $departmentId,
                    'specialization' => $specialization,
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /**
     * Marks a lecturer external, wiping any prior internal record.
     * Every external_lecturers column besides the keys is nullable, so
     * this can record as little or as much as admin has on hand.
     */
    public function setExternal(
        string $lecturerId,
        ?string $nonStaffNumber,
        ?string $department,
        ?string $specialization,
        ?string $institution
    ): void {
        // Same atomicity reasoning as setInternal() above.
        $this->db->beginTransaction();

        try {
            $this->db->prepare("DELETE FROM internal_lecturers WHERE lecturer_id = :lecturer_id")
                ->execute(['lecturer_id' => $lecturerId]);

            $existing = $this->db->prepare("SELECT non_staff_id FROM external_lecturers WHERE lecturer_id = :lecturer_id LIMIT 1");
            $existing->execute(['lecturer_id' => $lecturerId]);
            $nonStaffId = $existing->fetchColumn();

            if ($nonStaffId) {
                $stmt = $this->db->prepare(
                    "UPDATE external_lecturers
                     SET non_staff_number = :non_staff_number, department = :department,
                         specialization = :specialization, institution = :institution
                     WHERE non_staff_id = :non_staff_id"
                );
                $stmt->execute([
                    'non_staff_number' => $nonStaffNumber,
                    'department'       => $department,
                    'specialization'   => $specialization,
                    'institution'      => $institution,
                    'non_staff_id'     => $nonStaffId,
                ]);
            } else {
                $stmt = $this->db->prepare(
                    "INSERT INTO external_lecturers (non_staff_id, lecturer_id, non_staff_number, department, specialization, institution)
                     VALUES (:non_staff_id, :lecturer_id, :non_staff_number, :department, :specialization, :institution)"
                );
                $stmt->execute([
                    'non_staff_id'      => $this->generateUuid(),
                    'lecturer_id'       => $lecturerId,
                    'non_staff_number'  => $nonStaffNumber,
                    'department'        => $department,
                    'specialization'    => $specialization,
                    'institution'       => $institution,
                ]);
            }

            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function updateMaxSupervisionLoad(string $lecturerId, int $value): void
    {
        $stmt = $this->db->prepare(
            "UPDATE lecturers SET max_supervision_load = :value WHERE lecturer_id = :lecturer_id"
        );
        $stmt->execute(['value' => $value, 'lecturer_id' => $lecturerId]);
    }

    public function toggleAvailability(string $lecturerId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE lecturers SET is_available = NOT is_available WHERE lecturer_id = :lecturer_id"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
    }

    public function countActiveSupervisions(string $lecturerId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM supervision_assignments
             WHERE supervisor_id = :lecturer_id AND is_active = 1"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * The internal lecturer to auto-assign an unresponsive proposal to:
     * available, internal, with at least one remaining supervision
     * slot, highest remaining slot wins — a tie is broken by RAND()
     * ordering after the primary sort, which is exactly "pick randomly
     * among whoever's tied for the most room."
     *
     * lecturers.max_supervision_load is authoritative here, not
     * internal_lecturers.max_supervision_load — the latter is often
     * unset in practice; the former is what the admin Users page
     * actually edits.
     */
    public function findBestAvailableInternalCandidate(): ?array
    {
        $stmt = $this->db->query(
            "SELECT l.lecturer_id, u.user_id,
                    l.max_supervision_load - (
                        SELECT COUNT(*) FROM supervision_assignments sa
                        WHERE sa.supervisor_id = l.lecturer_id AND sa.is_active = 1
                    ) AS remaining_slots
             FROM lecturers l
             JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
             JOIN users u ON u.user_id = l.user_id
             WHERE l.is_available = 1
             HAVING remaining_slots > 0
             ORDER BY remaining_slots DESC, RAND()
             LIMIT 1"
        );
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findPendingProposalReviews(string $lecturerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT p.proposal_id, p.title, p.status, p.submission_date,
                    s.student_number,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name
             FROM thesis_proposals p
             JOIN students s ON s.student_id = p.student_id
             JOIN users u ON u.user_id = s.user_id
             WHERE p.proposed_supervisor_id = :lecturer_id
               AND p.status IN ('submitted', 'under_review')
             ORDER BY p.submission_date ASC"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
        return $stmt->fetchAll();
    }


    public function findActiveSupervisions(string $lecturerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT sa.assignment_id, sa.role, sa.proposal_id, sa.student_id,
                    s.student_number, s.user_id AS student_user_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                    p.status AS proposal_status
             FROM supervision_assignments sa
             JOIN students s ON s.student_id = sa.student_id
             JOIN users u ON u.user_id = s.user_id
             LEFT JOIN thesis_proposals p ON p.proposal_id = sa.proposal_id
             WHERE sa.supervisor_id = :lecturer_id
               AND sa.is_active = 1
             ORDER BY u.first_name, u.last_name"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
        return $stmt->fetchAll();
    }

    /**
     * The mirror of findActiveSupervisions() — every lecturer currently
     * (actively) supervising one student, for that student's chat
     * thread list (main + co-supervisor both appear as separate
     * sendable threads).
     */
    public function findActiveSupervisorsForStudent(string $studentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT sa.assignment_id, sa.role, l.lecturer_id, u.user_id AS lecturer_user_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS lecturer_name
             FROM supervision_assignments sa
             JOIN lecturers l ON l.lecturer_id = sa.supervisor_id
             JOIN users u ON u.user_id = l.user_id
             WHERE sa.student_id = :student_id
               AND sa.is_active = 1
             ORDER BY u.first_name, u.last_name"
        );
        $stmt->execute(['student_id' => $studentId]);
        return $stmt->fetchAll();
    }

    /**
     * Builds a `NOT IN (...)` fragment plus its bound parameters for a
     * variable-length list of user ids. Returns an empty fragment when
     * the list is empty, so callers can interpolate unconditionally.
     *
     * @param array<int, string> $userIds
     * @return array{0:string, 1:array<string, string>}
     */
    private function exclusionClause(array $userIds, string $prefix): array
    {
        $userIds = array_values(array_unique(array_filter($userIds)));

        if (!$userIds) {
            return ['', []];
        }

        $placeholders = [];
        $params = [];

        foreach ($userIds as $i => $userId) {
            $placeholders[] = ':' . $prefix . $i;
            $params[$prefix . $i] = $userId;
        }

        return [' AND l.user_id NOT IN (' . implode(', ', $placeholders) . ')', $params];
    }

    /**
     * Lecturers available to invite as attendees.
     *
     * $alsoExclude carries the meeting's own student: a supervised
     * student may hold a lecturer account too, and they must not turn
     * up in the "other attendees" list — they attend as the student,
     * through the include-student checkbox, or not at all.
     *
     * @param array<int, string> $alsoExclude user ids to leave out
     */
    public function listAllExcept(string $excludeUserId, array $alsoExclude = []): array
    {
        [$clause, $params] = $this->exclusionClause($alsoExclude, 'ex');

        $stmt = $this->db->prepare(
            "SELECT l.lecturer_id, l.user_id,
                    COALESCE(d.name, el.department) AS department,
                    COALESCE(d.name, el.institution) AS affiliation,
                    CONCAT(u.first_name, ' ', u.last_name) AS name
             FROM lecturers l
             JOIN users u ON u.user_id = l.user_id
             LEFT JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
             LEFT JOIN departments d ON d.department_id = il.department_id
             LEFT JOIN external_lecturers el ON el.lecturer_id = l.lecturer_id
             WHERE l.user_id != :exclude_user_id" . $clause . "
             ORDER BY u.first_name, u.last_name"
        );
        $stmt->execute(['exclude_user_id' => $excludeUserId] + $params);
        return $stmt->fetchAll();
    }

    /**
     * Every user id that is a student supervised by this lecturer AND
     * also holds a lecturer account. These are the accounts that would
     * otherwise show up in an invite dropdown as a colleague while
     * being the subject of the meeting.
     *
     * @return array<int, string>
     */
    public function findSuperviseeUserIdsWhoAreLecturers(string $lecturerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT s.user_id
             FROM supervision_assignments sa
             JOIN students s ON s.student_id = sa.student_id
             JOIN lecturers l ON l.user_id = s.user_id
             WHERE sa.supervisor_id = :lecturer_id AND sa.is_active = 1"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * Documents belonging to this lecturer's active supervisees, so the
     * general (non-exam) meeting scheduler can offer a document to
     * review. Keyed by student for client-side filtering once a student
     * is picked.
     */
    public function findSuperviseeDocuments(string $lecturerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT d.document_id, d.file_name, d.document_status, d.uploaded_at,
                    dt.doc_type_name,
                    s.user_id AS student_user_id,
                    sa.proposal_id
             FROM supervision_assignments sa
             JOIN students s ON s.student_id = sa.student_id
             JOIN documents d ON d.user_id = s.user_id
             JOIN document_types dt ON dt.doc_type_id = d.document_type_id
             WHERE sa.supervisor_id = :lecturer_id
               AND sa.is_active = 1
             ORDER BY d.uploaded_at DESC"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
        return $stmt->fetchAll();
    }

    /**
     * Documents belonging to one specific student, for the meeting edit
     * form where the student is already known.
     */
    public function findDocumentsForStudentUser(string $studentUserId): array
    {
        $stmt = $this->db->prepare(
            "SELECT d.document_id, d.file_name, d.document_status, d.uploaded_at,
                    dt.doc_type_name
             FROM documents d
             JOIN document_types dt ON dt.doc_type_id = d.document_type_id
             WHERE d.user_id = :user_id
             ORDER BY d.uploaded_at DESC"
        );
        $stmt->execute(['user_id' => $studentUserId]);
        return $stmt->fetchAll();
    }


        /**
     * Exam schedules relevant to this lecturer's active supervisees —
     * one row per (student, exam_schedule) pair, only upcoming ones
     * (ends_at in the future or null). Used both to show the lecturer
     * what's coming up, and to constrain meeting creation to a valid
     * window (must happen before ends_at).
     */
    public function findUpcomingExamSchedulesForSupervisees(string $lecturerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT es.exam_schedule_id, es.thesis_schedule_id, es.starts_at, es.ends_at,
                    es.exam_type, es.exam_schedule_description,
                    sa.student_id, sa.proposal_id,
                    s.student_number, s.user_id AS student_user_id,
                    CONCAT(u.first_name, ' ', u.last_name) AS student_name
             FROM supervision_assignments sa
             JOIN students s ON s.student_id = sa.student_id
             JOIN users u ON u.user_id = s.user_id
             JOIN student_thesis_registrations str
                    ON str.student_id = sa.student_id AND str.status = 'active'
             JOIN exam_schedule es ON es.thesis_schedule_id = str.thesis_schedule_id
             WHERE sa.supervisor_id = :lecturer_id
               AND sa.is_active = 1
               AND (es.ends_at IS NULL OR es.ends_at >= NOW())
               AND NOT EXISTS (
                   SELECT 1 FROM meetings m2
                   WHERE m2.exam_schedule_id = es.exam_schedule_id
                     AND m2.proposal_id = sa.proposal_id
                     AND m2.status != 'cancelled'
               )
             ORDER BY es.ends_at ASC"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
        return $stmt->fetchAll();
    }

    /**
     * @param array<int, string> $alsoExclude see listAllExcept()
     */
    public function listInternalLecturersExcept(string $excludeUserId, array $alsoExclude = []): array
    {
        [$clause, $params] = $this->exclusionClause($alsoExclude, 'ex');

        $stmt = $this->db->prepare(
            "SELECT l.lecturer_id, l.user_id, CONCAT(u.first_name, ' ', u.last_name) AS name,
                    d.name AS affiliation
             FROM lecturers l
             JOIN users u ON u.user_id = l.user_id
             JOIN internal_lecturers il ON il.lecturer_id = l.lecturer_id
             LEFT JOIN departments d ON d.department_id = il.department_id
             WHERE l.user_id != :exclude" . $clause . "
             ORDER BY u.first_name, u.last_name"
        );
        $stmt->execute(['exclude' => $excludeUserId] + $params);
        return $stmt->fetchAll();
    }

    /**
     * @param array<int, string> $alsoExclude see listAllExcept()
     */
    public function listExternalLecturersExcept(string $excludeUserId, array $alsoExclude = []): array
    {
        [$clause, $params] = $this->exclusionClause($alsoExclude, 'ex');

        $stmt = $this->db->prepare(
            "SELECT l.lecturer_id, l.user_id, CONCAT(u.first_name, ' ', u.last_name) AS name,
                    el.institution AS affiliation
             FROM lecturers l
             JOIN users u ON u.user_id = l.user_id
             JOIN external_lecturers el ON el.lecturer_id = l.lecturer_id
             WHERE l.user_id != :exclude" . $clause . "
             ORDER BY u.first_name, u.last_name"
        );
        $stmt->execute(['exclude' => $excludeUserId] + $params);
        return $stmt->fetchAll();
    }

    /**
     * 'internal', 'external', or null if the user isn't in either table
     * (or isn't a lecturer at all) — used to enforce exam_type-based
     * invite restrictions server-side, not just in the UI.
     */
    public function getTypeByUserId(string $userId): ?string
    {
        $stmt = $this->db->prepare("SELECT lecturer_id FROM lecturers WHERE user_id = :user_id LIMIT 1");
        $stmt->execute(['user_id' => $userId]);
        $lecturerId = $stmt->fetchColumn();
        if (!$lecturerId) {
            return null;
        }

        $stmt = $this->db->prepare("SELECT 1 FROM internal_lecturers WHERE lecturer_id = :id LIMIT 1");
        $stmt->execute(['id' => $lecturerId]);
        if ($stmt->fetchColumn()) {
            return 'internal';
        }

        $stmt = $this->db->prepare("SELECT 1 FROM external_lecturers WHERE lecturer_id = :id LIMIT 1");
        $stmt->execute(['id' => $lecturerId]);
        if ($stmt->fetchColumn()) {
            return 'external';
        }

        return null;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }




        /**
     * Full thesis record for every student this lecturer supervises
     * (active supervision_assignments only), joined with exam results,
     * final document status, and graduation standing.
     */
    public function findTheses(string $lecturerId): array
    {
        $stmt = $this->db->prepare(
            "SELECT
                tp.proposal_id            AS thesis_id,
                tp.title,
                tp.status                 AS proposal_status,
                sa.role,
                s.student_id,
                s.student_number,
                pr.name                   AS program,
                CONCAT(u.first_name, ' ', u.last_name) AS student_name,
                ei.overall_grade          AS internal_exam_score,
                ei.exam_date              AS internal_exam_date,
                ee.overall_grade          AS external_exam_score,
                ee.exam_date              AS external_exam_date,
                doc.file_name             AS final_document_name,
                doc.validation_status     AS final_document_status,
                gl.graduation_id          AS graduation_id,
                gl.graduate_school_approved AS graduation_approved
             FROM supervision_assignments sa
             JOIN thesis_proposals tp ON tp.proposal_id = sa.proposal_id
             JOIN students s ON s.student_id = sa.student_id
             JOIN users u ON u.user_id = s.user_id
             LEFT JOIN student_thesis_registrations str
                    ON str.student_id = s.student_id AND str.status = 'active'
             LEFT JOIN thesis_schedules ts ON ts.schedule_id = str.thesis_schedule_id
             LEFT JOIN programs pr ON pr.program_id = ts.program_id
             LEFT JOIN examinations ei ON ei.proposal_id = tp.proposal_id AND ei.exam_type = 'internal'
             LEFT JOIN examinations ee ON ee.proposal_id = tp.proposal_id AND ee.exam_type = 'external'
             LEFT JOIN documents doc
                    ON doc.user_id = u.user_id
                   AND doc.document_type_id = (
                       SELECT doc_type_id FROM document_types WHERE doc_type_name = 'PLACEHOLDER' LIMIT 1
                   )
             LEFT JOIN graduation_list gl ON gl.student_id = s.student_id
             WHERE sa.supervisor_id = :lecturer_id AND sa.is_active = 1
             ORDER BY tp.created_at DESC"
        );
        $stmt->execute(['lecturer_id' => $lecturerId]);
        return $stmt->fetchAll();
    }

    public function findThesisDetail(string $lecturerId, string $proposalId): ?array
    {
        $theses = $this->findTheses($lecturerId);
        foreach ($theses as $t) {
            if ($t['thesis_id'] === $proposalId) {
                return $t;
            }
        }
        return null;
    }
}
