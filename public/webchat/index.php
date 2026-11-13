<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/config/bootstrap.php';

use Webchat\App\Token;

$config = webchat_config();
$authToken = (string) ($_COOKIE['webchat_auth'] ?? '');
$authUser = webchat_auth()->userFromSession($authToken, true);
if ($authUser === null) {
    header('Location: ../index.php');
    exit;
}
$authUserId = (int) ($authUser['_database_id'] ?? 0);
$cookieName = 'iaamx_webchat';
$forwardedProto = strtolower(trim(explode(',', (string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''), 2)[0]));
$secureCookie = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
    || $forwardedProto === 'https'
    || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')) === 'on';

try {
    $rawToken = (string) ($_COOKIE[$cookieName] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $rawToken)) {
        $rawToken = Token::random(32);
    }

    $session = webchat_repository()->createOrRefreshUserSession($rawToken, $config, $authUserId);
    if (isset($session['rotated_token']) && is_string($session['rotated_token'])) {
        $rawToken = $session['rotated_token'];
    }
    $relayToken = Token::issue(
        ['kind' => 'webchat', 'session_id' => (string) $session['public_id']],
        $config->required('APP_SECRET'),
        $config->int('WEBCHAT_TOKEN_TTL', 3600),
    );
    // Conversation content is loaded by the relay from OpenClaw chat.history.
    $history = [];
    $host = (string) ($_SERVER['HTTP_HOST'] ?? '127.0.0.1');
    $webSocketUrl = $config->publicWebSocketUrl($host, $secureCookie);

    setcookie($cookieName, $rawToken, [
        'expires' => time() + $config->int('WEBCHAT_SESSION_TTL', 2592000),
        'path' => '/',
        'secure' => $secureCookie,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
} catch (Throwable $exception) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo $config->get('APP_ENV', 'local') === 'production'
        ? 'El webchat no está disponible en este momento.'
        : 'No fue posible iniciar el webchat: ' . $exception->getMessage();
    exit;
}

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
$clientConfig = json_encode([
    'token' => $relayToken,
    'webSocketUrl' => $webSocketUrl,
    'maxMessageLength' => $config->int('WEBCHAT_MAX_MESSAGE_LENGTH', 4000),
], $jsonFlags | JSON_THROW_ON_ERROR);
$historyJson = json_encode($history, $jsonFlags | JSON_THROW_ON_ERROR);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="color-scheme" content="light">
    <title>IAAMXORG · Panel de control</title>
    <link rel="stylesheet" href="./styles.css?v=20261113-1533">
