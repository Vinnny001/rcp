-- A supporting file an examiner uploads alongside their score on an
-- exam document, giving admin a detailed reason for the decision
-- beyond the plain-text `examination_scores.remarks` field. The file
-- itself lives in `documents` like any other upload; this table just
-- links it to the specific exam_document and the examiner who
-- attached it, so admin can browse them by exam/document type.

CREATE TABLE IF NOT EXISTS exam_review_attachments (
    attachment_id     CHAR(36) NOT NULL DEFAULT (UUID()),
    exam_document_id  CHAR(36) NOT NULL,
    examiner_id       CHAR(36) NOT NULL,
    document_id       CHAR(36) NOT NULL,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (attachment_id),
    KEY idx_exam_review_attachments_exam_document (exam_document_id),
    KEY idx_exam_review_attachments_examiner (examiner_id),
    KEY idx_exam_review_attachments_document (document_id),
    CONSTRAINT exam_review_attachments_ibfk_1 FOREIGN KEY (exam_document_id) REFERENCES exam_documents (exam_document_id) ON DELETE CASCADE,
    CONSTRAINT exam_review_attachments_ibfk_2 FOREIGN KEY (examiner_id)      REFERENCES users (user_id),
    CONSTRAINT exam_review_attachments_ibfk_3 FOREIGN KEY (document_id)     REFERENCES documents (document_id) ON DELETE CASCADE
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
