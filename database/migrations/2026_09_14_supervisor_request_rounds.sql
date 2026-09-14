-- Supervisor requests go out to every shortlisted lecturer at once.
--
-- Until now a department-approved shortlist was worked one lecturer at
-- a time: the next was only asked once the previous one answered, so a
-- single slow reply held the whole student up. Now the coordinator sends
-- the request to everyone together, lecturers have a fixed window to
-- answer, and the student's own order — never who replied first —
-- decides who is appointed.
--
-- Each supervisor_shortlists row is one attempt, and its id is the
-- request id. A failed attempt keeps its outcome; the next attempt is a
-- new row that points back at it, so the history of what was tried, and
-- why it failed, stays on record.
--
-- Status now runs:
--   draft               the student is still writing it (with a draft proposal)
--   pending_coordinator sent; locked; waiting for a department meeting
--   meeting_scheduled   a meeting exists, the decision is not yet applied
--   approved            the department approved it; not yet sent to lecturers
--   requests_sent       out with lecturers, responses_due_at running
--   successful          at least one supervisor appointed
--   rejected            the department did not approve it
--   exhausted           sent, but nobody could be appointed
-- 'superseded' is kept only for rows written before this change.

ALTER TABLE supervisor_shortlists
    MODIFY COLUMN status ENUM(
        'draft','pending_coordinator','meeting_scheduled','approved',
        'requests_sent','successful','rejected','exhausted','superseded'
    ) NOT NULL DEFAULT 'pending_coordinator';

-- unavailable: at full supervision load, so never asked (or the load
--              filled before the outcome was decided)
-- expired:     still unanswered when the window closed
-- cancelled:   not needed — the student's higher choices filled the panel
ALTER TABLE supervisor_shortlist_choices
    MODIFY COLUMN request_status ENUM(
        'not_sent','pending','accepted','declined','unavailable','expired','cancelled'
    ) NOT NULL DEFAULT 'not_sent';

-- ---------------------------------------------------------------------
-- New columns, each added only if missing so the file can be re-run.
-- ---------------------------------------------------------------------

SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE supervisor_shortlists ADD COLUMN previous_shortlist_id CHAR(36) NULL AFTER proposal_id',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supervisor_shortlists' AND COLUMN_NAME = 'previous_shortlist_id');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The student, or the coordinator when they revised the list themselves.
SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE supervisor_shortlists ADD COLUMN created_by CHAR(36) NULL AFTER previous_shortlist_id',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supervisor_shortlists' AND COLUMN_NAME = 'created_by');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- The moment it left the student's hands. Nothing is editable after it.
SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE supervisor_shortlists ADD COLUMN submitted_at DATETIME NULL AFTER created_at',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supervisor_shortlists' AND COLUMN_NAME = 'submitted_at');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- What the proposal said when this attempt was sent. The proposal row is
-- edited in place if the coordinator later lets the student revise it,
-- so without these the record of a failed attempt would silently start
-- describing a different proposal.
SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE supervisor_shortlists ADD COLUMN proposal_title_snapshot VARCHAR(255) NULL AFTER submitted_at',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supervisor_shortlists' AND COLUMN_NAME = 'proposal_title_snapshot');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE supervisor_shortlists ADD COLUMN proposal_synopsis_snapshot TEXT NULL AFTER proposal_title_snapshot',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supervisor_shortlists' AND COLUMN_NAME = 'proposal_synopsis_snapshot');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE supervisor_shortlists ADD COLUMN requests_sent_at DATETIME NULL AFTER decided_at',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supervisor_shortlists' AND COLUMN_NAME = 'requests_sent_at');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE supervisor_shortlists ADD COLUMN requests_sent_by CHAR(36) NULL AFTER requests_sent_at',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supervisor_shortlists' AND COLUMN_NAME = 'requests_sent_by');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Stored rather than derived from requests_sent_at, so changing the
-- length of the window later never moves the deadline on a request that
-- is already out.
SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE supervisor_shortlists ADD COLUMN responses_due_at DATETIME NULL AFTER requests_sent_by',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supervisor_shortlists' AND COLUMN_NAME = 'responses_due_at');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- After a failed attempt the coordinator may hand editing back to the
-- student: the proposal, the list, or both. Until the student sends the
-- revision, nobody else may change anything or schedule a meeting.
SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE supervisor_shortlists ADD COLUMN edit_proposal_allowed TINYINT(1) NOT NULL DEFAULT 0 AFTER responses_due_at',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supervisor_shortlists' AND COLUMN_NAME = 'edit_proposal_allowed');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE supervisor_shortlists ADD COLUMN edit_shortlist_allowed TINYINT(1) NOT NULL DEFAULT 0 AFTER edit_proposal_allowed',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supervisor_shortlists' AND COLUMN_NAME = 'edit_shortlist_allowed');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE supervisor_shortlists ADD COLUMN edit_granted_at DATETIME NULL AFTER edit_shortlist_allowed',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supervisor_shortlists' AND COLUMN_NAME = 'edit_granted_at');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE supervisor_shortlists ADD COLUMN edit_granted_by CHAR(36) NULL AFTER edit_granted_at',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supervisor_shortlists' AND COLUMN_NAME = 'edit_granted_by');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE supervisor_shortlists ADD KEY idx_shortlists_previous (previous_shortlist_id)',
    'DO 0') FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'supervisor_shortlists' AND INDEX_NAME = 'idx_shortlists_previous');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- Existing rows
-- ---------------------------------------------------------------------

-- Every row written before this change had already been sent.
UPDATE supervisor_shortlists s
JOIN thesis_proposals tp ON tp.proposal_id = s.proposal_id
JOIN students st ON st.student_id = s.student_id
SET s.submitted_at = COALESCE(s.submitted_at, s.created_at),
    s.created_by   = COALESCE(s.created_by, st.user_id),
    s.proposal_title_snapshot    = COALESCE(s.proposal_title_snapshot, tp.title),
    s.proposal_synopsis_snapshot = COALESCE(s.proposal_synopsis_snapshot, tp.synopsis)
WHERE s.status <> 'draft';

-- A document uploaded while saving a draft kept document_status 'draft'
-- after the proposal was submitted, because submitting never promoted
-- it. Reviewers are only ever offered 'submitted' documents, so those
-- files were invisible to them — and the student could still delete
-- them from a proposal that was meant to be locked.
UPDATE documents d
JOIN exam_documents ed ON ed.document_id = d.document_id
JOIN thesis_proposals tp ON tp.proposal_id = ed.proposal_id
SET d.document_status = 'submitted'
WHERE d.document_status = 'draft' AND tp.status <> 'draft';