</head>
<body>
    <main class="dashboard-shell" aria-label="Panel de control IAAMXORG">
        <aside class="dashboard-sidebar" data-sidebar>
            <div class="sidebar-brand">
                <img class="sidebar-brand__logo" src="./assets/iaa-mx-pleno-oscuro.svg" alt="IAA MX">
                <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Colapsar menú" aria-expanded="true">
                    <span aria-hidden="true">‹</span>
                </button>
            </div>
            <div class="sidebar-profile">
                <div class="sidebar-avatar" aria-hidden="true"><?= webchat_h(mb_strtoupper(mb_substr((string) $authUser['name'], 0, 2))) ?></div>
                <div class="sidebar-profile__copy">
                    <strong><?= webchat_h((string) $authUser['name']) ?></strong>
                    <span><?= webchat_h((string) $authUser['email']) ?></span>
                </div>
            </div>
            <nav class="sidebar-nav" aria-label="Navegación principal">
                <p class="sidebar-section-title">Gestión</p>
                <a class="sidebar-link is-active" href="#assistant" data-sidebar-assistant><span class="sidebar-link__icon" aria-hidden="true">✦</span><span>Asistente de IA</span></a>
                <p class="sidebar-section-title">Cuenta</p>
                <button class="sidebar-link sidebar-link--button" type="button" data-sidebar-profile aria-controls="profile-panel" aria-expanded="false"><span class="sidebar-link__icon" aria-hidden="true">◎</span><span>Perfil</span></button>
                <button class="sidebar-link sidebar-link--button" type="button" data-sidebar-settings aria-controls="settings-panel" aria-expanded="false"><span class="sidebar-link__icon" aria-hidden="true">⚙</span><span>Ajustes</span></button>
                <p class="sidebar-section-title">Sesión</p>
                <button class="sidebar-link sidebar-link--button" type="button" data-action="logout"><span class="sidebar-link__icon" aria-hidden="true">⇥</span><span>Cerrar sesión</span></button>
            </nav>
        </aside>
        <section class="dashboard-main" aria-label="Contenido del dashboard">
            <div class="dashboard-background" aria-hidden="true"></div>
            <section class="profile-panel" id="profile-panel" data-profile-panel hidden aria-labelledby="profile-title">
                <div class="profile-panel__header">
                    <div>
                        <p class="profile-panel__eyebrow">Cuenta</p>
                        <h1 id="profile-title">Perfil</h1>
                    </div>
                    <button class="profile-panel__close" type="button" data-profile-close aria-label="Cerrar perfil">×</button>
                </div>
                <dl class="profile-panel__details">
                    <div><dt>Nombre</dt><dd><?= webchat_h((string) $authUser['name']) ?></dd></div>
                    <div><dt>Correo electrónico</dt><dd><?= webchat_h((string) $authUser['email']) ?></dd></div>
                    <div><dt>Estado</dt><dd>Cuenta activa</dd></div>
                </dl>
            </section>
            <section class="settings-panel" id="settings-panel" data-settings-panel hidden aria-labelledby="settings-title">
                <div class="profile-panel__header">
                    <div>
                        <p class="profile-panel__eyebrow">Preferencias</p>
                        <h1 id="settings-title">Ajustes</h1>
                    </div>
                    <button class="profile-panel__close" type="button" data-settings-close aria-label="Cerrar ajustes">×</button>
                </div>
                <form class="settings-form" data-settings-form>
                    <div class="setting-field">
                        <div class="setting-label-row"><label for="setting-theme">Tema de la interfaz</label><button class="setting-help" type="button" data-setting-help="help-theme" aria-controls="help-theme" aria-expanded="false" aria-label="Ayuda sobre el tema">?</button></div>
                        <select id="setting-theme" name="theme" data-setting-theme>
                            <option value="system">Usar configuración del dispositivo</option>
                            <option value="light">Claro</option>
                            <option value="dark">Oscuro</option>
                        </select>
                        <p class="setting-help-text" id="help-theme" data-setting-help-text hidden>Define el contraste de la interfaz de Ajustes. “Usar configuración del dispositivo” sigue el tema que tenga configurado tu navegador o sistema.</p>
                    </div>
                    <div class="setting-field">
                        <div class="setting-label-row"><label for="setting-sidebar">Estado inicial del menú</label><button class="setting-help" type="button" data-setting-help="help-sidebar" aria-controls="help-sidebar" aria-expanded="false" aria-label="Ayuda sobre el estado del menú">?</button></div>
                        <select id="setting-sidebar" name="sidebar" data-setting-sidebar>
                            <option value="expanded">Expandido</option>
                            <option value="collapsed">Colapsado</option>
                        </select>
                        <p class="setting-help-text" id="help-sidebar" data-setting-help-text hidden>Controla si el sidebar inicia mostrando los nombres de las opciones o únicamente sus iconos.</p>
                    </div>
                    <div class="setting-field">
                        <div class="setting-label-row"><label for="setting-assistant-mode">Modo inicial del asistente</label><button class="setting-help" type="button" data-setting-help="help-assistant-mode" aria-controls="help-assistant-mode" aria-expanded="false" aria-label="Ayuda sobre el modo del asistente">?</button></div>
                        <select id="setting-assistant-mode" name="assistantMode" data-setting-assistant-mode>
                            <option value="floating">Flotante</option>
                            <option value="embedded">Integrado</option>
                        </select>
                        <p class="setting-help-text" id="help-assistant-mode" data-setting-help-text hidden>Define cómo aparece el asistente al abrirlo: como panel sobre el dashboard o integrado dentro del área principal.</p>
                    </div>
                    <p class="settings-form__note">Estas preferencias se guardan en este navegador. El tono y las instrucciones permanentes del agente se agregarán cuando exista su configuración del lado servidor.</p>
                    <button class="settings-form__save" type="submit">Guardar ajustes</button>
                    <span class="settings-form__status" data-settings-status role="status" aria-live="polite"></span>
                </form>
            </section>
        </section>
    </main>

    <button class="chat-launcher" type="button" data-chat-open aria-controls="assistant-panel" aria-expanded="false" aria-label="Abrir asistente de IA">
        <svg viewBox="0 0 40 40" aria-hidden="true" focusable="false">
            <path d="M8 10.5h18a4 4 0 0 1 4 4v8a4 4 0 0 1-4 4H17l-5.5 4v-4H8a4 4 0 0 1-4-4v-8a4 4 0 0 1 4-4Z" />
            <path d="m27 4 .9 2.6L30.5 8l-2.6.9L27 11.5l-.9-2.6L23.5 8l2.6-.9L27 4ZM22 14v5M19.5 16.5h5" class="chat-launcher__spark" />
        </svg>
    </button>

    <aside class="chat-panel" id="assistant-panel" hidden aria-label="Asistente de IA">
        <header class="chat-header">
            <strong class="chat-header__title">
                <img class="chat-header__logo" src="./assets/iaa-mx-pleno-claro.svg" alt="IAA MX">
                <span class="sr-only">Asistente de IA</span>
            </strong>
            <div class="chat-header__actions">
                <button class="icon-button" type="button" data-chat-embed aria-label="Integrar asistente de IA" title="Integrar asistente de IA">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 11V8h3M16 8h3v3M19 13v3h-3M11 16H8v-3" /></svg>
                </button>
                <button class="icon-button" type="button" data-chat-maximize aria-label="Maximizar asistente de IA" title="Maximizar asistente de IA" aria-pressed="false">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="8" y="8" width="10" height="10" rx="1" /><path d="M6 15H5a1 1 0 0 1-1-1V5a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v1" /></svg>
                </button>
                <button class="icon-button" type="button" data-chat-popout aria-label="Abrir asistente de IA en una ventana flotante" title="Abrir asistente de IA en una ventana flotante">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 6h4v4M18 6l-7 7" /><path d="M18 13v4a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h4" /></svg>
                </button>
                <button class="icon-button" type="button" data-chat-minimize aria-label="Minimizar asistente de IA" title="Minimizar asistente de IA">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14" /></svg>
                </button>
                <button class="icon-button" type="button" data-chat-close aria-label="Cerrar asistente de IA" title="Cerrar asistente de IA">
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 7 10 10M17 7 7 17" /></svg>
                </button>
            </div>
            <span class="connection-status sr-only" aria-live="polite"><span class="connection-dot" data-connection-dot></span><span data-connection-label>Listo para ayudarte</span></span>
        </header>

        <div class="chat-body" data-chat-body>
            <section class="chat-welcome" data-chat-welcome>
                <p class="chat-welcome__eyebrow">Bienvenido</p>
                <h1>¿Qué te gustaría hacer hoy?</h1>
            </section>

            <div class="quick-actions" data-quick-actions>
                <button type="button" data-prompt="Dame un resumen de mi mañana.">Resumen de la mañana</button>
                <button type="button" data-prompt="Muéstrame mis mensajes urgentes.">Mensajes urgentes</button>
                <button type="button" data-prompt="Prepara un reporte de desempeño.">Informe de desempeño</button>
            </div>

            <div class="message-list" data-message-list aria-live="polite" aria-relevant="additions text"></div>
            <div class="typing-indicator" data-typing hidden><span></span><span></span><span></span><em>El asistente está escribiendo…</em></div>
        </div>

        <form class="chat-composer" data-chat-form>
            <label class="sr-only" for="chat-input">Escribe un mensaje</label>
            <textarea id="chat-input" rows="1" maxlength="4000" placeholder="Escribe tu mensaje..." autocomplete="off"></textarea>
            <button class="send-button" type="submit" aria-label="Enviar mensaje">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 19V5M6 11l6-6 6 6" /></svg>
            </button>
        </form>
        <p class="chat-disclaimer">Las respuestas de la IA no siempre son precisas.</p>
    </aside>

    <div class="popup-source-disabled" data-popup-source-disabled hidden role="status" aria-live="polite">
        <span class="popup-source-disabled__message">El asistente está abierto en otra ventana.</span>
    </div>

    <noscript>
        <div class="no-script-message">Este webchat necesita JavaScript habilitado para conectar con el asistente.</div>
    </noscript>

    <script>
        window.IAAMX_WEBCHAT = <?= $clientConfig ?>;
        window.IAAMX_HISTORY = <?= $historyJson ?>;
    </script>
    <script src="./app.js" defer></script>
</body>
</html>
