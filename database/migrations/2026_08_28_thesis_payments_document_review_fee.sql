-- Splits the old, overloaded 'thesis_review_fee' fee_type into two
-- distinct concepts that were previously conflated in code:
--   - 'document_review_fee': per-document-type, tied to a specific
--     exam_schedule (document_review_rates). This is what the enum
--     value 'thesis_review_fee' actually meant before this migration.
--   - 'thesis_review_fee': now repurposed for a new, separate concept —
--     a recurring annual fee per program (thesis_review_fee_rates),
--     owed once a supervisor is assigned, anchored to the student's
--     thesis_schedules.enrollment_start_date.
--
-- Safe to run unconditionally: thesis_payments had zero rows at the
-- time of this change, so there is no existing data to reinterpret.

ALTER TABLE thesis_payments
    MODIFY fee_type ENUM('thesis_registration', 'thesis_review_fee', 'document_review_fee') NOT NULL;
