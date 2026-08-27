<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class ProgramSchedule
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findAllOpen(): array
    {
        $stmt = $this->db->query(
            "SELECT * FROM program_schedules
             WHERE enrollment_end_date IS NULL OR enrollment_end_date >= CURDATE()
             ORDER BY department, program, enrollment_year DESC"
        );
        return $stmt->fetchAll();
    }

    public function findById(string $scheduleId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM program_schedules WHERE schedule_id = :schedule_id LIMIT 1");
        $stmt->execute(['schedule_id' => $scheduleId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findRatesForSchedule(string $scheduleId): array
    {
        $stmt = $this->db->prepare("SELECT * FROM program_fee_rates WHERE schedule_id = :schedule_id");
        $stmt->execute(['schedule_id' => $scheduleId]);
        return $stmt->fetchAll();
    }

    public function findRateForType(string $scheduleId, string $feeType): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM program_fee_rates WHERE schedule_id = :schedule_id AND fee_type = :fee_type LIMIT 1"
        );
        $stmt->execute(['schedule_id' => $scheduleId, 'fee_type' => $feeType]);
        $row = $stmt->fetch();
        return $row ?: null;
    }



    public function findSemestersBySchedule(string $scheduleId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM program_semesters WHERE schedule_id = :schedule_id ORDER BY semester_number ASC"
        );
        $stmt->execute(['schedule_id' => $scheduleId]);
        return $stmt->fetchAll();
    }

    public function findSemesterById(string $semesterId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM program_semesters WHERE semester_id = :semester_id LIMIT 1");
        $stmt->execute(['semester_id' => $semesterId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function createSemester(string $scheduleId, array $data, string $createdByUserId): string
    {
        $semesterId = $this->generateUuid();
        $stmt = $this->db->prepare(
            "INSERT INTO program_semesters
                (semester_id, schedule_id, semester_number, start_date, end_date, tuition_amount, currency, tuition_due_date, created_by)
             VALUES
                (:semester_id, :schedule_id, :semester_number, :start_date, :end_date, :tuition_amount, :currency, :tuition_due_date, :created_by)"
        );
        $stmt->execute([
            'semester_id'      => $semesterId,
            'schedule_id'      => $scheduleId,
            'semester_number'  => $data['semester_number'],
            'start_date'       => $data['start_date'] ?: null,
            'end_date'         => $data['end_date'] ?: null,
            'tuition_amount'   => $data['tuition_amount'],
            'currency'         => $data['currency'] ?? 'KES',
            'tuition_due_date' => $data['tuition_due_date'] ?: null,
            'created_by'       => $createdByUserId,
        ]);
        return $semesterId;
    }

    public function updateSemester(string $semesterId, array $data, string $updatedByUserId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE program_semesters
             SET start_date = :start_date, end_date = :end_date,
                 tuition_amount = :tuition_amount, tuition_due_date = :tuition_due_date,
                 updated_by = :updated_by
             WHERE semester_id = :semester_id"
        );
        $stmt->execute([
            'start_date'       => $data['start_date'] ?: null,
            'end_date'         => $data['end_date'] ?: null,
            'tuition_amount'   => $data['tuition_amount'],
            'tuition_due_date' => $data['tuition_due_date'] ?: null,
            'updated_by'       => $updatedByUserId,
            'semester_id'      => $semesterId,
        ]);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }



    public function findExamFeesForSemester(string $semesterId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM semester_exam_fees WHERE semester_id = :semester_id ORDER BY exam_type"
        );
        $stmt->execute(['semester_id' => $semesterId]);
        return $stmt->fetchAll();
    }

    public function findExamFee(string $semesterId, string $examType): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM semester_exam_fees WHERE semester_id = :semester_id AND exam_type = :exam_type LIMIT 1"
        );
        $stmt->execute(['semester_id' => $semesterId, 'exam_type' => $examType]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function createExamFee(string $semesterId, string $examType, float $amount, string $currency, ?string $dueDate, string $createdByUserId): string
    {
        $examFeeId = $this->generateUuid();
        $stmt = $this->db->prepare(
            "INSERT INTO semester_exam_fees (exam_fee_id, semester_id, exam_type, amount, currency, due_date, created_by)
             VALUES (:exam_fee_id, :semester_id, :exam_type, :amount, :currency, :due_date, :created_by)"
        );
        $stmt->execute([
            'exam_fee_id' => $examFeeId,
            'semester_id' => $semesterId,
            'exam_type'   => $examType,
            'amount'      => $amount,
            'currency'    => $currency,
            'due_date'    => $dueDate,
            'created_by'  => $createdByUserId,
        ]);
        return $examFeeId;
    }

    public function updateExamFee(string $examFeeId, float $amount, ?string $dueDate, string $updatedByUserId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE semester_exam_fees SET amount = :amount, due_date = :due_date, updated_by = :updated_by
             WHERE exam_fee_id = :exam_fee_id"
        );
        $stmt->execute([
            'amount'     => $amount,
            'due_date'   => $dueDate,
            'updated_by' => $updatedByUserId,
            'exam_fee_id' => $examFeeId,
        ]);
    }
}
