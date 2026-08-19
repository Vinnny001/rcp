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
}