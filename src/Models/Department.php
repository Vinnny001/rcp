<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Department
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function all(): array
    {
        $stmt = $this->db->query("SELECT department_id, name FROM departments ORDER BY name");
        return $stmt->fetchAll();
    }

    public function findById(string $departmentId): ?array
    {
        $stmt = $this->db->prepare("SELECT * FROM departments WHERE department_id = :id LIMIT 1");
        $stmt->execute(['id' => $departmentId]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $name): void
    {
        $stmt = $this->db->prepare("INSERT INTO departments (name) VALUES (:name)");
        $stmt->execute(['name' => $name]);
    }

    public function update(string $departmentId, string $name): void
    {
        $stmt = $this->db->prepare("UPDATE departments SET name = :name WHERE department_id = :id");
        $stmt->execute(['name' => $name, 'id' => $departmentId]);
    }

    public function delete(string $departmentId): void
    {
        $stmt = $this->db->prepare("DELETE FROM departments WHERE department_id = :id");
        $stmt->execute(['id' => $departmentId]);
    }
}
