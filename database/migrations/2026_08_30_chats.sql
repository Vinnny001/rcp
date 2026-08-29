-- Direct text chat between a student and their supervisor(s).
--
-- A conversation is identified by the (student_user_id, lecturer_user_id)
-- pair, not by a generic sender/recipient — this is what keeps a
-- dual-role user's two hats structurally unable to see each other's
-- messages: every query filters by the column matching the session's
-- current role, and a user's OTHER role never matches that column for
-- a conversation that belongs to their first role.

CREATE TABLE IF NOT EXISTS chats (
    chat_id          CHAR(36) NOT NULL DEFAULT (UUID()),
    student_user_id  CHAR(36) NOT NULL,
    lecturer_user_id CHAR(36) NOT NULL,
    sender_user_id   CHAR(36) NOT NULL,
    message          TEXT     NOT NULL,
    reply_to_chat_id CHAR(36) NULL,
    read_at          DATETIME NULL,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (chat_id),
    KEY idx_chats_thread (student_user_id, lecturer_user_id, created_at),
    KEY idx_chats_sender (sender_user_id),
    KEY idx_chats_reply_to (reply_to_chat_id),
    CONSTRAINT chats_ibfk_1 FOREIGN KEY (student_user_id)  REFERENCES users (user_id),
    CONSTRAINT chats_ibfk_2 FOREIGN KEY (lecturer_user_id) REFERENCES users (user_id),
    CONSTRAINT chats_ibfk_3 FOREIGN KEY (sender_user_id)   REFERENCES users (user_id),
    CONSTRAINT chats_ibfk_4 FOREIGN KEY (reply_to_chat_id) REFERENCES chats (chat_id)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4;
