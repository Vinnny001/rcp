-- The files each supervisor request was sent with.
--
-- A request keeps a copy of the proposal's title and synopsis as they
-- stood when it was sent. The PDFs were not kept: if the coordinator
-- handed the proposal back and the student uploaded a new one, the old
-- file was deleted, and a failed request no longer showed what it had
-- actually been judged on.
--
-- This records each request's files by path rather than by document
-- row. The documents row is still replaced when the student uploads a
-- new version — it represents the proposal as it is now — but the file
-- on disk is kept for as long as any request refers to it.

CREATE TABLE IF NOT EXISTS supervisor_shortlist_files (
    file_id        CHAR(36) NOT NULL DEFAULT (UUID()),
    shortlist_id   CHAR(36) NOT NULL,
    document_type  VARCHAR(100) NOT NULL,
    file_name      VARCHAR(255) NOT NULL,
    file_path      VARCHAR(500) NOT NULL,
    file_size_kb   INT UNSIGNED NULL,
    captured_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (file_id),
    KEY idx_request_files_request (shortlist_id),
    KEY idx_request_files_path (file_path),
    CONSTRAINT fk_request_files_request FOREIGN KEY (shortlist_id)
        REFERENCES supervisor_shortlists (shortlist_id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- Requests already sent: their proposals have been locked since, so the
-- files on them now are the files they were sent with.
INSERT INTO supervisor_shortlist_files (file_id, shortlist_id, document_type, file_name, file_path, file_size_kb, captured_at)
SELECT UUID(), s.shortlist_id, dt.doc_type_name, d.file_name, d.file_path, d.file_size_kb, COALESCE(s.submitted_at, s.created_at)
FROM supervisor_shortlists s
JOIN exam_documents ed ON ed.proposal_id = s.proposal_id
JOIN documents d ON d.document_id = ed.document_id
JOIN document_types dt ON dt.doc_type_id = ed.document_type_id
WHERE s.status NOT IN ('draft', 'superseded')
  AND dt.doc_type_name IN ('Synopsis', 'Proposal')
  AND NOT EXISTS (SELECT 1 FROM supervisor_shortlist_files f WHERE f.shortlist_id = s.shortlist_id);
