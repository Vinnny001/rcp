-- Roles for the supervision redesign: research coordinators, department
-- heads, and which programs a lecturer may examine.
--
-- These are deliberately NOT rows in `roles`/`user_roles`. That system
-- is flat — it answers "is this person a lecturer?" and drives the
-- session role and navigation. Every role here is scoped to a program
-- or a department, and "coordinator of Computer Science" is not a
-- thing user_roles can hold. A coordinator's session role stays
-- 'lecturer'; the scoped row is what grants the coordinator powers.

-- Admin-extensible list of positions a department head can hold, so a
-- new title is a row rather than an ENUM migration. 'coordinator'
-- appears here because it shows on a department roster, but holding
-- the position grants nothing on its own — see research_coordinators.
CREATE TABLE IF NOT EXISTS department_positions (
    position_id   CHAR(36) NOT NULL DEFAULT (UUID()),
    code          VARCHAR(50)  NOT NULL,
    name          VARCHAR(100) NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (position_id),
    UNIQUE KEY uniq_department_position_code (code)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

INSERT INTO department_positions (position_id, code, name, display_order)
SELECT * FROM (
    SELECT UUID() AS a, 'chair'       AS b, 'Chair'       AS c, 1 AS d UNION ALL
    SELECT UUID(),      'secretary',       'Secretary',        2 UNION ALL
    SELECT UUID(),      'member',          'Member',           3 UNION ALL
    SELECT UUID(),      'coordinator',     'Coordinator',      4
) AS seed
WHERE NOT EXISTS (SELECT 1 FROM (SELECT code FROM department_positions) AS existing);

-- Department heads vote on supervisor shortlists at the meetings a
-- coordinator convenes.
CREATE TABLE IF NOT EXISTS department_heads (
    dept_head_id  CHAR(36) NOT NULL DEFAULT (UUID()),
    department_id CHAR(36) NOT NULL,
    user_id       CHAR(36) NOT NULL,
    position_id   CHAR(36) NOT NULL,
    assigned_by   CHAR(36) NOT NULL,
    assigned_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (dept_head_id),
    UNIQUE KEY uniq_dept_head_user (department_id, user_id),
    KEY idx_dept_heads_department (department_id),
    CONSTRAINT fk_dept_heads_department FOREIGN KEY (department_id) REFERENCES departments (department_id),
    CONSTRAINT fk_dept_heads_user       FOREIGN KEY (user_id)       REFERENCES users (user_id),
    CONSTRAINT fk_dept_heads_position   FOREIGN KEY (position_id)   REFERENCES department_positions (position_id),
    CONSTRAINT fk_dept_heads_assigned_by FOREIGN KEY (assigned_by)  REFERENCES users (user_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- One coordinator per program. The unique key is on program_id alone:
-- a program has exactly one, while a user may coordinate several
-- programs if the admin assigns them.
CREATE TABLE IF NOT EXISTS research_coordinators (
    coordinator_id CHAR(36) NOT NULL DEFAULT (UUID()),
    program_id     CHAR(36) NOT NULL,
    user_id        CHAR(36) NOT NULL,
    assigned_by    CHAR(36) NOT NULL,
    assigned_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (coordinator_id),
    UNIQUE KEY uniq_coordinator_program (program_id),
    KEY idx_coordinators_user (user_id),
    CONSTRAINT fk_coordinators_program     FOREIGN KEY (program_id)  REFERENCES programs (program_id),
    CONSTRAINT fk_coordinators_user        FOREIGN KEY (user_id)     REFERENCES users (user_id),
    CONSTRAINT fk_coordinators_assigned_by FOREIGN KEY (assigned_by) REFERENCES users (user_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- Which programs a lecturer may examine. Keyed on lecturers, not users,
-- because external lecturers examine too and both kinds are rows there.
-- A lecturer with no row for a program never appears in that program's
-- examiner picker.
CREATE TABLE IF NOT EXISTS examiner_program_qualifications (
    qualification_id CHAR(36) NOT NULL DEFAULT (UUID()),
    lecturer_id      CHAR(36) NOT NULL,
    program_id       CHAR(36) NOT NULL,
    added_by         CHAR(36) NOT NULL,
    added_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (qualification_id),
    UNIQUE KEY uniq_examiner_program (lecturer_id, program_id),
    KEY idx_examiner_qual_program (program_id),
    CONSTRAINT fk_examiner_qual_lecturer FOREIGN KEY (lecturer_id) REFERENCES lecturers (lecturer_id),
    CONSTRAINT fk_examiner_qual_program  FOREIGN KEY (program_id)  REFERENCES programs (program_id),
    CONSTRAINT fk_examiner_qual_added_by FOREIGN KEY (added_by)    REFERENCES users (user_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- Profile links students read before shortlisting a supervisor. Named
-- to avoid confusion with `publications`, which holds students' own
-- thesis publications and is unrelated.
-- Research interests sit alongside the `specialization` field that is
-- already here, rather than in a table of their own: one lecturer has
-- one block of interests, so a second table would buy nothing.
-- MySQL has no ADD COLUMN IF NOT EXISTS, so this is guarded to keep
-- the file safe to re-run.
SET @add_interests := (
    SELECT IF(
        COUNT(*) = 0,
        'ALTER TABLE internal_lecturers ADD COLUMN research_interests TEXT NULL AFTER specialization',
        'DO 0'
    )
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME   = 'internal_lecturers'
      AND COLUMN_NAME  = 'research_interests'
);
PREPARE stmt FROM @add_interests;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS lecturer_publication_links (
    link_id     CHAR(36) NOT NULL DEFAULT (UUID()),
    lecturer_id CHAR(36) NOT NULL,
    platform    VARCHAR(50)  NOT NULL,
    label       VARCHAR(100) NULL,
    url         VARCHAR(500) NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (link_id),
    KEY idx_pub_links_lecturer (lecturer_id),
    CONSTRAINT fk_pub_links_lecturer FOREIGN KEY (lecturer_id) REFERENCES lecturers (lecturer_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
