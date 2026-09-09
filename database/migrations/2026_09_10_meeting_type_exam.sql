-- Adds the meeting kinds the redesign introduced.
--
-- 'exam' is the generic kind for anything examined against a rubric —
-- which stage it examines is exam_stage_id's job, not the type's. That
-- is what lets an admin add a new stage without an enum migration,
-- which was the point of making stages configurable.
--
-- 'supervision_review' is here for completeness, though shortlist
-- meetings currently live in their own table with their own votes and
-- minutes.
--
-- The old values stay: they carry existing rows, and every one of them
-- already has an exam_stage_id pointing at the stage it examined.

ALTER TABLE meetings
    MODIFY COLUMN meeting_type
    ENUM('approval_board','supervisory','concept_presentation','viva','exam','supervision_review')
    NOT NULL;
