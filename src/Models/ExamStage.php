<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

/**
 * The admin-editable exam stages that form the middle of the student
 * journey rail. Registration, Requirements Validation and Graduation
 * are structural rather than exams, so they stay fixed in
 * StudentJourney — everything between them is a row here.
 *
 * Stages are archived (is_active = 0), never deleted: a graduated
 * student's frozen track may still reference a retired stage.
 */
class ExamStage
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function allActive(): array
    {
        return $this->db->query(
            "SELECT s.stage_id, s.code, s.name, s.display_order, s.evidence_doc_type_id,
                    dt.doc_type_name AS evidence_doc_type_name
             FROM exam_stages s
             LEFT JOIN document_types dt ON dt.doc_type_id = s.evidence_doc_type_id
             WHERE s.is_active = 1 ORDER BY s.display_order, s.name"
        )->fetchAll();
    }

    /**
     * Includes archived stages — for the admin screen, which must be
     * able to see and restore what it retired.
     *
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->db->query(
            "SELECT stage_id, code, name, display_order, evidence_doc_type_id, is_active
             FROM exam_stages ORDER BY display_order, name"
        )->fetchAll();
    }

    public function findByCode(string $code): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT stage_id, code, name, display_order, evidence_doc_type_id, is_active
             FROM exam_stages WHERE code = :code LIMIT 1"
        );
        $stmt->execute(['code' => $code]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
