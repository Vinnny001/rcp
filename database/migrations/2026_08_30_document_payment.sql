-- thesis_payments has no document_type_id, so once an exam window
-- requires more than one document type, a payment against that
-- exam_schedule_id can't say which document it actually covers. This
-- table replaces thesis_payments' 'document_review_fee' fee_type
-- entirely, scoped precisely to (exam_schedule_id, document_type_id)
-- so "has this specific document been paid for" is answerable exactly.
--
-- Safe to run unconditionally: thesis_payments had zero
-- 'document_review_fee' rows (zero rows at all) at the time of this
-- change.

CREATE TABLE IF NOT EXISTS document_payment (
    document_payment_id    CHAR(36)  NOT NULL DEFAULT (UUID()),
    thesis_registration_id CHAR(36)  NOT NULL,
    exam_schedule_id       CHAR(36)  NOT NULL,
    document_type_id       CHAR(36)  NOT NULL,
    amount                 DECIMAL(10,2) NOT NULL,
    currency               CHAR(3)   NOT NULL DEFAULT 'KES',
    payment_method         ENUM('erp_integration','manual_upload','mpesa','bank') NOT NULL,
    reference_number       VARCHAR(100) NULL,
    status                 ENUM('pending','confirmed','rejected') NOT NULL DEFAULT 'pending',
    verified_by            CHAR(36)  NULL,
    payment_date           DATE      NULL,
    created_at             DATETIME  NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (document_payment_id),
    KEY idx_document_payment_registration (thesis_registration_id),
    KEY idx_document_payment_exam_schedule (exam_schedule_id),
    KEY idx_document_payment_document_type (document_type_id),
    KEY idx_document_payment_verified_by (verified_by),
    CONSTRAINT document_payment_ibfk_1 FOREIGN KEY (thesis_registration_id) REFERENCES student_thesis_registrations (thesis_registration_id),
    CONSTRAINT document_payment_ibfk_2 FOREIGN KEY (exam_schedule_id)       REFERENCES exam_schedule (exam_schedule_id),
    CONSTRAINT document_payment_ibfk_3 FOREIGN KEY (document_type_id)      REFERENCES document_types (doc_type_id),
    CONSTRAINT document_payment_ibfk_4 FOREIGN KEY (verified_by)           REFERENCES users (user_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;

-- 'document_review_fee' now lives entirely in document_payment above.
ALTER TABLE thesis_payments
    MODIFY fee_type ENUM('thesis_registration', 'thesis_review_fee') NOT NULL;

-- exam_type/examination_fee scoping on `payments` was dead code —
-- Payment::sumConfirmedByType() had no callers anywhere in the app.
ALTER TABLE payments
    DROP COLUMN exam_type;
