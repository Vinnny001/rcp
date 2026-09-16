-- Topic approval.
--
-- Once a student has supervisors, the topic and its synopsis are approved
-- by those supervisors at a meeting the main supervisor calls, before the
-- Proposal Approval exam. A student may put forward as many topics as they
-- like, and the meeting approves at most one. If none is approved the
-- topics stay on record and the student submits fresh ones.
--
-- `proposal_topics` holds what is put forward, `topic_decisions` each
-- supervisor's decision at a meeting. The title sent with the supervisor
-- request becomes the first topic, so nobody retypes it.
--
-- Idempotent: MySQL has no ADD COLUMN/ENUM IF NOT EXISTS, so the enum
-- change checks information_schema first.

CREATE TABLE IF NOT EXISTS proposal_topics (
    topic_id        CHAR(36)     NOT NULL DEFAULT (UUID()),
    student_id      CHAR(36)     NOT NULL,
    proposal_id     CHAR(36)     NOT NULL,
    title           VARCHAR(255) NOT NULL,
    synopsis        TEXT         NOT NULL,
    -- 'proposal' is the topic carried over from the supervisor request.
    origin          ENUM('proposal', 'student') NOT NULL DEFAULT 'student',
    status          ENUM('submitted', 'approved', 'not_approved') NOT NULL DEFAULT 'submitted',
    meeting_id      CHAR(36)     DEFAULT NULL,
    decision_reason TEXT,
    decided_at      DATETIME     DEFAULT NULL,
    submitted_by    CHAR(36)     NOT NULL,
    created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (topic_id),
    KEY idx_topics_student (student_id),
    KEY idx_topics_proposal (proposal_id),
    KEY idx_topics_status (status),
    KEY fk_topics_meeting (meeting_id),
    KEY fk_topics_submitted_by (submitted_by),
    CONSTRAINT fk_topics_student FOREIGN KEY (student_id) REFERENCES students (student_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_topics_proposal FOREIGN KEY (proposal_id) REFERENCES thesis_proposals (proposal_id) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT fk_topics_meeting FOREIGN KEY (meeting_id) REFERENCES meetings (meeting_id) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT fk_topics_submitted_by FOREIGN KEY (submitted_by) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci;

CREATE TABLE IF NOT EXISTS topic_decisions (
    decision_id   CHAR(36) NOT NULL DEFAULT (UUID()),
    meeting_id    CHAR(36) NOT NULL,
    topic_id      CHAR(36) NOT NULL,
    -- The decision belongs to the supervisor who made it, not to a
    -- lecturer record, so it reads the same however they signed in.
    voter_user_id CHAR(36) NOT NULL,
    decision      ENUM('approve', 'reject') NOT NULL,
    comment       TEXT,
    recorded_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (decision_id),
    UNIQUE KEY uq_topic_voter (meeting_id, topic_id, voter_user_id),
    KEY fk_decisions_topic (topic_id),
    KEY fk_decisions_voter (voter_user_id),
    CONSTRAINT fk_decisions_meeting FOREIGN KEY (meeting_id) REFERENCES meetings (meeting_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_decisions_topic FOREIGN KEY (topic_id) REFERENCES proposal_topics (topic_id) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_decisions_voter FOREIGN KEY (voter_user_id) REFERENCES users (user_id) ON DELETE RESTRICT ON UPDATE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_0900_ai_ci;

-- meetings.meeting_type gains 'topic_approval'.
SET @needs_type := (
    SELECT COUNT(*) FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meetings'
      AND COLUMN_NAME = 'meeting_type' AND COLUMN_TYPE NOT LIKE '%topic_approval%'
);
SET @sql := IF(
    @needs_type > 0,
    "ALTER TABLE meetings MODIFY COLUMN meeting_type
        ENUM('approval_board','supervisory','concept_presentation','viva','exam','supervision_review','topic_approval') NOT NULL",
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- The topic every student with a proposal is already working under
-- becomes their first topic, so the first meeting has something to
-- consider. Students whose proposal is already approved get theirs
-- recorded as approved — that decision was taken before this existed.
INSERT INTO proposal_topics (topic_id, student_id, proposal_id, title, synopsis, origin, status, decided_at, submitted_by)
SELECT UUID(), tp.student_id, tp.proposal_id, tp.title, tp.synopsis, 'proposal',
       IF(tp.status = 'approved', 'approved', 'submitted'),
       IF(tp.status = 'approved', COALESCE(tp.approval_date, tp.updated_at), NULL),
       s.user_id
FROM thesis_proposals tp
JOIN students s ON s.student_id = tp.student_id
WHERE tp.status NOT IN ('draft', 'rejected')
  AND NOT EXISTS (SELECT 1 FROM proposal_topics t WHERE t.proposal_id = tp.proposal_id);
