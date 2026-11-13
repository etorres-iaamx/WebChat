ALTER TABLE auth_sessions
    ADD COLUMN persistent TINYINT(1) NOT NULL DEFAULT 0 AFTER token_hash;
