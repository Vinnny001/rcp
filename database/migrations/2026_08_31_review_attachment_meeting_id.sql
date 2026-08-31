-- Adds meeting_id to exam_review_attachments and document_review_attachments,
-- at the user's explicit request, to make "which meeting was this review
-- attachment uploaded under" a direct lookup instead of an indirect join.
--
-- exam_review_attachments already has exam_document_id, which unambiguously
-- identifies the document under review (exam_documents.document_id) — but
-- had no direct link to the meeting itself; callers previously had to join
-- through (proposal_id, exam_schedule_id) matching against meetings.
--
-- document_review_attachments has no equivalent "which document was under
-- review" column at all (document_id here is the examiner's own uploaded
-- evidence file, confirmed with the user) — meeting_id is the only lookup
-- path back to context for this table, so it's required (NOT NULL) rather
-- than merely a convenience.
--
-- Both tables are empty in production as of this migration, so no backfill
-- is needed.

ALTER TABLE exam_review_attachments
    ADD COLUMN meeting_id CHAR(36) NOT NULL AFTER exam_document_id,
    ADD KEY idx_era_meeting (meeting_id),
    ADD CONSTRAINT fk_era_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(meeting_id);

ALTER TABLE document_review_attachments
    ADD COLUMN meeting_id CHAR(36) NOT NULL AFTER examiner_id,
    ADD KEY idx_dra_meeting (meeting_id),
    ADD CONSTRAINT fk_dra_meeting FOREIGN KEY (meeting_id) REFERENCES meetings(meeting_id);
