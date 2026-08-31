-- Makes the exam fail/resubmit/pass/distinction thresholds
-- admin-configurable instead of hardcoded in GradingPolicy.php.
--
-- The grading_bands table itself was already created directly against
-- the live DB (band_id and created_by both NOT NULL, no defaults) —
-- this migration only seeds it with the exact thresholds that were
-- hardcoded before this change, so no existing behavior changes until
-- admin edits a band. created_by is the same "Registrar Office" system
-- account already used to seed thesis_review_fee_rates.

INSERT INTO grading_bands (band_id, min_score, max_score, outcome, created_by) VALUES
    (UUID(), 0.00,  30.00,  'fail',        '9d87e71f-9a2d-11f1-97a0-b4b6861db1cf'),
    (UUID(), 31.00, 49.00,  'resubmit',    '9d87e71f-9a2d-11f1-97a0-b4b6861db1cf'),
    (UUID(), 50.00, 74.00,  'pass',        '9d87e71f-9a2d-11f1-97a0-b4b6861db1cf'),
    (UUID(), 75.00, 100.00, 'distinction', '9d87e71f-9a2d-11f1-97a0-b4b6861db1cf');
