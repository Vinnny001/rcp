-- Documents attached to a meeting for review.
--
-- exam_documents already links documents to a (proposal, exam_schedule)
-- pair, which only works for meetings tied to a formal exam window.
-- A general supervisory meeting has no exam_schedule_id, so it needs its
-- own link: the supervisor optionally picks any document belonging to
-- the student being supervised, and invited examiners (plus the
-- supervisor) score it into document_review_scores.

CREATE TABLE IF NOT EXISTS meeting_documents (
    meeting_document_id CHAR(36)  NOT NULL DEFAULT (UUID()),
    meeting_id          CHAR(36)  NOT NULL,
    document_id         CHAR(36)  NOT NULL,
    added_by            CHAR(36)  NOT NULL,
    added_at            DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (meeting_document_id),
    UNIQUE KEY uniq_meeting_document (meeting_id, document_id),
    KEY idx_meeting_documents_document (document_id),
    KEY idx_meeting_documents_added_by (added_by),
    CONSTRAINT meeting_documents_ibfk_1 FOREIGN KEY (meeting_id)  REFERENCES meetings (meeting_id)   ON DELETE CASCADE,
    CONSTRAINT meeting_documents_ibfk_2 FOREIGN KEY (document_id) REFERENCES documents (document_id) ON DELETE CASCADE,
    CONSTRAINT meeting_documents_ibfk_3 FOREIGN KEY (added_by)    REFERENCES users (user_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
