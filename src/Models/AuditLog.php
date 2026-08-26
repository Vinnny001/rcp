<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * audit_logs is populated by database triggers, not application code —
 * there is no writer here, only a reader for the admin-facing log page.
 */
class AuditLog
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int, string>
     */
    public function distinctEntityTypes(): array
    {
        return $this->db->query(
            "SELECT DISTINCT entity_type FROM audit_logs ORDER BY entity_type"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * @return array<int, string>
     */
    public function distinctActions(): array
    {
        return $this->db->query(
            "SELECT DISTINCT action FROM audit_logs ORDER BY action"
        )->fetchAll(PDO::FETCH_COLUMN);
    }

    /**
     * user_id on a row can be null — the triggers that write these rows
     * don't always have an application user in scope (a schema-level
     * change, a script run outside a request) — so the actor name is
     * left join and shown as "System" when absent.
     *
     * @param array{entity_type?: ?string, action?: ?string} $filters
     * @return array<int, array<string, mixed>>
     */
    public function recent(array $filters = [], int $limit = 200): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['entity_type'])) {
            $where[] = 'al.entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }
        if (!empty($filters['action'])) {
            $where[] = 'al.action = :action';
            $params['action'] = $filters['action'];
        }

        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

        $stmt = $this->db->prepare(
            "SELECT al.*, CONCAT(u.first_name, ' ', u.last_name) AS actor_name
             FROM audit_logs al
             LEFT JOIN users u ON u.user_id = al.user_id
             $whereSql
             ORDER BY al.created_at DESC
             LIMIT " . (int) $limit
        );
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
}
