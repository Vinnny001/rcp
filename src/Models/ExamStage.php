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

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO exam_stages (stage_id, code, name, display_order, evidence_doc_type_id)
             VALUES (UUID(), :code, :name, :display_order, :evidence_doc_type_id)"
        );
        $stmt->execute([
            'code'                 => $this->uniqueCodeFrom((string) $data['name']),
            'name'                 => trim((string) $data['name']),
            'display_order'        => (int) $data['display_order'],
            'evidence_doc_type_id' => ($data['evidence_doc_type_id'] ?? '') ?: null,
        ]);
    }

    /**
     * The code is deliberately not editable — it's the stable identifier
     * other code refers a stage by, while the name is the label students
     * read and admins are free to reword.
     *
     * @param array<string, mixed> $data
     */
    public function update(string $stageId, array $data): void
    {
        $stmt = $this->db->prepare(
            "UPDATE exam_stages
             SET name = :name, display_order = :display_order,
                 evidence_doc_type_id = :evidence_doc_type_id
             WHERE stage_id = :stage_id"
        );
        $stmt->execute([
            'stage_id'             => $stageId,
            'name'                 => trim((string) $data['name']),
            'display_order'        => (int) $data['display_order'],
            'evidence_doc_type_id' => ($data['evidence_doc_type_id'] ?? '') ?: null,
        ]);
    }

    /**
     * Archiving takes a stage out of the rail for students still in
     * progress without touching anyone who already completed it — their
     * track reads from its own snapshot. There is deliberately no
     * delete: removing the row would orphan those snapshots.
     */
    public function setActive(string $stageId, bool $active): void
    {
        $stmt = $this->db->prepare("UPDATE exam_stages SET is_active = :active WHERE stage_id = :stage_id");
        $stmt->execute(['stage_id' => $stageId, 'active' => $active ? 1 : 0]);
    }

    /**
     * How many students have already completed a stage — shown on the
     * admin screen so archiving isn't done blind.
     */
    public function completionCount(string $stageId): int
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM student_stage_progress WHERE stage_id = :id AND status = 'complete'"
        );
        $stmt->execute(['id' => $stageId]);

        return (int) $stmt->fetchColumn();
    }

    private function uniqueCodeFrom(string $name): string
    {
        $base = trim(preg_replace('/_+/', '_', preg_replace('/[^a-z0-9]+/', '_', strtolower($name))) ?? '', '_');
        $base = $base !== '' ? substr($base, 0, 40) : 'stage';

        $code = $base;
        $suffix = 2;
        while ($this->findByCode($code) !== null) {
            $code = $base . '_' . $suffix++;
        }

        return $code;
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
