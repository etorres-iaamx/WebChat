-- Pairing attempts belonged to the pre-native pairing flow and have no
-- runtime consumers. The current flow uses channel_identities for ownership,
-- OpenClaw now owns the pending request and code; the WebChat-side pairing
-- tables are removed by migration 014.
DROP TABLE IF EXISTS pairing_attempts;
