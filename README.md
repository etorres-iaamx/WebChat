# WebChat IAAMX

Extensión independiente del WebChat para registrar usuarios, verificar su correo,
crear un agente OpenClaw por usuario y resolver el mismo agente desde WebChat,
Telegram y WhatsApp.

## Estado actual

Implementado en esta primera entrega:

- Registro en español con los campos de IAADL-101.
- Inicio de sesión por correo y contraseña.
- Sesiones temporales por defecto (12 horas de expiración absoluta y 12 horas de inactividad); la opción "Recordarme en este dispositivo" crea una cookie persistente de 30 días, sin exigir actividad diaria.
- Verificación de correo con código temporal.
- Adaptador de envío preparado para Himalaya; se habilita con `HIMALAYA_ENABLED=1`.
- Esquema MariaDB normalizado para usuarios, agentes, bindings, sesiones, códigos e identidades.
- Creación idempotente del agente lógico al verificar el correo.
- WebChat protegido: una persona no autenticada no puede abrir el chat.
- Provisioning real de OpenClaw administrado por el core de la aplicación; se habilita con `OPENCLAW_PROVISIONING_ENABLED=1`.

El contenido de conversación es exclusivamente el de OpenClaw; `relay_requests` conserva
solo correlación técnica. Telegram y WhatsApp usan identidades externas y el pairing nativo
de OpenClaw. La solicitud pendiente y su expiración viven únicamente en OpenClaw; WebChat
no mantiene un segundo estado de pairing ni decide la visibilidad con polling o eventos.

## Siguiente bloque implementado

- `public/channel.php` resuelve una identidad de Telegram/WhatsApp sin exponer el agente.
- Toda identidad no vinculada recibe el mismo onboarding: enlace de WebChat, código nativo
  y contacto de soporte en la respuesta de Telegram/WhatsApp; no se enruta a `main`.
- `channel_identities` conserva la identidad vinculada (`binding_pending`/`paired`); la identidad
  se obtiene del pairing validado por OpenClaw, no de un parámetro enviado en la URL.
- El formulario de pairing está disponible para el usuario autenticado; el código de OpenClaw
  es la única validación que decide si la vinculación puede completarse.
- OpenClaw genera y aprueba los códigos; WebChat los muestra y ejecuta la aprobación nativa.
- El WebChat autenticado ya no puede caer silenciosamente en `main`: requiere un binding
  OpenClaw provisionado.
- `OPENCLAW_PROVISIONING_ENABLED=1` activa el provisioning mediante el adaptador interno del core.
- Al crear el agente, el adapter genera su `USER.md` con los datos verificados del
  perfil WebChat (nombre, correo, teléfono, país e identificador público); nunca copia
  contraseñas ni códigos de verificación.

El WebChat solo orquesta identidad, ruteo, sesiones y la pantalla de aprobación del pairing.
El pairing nativo de OpenClaw se aprueba desde WebChat mediante la CLI del Gateway; el core
conserva la asociación del remitente con el agente. `runtime/agent_admin.py` es el adaptador
interno del core: crea el agente
con la CLI oficial, escribe únicamente su contexto y persiste el binding resultante.

### Aislamiento sin Docker

El core crea un agente OpenClaw distinto por usuario, con `agentDir`, workspace,
sesiones y memoria separados. La aplicación no usa `main` como fallback y conserva el
`agentId` devuelto por la CLI para construir las sesiones del usuario. Esta separación
de datos y contexto es la responsabilidad del core; `AGENTS.md` solo aporta reglas de
comportamiento y no se considera una frontera de seguridad.

Para activar provisioning en un entorno de pruebas, define un workspace permitido:

```dotenv
OPENCLAW_PROVISIONING_ENABLED=1
OPENCLAW_AGENT_WORKSPACE_ROOT=/var/lib/openclaw/workspaces
```

