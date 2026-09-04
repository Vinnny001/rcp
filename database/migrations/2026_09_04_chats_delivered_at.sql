-- Tracks the middle state between "sent" and "read" — set the instant
-- rcp_messaging confirms the recipient had a live connection (either at
-- send time, or in bulk when they next come online), independently of
-- read_at, so the client can render sent/delivered/read tick marks.

ALTER TABLE chats
    ADD COLUMN delivered_at DATETIME NULL AFTER read_at;
