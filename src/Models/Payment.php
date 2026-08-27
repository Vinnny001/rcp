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
     * - examination_fee: pass $semesterId AND $examType to scope to a
     *   specific semester's internal or external exam fee — these are
     *   tracked independently since a student may owe one, the other,
     *   or both, at potentially different rates.
     * - registration / thesis_fee: neither param is used, since those
     *   remain one-time-per-schedule, not per-semester.
     */
    public function sumConfirmedByType(
        string $studentId,
        string $paymentType,
        ?string $semesterId = null,
        ?string $examType = null
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

        if ($paymentType === 'examination_fee' && $semesterId !== null && $examType !== null) {
            $stmt = $this->db->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM payments
                 WHERE student_id = :student_id AND payment_type = :payment_type
                   AND status = 'confirmed' AND semester_id = :semester_id AND exam_type = :exam_type"
            );
            $stmt->execute([
                'student_id'   => $studentId,
                'payment_type' => $paymentType,
                'semester_id'  => $semesterId,
                'exam_type'    => $examType,
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
