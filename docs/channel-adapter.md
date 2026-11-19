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

WebChat no genera códigos: OpenClaw los crea y los mantiene como autoridad nativa.
WebChat sí muestra el código recibido, persiste el estado actual y ejecuta el formulario
que llama a `openclaw pairing approve <canal> <código>` desde el backend.

Cuando un remitente desconocido abre la invitación de WebChat, el canal y su
identificador se conservan durante el registro o inicio de sesión. Tras autenticar
la cuenta, WebChat registra la identidad como `binding_pending`; después OpenClaw continúa
con su pairing nativo y bloquea el acceso hasta que el código sea aprobado. La interfaz solo
muestra el apartado cuando existe una solicitud vigente.

`pairing_state` contiene una sola solicitud activa por identidad y canal. `pairing_events`
es histórico y registra creación, aprobación, expiración o reemplazo; no se consulta para
decidir la visibilidad normal del apartado.

La actualización principal llega por el evento `pairing.updated` del relay. El navegador
hace una consulta inicial al abrir el dashboard y usa polling de 30 segundos únicamente
durante una desconexión del WebSocket.

El core de la aplicación mantiene la asociación entre `canal + identificador externo`
y el agente correspondiente. Aprobar el pairing de OpenClaw autoriza al remitente a
hablar con el bot; no crea un agente ni sustituye el routing del core.

La integración específica con el Gateway se conectará cuando el canal real esté activo.
La cuenta de WhatsApp del entorno actualmente aparece desconectada; no se debe marcar
como operativa hasta completar `openclaw channels login --channel whatsapp`.
