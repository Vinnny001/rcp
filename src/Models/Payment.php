<?php

declare(strict_types=1);

namespace App\Models;

use PDO;

class Payment
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    public function findByStudentId(string $studentId): array
    {
        $stmt = $this->db->prepare(
            "SELECT * FROM payments
             WHERE student_id = :student_id
             ORDER BY payment_date DESC"
        );
        $stmt->execute(['student_id' => $studentId]);
        return $stmt->fetchAll();
    }

    /**
     * Sums confirmed payments of a given type for a student.
     * - tuition: pass $semesterId to scope to a specific semester.
     * - registration / thesis_fee / examination_fee: $semesterId is
     *   unused, since those remain one-time-per-schedule, not
     *   per-semester.
     */
    public function sumConfirmedByType(
        string $studentId,
        string $paymentType,
        ?string $semesterId = null
    ): float {
        if ($paymentType === 'tuition' && $semesterId !== null) {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM payments
                 WHERE student_id = :student_id AND payment_type = :payment_type
                   AND status = 'confirmed' AND semester_id = :semester_id"
            );
            $stmt->execute([
                'student_id'   => $studentId,
                'payment_type' => $paymentType,
                'semester_id'  => $semesterId,
            ]);
            return (float) $stmt->fetchColumn();
        }

        $stmt = $this->db->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM payments
             WHERE student_id = :student_id AND payment_type = :payment_type AND status = 'confirmed'"
        );
        $stmt->execute(['student_id' => $studentId, 'payment_type' => $paymentType]);
        return (float) $stmt->fetchColumn();
    }
}
