-- Minutes of a supervisor-request meeting, and their summary.
--
-- The minutes can be typed, uploaded as a document, or both — one of
-- the two is needed to finalise them. Every meeting also carries a short
-- summary, also needed to finalise. Once the minutes are final the
-- summary is what people read: the full minutes stay with those who
-- belong to the meeting, and the student only ever sees the summary.
--
-- The document is kept outside public/ (var/uploads/minutes) and served
-- through a route that checks who is asking, so the path stored here is
-- relative to var/uploads.
--
-- Meetings decided before summaries existed get one written from the
-- record — the outcome and the votes — and a request the department
-- turned down gives the student that summary as its reason instead of
-- the full minutes.

SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE shortlist_meetings ADD COLUMN summary TEXT NULL AFTER minutes',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shortlist_meetings' AND COLUMN_NAME = 'summary');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE shortlist_meetings ADD COLUMN minutes_file_name VARCHAR(255) NULL AFTER summary',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shortlist_meetings' AND COLUMN_NAME = 'minutes_file_name');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE shortlist_meetings ADD COLUMN minutes_file_path VARCHAR(255) NULL AFTER minutes_file_name',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shortlist_meetings' AND COLUMN_NAME = 'minutes_file_path');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE shortlist_meetings ADD COLUMN minutes_file_size_kb INT UNSIGNED NULL AFTER minutes_file_path',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shortlist_meetings' AND COLUMN_NAME = 'minutes_file_size_kb');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE shortlist_meetings ADD COLUMN minutes_file_uploaded_at DATETIME NULL AFTER minutes_file_size_kb',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shortlist_meetings' AND COLUMN_NAME = 'minutes_file_uploaded_at');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Summaries for meetings whose decision has already been applied. The
-- wording matches SupervisorShortlist::decisionSummary(), which writes
-- the same thing for a meeting finalised without one.
UPDATE shortlist_meetings m
JOIN supervisor_shortlists s ON s.shortlist_id = m.shortlist_id
LEFT JOIN (
    SELECT meeting_id, SUM(vote = 'approve') AS approve_count, SUM(vote = 'reject') AS reject_count
    FROM shortlist_meeting_votes
    GROUP BY meeting_id
) v ON v.meeting_id = m.meeting_id
SET m.summary = CASE
    WHEN s.status <> 'rejected' THEN
        CONCAT('The department approved this supervisor request, with ',
               COALESCE(v.approve_count, 0), ' for and ', COALESCE(v.reject_count, 0), ' against.')
    WHEN COALESCE(v.approve_count, 0) = COALESCE(v.reject_count, 0) THEN
        CONCAT('The department did not approve this supervisor request: the vote was tied, ',
               COALESCE(v.approve_count, 0), ' for and ', COALESCE(v.reject_count, 0), ' against, and a tie does not approve.')
    ELSE
        CONCAT('The department did not approve this supervisor request, with ',
               COALESCE(v.approve_count, 0), ' for and ', COALESCE(v.reject_count, 0), ' against.')
END
WHERE m.summary IS NULL
  AND m.minutes_finalized_at IS NOT NULL
  AND s.status NOT IN ('pending_coordinator', 'meeting_scheduled');

-- A turned-down request showed the student the full minutes as its
-- reason. It shows the summary now.
UPDATE supervisor_shortlists s
JOIN shortlist_meetings m ON m.shortlist_id = s.shortlist_id
SET s.rejection_reason = m.summary
WHERE s.status = 'rejected'
  AND m.summary IS NOT NULL
  AND (s.rejection_reason IS NULL OR s.rejection_reason = m.minutes);
