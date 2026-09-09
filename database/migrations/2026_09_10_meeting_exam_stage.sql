-- Ties an exam meeting to the stage it examines.
--
-- Without this there is no way to tell which marking scheme a meeting
-- is scored against: stages carry the rubric, and a meeting only knew
-- its meeting_type. It is also what will eventually distinguish a
-- Thesis Draft meeting from a Final Thesis one, which share a document
-- type and so cannot be told apart from documents alone.
--
-- Nullable: supervisory check-ins and supervision reviews examine
-- nothing, and existing meetings pre-date stages entirely.

SET @add_stage := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE meetings ADD COLUMN exam_stage_id CHAR(36) NULL AFTER exam_schedule_id',
        'DO 0')
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meetings' AND COLUMN_NAME = 'exam_stage_id'
);
PREPARE stmt FROM @add_stage; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @fk_stage := (
    SELECT IF(COUNT(*) = 0,
        'ALTER TABLE meetings ADD CONSTRAINT fk_meetings_exam_stage FOREIGN KEY (exam_stage_id) REFERENCES exam_stages (stage_id)',
        'DO 0')
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'meetings'
      AND CONSTRAINT_NAME = 'fk_meetings_exam_stage'
);
PREPARE stmt FROM @fk_stage; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Existing meetings map onto the stages they were already examining.
-- The three former viva rows became final_thesis when meeting types
-- were split; approval_board rows are the proposal approval stage.
UPDATE meetings m
JOIN exam_stages s ON s.code = 'approval'
SET m.exam_stage_id = s.stage_id
WHERE m.meeting_type = 'approval_board' AND m.exam_stage_id IS NULL;

UPDATE meetings m
JOIN exam_stages s ON s.code = 'concept_presentation'
SET m.exam_stage_id = s.stage_id
WHERE m.meeting_type = 'concept_presentation' AND m.exam_stage_id IS NULL;

UPDATE meetings m
JOIN exam_stages s ON s.code = 'final_thesis'
SET m.exam_stage_id = s.stage_id
WHERE m.meeting_type = 'viva' AND m.exam_stage_id IS NULL;
