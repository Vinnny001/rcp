<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * An exam schedule is the window a viva or examination meeting must
 * fall inside, scoped to a thesis schedule (and so to a programme).
 */
class ExamSchedule
{
    private PDO $db;

    public const VALID_EXAM_TYPES = ['internal', 'external', 'hybrid'];

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->query(
            "SELECT es.*,
                    p.name AS program_name,
                    ts.enrollment_start_date, ts.enrollment_end_date,
                    (SELECT COUNT(*) FROM meetings m
                      WHERE m.exam_schedule_id = es.exam_schedule_id AND m.status != 'cancelled') AS meeting_count,
                    (SELECT COUNT(*) FROM exam_schedule_documents esd
                      WHERE esd.exam_schedule_id = es.exam_schedule_id) AS document_slot_count
             FROM exam_schedule es
             JOIN thesis_schedules ts ON ts.schedule_id = es.thesis_schedule_id
             JOIN programs p ON p.program_id = ts.program_id
             ORDER BY es.starts_at DESC"
        )->fetchAll();
    }

    public function findById(string $examScheduleId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM exam_schedule WHERE exam_schedule_id = :id LIMIT 1");
        $stmt->execute(['id' => $examScheduleId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(array $data): string
    {
        $examScheduleId = $this->generateUuid();

        $stmt = $this->db->prepare(
            "INSERT INTO exam_schedule
                (exam_schedule_id, thesis_schedule_id, starts_at, ends_at, exam_type, exam_schedule_description)
             VALUES
                (:exam_schedule_id, :thesis_schedule_id, :starts_at, :ends_at, :exam_type, :description)"
        );
        $stmt->execute([
            'exam_schedule_id'   => $examScheduleId,
            'thesis_schedule_id' => $data['thesis_schedule_id'],
            'starts_at'          => $data['starts_at'],
            'ends_at'            => $data['ends_at'],
            'exam_type'          => $data['exam_type'],
            'description'        => $data['exam_schedule_description'] ?: null,
        ]);

        return $examScheduleId;
    }

    public function update(string $examScheduleId, array $data): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE exam_schedule
             SET thesis_schedule_id = :thesis_schedule_id,
                 starts_at = :starts_at,
                 ends_at = :ends_at,
                 exam_type = :exam_type,
                 exam_schedule_description = :description,
                 updated_at = NOW()
             WHERE exam_schedule_id = :exam_schedule_id"
        );
        $stmt->execute([
            'thesis_schedule_id' => $data['thesis_schedule_id'],
            'starts_at'          => $data['starts_at'],
            'ends_at'            => $data['ends_at'],
            'exam_type'          => $data['exam_type'],
            'description'        => $data['exam_schedule_description'] ?: null,
            'exam_schedule_id'   => $examScheduleId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return string|null an error message, or null on success
     */
    public function delete(string $examScheduleId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM meetings WHERE exam_schedule_id = :id AND status != 'cancelled'"
        );
        $stmt->execute(['id' => $examScheduleId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return 'Meetings are already scheduled inside this window.';
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM exam_documents WHERE exam_schedule_id = :id");
        $stmt->execute(['id' => $examScheduleId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return 'Students have already submitted documents against this window.';
        }

        // Document slots are configuration, not student work — they go
        // with the window rather than blocking its removal.
        $this->db->prepare("DELETE FROM exam_schedule_documents WHERE exam_schedule_id = :id")
                 ->execute(['id' => $examScheduleId]);

        $this->db->prepare("DELETE FROM exam_schedule WHERE exam_schedule_id = :id")
                 ->execute(['id' => $examScheduleId]);

        return null;
    }

    /**
     * Document types a student must submit for a window, with their
     * submission deadlines.
     */
    public function documentSlots(string $examScheduleId): array
    {
        $stmt = $this->db->prepare(
            "SELECT esd.*, dt.doc_type_name
             FROM exam_schedule_documents esd
             JOIN document_types dt ON dt.doc_type_id = esd.document_type_id
             WHERE esd.exam_schedule_id = :id
             ORDER BY esd.document_submission_deadline"
        );
        $stmt->execute(['id' => $examScheduleId]);
        return $stmt->fetchAll();
    }

    public function addDocumentSlot(string $examScheduleId, array $data): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO exam_schedule_documents
                (esd_id, exam_schedule_id, document_type_id, document_submission_starts_at, document_submission_deadline)
             VALUES (:esd_id, :exam_schedule_id, :document_type_id, :starts_at, :deadline)"
        );
        $stmt->execute([
            'esd_id'           => $this->generateUuid(),
            'exam_schedule_id' => $examScheduleId,
            'document_type_id' => $data['document_type_id'],
            'starts_at'        => $data['document_submission_starts_at'] ?: null,
            'deadline'         => $data['document_submission_deadline'] ?: null,
        ]);
    }

    public function removeDocumentSlot(string $esdId): void
    {
        $this->db->prepare("DELETE FROM exam_schedule_documents WHERE esd_id = :id")
                 ->execute(['id' => $esdId]);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
