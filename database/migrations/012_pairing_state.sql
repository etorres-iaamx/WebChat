CREATE TABLE pairing_state (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    channel VARCHAR(32) NOT NULL,
    account_id VARCHAR(128) NOT NULL DEFAULT 'default',
    external_identity VARCHAR(255) NOT NULL,
    request_id VARCHAR(255) NOT NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'pending',
    expires_at DATETIME(6) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    confirmed_at DATETIME(6) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_pairing_state_identity (channel, account_id, external_identity),
    KEY idx_pairing_state_pending (channel, account_id, external_identity, status, expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO pairing_state (channel, account_id, external_identity, request_id, status, expires_at, created_at, updated_at, confirmed_at)
SELECT e.channel, e.account_id, e.external_identity, e.request_id, e.status, e.expires_at, e.created_at, e.updated_at, e.confirmed_at
FROM pairing_events e
INNER JOIN (
    SELECT channel, account_id, external_identity, MAX(id) AS id
    FROM pairing_events
    GROUP BY channel, account_id, external_identity
) latest ON latest.id = e.id;
