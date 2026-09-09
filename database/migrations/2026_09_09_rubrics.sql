-- Rubric-based examiner marking, replacing a single flat score.
--
-- Note what is deliberately absent: there is no max_total column. The
-- maximum is the sum of the criteria, derived on read. The source form
-- for Table 1 carries a TOTAL row reading 40 while its own criteria add
-- up to 45 — exactly the drift a separately stored total invites. With
-- the total derived, editing a criterion moves the maximum with it and
-- the two can never disagree.

CREATE TABLE IF NOT EXISTS rubric_templates (
    template_id      CHAR(36) NOT NULL DEFAULT (UUID()),
    code             VARCHAR(60)  NOT NULL,
    name             VARCHAR(200) NOT NULL,

    -- Table 1 asks one examiner to record an average across the panel
    -- and sign it; Table 2 has no such line.
    has_panel_leader TINYINT(1) NOT NULL DEFAULT 0,

    is_active        TINYINT(1) NOT NULL DEFAULT 1,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (template_id),
    UNIQUE KEY uniq_rubric_template_code (code)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS rubric_criteria (
    criterion_id   CHAR(36) NOT NULL DEFAULT (UUID()),
    template_id    CHAR(36) NOT NULL,
    section_name   VARCHAR(150) NOT NULL,
    criterion_text TEXT NOT NULL,
    max_score      DECIMAL(5,2) NOT NULL,
    display_order  SMALLINT UNSIGNED NOT NULL,
    PRIMARY KEY (criterion_id),
    KEY idx_rubric_criteria_template (template_id, display_order),
    CONSTRAINT fk_rubric_criteria_template FOREIGN KEY (template_id)
        REFERENCES rubric_templates (template_id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- The examiner-facing interpretation bands. Table 2 prints five of
-- them; Table 1 prints none, and falls back to the global student
-- scale in grading_bands like everything else.
CREATE TABLE IF NOT EXISTS rubric_template_bands (
    band_id        CHAR(36) NOT NULL DEFAULT (UUID()),
    template_id    CHAR(36) NOT NULL,
    min_percentage DECIMAL(5,2) NOT NULL,
    label          VARCHAR(120) NOT NULL,
    description    VARCHAR(255) NULL,
    PRIMARY KEY (band_id),
    KEY idx_rubric_bands_template (template_id, min_percentage),
    CONSTRAINT fk_rubric_bands_template FOREIGN KEY (template_id)
        REFERENCES rubric_templates (template_id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS rubric_scores (
    rubric_score_id CHAR(36) NOT NULL DEFAULT (UUID()),
    meeting_id      CHAR(36) NOT NULL,
    criterion_id    CHAR(36) NOT NULL,
    examiner_id     CHAR(36) NOT NULL,
    score           DECIMAL(5,2) NOT NULL,
    remarks         TEXT NULL,
    scored_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (rubric_score_id),
    UNIQUE KEY uniq_meeting_criterion_examiner (meeting_id, criterion_id, examiner_id),
    KEY idx_rubric_scores_examiner (meeting_id, examiner_id),
    CONSTRAINT fk_rubric_scores_meeting   FOREIGN KEY (meeting_id)   REFERENCES meetings (meeting_id) ON DELETE CASCADE,
    CONSTRAINT fk_rubric_scores_criterion FOREIGN KEY (criterion_id) REFERENCES rubric_criteria (criterion_id),
    CONSTRAINT fk_rubric_scores_examiner  FOREIGN KEY (examiner_id)  REFERENCES users (user_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- Keyed on meeting_id alone: a panel has exactly one leader, by
-- construction rather than by convention, so "two leaders" and "no
-- leader" cannot both be states the code has to handle.
CREATE TABLE IF NOT EXISTS rubric_panel_leaders (
    meeting_id    CHAR(36) NOT NULL,
    examiner_id   CHAR(36) NOT NULL,

    -- The average is calculated from the panel's totals; this records
    -- the leader attesting to it, which is what the form's signature
    -- line is actually for.
    average_score DECIMAL(5,2) NULL,
    confirmed_at  DATETIME NULL,

    PRIMARY KEY (meeting_id),
    CONSTRAINT fk_panel_leader_meeting  FOREIGN KEY (meeting_id)  REFERENCES meetings (meeting_id) ON DELETE CASCADE,
    CONSTRAINT fk_panel_leader_examiner FOREIGN KEY (examiner_id) REFERENCES users (user_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- Link the configurable exam stages to the scheme they are marked with.
-- The column is added here rather than in the exam_stages migration
-- because rubric_templates did not exist at that point.
SET @add_stage_template := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE exam_stages ADD COLUMN rubric_template_id CHAR(36) NULL AFTER evidence_doc_type_id',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_stages' AND COLUMN_NAME = 'rubric_template_id'
);
PREPARE stmt FROM @add_stage_template; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_stage_template := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE exam_stages ADD CONSTRAINT fk_exam_stages_rubric FOREIGN KEY (rubric_template_id) REFERENCES rubric_templates (template_id)',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_stages'
      AND CONSTRAINT_NAME = 'fk_exam_stages_rubric'
);
PREPARE stmt FROM @fk_stage_template; EXECUTE stmt; DEALLOCATE PREPARE stmt;
