# Conexión de Telegram y WhatsApp con WebChat

## Qué queda implementado

1. WebChat verifica una identidad externa contra `public/channel.php`.
2. Una identidad registrada se vincula al agente OpenClaw del usuario mediante un binding directo por peer.
3. Una identidad no vinculada no se enruta al agente global: OpenClaw genera el código nativo y el plugin agrega el enlace de onboarding, el código y soporte.
4. Telegram y WhatsApp usan el mismo código y el mismo flujo; solo cambia el identificador externo del canal.

## Instalación del plugin

Desde el repositorio:

```bash
openclaw plugins install ./openclaw-plugin/webchat-identity
```

Configurar la URL local que el Gateway pueda alcanzar:

```bash
openclaw config set plugins.entries.webchat-identity.config.resolveUrl \
  http://127.0.0.1:8082/channel.php

# Opcional pero recomendado si el resolver queda expuesto fuera de loopback.
openclaw config set plugins.entries.webchat-identity.config.resolveSecret \
  '<secreto-compartido-de-al-menos-16-caracteres>'
```

Después validar y reiniciar el Gateway:

```bash
openclaw plugins validate ./openclaw-plugin/webchat-identity
openclaw plugins doctor
openclaw gateway restart
```

El plugin no reclama mensajes directos de identidades no vinculadas. Deja que OpenClaw
aplique `dmPolicy: pairing`, conserva el código asociado al remitente y enriquece únicamente
el mensaje saliente que ya contiene ese mismo código con la URL de onboarding y soporte.
Una respuesta normal del agente nunca se enriquece. Si la identidad ya está vinculada, el
binding por peer dirige el mensaje al agente correcto y no se inicia onboarding.

Después de iniciar sesión o registrarse, el usuario captura el código recibido. El backend busca
ese código en las solicitudes pendientes de OpenClaw, obtiene la identidad que OpenClaw devuelve,
la reserva como `binding_pending` y ejecuta la aprobación nativa; entonces crea el binding por
peer y cambia la identidad a `paired`. No se confía en una identidad enviada por el navegador
ni en un token de onboarding de la URL.

El binding por peer debe apuntar a un agente provisionado específico. No se debe conservar
una sesión antigua bajo `agent:main:telegram:direct:<id>` después de crear el binding; hay
que eliminarla para que el siguiente mensaje cree una sesión bajo el agente correcto.

## WhatsApp

WhatsApp usa el mismo resolver, código nativo y binding directo que Telegram. Las identidades
de WhatsApp se normalizan desde JID o número hacia el formato `+<número>` antes de resolver,
reservar o crear el binding. OpenClaw reporta el canal WhatsApp conectado y vinculado; el flujo
solo requiere que `WHATSAPP_BOT_URL` tenga el enlace público correspondiente si se desea
mostrarlo directamente desde Perfil.
