<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class ThesisFeeRate
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findRegistrationRate(string $programId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM thesis_registration_rates WHERE program_id = :program_id LIMIT 1"
        );
        $stmt->execute(['program_id' => $programId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /**
     * Looks up the review fee for a specific academic year. If that exact
     * year has no rate set, falls back to the most recent year at or
     * before it — a program's rate doesn't need re-entering every single
     * year if it hasn't changed, but a student is never left with no
     * rate to be charged just because this year wasn't explicitly set.
     */
    public function findReviewFeeRate(string $programId, int $academicYear): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM thesis_review_fee_rates
             WHERE program_id = :program_id AND academic_year <= :academic_year
             ORDER BY academic_year DESC
             LIMIT 1"
        );
        $stmt->execute(['program_id' => $programId, 'academic_year' => $academicYear]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    // ---------- Registration rates (admin CRUD) ----------

    public function allRegistrationRates(): array
    {
        return $this->db->query(
            "SELECT trr.*, p.name AS program_name
             FROM thesis_registration_rates trr
             JOIN programs p ON p.program_id = trr.program_id
             ORDER BY p.name, trr.updated_at DESC"
        )->fetchAll();
    }

    public function createRegistrationRate(array $data, string $updatedBy): string
    {
        $rateId = $this->generateUuid();
        $stmt = $this->db->prepare(
            "INSERT INTO thesis_registration_rates
                (rate_id, program_id, amount, currency, due_after_weeks, description, updated_by)
             VALUES (:rate_id, :program_id, :amount, :currency, :due_after_weeks, :description, :updated_by)"
        );
        $stmt->execute([
            'rate_id'         => $rateId,
            'program_id'      => $data['program_id'],
            'amount'          => $data['amount'],
            'currency'        => $data['currency'] ?: 'KES',
            'due_after_weeks' => $data['due_after_weeks'] !== '' ? (int) $data['due_after_weeks'] : null,
            'description'     => $data['description'] ?: null,
            'updated_by'      => $updatedBy,
        ]);
        return $rateId;
    }

    public function updateRegistrationRate(string $rateId, array $data, string $updatedBy): void
    {
        $stmt = $this->db->prepare(
            "UPDATE thesis_registration_rates
             SET program_id = :program_id, amount = :amount, currency = :currency,
                 due_after_weeks = :due_after_weeks, description = :description, updated_by = :updated_by
             WHERE rate_id = :rate_id"
        );
        $stmt->execute([
            'program_id'      => $data['program_id'],
            'amount'          => $data['amount'],
            'currency'        => $data['currency'] ?: 'KES',
            'due_after_weeks' => $data['due_after_weeks'] !== '' ? (int) $data['due_after_weeks'] : null,
            'description'     => $data['description'] ?: null,
            'updated_by'      => $updatedBy,
            'rate_id'         => $rateId,
        ]);
    }

    public function deleteRegistrationRate(string $rateId): void
    {
        $this->db->prepare("DELETE FROM thesis_registration_rates WHERE rate_id = :id")->execute(['id' => $rateId]);
    }

    // ---------- Review fee rates (admin CRUD) ----------

    public function allReviewFeeRates(): array
    {
        return $this->db->query(
            "SELECT tvr.*, p.name AS program_name
             FROM thesis_review_fee_rates tvr
             JOIN programs p ON p.program_id = tvr.program_id
             ORDER BY p.name, tvr.academic_year DESC"
        )->fetchAll();
    }

    public function createReviewFeeRate(array $data, string $createdBy): string
    {
        $rateId = $this->generateUuid();
        $stmt = $this->db->prepare(
            "INSERT INTO thesis_review_fee_rates
                (rate_id, program_id, academic_year, amount, currency, due_date, created_by, updated_by)
             VALUES (:rate_id, :program_id, :academic_year, :amount, :currency, :due_date, :created_by, :created_by)"
        );
        $stmt->execute([
            'rate_id'       => $rateId,
            'program_id'    => $data['program_id'],
            'academic_year' => $data['academic_year'],
            'amount'        => $data['amount'],
            'currency'      => $data['currency'] ?: 'KES',
            'due_date'      => $data['due_date'] ?: null,
            'created_by'    => $createdBy,
        ]);
        return $rateId;
    }

    public function updateReviewFeeRate(string $rateId, array $data, string $updatedBy): void
    {
        $stmt = $this->db->prepare(
            "UPDATE thesis_review_fee_rates
             SET program_id = :program_id, academic_year = :academic_year, amount = :amount,
                 currency = :currency, due_date = :due_date, updated_by = :updated_by
             WHERE rate_id = :rate_id"
        );
        $stmt->execute([
            'program_id'    => $data['program_id'],
            'academic_year' => $data['academic_year'],
            'amount'        => $data['amount'],
            'currency'      => $data['currency'] ?: 'KES',
            'due_date'      => $data['due_date'] ?: null,
            'updated_by'    => $updatedBy,
            'rate_id'       => $rateId,
        ]);
    }

    public function deleteReviewFeeRate(string $rateId): void
    {
        $this->db->prepare("DELETE FROM thesis_review_fee_rates WHERE rate_id = :id")->execute(['id' => $rateId]);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