Para provisionar también un Profile de Hermes, habilita `HERMES_PROVISIONING_ENABLED=1`
y configura `HERMES_HOME`. El perfil recibe contexto aislado (`SOUL.md`,
`memories/USER.md` y `AGENTS.md`) mediante el adapter Python embebido.

Webchat PHP 8.3 puro para IAAMXORG, basado en los mockups IAADL-205/206:
dashboard con barra lateral, fondo de oficina y asistente contextual. El perfil del
usuario se muestra en la barra lateral para mantener despejada el área central. En esta etapa la
interfaz solo expone controles con funcionalidad implementada: Asistente de IA, Perfil y Ajustes,
abrir el asistente desde el dashboard, colapsar el menú, cambiar entre modo
flotante/integrado, maximizar, abrir pop-out, minimizar, cerrar y cerrar sesión. La
sección Perfil muestra los datos de la cuenta autenticada; la edición de datos queda
fuera de esta etapa. Ajustes permite guardar en el navegador el tema y el estado inicial
del sidebar. Las secciones futuras no
se muestran hasta contar con backend y navegación reales.

La ruta pública `/webchat/` es únicamente la pantalla de inicio de sesión y registro.
Después de autenticar, redirige directamente al dashboard protegido en
`/webchat/dashboard`. Las rutas técnicas `/webchat/index.php` y
`/webchat/webchat/index.php` no son públicas y responden `404`; el dashboard se sirve
internamente mediante el rewrite de Caddy.

Perfil incluye foto de perfil, cambio de contraseña y el formulario de pairing de
Telegram/WhatsApp. La foto y la contraseña tienen endpoints reales, y el formulario
valida el código nativo mediante el Gateway de OpenClaw. Telegram y WhatsApp solo se
exponen como enlaces o canales operativos cuando su configuración está disponible.

Cada opción de Ajustes incluye ayuda contextual accesible mediante un icono `?`; las
explicaciones indican el efecto de la preferencia antes de guardarla. El mismo texto
aparece como tooltip al pasar el cursor o enfocar el icono, y como ayuda expandida al
hacer clic.

El asistente conserva una única conversación mientras cambia de presentación: puede
flotar sobre el dashboard, integrarse en el área principal o abrirse en una ventana
independiente. El modo pop-out desactiva temporalmente la ventana origen y la reactiva
cuando la ventana secundaria se cierra. Las acciones rápidas actuales son generales;
las acciones específicas por módulo se agregarán cuando esos módulos existan.

La identidad visual usa el logo oficial `iaa-mx-pleno-claro.svg` sobre fondos claros,
`iaa-mx-pleno-oscuro.svg` en el sidebar oscuro y el fondo `background-iaamx.jpg`,
tomados del repositorio `julianflores/iaaorgmx`.
Los estados del sidebar y los controles de ayuda usan los tokens oficiales del mismo
repositorio (`#1e2436`, `#9e0511` y `#414a60`); la interfaz no mantiene una paleta
alternativa para esos elementos.

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

El navegador recibe únicamente un token firmado y de duración limitada para la sesión. La
credencial del Gateway solo se lee en el proceso PHP del relay. Cada usuario autenticado tiene
una conversación y una clave de sesión dedicada a su agente provisionado (`agent:<agent-id>:...`).
WebChat no usa `main` como fallback; si falta el agente propio, la operación falla de forma segura.

## Requisitos

- PHP 8.3+ con `pdo_mysql` y soporte de streams.
- Python 3.12+ para el adapter embebido de lifecycle.
- MariaDB 10.6+.
- OpenClaw Gateway local accesible por WebSocket.
- Caddy y ngrok para publicar HTTP y WebSocket en el mismo dominio.

Estas dependencias deben instalarse en el host antes de ejecutar el instalador del
repositorio. En Debian/Ubuntu, una preparación mínima es:

```bash
sudo apt update
sudo apt install -y php8.3-cli php8.3-mysql python3.12 mariadb-server caddy unzip curl
```

