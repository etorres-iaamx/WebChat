# Conexión de Telegram y WhatsApp con WebChat

## Qué queda implementado

1. WebChat verifica una identidad externa contra `public/channel.php`.
2. Una identidad registrada se vincula al agente OpenClaw del usuario mediante un binding directo por peer.
3. Una identidad desconocida no se enruta al agente global: el plugin responde con el enlace de registro de WebChat.
4. Telegram y WhatsApp usan el mismo código y el mismo flujo; solo cambia el identificador externo del canal.

## Instalación del plugin

Desde el repositorio:

```bash
openclaw plugins install ./openclaw-plugin/webchat-identity
```

Configurar la URL local que el Gateway pueda alcanzar:

```bash
openclaw config set plugins.entries.webchat-identity.config.resolveUrl \
  http://127.0.0.1:8080/channel.php
```

Después validar y reiniciar el Gateway:

```bash
openclaw plugins validate ./openclaw-plugin/webchat-identity
openclaw plugins doctor
openclaw gateway restart
```

El plugin solo reclama mensajes directos de Telegram y WhatsApp. Si WebChat responde que la identidad está registrada, deja continuar el enrutamiento normal; el binding por peer dirige el mensaje al agente correcto. Si no está registrada, reclama el mensaje y envía el enlace de registro.

## WhatsApp

La lógica está lista, pero el canal debe estar autenticado en OpenClaw. La primera conexión requiere ejecutar el login de WhatsApp y escanear el QR en el host. Mientras el canal esté desconectado no es posible hacer una prueba real de extremo a extremo.
