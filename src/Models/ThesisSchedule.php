<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * A thesis schedule is the per-programme intake window students
 * register against, and the anchor every exam_schedule hangs off.
 */
class ThesisSchedule
{
    private PDO $db;

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
            "SELECT ts.*,
                    p.name  AS program_name,
                    d.name  AS department_name,
                    trr.amount AS registration_amount, trr.currency AS registration_currency,
                    tvr.amount AS review_amount,       tvr.currency AS review_currency,
                    (SELECT COUNT(*) FROM student_thesis_registrations str
                      WHERE str.thesis_schedule_id = ts.schedule_id) AS registration_count,
                    (SELECT COUNT(*) FROM exam_schedule es
                      WHERE es.thesis_schedule_id = ts.schedule_id) AS exam_schedule_count
             FROM thesis_schedules ts
             JOIN programs p ON p.program_id = ts.program_id
             LEFT JOIN departments d ON d.department_id = p.department_id
             LEFT JOIN thesis_registration_rates trr ON trr.rate_id = ts.thesis_registration_rates_id
             LEFT JOIN thesis_review_fee_rates tvr  ON tvr.rate_id = ts.thesis_review_rates_id
             ORDER BY ts.enrollment_start_date DESC, p.name"
        )->fetchAll();
    }

    public function findById(string $scheduleId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM thesis_schedules WHERE schedule_id = :id LIMIT 1");
        $stmt->execute(['id' => $scheduleId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Registration and review fee rates are programme-scoped, so the
     * create/edit form only offers the rates belonging to the programme
     * the schedule is for.
     *
     * @return array{registration:array<int, array<string, mixed>>, review:array<int, array<string, mixed>>}
     */
    public function ratesByProgram(): array
    {
        $registration = $this->db->query(
            "SELECT rate_id, program_id, amount, currency, due_date
             FROM thesis_registration_rates ORDER BY updated_at DESC"
        )->fetchAll();

        $review = $this->db->query(
            "SELECT rate_id, program_id, academic_year, amount, currency, due_date
             FROM thesis_review_fee_rates ORDER BY academic_year DESC"
        )->fetchAll();

        return ['registration' => $registration, 'review' => $review];
    }

    public function create(array $data, string $createdBy): string
    {
        $scheduleId = $this->generateUuid();

        $stmt = $this->db->prepare(
            "INSERT INTO thesis_schedules
                (schedule_id, program_id, enrollment_start_date, enrollment_end_date,
                 thesis_registration_rates_id, thesis_review_rates_id, created_by)
             VALUES
                (:schedule_id, :program_id, :start_date, :end_date,
                 :registration_rate_id, :review_rate_id, :created_by)"
        );
        $stmt->execute([
            'schedule_id'          => $scheduleId,
            'program_id'           => $data['program_id'],
            'start_date'           => $data['enrollment_start_date'] ?: null,
            'end_date'             => $data['enrollment_end_date'] ?: null,
            'registration_rate_id' => $data['thesis_registration_rates_id'] ?: null,
            'review_rate_id'       => $data['thesis_review_rates_id'] ?: null,
            'created_by'           => $createdBy,
        ]);

        return $scheduleId;
    }

    public function update(string $scheduleId, array $data): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE thesis_schedules
             SET program_id = :program_id,
                 enrollment_start_date = :start_date,
                 enrollment_end_date = :end_date,
                 thesis_registration_rates_id = :registration_rate_id,
                 thesis_review_rates_id = :review_rate_id,
                 updated_at = NOW()
             WHERE schedule_id = :schedule_id"
        );
        $stmt->execute([
            'program_id'           => $data['program_id'],
            'start_date'           => $data['enrollment_start_date'] ?: null,
            'end_date'             => $data['enrollment_end_date'] ?: null,
            'registration_rate_id' => $data['thesis_registration_rates_id'] ?: null,
            'review_rate_id'       => $data['thesis_review_rates_id'] ?: null,
            'schedule_id'          => $scheduleId,
        ]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Refuses to delete a schedule students have already registered
     * against, or that has exam schedules hanging off it — deleting
     * either would strand real records.
     *
     * @return string|null an error message, or null on success
     */
    public function delete(string $scheduleId): ?string
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM student_thesis_registrations WHERE thesis_schedule_id = :id"
        );
        $stmt->execute(['id' => $scheduleId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return 'Students have already registered against this schedule.';
        }

        $stmt = $this->db->prepare("SELECT COUNT(*) FROM exam_schedule WHERE thesis_schedule_id = :id");
        $stmt->execute(['id' => $scheduleId]);
        if ((int) $stmt->fetchColumn() > 0) {
            return 'This schedule still has exam schedules linked to it — remove those first.';
        }

        $delete = $this->db->prepare("DELETE FROM thesis_schedules WHERE schedule_id = :id");
        $delete->execute(['id' => $scheduleId]);

        return null;
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