Instala también el binario de OpenClaw y el CLI de ngrok siguiendo sus instrucciones
oficiales. Después autentica ambos servicios en el host:

```bash
openclaw gateway status
ngrok config add-authtoken TU_TOKEN_DE_NGROK
```

El Gateway debe estar iniciado y accesible por WebSocket antes de ejecutar
`bin/install.sh`. Ngrok debe tener una cuenta autenticada si se utilizará un dominio
reservado. El instalador del repositorio **no instala** paquetes del sistema, OpenClaw,
MariaDB, Caddy ni ngrok; solo valida que existan, prepara WebChat y configura sus servicios.

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

## Instalación reproducible en otra máquina

Para preparar un host nuevo, copia `.env.example` a `.env`, completa las credenciales,
el dominio público y el workspace de agentes, y ejecuta:

```bash
chmod 600 .env
bin/install.sh --env-file .env
```

El instalador valida PHP 8.3 con `pdo_mysql`, Python 3.12, MariaDB y OpenClaw; crea la
base y el usuario de aplicación usando una cuenta DBA temporal, ejecuta las migraciones,
instala/configura el plugin, genera unidades systemd para HTTP, relay, Caddy, provisioning
y ngrok, y activa los servicios. `DB_ADMIN_SOCKET_AUTH=1` permite usar autenticación local
por socket sin guardar una contraseña DBA. La contraseña de la aplicación sí permanece en
`.env`, que no debe versionarse.

Para validar sin modificar la máquina:

```bash
bin/install.sh --env-file .env --check-only
```

El instalador no instala el Gateway de OpenClaw ni crea la cuenta de ngrok: ambos deben
estar instalados y autenticados previamente. `WEBCHAT_NGROK_DOMAIN` es opcional si se
publicará Caddy mediante otro proxy.

La pantalla se publica mediante Caddy en `http://127.0.0.1:8080/webchat/`; las entradas
internas `auth.php` y `dashboard.php` no deben abrirse directamente. Si se accede al
entorno local, usa `WEBCHAT_PUBLIC_WS_URL=ws://127.0.0.1:8081/webchat/ws`.

## MariaDB

Crear una base y un usuario con permisos solo sobre esa base:

```sql
CREATE DATABASE webchat_iaamx CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'webchat'@'127.0.0.1' IDENTIFIED BY 'cambia-esta-clave';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
  ON webchat_iaamx.* TO 'webchat'@'127.0.0.1';
FLUSH PRIVILEGES;
```

Las migraciones crean las entidades de identidad, sesión, correlación del relay y estado de
pairing. Desde
`004_openclaw_history_only.sql`, el contenido de conversación no se guarda en MariaDB:
el historial se obtiene exclusivamente con `chat.history` de OpenClaw; `relay_requests`
solo conserva request IDs, run IDs y estados técnicos para idempotencia. La migración
`008_drop_legacy_relay_tables.sql` elimina físicamente `messages` y `gateway_runs`;
esas tablas no forman parte del runtime actual. Esa migración requiere ejecutarse
una vez con una cuenta DBA, porque el usuario de la aplicación no tiene permiso
`DROP`; después debe registrarse como aplicada en `schema_migrations`.

Los provisionings pendientes se guardan en `runtime_provision_jobs` y pueden procesarse
con un worker periódico. En producción, prográmalo con el supervisor disponible, por
ejemplo cada minuto:

```bash
php bin/provision-pending.php 10
```

El worker también ejecuta mantenimiento seguro: elimina sesiones expiradas, códigos usados
o vencidos después de 30 días, correlaciones `relay_requests` después de 30 días y eventos
`audit_events` después de 180 días. Ajusta esos periodos con
`WEBCHAT_RETENTION_VERIFICATION_DAYS`, `WEBCHAT_RETENTION_RELAY_DAYS` y
`WEBCHAT_RETENTION_AUDIT_DAYS`. Nunca elimina usuarios, agentes, identidades ni conversaciones.
El worker es seguro para reintentar: los bindings existentes se reutilizan y los errores
técnicos se conservan solo en logs y en el estado interno del job.

