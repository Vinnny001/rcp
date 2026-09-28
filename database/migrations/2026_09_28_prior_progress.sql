-- Continuing from where a student left off.
--
-- A student who already passed stages elsewhere (or before this system
-- existed) claims the last stage they passed and uploads the evidence for
-- it — the meeting minutes and transcript to begin with. An administrator
-- approves the claim, and that stage and every earlier one are recorded
-- complete, so the student resumes at the next stage instead of starting
-- again.
--
-- What counts as evidence is the administrator's to set, per stage, on the
-- Exam Stages screen: `stage_evidence_requirements` rows with stage_id
-- NULL are the default list, used by any stage with no list of its own.
--
-- Idempotent: the seeds check for themselves before inserting.

CREATE TABLE IF NOT EXISTS stage_evidence_requirements (
    requirement_id   CHAR(36)     NOT NULL DEFAULT (UUID()),
    -- NULL is the default list, used by stages that have none of their own.
    stage_id         CHAR(36)     DEFAULT NULL,
    label            VARCHAR(150) NOT NULL,
    document_type_id CHAR(36)     NOT NULL,
    note             TEXT,
    is_required      TINYINT(1)   NOT NULL DEFAULT 1,
    display_order    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_active        TINYINT(1)   NOT NULL DEFAULT 1,
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (requirement_id),
    KEY idx_evidence_stage (stage_id, is_active, display_order),
    KEY fk_evidence_doc_type (document_type_id),
    CONSTRAINT fk_evidence_stage FOREIGN KEY (stage_id) REFERENCES exam_stages (stage_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_evidence_doc_type FOREIGN KEY (document_type_id) REFERENCES document_types (doc_type_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS prior_progress_claims (
    claim_id        CHAR(36) NOT NULL DEFAULT (UUID()),
    student_id      CHAR(36) NOT NULL,
    -- The last stage they say they passed; approval grants it and every
    -- earlier stage.
    stage_id        CHAR(36) NOT NULL,
    status          ENUM('submitted', 'approved', 'rejected') NOT NULL DEFAULT 'submitted',
    student_note    TEXT,
    decision_reason TEXT,
    decided_by      CHAR(36) DEFAULT NULL,
    decided_at      DATETIME DEFAULT NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (claim_id),
    KEY idx_claims_student (student_id, status),
    KEY fk_claims_stage (stage_id),
    KEY fk_claims_decided_by (decided_by),
    CONSTRAINT fk_claims_student FOREIGN KEY (student_id) REFERENCES students (student_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_claims_stage FOREIGN KEY (stage_id) REFERENCES exam_stages (stage_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_claims_decided_by FOREIGN KEY (decided_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS prior_progress_files (
    file_id        CHAR(36)     NOT NULL DEFAULT (UUID()),
    claim_id       CHAR(36)     NOT NULL,
    -- The requirement this answers, kept by label too so the claim still
    -- reads correctly after an administrator edits the list.
    requirement_id CHAR(36)     DEFAULT NULL,
    label          VARCHAR(150) NOT NULL,
    document_id    CHAR(36)     NOT NULL,
    PRIMARY KEY (file_id),
    KEY idx_files_claim (claim_id),
    KEY fk_files_requirement (requirement_id),
    KEY fk_files_document (document_id),
    CONSTRAINT fk_files_claim FOREIGN KEY (claim_id) REFERENCES prior_progress_claims (claim_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_files_requirement FOREIGN KEY (requirement_id) REFERENCES stage_evidence_requirements (requirement_id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_files_document FOREIGN KEY (document_id) REFERENCES documents (document_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci;

-- The two kinds of evidence asked for to begin with need document types of
-- their own, so an upload files itself as what it is.
INSERT INTO document_types (doc_type_id, doc_type_name, description)
SELECT UUID(), 'Meeting Minutes', 'Minutes of the meeting or examination at which a stage was passed'
WHERE NOT EXISTS (SELECT 1 FROM document_types WHERE doc_type_name = 'Meeting Minutes');

INSERT INTO document_types (doc_type_id, doc_type_name, description)
SELECT UUID(), 'Transcript', 'An academic transcript or results record'
WHERE NOT EXISTS (SELECT 1 FROM document_types WHERE doc_type_name = 'Transcript');

-- The default list an administrator can then change: minutes and a
-- transcript, both required.
INSERT INTO stage_evidence_requirements (requirement_id, stage_id, label, document_type_id, note, is_required, display_order)
SELECT UUID(), NULL, 'Meeting minutes', dt.doc_type_id,
       'The minutes of the meeting or examination at which you passed this stage.', 1, 1
FROM document_types dt
WHERE dt.doc_type_name = 'Meeting Minutes'
  AND NOT EXISTS (SELECT 1 FROM stage_evidence_requirements WHERE stage_id IS NULL AND label = 'Meeting minutes');

INSERT INTO stage_evidence_requirements (requirement_id, stage_id, label, document_type_id, note, is_required, display_order)
SELECT UUID(), NULL, 'Transcript', dt.doc_type_id,
       'Your transcript or results record showing the stage was passed.', 1, 2
FROM document_types dt
WHERE dt.doc_type_name = 'Transcript'
  AND NOT EXISTS (SELECT 1 FROM stage_evidence_requirements WHERE stage_id IS NULL AND label = 'Transcript');
