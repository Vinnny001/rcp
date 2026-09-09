-- A shortlist meeting gets a lead and, optionally, a secretary.
--
-- Minutes were coordinator-only. They are now written by whoever holds
-- the secretary role, falling back to the lead when no secretary was
-- appointed — which is how these meetings actually run: someone chairs
-- and someone writes, and often it is the same person.
--
-- The coordinator may take either role themselves. Both columns point
-- at users rather than department_heads, because the coordinator is
-- not necessarily a head of that department.

SET @add_lead := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE shortlist_meetings ADD COLUMN lead_user_id CHAR(36) NULL AFTER created_by',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shortlist_meetings' AND COLUMN_NAME = 'lead_user_id'
);
PREPARE stmt FROM @add_lead; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @add_sec := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE shortlist_meetings ADD COLUMN secretary_user_id CHAR(36) NULL AFTER lead_user_id',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shortlist_meetings' AND COLUMN_NAME = 'secretary_user_id'
);
PREPARE stmt FROM @add_sec; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_lead := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE shortlist_meetings ADD CONSTRAINT fk_shortlist_meetings_lead FOREIGN KEY (lead_user_id) REFERENCES users (user_id)',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shortlist_meetings'
      AND CONSTRAINT_NAME = 'fk_shortlist_meetings_lead'
);
PREPARE stmt FROM @fk_lead; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_sec := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE shortlist_meetings ADD CONSTRAINT fk_shortlist_meetings_secretary FOREIGN KEY (secretary_user_id) REFERENCES users (user_id)',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shortlist_meetings'
      AND CONSTRAINT_NAME = 'fk_shortlist_meetings_secretary'
);
PREPARE stmt FROM @fk_sec; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing meetings were minuted by whoever scheduled them.
UPDATE shortlist_meetings SET lead_user_id = created_by WHERE lead_user_id IS NULL;
