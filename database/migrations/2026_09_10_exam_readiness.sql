-- Exam readiness: the student declaring they are ready to be examined
-- in a particular exam window.
--
-- An exam_schedule row is a window a program opens — it already
-- carries the required documents and the fees. What it lacked was the
-- stage it examines, so tagging it here is what lets one window pull
-- together the documents, the fees and the marking scheme.

SET @add_stage := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE exam_schedule ADD COLUMN exam_stage_id CHAR(36) NULL AFTER exam_type',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_schedule' AND COLUMN_NAME = 'exam_stage_id'
);
PREPARE stmt FROM @add_stage; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_stage := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE exam_schedule ADD CONSTRAINT fk_exam_schedule_stage FOREIGN KEY (exam_stage_id) REFERENCES exam_stages (stage_id)',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_schedule'
      AND CONSTRAINT_NAME = 'fk_exam_schedule_stage'
);
PREPARE stmt FROM @fk_stage; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- One declaration per student per window. Withdrawing is a delete
-- rather than a status, because "I am ready" is not a thing that needs
-- a history — the meeting it produced is the record that matters.
CREATE TABLE IF NOT EXISTS exam_readiness (
    readiness_id     CHAR(36) NOT NULL DEFAULT (UUID()),
    student_id       CHAR(36) NOT NULL,
    exam_schedule_id CHAR(36) NOT NULL,
    marked_ready_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    -- Set once a coordinator schedules the exam, which is also what
    -- takes the student out of the ready queue.
    meeting_id       CHAR(36) NULL,

    PRIMARY KEY (readiness_id),
    UNIQUE KEY uniq_student_exam_window (student_id, exam_schedule_id),
    KEY idx_readiness_schedule (exam_schedule_id),
    CONSTRAINT fk_readiness_student  FOREIGN KEY (student_id)       REFERENCES students (student_id),
    CONSTRAINT fk_readiness_schedule FOREIGN KEY (exam_schedule_id) REFERENCES exam_schedule (exam_schedule_id),
    CONSTRAINT fk_readiness_meeting  FOREIGN KEY (meeting_id)       REFERENCES meetings (meeting_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
