<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * Plain CRUD over grading_bands — the admin-configurable fail/resubmit/
 * pass/distinction thresholds used to band an average exam score.
 * Resolving a score against these bands is GradingPolicy's job, not
 * this model's.
 */
class GradingBand
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function all(): array
    {
        return $this->db->query(
            "SELECT * FROM grading_bands ORDER BY min_score DESC"
        )->fetchAll();
    }

    public function create(array $data, string $createdBy): string
    {
        $bandId = $this->generateUuid();
        $stmt = $this->db->prepare(
            "INSERT INTO grading_bands (band_id, min_score, max_score, outcome, created_by)
             VALUES (:band_id, :min_score, :max_score, :outcome, :created_by)"
        );
        $stmt->execute([
            'band_id'    => $bandId,
            'min_score'  => $data['min_score'],
            'max_score'  => $data['max_score'],
            'outcome'    => $data['outcome'],
            'created_by' => $createdBy,
        ]);
        return $bandId;
    }

    public function update(string $bandId, array $data): void
    {
        $stmt = $this->db->prepare(
            "UPDATE grading_bands
             SET min_score = :min_score, max_score = :max_score, outcome = :outcome
             WHERE band_id = :band_id"
        );
        $stmt->execute([
            'min_score' => $data['min_score'],
            'max_score' => $data['max_score'],
            'outcome'   => $data['outcome'],
            'band_id'   => $bandId,
        ]);
    }

    public function delete(string $bandId): void
    {
        $this->db->prepare("DELETE FROM grading_bands WHERE band_id = :id")->execute(['id' => $bandId]);
    }

    private function generateUuid(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }
}
