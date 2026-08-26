-- Non-reviewable resources shared on a meeting (e.g. a grading sheet, a
-- reading, a video call recording link) — distinct from meeting_documents,
-- which is specifically for documents an examiner scores. A resource is
-- either an existing document (the student's, or one the lecturer
-- uploaded themselves) or a plain external link with a label.

CREATE TABLE IF NOT EXISTS meeting_resources (
    resource_id   CHAR(36)     NOT NULL DEFAULT (UUID()),
    meeting_id    CHAR(36)     NOT NULL,
    resource_type ENUM('document', 'link') NOT NULL,
    document_id   CHAR(36)     NULL,
    url           VARCHAR(500) NULL,
    label         VARCHAR(255) NULL,
    added_by      CHAR(36)     NOT NULL,
    added_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (resource_id),
    KEY idx_meeting_resources_meeting (meeting_id),
    KEY idx_meeting_resources_document (document_id),
    KEY idx_meeting_resources_added_by (added_by),
    CONSTRAINT meeting_resources_ibfk_1 FOREIGN KEY (meeting_id)  REFERENCES meetings (meeting_id)   ON DELETE CASCADE,
    CONSTRAINT meeting_resources_ibfk_2 FOREIGN KEY (document_id) REFERENCES documents (document_id) ON DELETE CASCADE,
    CONSTRAINT meeting_resources_ibfk_3 FOREIGN KEY (added_by)    REFERENCES users (user_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
