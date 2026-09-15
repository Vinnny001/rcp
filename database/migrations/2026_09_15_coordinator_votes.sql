-- The research coordinator votes on supervisor requests too.
--
-- A vote used to belong to a department head (dept_head_id NOT NULL), so
-- a coordinator who was not also an invited head had no way to vote at
-- all. Votes now belong to a person: voter_user_id, with voter_role
-- saying in what capacity they voted. dept_head_id stays for head votes.
--
-- One person, one vote per meeting: the unique key is on the voter, so a
-- coordinator who is also an invited head cannot vote twice.

SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE shortlist_meeting_votes ADD COLUMN voter_user_id CHAR(36) NULL AFTER dept_head_id',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shortlist_meeting_votes' AND COLUMN_NAME = 'voter_user_id');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE shortlist_meeting_votes ADD COLUMN voter_role ENUM(''head'',''coordinator'') NOT NULL DEFAULT ''head'' AFTER voter_user_id',
    'DO 0') FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shortlist_meeting_votes' AND COLUMN_NAME = 'voter_role');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Every existing vote was a head's.
UPDATE shortlist_meeting_votes v
JOIN department_heads dh ON dh.dept_head_id = v.dept_head_id
SET v.voter_user_id = dh.user_id
WHERE v.voter_user_id IS NULL;

ALTER TABLE shortlist_meeting_votes MODIFY COLUMN dept_head_id CHAR(36) NULL;
ALTER TABLE shortlist_meeting_votes MODIFY COLUMN voter_user_id CHAR(36) NOT NULL;

SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE shortlist_meeting_votes ADD UNIQUE KEY uniq_meeting_person (meeting_id, voter_user_id)',
    'DO 0') FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shortlist_meeting_votes' AND INDEX_NAME = 'uniq_meeting_person');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @c := (SELECT IF(COUNT(*) = 0,
    'ALTER TABLE shortlist_meeting_votes ADD CONSTRAINT fk_votes_person FOREIGN KEY (voter_user_id) REFERENCES users (user_id)',
    'DO 0') FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'shortlist_meeting_votes' AND CONSTRAINT_NAME = 'fk_votes_person');
PREPARE stmt FROM @c; EXECUTE stmt; DEALLOCATE PREPARE stmt;
