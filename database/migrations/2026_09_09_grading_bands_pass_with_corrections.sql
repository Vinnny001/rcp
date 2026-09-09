-- Aligns the student-facing exam scale with the examiner's five
-- interpretation bands from the Thesis Marking Scheme, by adding the
-- band that was missing between "pass" and "resubmit".
--
-- Before, the two scales disagreed at the edges: a 72 read as
-- "typographical corrections only" to the examiner but plain Pass to
-- the student, and a 35 was "rejected outright" to the examiner but
-- Resubmit to the student. The cut points now match exactly:
--
--   70-100  distinction            <- examiner: typographical corrections
--   60-69   pass                   <- examiner: minor changes
--   50-59   pass_with_corrections  <- examiner: major corrections (NEW)
--   40-49   resubmit               <- examiner: resubmit after improvement
--   0-39    fail                   <- examiner: rejected outright

ALTER TABLE grading_bands
    MODIFY COLUMN outcome
    ENUM('pass','fail','resubmit','distinction','pass_with_corrections') NOT NULL;

-- Same vocabulary, kept in step even though this table is currently unused.
ALTER TABLE examinations
    MODIFY COLUMN outcome
    ENUM('pass','fail','resubmit','distinction','pass_with_corrections') DEFAULT NULL;

UPDATE grading_bands SET min_score = 70, max_score = 100 WHERE outcome = 'distinction';
UPDATE grading_bands SET min_score = 60, max_score = 69  WHERE outcome = 'pass';
UPDATE grading_bands SET min_score = 40, max_score = 49  WHERE outcome = 'resubmit';
UPDATE grading_bands SET min_score = 0,  max_score = 39  WHERE outcome = 'fail';

-- Derived-table wrappers keep this re-runnable: MySQL won't allow a
-- direct subquery on the table being inserted into.
INSERT INTO grading_bands (band_id, min_score, max_score, outcome, created_by)
SELECT UUID(), 50, 59, 'pass_with_corrections', src.created_by
FROM (SELECT created_by FROM grading_bands WHERE outcome = 'pass' LIMIT 1) AS src
WHERE NOT EXISTS (
    SELECT 1 FROM (SELECT outcome FROM grading_bands) AS chk
    WHERE chk.outcome = 'pass_with_corrections'
);
