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
$installer = file_get_contents(__DIR__ . '/../bin/install.sh');
$envExample = file_get_contents(__DIR__ . '/../.env.example');

foreach ([$service, $resolver, $plugin, $schema, $manager, $pairing, $auth, $authJs, $installer, $envExample] as $source) {
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
    'resolver unifica onboarding y pairing' => str_contains($resolver, "\$action = 'pairing'") && !str_contains($resolver, "continue_pairing"),
    'resolver expone estado desconocido' => str_contains($resolver, "'state' => \$state ?? 'unknown'"),
    'plugin usa resolver local correcto' => str_contains($plugin, "127.0.0.1:8082/channel.php"),
    'plugin envía secreto opcional' => str_contains($plugin, 'x-webchat-resolve-secret'),
    'plugin no intercepta el onboarding antes del pairing nativo' => !str_contains($plugin, 'api.on("before_dispatch"') && !str_contains($plugin, 'api.on("before_agent_reply"'),
    'plugin enriquece pairing nativo' => str_contains($plugin, 'pairingDestination') && str_contains($plugin, 'Completa tu pairing en WebChat'),
    'plugin normaliza identidad WhatsApp JID' => str_contains($plugin, 'normalizeChannelIdentity') && str_contains($plugin, 'split("@", 1)') && str_contains($plugin, 'return `+${identity}`'),
    'plugin guarda el código de pairing de forma síncrona' => str_contains($plugin, 'Store the code synchronously') && str_contains($plugin, 'pairingRequests.set(key, asText(event.code))'),
    'plugin no depende de inbound_claim global' => !str_contains($plugin, 'api.on("inbound_claim"'),
    'plugin usa identidad del evento nativo' => str_contains($plugin, 'event.senderId'),
    'plugin no restringe el hook a un trigger frágil' => !str_contains($plugin, 'eligibleTriggers: ["user"]'),
    'plugin excluye al administrador' => str_contains($plugin, 'telegram:1557679246'),
    'plugin permite configurar administradores' => str_contains($schema, 'adminIdentities'),
    'instalador exige identidad administrativa' => str_contains($installer, 'Instalación bloqueada: define WEBCHAT_ADMIN_TELEGRAM_ID o WEBCHAT_ADMIN_WHATSAPP_ID'),
    'instalador pregunta por Telegram y WhatsApp' => str_contains($installer, 'Telegram ID del administrador') && str_contains($installer, 'WhatsApp del administrador'),
    'instalador crea binding administrativo' => str_contains($installer, '"agentId": "main"') && str_contains($installer, 'admin_identities'),
    'instalador elimina fallback amplio a main' => str_contains($installer, 'item.get("agentId") == "main"') && !str_contains($installer, 'bindings.append(fallback)'),
    'binding permite quitar peers' => str_contains($manager, 'unbindPeer') && str_contains($manager, 'unbindAgentPeers'),
    'binding elimina fallbacks amplios' => str_contains($manager, 'removeBroadMainBindings'),
    'ejemplo expone identidades administrativas' => str_contains($envExample, 'WEBCHAT_ADMIN_TELEGRAM_ID=') && str_contains($envExample, 'WEBCHAT_ADMIN_WHATSAPP_ID='),
    'plugin adjunta URL y soporte al pairing' => str_contains($plugin, 'channel_pairing_requested') && str_contains($plugin, 'Completa tu pairing en WebChat'),
    'plugin no omite pairing por route_to_agent' => !str_contains($plugin, 'result?.action === "route_to_agent"') && str_contains($plugin, 'fallbackWebchatUrl'),
    'plugin no adjunta código a respuestas normales' => str_contains($plugin, 'asText(content).includes(code)'),
    'plugin usa payload moderno de respuesta' => str_contains($plugin, 'reply_payload_sending'),
    'plugin reconoce el destino por conversationId' => str_contains($plugin, 'context.conversationId'),
    'plugin admite delivery sin identidad explicita' => str_contains($plugin, 'The exact native code is unique'),
    'plugin no mezcla códigos entre remitentes' => str_contains($plugin, 'pairingEligibility.get(key)') && str_contains($plugin, 'pairingRequests.get(key)'),
    'pairing reserva la identidad devuelta por OpenClaw' => str_contains($service, 'identityFromRequest') && str_contains($service, '$this->reserve($userId, $channel, $externalIdentity)'),
    'link sincroniza peer binding' => str_contains($service, 'bindPeer'),
    'binding usa peer directo' => str_contains($manager, "'kind' => 'direct'"),
    'binding rechaza peer de otro agente' => str_contains($manager, 'ya está vinculada a otro agente OpenClaw.'),
    'binding conserva los existentes' => str_contains($manager, "'config', 'get', 'bindings', '--json'"),
    'schema expone resolveSecret' => str_contains($schema, 'resolveSecret'),
    'auth js no depende de intent' => !str_contains($authJs, 'onboardingIntent') && !str_contains($authJs, 'channel/reserve'),
    'resolver no emite intent en la URL' => !str_contains($resolver, 'Token::issue') && !str_contains($resolver, 'http_build_query'),
    'servicio no usa polling de pairing para visibilidad' => !str_contains($service, 'pendingForUser') && !str_contains($service, 'hasActivePairingState'),
    'dashboard no consulta estado para mostrar el formulario' => !str_contains($authJs, 'channel/pending') && !str_contains(file_get_contents(__DIR__ . '/../public/dashboard.php'), 'pendingForUser'),
    'dashboard muestra pairing de Telegram y WhatsApp' => str_contains(file_get_contents(__DIR__ . '/../public/dashboard.php'), "['telegram', 'whatsapp']"),
    'dashboard muestra estado Vinculado tras pairing' => str_contains(file_get_contents(__DIR__ . '/../public/dashboard.php'), "'Vinculado'") && str_contains(file_get_contents(__DIR__ . '/../public/dashboard.js'), "label.textContent = 'Vinculado'"),
    'servicio consulta estados de pairing del usuario' => str_contains($service, 'statesForUser'),
    'servicio aprueba pairing y vincula después' => str_contains($service, 'approvePairing') && str_contains($service, 'bindPeer'),
    'pairing valida códigos alfanuméricos' => str_contains($pairing, "^[A-Za-z0-9]{6,12}$"),
    'pairing lista solicitudes antes de aprobar' => str_contains($pairing, "'pairing', 'list'") && str_contains($pairing, "'pairing', 'approve'"),
    'pairing valida identidad esperada' => str_contains($pairing, 'expectedIdentity'),
    'bindings priorizan peers directos' => str_contains($manager, 'array_merge($peerBindings, $fallbackBindings)'),
    'plugin ya no depende de eventos para mostrar el formulario' => !str_contains($plugin, 'pairingEventUrl') && !str_contains($plugin, 'notifyPairingEvent'),
    'relay no mantiene estado duplicado de pairing' => !str_contains(file_get_contents(__DIR__ . '/../src/WebSocket/RelayServer.php'), 'PairingEventBus'),
    'pairing obtiene identidad desde solicitud nativa' => str_contains($pairing, 'identityFromRequest'),
    'pairing normaliza identidad WhatsApp en el backend' => str_contains($pairing, 'normalizeIdentityValue') && str_contains($pairing, 'explode(\'@\', $identity, 2)'),
    'servicio normaliza WhatsApp antes de resolver y reservar' => str_contains($service, 'normalizeIdentity') && str_contains($service, 'explode(\'@\', $identity, 2)'),
    'plugin normaliza identidad antes de consultar el resolver' => str_contains($plugin, 'const identity = normalizeChannelIdentity(channel || "", asText(identityValue));'),
];

$failed = array_keys(array_filter($assertions, static fn (bool $passed): bool => !$passed));
if ($failed !== []) {
    throw new RuntimeException('Fallaron: ' . implode(', ', $failed));
}

echo 'PASS (' . count($assertions) . " assertions)\n";
