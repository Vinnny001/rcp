-- Notifications were keyed only by user_id, so a user holding both the
-- lecturer and student roles saw every notification addressed to
-- either hat regardless of which one they were logged in as. `role`
-- records which hat a notification was actually sent to, since every
-- send site already knows this at the time it creates the row.
--
-- Backfill for the rows that existed before this column: 'broadcast'
-- rows here are all lecturer-supervision-load notices (role=lecturer);
-- the 'supervision_reminder' row is a lecturer-to-student reminder
-- (role=student).

ALTER TABLE notifications
    ADD COLUMN role ENUM('admin', 'lecturer', 'student') NOT NULL DEFAULT 'student';

UPDATE notifications SET role = 'lecturer' WHERE related_entity_type = 'broadcast';
UPDATE notifications SET role = 'student' WHERE related_entity_type = 'supervision_reminder';
