-- The relay uses OpenClaw for conversation history and relay_requests for
-- technical correlation. These tables are no longer part of the schema.
-- This migration requires a DBA-capable database user because the runtime
-- application user intentionally does not have DROP privilege.
DROP TABLE IF EXISTS gateway_runs;
DROP TABLE IF EXISTS messages;
