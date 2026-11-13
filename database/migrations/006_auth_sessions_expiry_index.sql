ALTER TABLE auth_sessions
    ADD KEY idx_auth_sessions_expires_at (expires_at);
