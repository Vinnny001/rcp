<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Program
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function all(): array
    {
        $stmt = $this->db->query(
            "SELECT p.program_id, p.department_id, p.name, d.name AS department_name
             FROM programs p
             JOIN departments d ON d.department_id = p.department_id
             ORDER BY d.name, p.name"
        );
        return $stmt->fetchAll();
    }

    public function findById(string $programId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM programs WHERE program_id = :id LIMIT 1");
        $stmt->execute(['id' => $programId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $departmentId, string $name): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO programs (department_id, name) VALUES (:department_id, :name)"
        );
        $stmt->execute(['department_id' => $departmentId, 'name' => $name]);
    }

    public function update(string $programId, string $departmentId, string $name): void
    {
        $stmt = $this->db->prepare(
            "UPDATE programs SET department_id = :department_id, name = :name WHERE program_id = :id"
        );
        $stmt->execute(['department_id' => $departmentId, 'name' => $name, 'id' => $programId]);
    }

    public function delete(string $programId): void
    {
        $stmt = $this->db->prepare("DELETE FROM programs WHERE program_id = :id");
        $stmt->execute(['id' => $programId]);
    }
}
