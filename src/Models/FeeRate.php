<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class FeeRate
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findAll(): array
    {
        $stmt = $this->db->query("SELECT * FROM fee_rates ORDER BY fee_type");
        return $stmt->fetchAll();
    }

    public function findByType(string $feeType): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM fee_rates WHERE fee_type = :fee_type LIMIT 1");
        $stmt->execute(['fee_type' => $feeType]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function validTypes(): array
    {
        $stmt = $this->db->query("SELECT fee_type FROM fee_rates");
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    public function update(string $feeType, float $amount, string $updatedByUserId): void
    {
        $stmt = $this->db->prepare(
            "UPDATE fee_rates SET amount = :amount, updated_by = :updated_by WHERE fee_type = :fee_type"
        );
        $stmt->execute([
            'amount'     => $amount,
            'updated_by' => $updatedByUserId,
            'fee_type'   => $feeType,
        ]);
    }
}
