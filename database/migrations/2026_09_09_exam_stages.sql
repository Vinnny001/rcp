-- Turns the student journey's exam steps into admin-editable data.
--
-- The dashboard rail was six hardcoded <div>s driven by one integer,
-- and exam progress was detected by string-matching the document type
-- name 'Thesis'. Adding a stage meant editing a template, a controller
-- and an enum. Now a stage is a row.
--
-- The rail composes as: Registration and Requirements Validation
-- (structural, fixed) + these stages + Graduation (fixed).
--
-- Freezing finished students: a live stage list alone can't satisfy
-- "a graduated student's progress must not change" — renaming a stage
-- would rewrite their history. So each progress row carries its own
-- snapshot of the stage's name and position at the moment it
-- completed, and a graduated student's rail is rendered from those
-- snapshots alone.

CREATE TABLE IF NOT EXISTS exam_stages (
    stage_id       CHAR(36) NOT NULL DEFAULT (UUID()),
    code           VARCHAR(50)  NOT NULL,
    name           VARCHAR(150) NOT NULL,
    display_order  SMALLINT UNSIGNED NOT NULL,

    -- The document type whose passing exam outcome marks this stage
    -- complete. NULL means nothing infers it yet — it completes only
    -- when explicitly recorded (see StudentJourney::recordStageComplete).
    evidence_doc_type_id CHAR(36) NULL,

    -- Archived, never deleted: a graduated student may still reference
    -- a stage the department has since retired.
    is_active      TINYINT(1) NOT NULL DEFAULT 1,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (stage_id),
    UNIQUE KEY uniq_exam_stage_code (code),
    KEY idx_exam_stages_order (display_order),
    CONSTRAINT fk_exam_stages_doc_type FOREIGN KEY (evidence_doc_type_id)
        REFERENCES document_types (doc_type_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

CREATE TABLE IF NOT EXISTS student_stage_progress (
    progress_id   CHAR(36) NOT NULL DEFAULT (UUID()),
    student_id    CHAR(36) NOT NULL,
    stage_id      CHAR(36) NOT NULL,
    status        ENUM('not_started','in_progress','complete') NOT NULL DEFAULT 'not_started',
    completed_at  DATETIME NULL,

    -- What this stage was called, and where it sat, when the student
    -- completed it. A later rename or reorder cannot rewrite a
    -- finished student's record.
    stage_name_snapshot    VARCHAR(150) NULL,
    display_order_snapshot SMALLINT UNSIGNED NULL,

    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (progress_id),
    UNIQUE KEY uniq_student_stage (student_id, stage_id),
    KEY idx_progress_student (student_id),
    CONSTRAINT fk_progress_student FOREIGN KEY (student_id) REFERENCES students (student_id),
    CONSTRAINT fk_progress_stage   FOREIGN KEY (stage_id)   REFERENCES exam_stages (stage_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- Seed the four stages the rail replaces. Codes are stable identifiers;
-- names are what students read and admins may edit.
INSERT INTO exam_stages (stage_id, code, name, display_order, evidence_doc_type_id)
SELECT UUID(), 'approval', 'Proposal Approval', 1,
       (SELECT doc_type_id FROM document_types WHERE doc_type_name = 'Proposal' LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM (SELECT code FROM exam_stages) AS c WHERE c.code = 'approval');

INSERT INTO exam_stages (stage_id, code, name, display_order, evidence_doc_type_id)
SELECT UUID(), 'concept_presentation', 'Concept Presentation', 2,
       (SELECT doc_type_id FROM document_types WHERE doc_type_name = 'Presentation' LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM (SELECT code FROM exam_stages) AS c WHERE c.code = 'concept_presentation');

INSERT INTO exam_stages (stage_id, code, name, display_order, evidence_doc_type_id)
SELECT UUID(), 'thesis_draft', 'Thesis Draft', 3,
       (SELECT doc_type_id FROM document_types WHERE doc_type_name = 'Thesis' LIMIT 1)
WHERE NOT EXISTS (SELECT 1 FROM (SELECT code FROM exam_stages) AS c WHERE c.code = 'thesis_draft');

-- Final Thesis has no evidence document type of its own: it shares the
-- 'Thesis' type with the draft, so the two can't be told apart from
-- documents alone. It completes when an exam meeting carrying this
-- stage records its outcome (phase 5).
INSERT INTO exam_stages (stage_id, code, name, display_order, evidence_doc_type_id)
SELECT UUID(), 'final_thesis', 'Final Thesis', 4, NULL
WHERE NOT EXISTS (SELECT 1 FROM (SELECT code FROM exam_stages) AS c WHERE c.code = 'final_thesis');
