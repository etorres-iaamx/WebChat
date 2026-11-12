CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(36) NOT NULL,
    display_name VARCHAR(120) NOT NULL,
    email VARCHAR(255) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_public_id (public_id),
    KEY idx_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(36) NOT NULL,
    gateway_session_key VARCHAR(255) NOT NULL,
    title VARCHAR(160) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_conversations_public_id (public_id),
    UNIQUE KEY uq_conversations_gateway_session_key (gateway_session_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS conversation_participants (
    conversation_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    role VARCHAR(32) NOT NULL DEFAULT 'member',
    joined_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (conversation_id, user_id),
    KEY idx_conversation_participants_user (user_id),
    CONSTRAINT fk_conversation_participants_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_conversation_participants_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS web_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(36) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_web_sessions_public_id (public_id),
    UNIQUE KEY uq_web_sessions_token_hash (token_hash),
    KEY idx_web_sessions_expires_at (expires_at),
    KEY idx_web_sessions_user (user_id),
    KEY idx_web_sessions_conversation (conversation_id),
    CONSTRAINT fk_web_sessions_user
        FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_web_sessions_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(36) NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    sender_type VARCHAR(16) NOT NULL,
    sender_user_id BIGINT UNSIGNED NULL,
    gateway_run_id BIGINT UNSIGNED NULL,
    client_request_id VARCHAR(80) NULL,
    content LONGTEXT NOT NULL,
    status VARCHAR(24) NOT NULL DEFAULT 'accepted',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    completed_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_messages_public_id (public_id),
    UNIQUE KEY uq_messages_gateway_run (gateway_run_id),
    UNIQUE KEY uq_messages_request (conversation_id, client_request_id),
    KEY idx_messages_conversation_created (conversation_id, created_at, id),
    KEY idx_messages_sender_user (sender_user_id),
    KEY idx_messages_status (status),
    CONSTRAINT fk_messages_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_messages_sender_user
        FOREIGN KEY (sender_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gateway_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(36) NOT NULL,
    conversation_id BIGINT UNSIGNED NOT NULL,
    user_message_id BIGINT UNSIGNED NOT NULL,
    assistant_message_id BIGINT UNSIGNED NULL,
    openclaw_run_id VARCHAR(255) NOT NULL,
    state VARCHAR(32) NOT NULL DEFAULT 'running',
    error_code VARCHAR(80) NULL,
    error_message VARCHAR(500) NULL,
    started_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    finished_at DATETIME(6) NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_gateway_runs_public_id (public_id),
    UNIQUE KEY uq_gateway_runs_openclaw_run_id (openclaw_run_id),
    KEY idx_gateway_runs_conversation_state (conversation_id, state),
    KEY idx_gateway_runs_user_message (user_message_id),
    CONSTRAINT fk_gateway_runs_conversation
        FOREIGN KEY (conversation_id) REFERENCES conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_gateway_runs_user_message
        FOREIGN KEY (user_message_id) REFERENCES messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE gateway_runs
    ADD CONSTRAINT fk_gateway_runs_assistant_message
        FOREIGN KEY (assistant_message_id) REFERENCES messages (id) ON DELETE SET NULL;

ALTER TABLE messages
    ADD CONSTRAINT fk_messages_gateway_run
        FOREIGN KEY (gateway_run_id) REFERENCES gateway_runs (id) ON DELETE SET NULL;
