<?php

declare(strict_types=1);

$service = file_get_contents(__DIR__ . '/../src/Channels/ChannelIdentityService.php');
$resolver = file_get_contents(__DIR__ . '/../public/channel.php');
$plugin = file_get_contents(__DIR__ . '/../openclaw-plugin/webchat-identity/index.js');
$schema = file_get_contents(__DIR__ . '/../openclaw-plugin/webchat-identity/openclaw.plugin.json');
$manager = file_get_contents(__DIR__ . '/../src/Runtime/OpenClawBindingManager.php');

foreach ([$service, $resolver, $plugin, $schema, $manager] as $source) {
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
    'plugin excluye al administrador' => str_contains($plugin, 'telegram:1557679246'),
    'plugin permite configurar administradores' => str_contains($schema, 'adminIdentities'),
    'link crea binding pendiente' => str_contains($service, "status = \'binding_pending\'"),
    'link sincroniza peer binding' => str_contains($service, 'bindPeer'),
    'binding usa peer directo' => str_contains($manager, "'kind' => 'direct'"),
    'binding rechaza peer de otro agente' => str_contains($manager, 'ya está vinculada a otro agente OpenClaw.'),
    'binding conserva los existentes' => str_contains($manager, "'config', 'get', 'bindings', '--json'"),
    'schema expone resolveSecret' => str_contains($schema, 'resolveSecret'),
];

$failed = array_keys(array_filter($assertions, static fn (bool $passed): bool => !$passed));
if ($failed !== []) {
    throw new RuntimeException('Fallaron: ' . implode(', ', $failed));
}

echo 'PASS (' . count($assertions) . " assertions)\n";
