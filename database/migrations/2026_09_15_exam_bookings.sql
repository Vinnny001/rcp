-- Exam bookings.
--
-- A student now books one of the exam windows open for their stage before
-- submitting its documents, and the booking (an exam_readiness row) puts
-- them in the coordinator's queue. "Marking yourself ready" is gone.
--
-- A student who already uploaded documents for a window that examines a
-- stage did so before bookings existed. Book them onto that window, so the
-- documents stay in front of them and the coordinator sees them — unless
-- they already hold a booking for a window of the same stage.
--
-- Idempotent: rerunning books nobody twice.

INSERT INTO exam_readiness (readiness_id, student_id, exam_schedule_id, marked_ready_at)
SELECT UUID(), uploads.student_id, uploads.exam_schedule_id, uploads.first_upload
FROM (
    SELECT st.student_id, ed.exam_schedule_id, es.exam_stage_id, MIN(ed.submitted_at) AS first_upload
    FROM exam_documents ed
    JOIN documents d ON d.document_id = ed.document_id
    JOIN students st ON st.user_id = d.user_id
    JOIN exam_schedule es ON es.exam_schedule_id = ed.exam_schedule_id AND es.exam_stage_id IS NOT NULL
    GROUP BY st.student_id, ed.exam_schedule_id, es.exam_stage_id
) uploads
WHERE NOT EXISTS (
    SELECT 1 FROM exam_readiness r
    JOIN exam_schedule res ON res.exam_schedule_id = r.exam_schedule_id
    WHERE r.student_id = uploads.student_id AND res.exam_stage_id = uploads.exam_stage_id
);
