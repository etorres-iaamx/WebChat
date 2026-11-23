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
identidad está registrada. Una identidad desconocida recibe `registered: false` y una URL
genérica de WebChat. No se crea sesión ni se envía el mensaje al agente
`main`; OpenClaw continúa su flujo nativo de `dmPolicy: pairing` para generar el código.

## Pairing

WebChat no genera códigos: OpenClaw los crea y los mantiene como autoridad nativa.
WebChat sí muestra el código recibido, persiste el estado actual y ejecuta el formulario
que llama a `openclaw pairing approve <canal> <código>` desde el backend.

Cuando un remitente escribe por primera vez, el plugin deja pasar el pairing nativo y agrega
al mismo mensaje el enlace de WebChat, el código y el contacto de soporte. El usuario puede
registrarse o iniciar sesión desde ese enlace y captura el código. El backend valida el código
contra la solicitud nativa de OpenClaw, obtiene de ella la identidad real y crea `binding_pending`
antes de completar el binding. La URL no transporta un intent ni una identidad confiable.

Las tablas históricas `pairing_state` y `pairing_events` pueden existir en instalaciones
anteriores, pero ya no participan en el flujo activo. La interfaz no hace polling ni recibe
eventos de pairing para decidir si muestra el formulario: el formulario está disponible
para el usuario autenticado y OpenClaw valida el código al aprobarlo.

El core de la aplicación mantiene la asociación entre `canal + identificador externo`
y el agente correspondiente. Aprobar el pairing de OpenClaw autoriza al remitente a
hablar con el bot; no crea un agente ni sustituye el routing del core.

La integración específica con el Gateway está activa para Telegram y WhatsApp. WhatsApp
debe tener una cuenta vinculada y el canal conectado en OpenClaw; además, `WHATSAPP_BOT_URL`
puede configurarse opcionalmente para enlazar directamente al bot desde Perfil.
