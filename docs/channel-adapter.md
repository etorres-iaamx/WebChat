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

1. El usuario autenticado solicita `POST /api.php?action=pairing/start`.
2. Se crea un desafío de seis dígitos con expiración de 15 minutos.
3. El canal confirma la identidad mediante `pairing/confirm`.
4. La identidad se guarda con una restricción única por canal e identidad externa.

La integración específica con el Gateway se conectará cuando el canal real esté activo.
La cuenta de WhatsApp del entorno actualmente aparece desconectada; no se debe marcar
como operativa hasta completar `openclaw channels login --channel whatsapp`.
