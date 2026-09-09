-- Supervisor assignment becomes coordinator-mediated: a student
-- submits one ranked shortlist, the department votes on it as a whole,
-- and only then does the system approach lecturers — one at a time, in
-- the student's own order.
--
-- This replaces the direct student -> lecturer flow in
-- supervision_requests, which is left in place for its existing rows
-- but takes no new ones.

CREATE TABLE IF NOT EXISTS supervisor_shortlists (
    shortlist_id CHAR(36) NOT NULL DEFAULT (UUID()),
    student_id   CHAR(36) NOT NULL,
    proposal_id  CHAR(36) NOT NULL,

    -- pending_coordinator: submitted, waiting to be picked up
    -- meeting_scheduled:   a meeting exists but has no finalised minutes
    -- approved / rejected: the department has decided and the minutes are final
    -- exhausted:           approved, but every lecturer on it declined
    -- superseded:          replaced by a later shortlist from the same student
    status ENUM('pending_coordinator','meeting_scheduled','approved','rejected','exhausted','superseded')
           NOT NULL DEFAULT 'pending_coordinator',

    rejection_reason TEXT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    decided_at   DATETIME NULL,

    PRIMARY KEY (shortlist_id),
    KEY idx_shortlists_student (student_id),
    KEY idx_shortlists_status (status),
    CONSTRAINT fk_shortlists_student  FOREIGN KEY (student_id)  REFERENCES students (student_id),
    CONSTRAINT fk_shortlists_proposal FOREIGN KEY (proposal_id) REFERENCES thesis_proposals (proposal_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- Up to five lecturers, ranked, exactly one flagged as the student's
-- preferred main supervisor. request_status tracks each one separately:
-- the department approves the whole list, but lecturers are approached
-- in rank order, so most sit at 'not_sent' for a while.
CREATE TABLE IF NOT EXISTS supervisor_shortlist_choices (
    choice_id     CHAR(36) NOT NULL DEFAULT (UUID()),
    shortlist_id  CHAR(36) NOT NULL,
    lecturer_id   CHAR(36) NOT NULL,
    rank_position SMALLINT UNSIGNED NOT NULL,
    is_preferred_main TINYINT(1) NOT NULL DEFAULT 0,
    request_status ENUM('not_sent','pending','accepted','declined','cancelled') NOT NULL DEFAULT 'not_sent',
    decline_reason TEXT NULL,
    sent_at       DATETIME NULL,
    decided_at    DATETIME NULL,

    PRIMARY KEY (choice_id),
    UNIQUE KEY uniq_shortlist_lecturer (shortlist_id, lecturer_id),
    UNIQUE KEY uniq_shortlist_rank (shortlist_id, rank_position),
    KEY idx_choices_lecturer (lecturer_id),
    CONSTRAINT fk_choices_shortlist FOREIGN KEY (shortlist_id) REFERENCES supervisor_shortlists (shortlist_id) ON DELETE CASCADE,
    CONSTRAINT fk_choices_lecturer  FOREIGN KEY (lecturer_id)  REFERENCES lecturers (lecturer_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- The department meeting that reviews one shortlist. Minutes live here
-- and gate the outcome: nothing acts on the vote until they are final.
CREATE TABLE IF NOT EXISTS shortlist_meetings (
    meeting_id   CHAR(36) NOT NULL DEFAULT (UUID()),
    shortlist_id CHAR(36) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    location     VARCHAR(255) NULL,
    virtual_link VARCHAR(255) NULL,
    mode         ENUM('physical','virtual','hybrid') NOT NULL DEFAULT 'physical',
    minutes      TEXT NULL,
    minutes_finalized_at DATETIME NULL,
    created_by   CHAR(36) NOT NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (meeting_id),
    KEY idx_shortlist_meetings_shortlist (shortlist_id),
    CONSTRAINT fk_shortlist_meetings_shortlist FOREIGN KEY (shortlist_id) REFERENCES supervisor_shortlists (shortlist_id) ON DELETE CASCADE,
    CONSTRAINT fk_shortlist_meetings_creator   FOREIGN KEY (created_by)   REFERENCES users (user_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- Who the coordinator asked to attend. Drives each head's "meetings I
-- am invited to" list, and bounds who may vote.
CREATE TABLE IF NOT EXISTS shortlist_meeting_invitees (
    invitee_id   CHAR(36) NOT NULL DEFAULT (UUID()),
    meeting_id   CHAR(36) NOT NULL,
    dept_head_id CHAR(36) NOT NULL,
    invited_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (invitee_id),
    UNIQUE KEY uniq_meeting_invitee (meeting_id, dept_head_id),
    CONSTRAINT fk_invitees_meeting FOREIGN KEY (meeting_id)   REFERENCES shortlist_meetings (meeting_id) ON DELETE CASCADE,
    CONSTRAINT fk_invitees_head    FOREIGN KEY (dept_head_id) REFERENCES department_heads (dept_head_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- One vote per head per meeting, on the shortlist as a whole rather
-- than per lecturer. The unique key is what stops a second vote.
CREATE TABLE IF NOT EXISTS shortlist_meeting_votes (
    vote_id      CHAR(36) NOT NULL DEFAULT (UUID()),
    meeting_id   CHAR(36) NOT NULL,
    dept_head_id CHAR(36) NOT NULL,
    vote         ENUM('approve','reject') NOT NULL,
    comment      TEXT NULL,
    voted_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (vote_id),
    UNIQUE KEY uniq_meeting_voter (meeting_id, dept_head_id),
    CONSTRAINT fk_votes_meeting FOREIGN KEY (meeting_id)   REFERENCES shortlist_meetings (meeting_id) ON DELETE CASCADE,
    CONSTRAINT fk_votes_head    FOREIGN KEY (dept_head_id) REFERENCES department_heads (dept_head_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
