# Adaptadores de canales

## Regla de resolución

Telegram y WhatsApp son entradas compartidas. La identidad externa nunca se convierte
directamente en un `agent_id` enviado por el cliente:

```text
identidad externa
  -> channel_identities
  -> usuario activo
  -> logical_agents
  -> runtime_bindings (OpenClaw)
  -> sesión del agente
```

`public/channel.php` implementa el contrato mínimo para que un adaptador consulte si una
identidad está registrada. Una identidad desconocida recibe `registered: false` y la URL
del WebChat. No se crea sesión ni se envía el mensaje al agente `main`.

## Pairing

WebChat no simula ni genera códigos de pairing. OpenClaw envía su mensaje nativo a
los remitentes desconocidos y `webapp2-etorres` procesa el formulario público para
ejecutar `openclaw pairing approve <canal> <código>`.

Cuando un remitente desconocido abre la invitación de WebChat, el canal y su
identificador se conservan durante el registro o inicio de sesión. Tras autenticar
la cuenta, WebChat registra el vínculo con el agente; después OpenClaw continúa con
su pairing nativo y bloquea el acceso hasta que el código sea aprobado.

El core mantiene por separado la asociación entre `canal + identificador externo` y
el agente correspondiente. Aprobar el pairing de OpenClaw autoriza al remitente a
hablar con el bot; no crea un agente ni sustituye el routing del core.

La integración específica con el Gateway se conectará cuando el canal real esté activo.
La cuenta de WhatsApp del entorno actualmente aparece desconectada; no se debe marcar
como operativa hasta completar `openclaw channels login --channel whatsapp`.
