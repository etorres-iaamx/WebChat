ALTER TABLE pairing_events
    ADD COLUMN active_key VARCHAR(16) NULL AFTER status;

UPDATE pairing_events
SET active_key = 'pending'
WHERE status = 'pending'
  AND expires_at > UTC_TIMESTAMP(6);

ALTER TABLE pairing_events
    ADD UNIQUE KEY uq_pairing_events_active_identity
        (channel, account_id, external_identity, active_key);
