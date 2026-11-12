ALTER TABLE users
    ADD COLUMN email_normalized VARCHAR(255) NULL AFTER email,
    ADD COLUMN password_hash VARCHAR(255) NULL AFTER email_normalized,
    ADD COLUMN phone VARCHAR(32) NULL AFTER password_hash,
    ADD COLUMN country_code VARCHAR(8) NULL AFTER phone,
    ADD COLUMN status VARCHAR(32) NOT NULL DEFAULT 'pending_verification' AFTER country_code,
    ADD COLUMN email_verified_at DATETIME(6) NULL AFTER status;

ALTER TABLE users
    ADD UNIQUE KEY uq_users_email_normalized (email_normalized);

CREATE TABLE logical_agents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    display_name VARCHAR(160) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_logical_agents_public_id (public_id),
    UNIQUE KEY uq_logical_agents_user (user_id),
    CONSTRAINT fk_logical_agents_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE runtime_bindings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    logical_agent_id BIGINT UNSIGNED NOT NULL,
    runtime VARCHAR(32) NOT NULL,
    external_agent_id VARCHAR(160) NULL,
    workspace_ref VARCHAR(255) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    last_error VARCHAR(500) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_runtime_bindings_agent_runtime (logical_agent_id, runtime),
    UNIQUE KEY uq_runtime_bindings_external (runtime, external_agent_id),
    CONSTRAINT fk_runtime_bindings_agent FOREIGN KEY (logical_agent_id) REFERENCES logical_agents (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE auth_sessions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    token_hash CHAR(64) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    last_seen_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_auth_sessions_public_id (public_id),
    UNIQUE KEY uq_auth_sessions_token_hash (token_hash),
    KEY idx_auth_sessions_user (user_id),
    CONSTRAINT fk_auth_sessions_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE verification_codes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(32) NOT NULL,
    code_hash CHAR(64) NOT NULL,
    expires_at DATETIME(6) NOT NULL,
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    used_at DATETIME(6) NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_verification_codes_lookup (user_id, purpose, used_at, expires_at),
    CONSTRAINT fk_verification_codes_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE channel_identities (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    channel VARCHAR(32) NOT NULL,
    external_identity VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'paired',
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_channel_identity (channel, external_identity),
    KEY idx_channel_identities_user (user_id),
    CONSTRAINT fk_channel_identities_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(80) NOT NULL,
    target_type VARCHAR(80) NOT NULL,
    target_public_id VARCHAR(160) NULL,
    metadata_json JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_audit_events_user_created (user_id, created_at),
    KEY idx_audit_events_target (target_type, target_public_id),
    CONSTRAINT fk_audit_events_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
