-- Minutes and one-off document requirements for exam meetings.
--
-- Minutes here follow exactly the rule shortlist meetings already use:
-- a lead, optionally a secretary, whoever holds the secretary role
-- writes them and the lead does when nobody was named, the coordinator
-- approves. Same columns, same order, so the two are read the same way.
--
-- Extra documents are the coordinator asking one student for something
-- beyond what the exam window requires of everyone — an ethics
-- clearance for a particular project, say. They sit apart from
-- exam_schedule_documents because that set applies to the whole window
-- and must not be edited for one candidate.

SET @cols := 'lead_user_id,secretary_user_id,minutes,minutes_finalized_at,minutes_approved_at,minutes_approved_by';

SET @sql := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE meetings
            ADD COLUMN lead_user_id         CHAR(36) NULL AFTER created_by,
            ADD COLUMN secretary_user_id    CHAR(36) NULL AFTER lead_user_id,
            ADD COLUMN minutes              TEXT NULL AFTER secretary_user_id,
            ADD COLUMN minutes_finalized_at DATETIME NULL AFTER minutes,
            ADD COLUMN minutes_approved_at  DATETIME NULL AFTER minutes_finalized_at,
            ADD COLUMN minutes_approved_by  CHAR(36) NULL AFTER minutes_approved_at',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meetings' AND COLUMN_NAME = 'minutes'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_lead := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE meetings ADD CONSTRAINT fk_meetings_lead FOREIGN KEY (lead_user_id) REFERENCES users (user_id)',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meetings' AND CONSTRAINT_NAME = 'fk_meetings_lead'
);
PREPARE stmt FROM @fk_lead; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_sec := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE meetings ADD CONSTRAINT fk_meetings_secretary FOREIGN KEY (secretary_user_id) REFERENCES users (user_id)',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meetings' AND CONSTRAINT_NAME = 'fk_meetings_secretary'
);
PREPARE stmt FROM @fk_sec; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing meetings were run by whoever scheduled them.
UPDATE meetings SET lead_user_id = created_by WHERE lead_user_id IS NULL;

CREATE TABLE IF NOT EXISTS exam_meeting_extra_documents (
    extra_doc_id     CHAR(36) NOT NULL DEFAULT (UUID()),
    meeting_id       CHAR(36) NOT NULL,
    document_type_id CHAR(36) NOT NULL,
    note             VARCHAR(255) NULL,
    added_by         CHAR(36) NOT NULL,
    added_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (extra_doc_id),
    UNIQUE KEY uniq_meeting_extra_doc (meeting_id, document_type_id),
    CONSTRAINT fk_extra_doc_meeting  FOREIGN KEY (meeting_id)       REFERENCES meetings (meeting_id) ON DELETE CASCADE,
    CONSTRAINT fk_extra_doc_type     FOREIGN KEY (document_type_id) REFERENCES document_types (doc_type_id),
    CONSTRAINT fk_extra_doc_added_by FOREIGN KEY (added_by)         REFERENCES users (user_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
