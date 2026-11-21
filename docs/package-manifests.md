# Manifiestos de paquetes por agente

Cada workspace de OpenClaw creado por WebChat incluye
`.webchat/packages.manifest`. El archivo conserva los paquetes que el usuario
quiera tener disponibles después de recrear su contenedor.

El formato es una entrada por línea:

```text
apt curl
pip requests
npm typescript
```

El helper `runtime/package_manifest.py` valida y administra el archivo:

```bash
python3 runtime/package_manifest.py --manifest /ruta/al/workspace/.webchat/packages.manifest \
  add "apt curl" "pip requests"
python3 runtime/package_manifest.py --manifest /ruta/al/workspace/.webchat/packages.manifest validate
python3 runtime/package_manifest.py --manifest /ruta/al/workspace/.webchat/packages.manifest install --dry-run
```

`install` ejecuta los gestores mediante `subprocess` sin shell y está pensado
para ejecutarse dentro del contenedor del agente durante su arranque. La
reinstalación real no se ejecuta en el host WebChat ni requiere acceso al
Docker socket. En el host actual no hay Docker, por lo que esta fase deja listo
el contrato portable; el entrypoint del contenedor lo conectará en la siguiente
fase.

El manifiesto vive dentro del workspace persistente y se crea con permisos
`0600`. Los paquetes no se agregan automáticamente por detectar comandos
manuales: el agente o su usuario los registra explícitamente con `add`, lo que
evita ejecutar o persistir comandos arbitrarios.
