# WebChat IAAMX

Extensión independiente del WebChat para registrar usuarios, verificar su correo,
crear un agente OpenClaw por usuario y resolver el mismo agente desde WebChat,
Telegram y WhatsApp.

## Estado actual

Implementado en esta primera entrega:

- Registro en español con los campos de IAADL-101.
- Inicio de sesión por correo y contraseña.
- Verificación de correo con código temporal.
- Adaptador de envío preparado para Himalaya; se habilita con `HIMALAYA_ENABLED=1`.
- Esquema MariaDB normalizado para usuarios, agentes, bindings, sesiones, códigos e identidades.
- Creación idempotente del agente lógico al verificar el correo.
- WebChat protegido: una persona no autenticada no puede abrir el chat.
- Provisioning real de OpenClaw preparado mediante `openclaw agents add`; se habilita con `OPENCLAW_PROVISIONING_ENABLED=1`.

Pendiente de la siguiente iteración: eliminar las tablas legacy del relay y hacer que
el historial sea exclusivamente el de OpenClaw; implementar webhooks/adaptadores de
Telegram y WhatsApp con sus identidades externas y pairing real.

## Siguiente bloque implementado

- `public/channel.php` resuelve una identidad de Telegram/WhatsApp sin exponer el agente.
- Una identidad desconocida recibe la URL del WebChat y no se enruta a OpenClaw.
- `pairing_attempts` guarda desafíos temporales y `channel_identities` guarda el vínculo confirmado.
- El WebChat autenticado ya no puede caer silenciosamente en `main`: requiere un binding
  OpenClaw provisionado.
- `OPENCLAW_PROVISIONING_ENABLED=1` activa el provisioning mediante el adaptador local.

El provisioning de runtimes está integrado en WebChat mediante adaptadores locales que
delegan en las CLIs oficiales. WebChat no depende de otro repositorio para administrar
OpenClaw o Hermes. Solo persiste en `runtime_bindings` el resultado de cada operación.
Para OpenClaw, el binding de Telegram/WhatsApp conserva la identidad externa como un
peer directo.

Para activar provisioning en un entorno de pruebas, define también un workspace permitido:

```dotenv
OPENCLAW_PROVISIONING_ENABLED=1
OPENCLAW_AGENT_WORKSPACE_ROOT=/var/lib/openclaw/workspaces
```

Para provisionar también un Profile de Hermes, habilita `HERMES_PROVISIONING_ENABLED=1`
y configura `HERMES_HOME`. El perfil recibe contexto aislado (`SOUL.md`,
`memories/USER.md` y `AGENTS.md`) mediante el adaptador local.

Webchat PHP 8.3 puro para IAAMXORG, basado en los mockups IAADL-206 (dashboard con botón flotante) e IAADL-205 (panel de AI Assistant).

## Arquitectura

```text
navegador
   │  wss://DOMINIO.ngrok.io/webchat/ws
   ▼
ngrok → Caddy :8080
          ├── /webchat/ws → relay PHP :8081 → OpenClaw Gateway ws://127.0.0.1:18789
          └── /webchat/*  → PHP HTTP :8082
                                      │
                                      └── MariaDB
```

El navegador recibe únicamente un token firmado y de duración limitada para la sesión. La credencial del Gateway solo se lee en el proceso PHP del relay. Cada visitante tiene una conversación y una clave de sesión OpenClaw dedicada (`agent:main:webchat-iaamx:<id>`).

## Requisitos

- PHP 8.3+ con `pdo_mysql` y soporte de streams.
- MariaDB 10.6+.
- OpenClaw Gateway local accesible por WebSocket.
- Caddy y ngrok para publicar HTTP y WebSocket en el mismo dominio.

## Instalación local

```bash
cp .env.example .env
chmod 600 .env
# Edita .env y define APP_SECRET, credenciales de MariaDB y OPENCLAW_GATEWAY_TOKEN.
```

En terminales separadas:

```bash
# Terminal 1: aplicar esquema
php bin/migrate.php
```

```bash
# Terminal 2: HTTP
php -S 127.0.0.1:8082 -t public
```

```bash
# Terminal 3: relay WebSocket
php bin/webchat-ws.php
```

La pantalla local queda en `http://127.0.0.1:8082/webchat/index.php`. Si se accede directamente sin Caddy, usa `WEBCHAT_PUBLIC_WS_URL=ws://127.0.0.1:8081/webchat/ws`.

## MariaDB

Crear una base y un usuario con permisos solo sobre esa base:

```sql
CREATE DATABASE webchat_iaamx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'webchat'@'127.0.0.1' IDENTIFIED BY 'cambia-esta-clave';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
  ON webchat_iaamx.* TO 'webchat'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Las migraciones crean las entidades de identidad, sesión y correlación del relay. Desde
`004_openclaw_history_only.sql`, el contenido de conversación no se guarda en MariaDB:
el historial se obtiene exclusivamente con `chat.history` de OpenClaw; `relay_requests`
solo conserva request IDs, run IDs y estados técnicos para idempotencia.

## OpenClaw Gateway

El relay usa el protocolo WebSocket v4 del Gateway:

1. conecta como cliente backend con el token del Gateway;
2. solicita `chat.history` para recuperar contexto;
3. envía `chat.send` con `deliver=false`;
4. relays los eventos `chat` (`status`, `delta`, `final`, `error`) al navegador.

Configura `OPENCLAW_GATEWAY_URL`, `OPENCLAW_GATEWAY_TOKEN` y `OPENCLAW_AGENT_ID` en `.env`. No coloques el token en `app.js`, HTML, el navegador ni el repositorio.

## Publicación en ngrok

1. Reemplaza `your-subdomain.ngrok.io` en `.env` por el dominio reservado de ngrok:

   ```dotenv
   WEBCHAT_PUBLIC_WS_URL=wss://your-subdomain.ngrok.io/webchat/ws
   WEBCHAT_ALLOWED_ORIGINS=https://your-subdomain.ngrok.io
   ```

2. Inicia los procesos locales:

   ```bash
   php -S 127.0.0.1:8082 -t public
   php bin/webchat-ws.php
   caddy run --config Caddyfile.example
   ```

3. Publica Caddy, no los puertos PHP individuales:

   ```bash
   ngrok http --domain=your-subdomain.ngrok.io 8080
   ```

4. Abre:

   `https://your-subdomain.ngrok.io/webchat/index.php`

Caddy conserva la actualización WebSocket en `/webchat/ws`; las rutas HTTP se sirven desde el proceso PHP integrado. Si tu cuenta de ngrok no permite `--domain`, usa el hostname asignado y actualiza las dos variables de origen/URL.

## Seguridad y límites del MVP

- Cookie de visitante `HttpOnly`, `SameSite=Lax` y `Secure` bajo HTTPS.
- Tokens relay firmados con HMAC y expiración.
- Lista de orígenes WebSocket configurable.
- Límite de mensaje configurable y `request_id` idempotente por conversación.
- El Gateway nunca se conecta desde el navegador.

Para un despliegue público real todavía conviene añadir autenticación de usuarios, rate limiting por IP/identidad, observabilidad y rotación gestionada de secretos.

## Verificación

```bash
php tests/run.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
git diff --check
```
