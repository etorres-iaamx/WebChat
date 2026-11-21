FROM node:22-bookworm-slim

ARG OPENCLAW_VERSION=2026.9.4

ENV HOME=/var/lib/openclaw \
    OPENCLAW_HOME=/var/lib/openclaw \
    OPENCLAW_GATEWAY_PORT=18789

RUN groupadd --system --gid 10001 openclaw \
    && useradd --system --uid 10001 --gid 10001 --create-home --home-dir /var/lib/openclaw openclaw \
    && npm install --global --no-audit --no-fund "openclaw@${OPENCLAW_VERSION}" \
    && mkdir -p /var/lib/openclaw/workspace /var/lib/openclaw/state \
    && chown -R openclaw:openclaw /var/lib/openclaw

COPY entrypoint.sh /usr/local/bin/webchat-agent-entrypoint
RUN chmod 0755 /usr/local/bin/webchat-agent-entrypoint

USER 10001:10001
WORKDIR /var/lib/openclaw/workspace
VOLUME ["/var/lib/openclaw"]
EXPOSE 18789

ENTRYPOINT ["/usr/local/bin/webchat-agent-entrypoint"]
