-- Flags an exam_document whose approval-board outcome came back
-- "resubmit". The student's Requirements page reopens this slot for a
-- fresh upload without a new meeting needing to be scheduled — the
-- resubmission lands as a new exam_documents row against the same
-- exam_schedule_id, so it naturally rejoins the same original meeting.

ALTER TABLE exam_documents
    ADD COLUMN requires_resubmit TINYINT(1) NOT NULL DEFAULT 0;
