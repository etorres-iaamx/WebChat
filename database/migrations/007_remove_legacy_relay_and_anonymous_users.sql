-- The application database user intentionally has no DROP privilege. Empty the
-- obsolete tables now; a privileged DBA can remove them physically later.
DELETE FROM gateway_runs;
DELETE FROM messages;

ALTER TABLE users
    MODIFY email VARCHAR(255) NOT NULL,
    MODIFY email_normalized VARCHAR(255) NOT NULL,
    MODIFY password_hash VARCHAR(255) NOT NULL;
