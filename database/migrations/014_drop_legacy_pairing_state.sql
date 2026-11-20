-- The native OpenClaw request and code are now the sole pairing authority.
-- These tables belonged to the retired WebChat-side visibility/event flow.
DROP TABLE IF EXISTS pairing_state;
DROP TABLE IF EXISTS pairing_events;