`003_pairing_attempts.sql`, `010_pairing_events.sql`, `011_pairing_events_active_identity.sql`
y `012_pairing_state.sql` son migraciones históricas. El instalador las marca como legacy y
las omite en instalaciones nuevas; la migración `014_drop_legacy_pairing_state.sql` las retira
de instalaciones existentes junto con `013_drop_legacy_pairing_attempts.sql`.

## OpenClaw Gateway

El relay usa el protocolo WebSocket v4 del Gateway:

1. conecta como cliente backend con el token del Gateway;
2. solicita `chat.history` para recuperar contexto;
3. envía `chat.send` con `deliver=false`;
4. relays los eventos `chat` (`status`, `delta`, `final`, `error`) al navegador.

Configura `OPENCLAW_GATEWAY_URL`, `OPENCLAW_GATEWAY_TOKEN` y deja `OPENCLAW_AGENT_ID` vacío
para que el cliente use el agente provisionado de la sesión. No coloques el token en
`dashboard.js`, HTML, el navegador ni el repositorio.

Cuando OpenClaw crea un pairing, el plugin conserva el código asociado al remitente y
enriquece el mismo mensaje nativo con enlace, código y soporte. Si el resolver no responde,
usa la URL pública configurada como fallback, por lo que un pairing válido no pierde el enlace.
La URL de WebChat es genérica:
el código nativo de OpenClaw es la única credencial de vinculación y el backend obtiene la
identidad desde la solicitud pendiente antes de aprobarla.

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

   `https://your-subdomain.ngrok.io/webchat/`

Caddy conserva la actualización WebSocket en `/webchat/ws`; las rutas HTTP se sirven desde el proceso PHP integrado. Si tu cuenta de ngrok no permite `--domain`, usa el hostname asignado y actualiza las dos variables de origen/URL.

Si el navegador pierde la conexión mientras el relay está enviando un frame, el
relay trata el `Broken pipe` como una desconexión normal del peer y cierra solo esa
conexión. No se reinicia el Gateway ni se muestra un error de Gateway cuando el
problema está únicamente entre navegador, proxy y relay; el cliente puede volver a
conectar mediante su reintento automático.

## Seguridad y límites del MVP

- Cookie de autenticación `HttpOnly`, `SameSite=Lax` y `Secure` bajo HTTPS.
- Tokens relay firmados con HMAC y expiración.
- Lista de orígenes WebSocket configurable.
- Límite de mensaje configurable y `request_id` idempotente por conversación.
- El Gateway nunca se conecta desde el navegador.

Para un despliegue público real todavía conviene añadir rate limiting por IP/identidad, observabilidad y rotación gestionada de secretos.

## Verificación

```bash
php tests/run.php
find . -name '*.php' -not -path './vendor/*' -print0 | xargs -0 -n1 php -l
git diff --check
```

La revisión debe incluir referencias a métodos y configuraciones, además de las
pruebas funcionales. Para evitar falsos positivos, se distinguen dos hallazgos:

- **Código redundante:** código que sí se ejecuta, pero duplica una validación,
  consulta, transformación o fuente de datos que ya realiza otra capa.
- **Código no usado:** clase, método, configuración, ruta o recurso que permanece
  en el repositorio, pero no tiene consumidores ni forma parte de una ruta activa.

En cada prueba se debe comprobar ambos aspectos: no reintroducir usuarios anónimos,
sesiones técnicas sin usuario final ni copias del historial de OpenClaw; y revisar
que los cambios no dejen métodos, variables o configuraciones sin consumidores.
Cuando se modifique código, esta sección y la documentación de arquitectura deben
actualizarse en el mismo cambio.
