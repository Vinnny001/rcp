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
}