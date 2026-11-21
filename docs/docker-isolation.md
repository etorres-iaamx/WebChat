# Aislamiento fuerte de agentes WebChat

Este directorio define el runtime de referencia para el aislamiento fuerte:
cada usuario obtiene un contenedor con un Gateway OpenClaw independiente y un
volumen persistente propio.

## Frontera

- WebChat y su MariaDB permanecen en el host central.
- El Gateway de cada usuario corre en su contenedor y red privada.
- No se monta `/var/run/docker.sock`.
- No se usa `privileged`, `hostNetwork`, `hostPID` ni volúmenes del host.
- El contenedor no publica puertos al host; el orquestador conecta WebChat al
  endpoint privado del runtime.
- El volumen persistente contiene el estado del agente; no se elimina al
  reiniciar el Gateway.
- El agente no recibe credenciales de la MariaDB central.

## Persistencia

`/var/lib/openclaw` debe montarse como un volumen exclusivo del usuario. Allí
se conservan configuración, workspace, memoria y sesiones del Gateway. La
imagen contiene únicamente el runtime reproducible.

Los paquetes instalados manualmente dentro de la capa del contenedor no son
parte de la imagen y pueden desaparecer al recrearlo. Esa personalización se
deja fuera de la primera fase; posteriormente podrá materializarse mediante
una imagen versionada o un manifiesto de paquetes por usuario.

## Operaciones permitidas

El usuario no ejecuta Docker. El servicio de control podrá, después de validar
el `logical_agent_id` y su `runtime_binding`, reiniciar únicamente su Gateway.
La reparación automática también conservará el volumen. No se expone todavía
una operación de borrado o restablecimiento total.

## Prueba en un host con Docker

Desde `docker/agent/`:

```bash
docker compose -f compose.example.yml build
OPENCLAW_GATEWAY_TOKEN="token-de-prueba" \
  docker compose -f compose.example.yml up -d
docker compose -f compose.example.yml ps
docker compose -f compose.example.yml down
```

La prueba debe confirmar que el contenedor inicia, que el puerto no se publica
al host, que el volumen permanece después de `down` y que no existen montajes
del host ni capacidades adicionales.

Esta definición es una imagen/runtime de referencia. La creación dinámica por
usuario y el endpoint administrativo se integrarán después en el provisioner;
no se ejecutan automáticamente en la instalación actual.
