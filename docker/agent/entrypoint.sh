#!/bin/sh
set -eu

: "${OPENCLAW_GATEWAY_TOKEN:?OPENCLAW_GATEWAY_TOKEN is required}"
: "${OPENCLAW_GATEWAY_PORT:=18789}"

case "$OPENCLAW_GATEWAY_PORT" in
  *[!0-9]*|'')
    echo "OPENCLAW_GATEWAY_PORT must be numeric" >&2
    exit 2
    ;;
esac

# The gateway is reachable only through the private network assigned by the
# orchestrator. The container has no Docker socket, host mounts, or host mode.
exec openclaw gateway run \
  --allow-unconfigured \
  --bind lan \
  --auth token \
  --token "$OPENCLAW_GATEWAY_TOKEN" \
  --port "$OPENCLAW_GATEWAY_PORT" \
  "$@"
