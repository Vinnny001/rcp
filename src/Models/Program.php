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
        $stmt = $this->db->query("SELECT program_id, department_id, name FROM programs ORDER BY name");
        return $stmt->fetchAll();
    }
}