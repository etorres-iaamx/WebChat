#!/usr/bin/env bash
set -Eeuo pipefail

# Reproducible host installer. It prepares WebChat; it does not install or
# configure the OpenClaw Gateway itself.
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ENV_FILE="$ROOT/.env"
SKIP_SERVICES=0
SKIP_OPENCLAW=0
CHECK_ONLY=0

usage() {
  cat <<'EOF'
Usage: bin/install.sh [options]

Options:
  --env-file PATH       Environment file (default: .env)
  --skip-services       Prepare files but do not install/start systemd units
  --skip-openclaw       Skip plugin installation/configuration
  --check-only          Validate dependencies and configuration only
  -h, --help            Show this help
EOF
}

die() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
need() { command -v "$1" >/dev/null 2>&1 || die "Falta dependencia: $1"; }
valid_name() { [[ "$1" =~ ^[A-Za-z0-9_]+$ ]]; }
valid_path() { [[ "$1" = /* ]]; }
escape_sql_string() {
  local value="$1"
  value=${value//\'/\'\'}
  printf '%s' "$value"
}

while (($#)); do
  case "$1" in
    --env-file) ENV_FILE="$2"; shift 2 ;;
    --skip-services) SKIP_SERVICES=1; shift ;;
    --skip-openclaw) SKIP_OPENCLAW=1; shift ;;
    --check-only) CHECK_ONLY=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) die "Opción desconocida: $1" ;;
  esac
done

[[ -r "$ENV_FILE" ]] || die "No existe o no se puede leer $ENV_FILE"
set -a
# shellcheck disable=SC1090
. "$ENV_FILE"
set +a

for command in php python3 mariadb openclaw; do need "$command"; done
php -m | grep -qi '^pdo_mysql$' || die 'PHP requiere la extensión pdo_mysql'
php -r 'version_compare(PHP_VERSION, "8.3.0", ">=") or exit(1);' || die 'PHP 8.3 o superior es requerido'
python3 -c 'import sys; assert sys.version_info >= (3, 12)' || die 'Python 3.12 o superior es requerido'

: "${APP_SECRET:?Define APP_SECRET en .env}"
: "${DB_DATABASE:?Define DB_DATABASE en .env}"
: "${DB_USERNAME:?Define DB_USERNAME en .env}"
: "${OPENCLAW_GATEWAY_URL:?Define OPENCLAW_GATEWAY_URL en .env}"
: "${OPENCLAW_GATEWAY_TOKEN:?Define OPENCLAW_GATEWAY_TOKEN en .env}"
: "${WEBCHAT_PUBLIC_BASE_URL:?Define WEBCHAT_PUBLIC_BASE_URL en .env}"
: "${WEBCHAT_PUBLIC_WS_URL:?Define WEBCHAT_PUBLIC_WS_URL en .env}"
: "${WEBCHAT_ALLOWED_ORIGINS:?Define WEBCHAT_ALLOWED_ORIGINS en .env}"

[[ "${OPENCLAW_PROVISIONING_ENABLED:-0}" == 1 ]] || die 'OPENCLAW_PROVISIONING_ENABLED debe ser 1 para una instalación funcional'
[[ "${OPENCLAW_BINDING_ENABLED:-0}" == 1 ]] || die 'OPENCLAW_BINDING_ENABLED debe ser 1 para vincular canales'
valid_name "$DB_DATABASE" || die 'DB_DATABASE contiene caracteres inválidos'
valid_name "$DB_USERNAME" || die 'DB_USERNAME contiene caracteres inválidos'
valid_path "${OPENCLAW_AGENT_WORKSPACE_ROOT:-}" || die 'OPENCLAW_AGENT_WORKSPACE_ROOT debe ser una ruta absoluta'

if ((CHECK_ONLY)); then
  printf 'CHECK OK: dependencias y configuración básica válidas.\n'
  exit 0
fi

state_dir="${WEBCHAT_INSTALL_STATE_DIR:-$ROOT/.runtime}"
systemd_dir="${XDG_CONFIG_HOME:-$HOME/.config}/systemd/user"
mkdir -p "$state_dir" "$systemd_dir" "$OPENCLAW_AGENT_WORKSPACE_ROOT"
chmod 700 "$state_dir"

admin_user="${DB_ADMIN_USER:-root}"
admin_socket="${DB_ADMIN_SOCKET:-/tmp/mysql.sock}"
admin_defaults="$(mktemp)"
drop_grant_active=0
cleanup() {
  if ((drop_grant_active)); then
    mariadb --defaults-extra-file="$admin_defaults" --protocol=socket -e "REVOKE DROP ON \`$DB_DATABASE\`.* FROM '$DB_USERNAME'@'${DB_GRANT_HOST:-127.0.0.1}', '$DB_USERNAME'@'localhost';" >/dev/null 2>&1 || true
  fi
  rm -f "$admin_defaults"
}
trap cleanup EXIT
chmod 600 "$admin_defaults"
if [[ "${DB_ADMIN_SOCKET_AUTH:-0}" == 1 ]]; then
  printf '[client]\nuser=%s\nhost=localhost\nsocket=%s\n' "$admin_user" "$admin_socket" >"$admin_defaults"
elif [[ -n "${DB_ADMIN_PASSWORD:-}" ]]; then
  printf '[client]\nuser=%s\npassword=%s\nhost=localhost\nsocket=%s\n' "$admin_user" "$DB_ADMIN_PASSWORD" "$admin_socket" >"$admin_defaults"
else
  read -r -s -p "Contraseña DBA de MariaDB ($admin_user): " password
  printf '\n'
  printf '[client]\nuser=%s\npassword=%s\nhost=localhost\nsocket=%s\n' "$admin_user" "$password" "$admin_socket" >"$admin_defaults"
  unset password
fi

grant_host="${DB_GRANT_HOST:-127.0.0.1}"
db_password_sql="$(escape_sql_string "${DB_PASSWORD:-}")"
mariadb --defaults-extra-file="$admin_defaults" --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS \`$DB_DATABASE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USERNAME'@'$grant_host' IDENTIFIED BY '$db_password_sql';
CREATE USER IF NOT EXISTS '$DB_USERNAME'@'localhost' IDENTIFIED BY '$db_password_sql';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'$grant_host';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'localhost';
GRANT DROP ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'$grant_host';
GRANT DROP ON \`$DB_DATABASE\`.* TO '$DB_USERNAME'@'localhost';
FLUSH PRIVILEGES;
SQL
drop_grant_active=1

php "$ROOT/bin/migrate.php"

mariadb --defaults-extra-file="$admin_defaults" --protocol=socket <<SQL
REVOKE DROP ON \`$DB_DATABASE\`.* FROM '$DB_USERNAME'@'$grant_host', '$DB_USERNAME'@'localhost';
FLUSH PRIVILEGES;
SQL
drop_grant_active=0

if (( !SKIP_OPENCLAW )); then
  openclaw plugins install "$ROOT/openclaw-plugin/webchat-identity" >/dev/null
  openclaw plugins validate "$ROOT/openclaw-plugin/webchat-identity" >/dev/null
  python3 - "$WEBCHAT_PUBLIC_BASE_URL" "${WEBCHAT_SUPPORT_EMAIL:-xochitl.bot.iaamx@gmail.com}" "${WEBCHAT_CHANNEL_RESOLVE_SECRET:-}" <<'PY' | openclaw config patch --stdin --replace-path plugins.entries.webchat-identity.config
import json, sys
base, support, secret = sys.argv[1:]
config = {
    "resolveUrl": "http://127.0.0.1:8082/channel.php",
    "publicBaseUrl": base.rstrip("/") + "/",
    "supportEmail": support,
}
if secret:
    config["resolveSecret"] = secret
print(json.dumps(config))
PY
fi

cat >"$state_dir/Caddyfile" <<EOF
{
  auto_https off
}

:${WEBCHAT_CADDY_PORT:-8080} {
  @webchat_ws path /webchat/ws
  reverse_proxy @webchat_ws 127.0.0.1:${WEBCHAT_WS_PORT:-8081}
  @webchat_dashboard path /webchat/dashboard
  handle @webchat_dashboard {
    rewrite * /dashboard.php
    reverse_proxy 127.0.0.1:${WEBCHAT_HTTP_PORT:-8082}
  }
  @webchat_channel path /webchat/channel.php
  handle @webchat_channel { respond 404 }
  @webchat_root path /webchat /webchat/
  handle @webchat_root {
    rewrite * /auth.php
    reverse_proxy 127.0.0.1:${WEBCHAT_HTTP_PORT:-8082}
  }
  reverse_proxy 127.0.0.1:${WEBCHAT_HTTP_PORT:-8082}
}
EOF

if (( !SKIP_SERVICES )); then
  need caddy
  need ngrok
  cat >"$systemd_dir/webchat-http.service" <<EOF
[Unit]
Description=WebChat HTTP application
After=network.target
[Service]
WorkingDirectory=$ROOT
EnvironmentFile=$ENV_FILE
ExecStart=$(command -v php) -S 127.0.0.1:${WEBCHAT_HTTP_PORT:-8082} -t public
Restart=on-failure
[Install]
WantedBy=default.target
EOF
  cat >"$systemd_dir/webchat-relay.service" <<EOF
[Unit]
Description=WebChat WebSocket relay
After=network.target
[Service]
WorkingDirectory=$ROOT
EnvironmentFile=$ENV_FILE
ExecStart=$(command -v php) bin/webchat-ws.php
Restart=on-failure
[Install]
WantedBy=default.target
EOF
  cat >"$systemd_dir/webchat-caddy.service" <<EOF
[Unit]
Description=WebChat reverse proxy
After=webchat-http.service webchat-relay.service
Requires=webchat-http.service webchat-relay.service
[Service]
ExecStart=$(command -v caddy) run --config $state_dir/Caddyfile --adapter caddyfile
Restart=on-failure
[Install]
WantedBy=default.target
EOF
  cat >"$systemd_dir/webchat-provision.service" <<EOF
[Unit]
Description=WebChat provisioning worker
[Service]
WorkingDirectory=$ROOT
EnvironmentFile=$ENV_FILE
ExecStart=$(command -v php) bin/provision-pending.php 10
Type=oneshot
EOF
  cat >"$systemd_dir/webchat-provision.timer" <<'EOF'
[Unit]
Description=Run WebChat provisioning worker
[Timer]
OnBootSec=30s
OnUnitActiveSec=60s
Unit=webchat-provision.service
[Install]
WantedBy=timers.target
EOF
  if [[ -n "${WEBCHAT_NGROK_DOMAIN:-}" ]]; then
    cat >"$systemd_dir/webchat-ngrok.service" <<EOF
[Unit]
Description=WebChat ngrok tunnel
After=webchat-caddy.service
Requires=webchat-caddy.service
[Service]
ExecStart=$(command -v ngrok) http ${WEBCHAT_CADDY_PORT:-8080} --url $WEBCHAT_NGROK_DOMAIN --log=stdout
Restart=on-failure
[Install]
WantedBy=default.target
EOF
  fi
  systemctl --user daemon-reload
  systemctl --user enable --now webchat-http.service webchat-relay.service webchat-caddy.service webchat-provision.timer
  [[ -z "${WEBCHAT_NGROK_DOMAIN:-}" ]] || systemctl --user enable --now webchat-ngrok.service
fi

printf 'WebChat instalado. Ejecuta: curl -fsS %s/webchat/\n' "${WEBCHAT_PUBLIC_BASE_URL%/}"
