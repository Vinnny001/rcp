-- Coordinator approval as a release gate.
--
-- Two things now need the coordinator's sign-off before they count:
--
--   Minutes  — the author (secretary, or lead) finalises them; the
--              coordinator then approves. Applying the department's
--              decision requires the approval, not just the
--              finalisation, so "written" and "accepted on behalf of
--              the program" stay separate acts.
--
--   Averages — the panel leader confirms the calculated average; the
--              coordinator then approves it, and only then does the
--              result reach the student. A confirmed-but-unapproved
--              average is visible to staff and invisible to students.

SET @add_min_at := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE shortlist_meetings ADD COLUMN minutes_approved_at DATETIME NULL AFTER minutes_finalized_at',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shortlist_meetings' AND COLUMN_NAME = 'minutes_approved_at'
);
PREPARE stmt FROM @add_min_at; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_min_by := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE shortlist_meetings ADD COLUMN minutes_approved_by CHAR(36) NULL AFTER minutes_approved_at',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shortlist_meetings' AND COLUMN_NAME = 'minutes_approved_by'
);
PREPARE stmt FROM @add_min_by; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_min_by := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE shortlist_meetings ADD CONSTRAINT fk_shortlist_minutes_approver FOREIGN KEY (minutes_approved_by) REFERENCES users (user_id)',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shortlist_meetings'
      AND CONSTRAINT_NAME = 'fk_shortlist_minutes_approver'
);
PREPARE stmt FROM @fk_min_by; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_avg_at := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE rubric_panel_leaders ADD COLUMN approved_at DATETIME NULL AFTER confirmed_at',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rubric_panel_leaders' AND COLUMN_NAME = 'approved_at'
);
PREPARE stmt FROM @add_avg_at; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_avg_by := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE rubric_panel_leaders ADD COLUMN approved_by CHAR(36) NULL AFTER approved_at',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rubric_panel_leaders' AND COLUMN_NAME = 'approved_by'
);
PREPARE stmt FROM @add_avg_by; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_avg_by := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE rubric_panel_leaders ADD CONSTRAINT fk_panel_average_approver FOREIGN KEY (approved_by) REFERENCES users (user_id)',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rubric_panel_leaders'
      AND CONSTRAINT_NAME = 'fk_panel_average_approver'
);
PREPARE stmt FROM @fk_avg_by; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Minutes finalised before this gate existed were, in effect, already
-- accepted — the decisions taken on them have been acted on.
UPDATE shortlist_meetings
SET minutes_approved_at = minutes_finalized_at,
    minutes_approved_by = created_by
WHERE minutes_finalized_at IS NOT NULL AND minutes_approved_at IS NULL;
