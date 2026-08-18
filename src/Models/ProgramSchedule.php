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
}