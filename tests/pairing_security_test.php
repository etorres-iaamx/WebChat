<?php

declare(strict_types=1);

$service = file_get_contents(__DIR__ . '/../src/Channels/ChannelIdentityService.php');
$resolver = file_get_contents(__DIR__ . '/../public/channel.php');
$plugin = file_get_contents(__DIR__ . '/../openclaw-plugin/webchat-identity/index.js');
$schema = file_get_contents(__DIR__ . '/../openclaw-plugin/webchat-identity/openclaw.plugin.json');
$manager = file_get_contents(__DIR__ . '/../src/Runtime/OpenClawBindingManager.php');
$pairing = file_get_contents(__DIR__ . '/../src/Runtime/OpenClawPairingManager.php');
$auth = file_get_contents(__DIR__ . '/../public/auth.php');
$authJs = file_get_contents(__DIR__ . '/../public/auth.js');
$pairingEvent = file_get_contents(__DIR__ . '/../public/pairing-event.php');

foreach ([$service, $resolver, $plugin, $schema, $manager, $pairing, $auth, $authJs, $pairingEvent] as $source) {
    if ($source === false) {
        throw new RuntimeException('No se pudo leer una fuente de pairing.');
    }
}

$assertions = [
    'resolve requiere binding OpenClaw' => str_contains($service, "INNER JOIN runtime_bindings rb"),
    'resolve requiere binding activo' => str_contains($service, "rb.status = \\'active\\'"),
    'link bloquea la fila durante la transacción' => str_contains($service, 'FOR UPDATE'),
    'link rechaza takeover' => str_contains($service, 'Esta identidad de canal ya está vinculada a otra cuenta.'),
    'link no reasigna el propietario' => !str_contains($service, 'ON DUPLICATE KEY UPDATE user_id ='),
    'resolver valida secreto opcional' => str_contains($resolver, 'HTTP_X_WEBCHAT_RESOLVE_SECRET'),
    'resolver distingue pairing pendiente' => str_contains($resolver, "continue_pairing"),
    'resolver expone estado desconocido' => str_contains($resolver, "'state' => \$state ?? 'unknown'"),
    'plugin usa resolver local correcto' => str_contains($plugin, "127.0.0.1:8082/channel.php"),
    'plugin envía secreto opcional' => str_contains($plugin, 'x-webchat-resolve-secret'),
    'plugin personaliza mensaje de onboarding' => str_contains($plugin, 'Después podrás aprobar el pairing'),
    'plugin informa pairing pendiente' => str_contains($plugin, 'Tu solicitud de ${channel} ya está pendiente'),
    'plugin intercepta antes de ejecutar el modelo' => str_contains($plugin, 'api.on("before_agent_reply"'),
    'plugin usa reply payload del dispatcher' => str_contains($plugin, 'reply: {text: result.text}'),
    'plugin intercepta antes del dispatch nativo' => str_contains($plugin, 'api.on("before_dispatch"'),
    'plugin no depende de inbound_claim global' => !str_contains($plugin, 'api.on("inbound_claim"'),
    'plugin tolera identidad desde sender o chat' => str_contains($plugin, 'context.channelContext?.sender?.id') && str_contains($plugin, 'context.channelContext?.chat?.id'),
    'plugin no restringe el hook a un trigger frágil' => !str_contains($plugin, 'eligibleTriggers: ["user"]'),
    'plugin excluye al administrador' => str_contains($plugin, 'telegram:1557679246'),
    'plugin permite configurar administradores' => str_contains($schema, 'adminIdentities'),
    'plugin adjunta URL y soporte al pairing' => str_contains($plugin, 'channel_pairing_requested') && str_contains($plugin, 'Completa tu pairing en WebChat'),
    'plugin no adjunta código a respuestas normales' => str_contains($plugin, 'asText(event.content).includes(code)'),
    'plugin no mezcla códigos entre remitentes' => str_contains($plugin, 'pairingRequests.get(pairingKey(channel, target))'),
    'resolver continúa pairing pendiente' => str_contains($resolver, '$state === \'binding_pending\' ? \'continue_pairing\' : \'register\''),
    'link crea binding pendiente' => str_contains($service, "status = \'binding_pending\'"),
    'link sincroniza peer binding' => str_contains($service, 'bindPeer'),
    'binding usa peer directo' => str_contains($manager, "'kind' => 'direct'"),
    'binding rechaza peer de otro agente' => str_contains($manager, 'ya está vinculada a otro agente OpenClaw.'),
    'binding conserva los existentes' => str_contains($manager, "'config', 'get', 'bindings', '--json'"),
    'schema expone resolveSecret' => str_contains($schema, 'resolveSecret'),
    'auth no auto-vincula identidad' => !str_contains($auth, 'webchat_channels()->link('),
    'auth js no auto-vincula identidad' => !str_contains($authJs, "action=channel/link"),
    'auth js reserva después de verificar' => str_contains($authJs, "'channel/reserve'"),
    'servicio expone pairing pendiente' => str_contains($service, 'pendingForUser'),
    'servicio permite re-pairing con solicitud vigente' => str_contains($service, "status IN (\\'binding_pending\\', \\'paired\\')"),
    'servicio documenta binding_pending como entrada normal' => str_contains($service, 'Normal onboarding enters through binding_pending.'),
    'servicio aprueba pairing y vincula después' => str_contains($service, 'approvePairing') && str_contains($service, 'bindPeer'),
    'pairing valida códigos alfanuméricos' => str_contains($pairing, "^[A-Za-z0-9]{6,12}$"),
    'pairing lista solicitudes antes de aprobar' => str_contains($pairing, "'pairing', 'list'") && str_contains($pairing, "'pairing', 'approve'"),
    'pairing valida identidad esperada' => str_contains($pairing, 'expectedIdentity'),
    'bindings priorizan peers directos' => str_contains($manager, 'array_merge($peerBindings, $fallbackBindings)'),
    'plugin notifica evento de pairing' => str_contains($plugin, 'pairingEventUrl') && str_contains($plugin, 'pairing_created'),
    'endpoint exige secreto de evento' => str_contains($pairingEvent, 'HTTP_X_WEBCHAT_PAIRING_SECRET'),
    'endpoint persiste evento de pairing' => str_contains($pairingEvent, 'recordPairingEvent'),
    'servicio consulta estado activo' => str_contains($service, 'hasActivePairingState') && str_contains($service, 'pairing_state'),
];

$failed = array_keys(array_filter($assertions, static fn (bool $passed): bool => !$passed));
if ($failed !== []) {
    throw new RuntimeException('Fallaron: ' . implode(', ', $failed));
}

echo 'PASS (' . count($assertions) . " assertions)\n";
