(() => {
    'use strict';

    const config = window.IAAMX_WEBCHAT || {};
    const basePath = document.documentElement.dataset.webchatPath || '/webchat';
    const initialHistory = Array.isArray(window.IAAMX_HISTORY) ? window.IAAMX_HISTORY : [];
    const panel = document.querySelector('#assistant-panel');
    const launcher = document.querySelector('[data-chat-open]');
    const messageList = document.querySelector('[data-message-list]');
    const welcome = document.querySelector('[data-chat-welcome]');
    const quickActions = document.querySelector('[data-quick-actions]');
    const typingIndicator = document.querySelector('[data-typing]');
    const form = document.querySelector('[data-chat-form]');
    const input = document.querySelector('#chat-input');
    const sendButton = document.querySelector('.send-button');
    const connectionDot = document.querySelector('[data-connection-dot]');
    const connectionLabel = document.querySelector('[data-connection-label]');
    const embedButton = document.querySelector('[data-chat-embed]');
    const maximizeButton = document.querySelector('[data-chat-maximize]');
    const popoutButton = document.querySelector('[data-chat-popout]');
    const minimizeButton = document.querySelector('[data-chat-minimize]');
    const closeButton = document.querySelector('[data-chat-close]');
    const dashboardShell = document.querySelector('.dashboard-shell');
    const dashboardMain = document.querySelector('.dashboard-main');
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const sidebarAssistant = document.querySelector('[data-sidebar-assistant]');
    const sidebarProfile = document.querySelector('[data-sidebar-profile]');
    const sidebarSettings = document.querySelector('[data-sidebar-settings]');
    const profilePanel = document.querySelector('[data-profile-panel]');
    const profileClose = document.querySelector('[data-profile-close]');
    const avatarForm = document.querySelector('[data-avatar-form]');
    const avatarInput = document.querySelector('[data-avatar-input]');
    const avatarFileName = document.querySelector('[data-avatar-file-name]');
    const avatarStatus = document.querySelector('[data-avatar-status]');
    const passwordForm = document.querySelector('[data-password-form]');
    const passwordStatus = document.querySelector('[data-password-status]');
    const pairingForms = document.querySelectorAll('[data-pairing-form]');
    const avatarImages = document.querySelectorAll('[data-avatar-image]');
    const avatarFallbacks = document.querySelectorAll('[data-avatar-fallback]');
    const settingsPanel = document.querySelector('[data-settings-panel]');
    const settingsClose = document.querySelector('[data-settings-close]');
    const settingsForm = document.querySelector('[data-settings-form]');
    const settingsTheme = document.querySelector('[data-setting-theme]');
    const settingsSidebar = document.querySelector('[data-setting-sidebar]');
    const settingsStatus = document.querySelector('[data-settings-status]');
    const logoutButton = document.querySelector('[data-action="logout"]');
    const sourceDisabledNotice = document.querySelector('[data-popup-source-disabled]');
    const viewportWarning = document.querySelector('[data-viewport-warning]');
    const query = new URLSearchParams(window.location.search);
    const isPopupWindow = query.get('window') === 'floating' || query.get('chat_window') === 'floating';
    const maxMessageLength = Number(config.maxMessageLength || 4000);
    if (input) input.maxLength = maxMessageLength;

    let socket = null;
    let reconnectTimer = null;
    let reconnectAttempts = 0;
    let connectInProgress = false;
    let closedByUser = false;
    let gatewayHistoryLoaded = false;
    let lastNonMaximizedMode = 'floating';
    let popupWindow = null;
    let popupMonitor = null;
    let redirectingToAuth = false;
    let sourceModeBeforePopup = 'floating';
    const messages = new Map();
    const pendingOutbound = [];
    const inFlightOutbound = new Map();
    const pendingRuns = new Map();

    function isMobileViewport() {
        return window.matchMedia?.('(max-width: 640px)').matches === true;
    }

    function updateViewportWarning() {
        if (!viewportWarning) return;
        const tooSmallForDesktop = !isPopupWindow
            && !isMobileViewport()
            && (window.innerWidth < 760 || window.innerHeight < 520);
        viewportWarning.hidden = !tooSmallForDesktop;
        document.body.classList.toggle('viewport-too-small', tooSmallForDesktop);
    }

    function clearMaximizedBounds() {
        if (!panel) return;
        ['top', 'right', 'bottom', 'left', 'width', 'height'].forEach((property) => {
            panel.style.removeProperty(property);
        });
    }

    function updateMaximizedBounds() {
        if (!panel || panel.dataset.chatMode !== 'maximized' || isMobileViewport()) {
            clearMaximizedBounds();
            return;
        }

        const bounds = dashboardMain?.getBoundingClientRect();
        if (!bounds || bounds.width <= 0 || bounds.height <= 0) return;

        const margin = 8;
        panel.style.top = `${Math.max(0, bounds.top + margin)}px`;
        panel.style.left = `${Math.max(0, bounds.left + margin)}px`;
        panel.style.right = 'auto';
        panel.style.bottom = 'auto';
        panel.style.width = `${Math.max(0, bounds.width - (margin * 2))}px`;
        panel.style.height = `${Math.max(0, bounds.height - (margin * 2))}px`;
    }

    function redirectToAuth() {
        if (redirectingToAuth) return;
        redirectingToAuth = true;
        window.clearTimeout(reconnectTimer);
        closedByUser = true;
        socket?.close(1000, 'session expired');
        window.location.replace(`${basePath}/`);
    }

    async function checkSession() {
        if (redirectingToAuth) return false;
        try {
            const response = await fetch(`${basePath}/api.php?action=me`, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' },
            });
            const payload = await response.json();
            if (!response.ok || payload.authenticated !== true) {
                redirectToAuth();
                return false;
            }
            return true;
        } catch (_) {
            // A transient network failure must not log the user out. The next
            // heartbeat or the server-side dashboard guard will retry.
            return true;
        }
    }

    function setConnection(state, label) {
        if (connectionLabel) connectionLabel.textContent = label;
        if (connectionDot) {
            connectionDot.dataset.connected = state === 'connected' ? 'true' : 'false';
            connectionDot.dataset.error = state === 'error' ? 'true' : 'false';
        }
    }

    function setFormStatus(element, message, error = false) {
        if (!element) return;
        element.textContent = message;
        element.dataset.error = error ? 'true' : 'false';
    }

    async function readApiResponse(response) {
        const payload = await response.json().catch(() => ({}));
        if (!response.ok || payload.ok !== true) throw new Error(payload.error || 'No fue posible completar la operación.');
        return payload.data || {};
    }

    async function saveAvatar(event) {
        event.preventDefault();
        const file = avatarInput?.files?.[0];
        if (!file) return setFormStatus(avatarStatus, 'Selecciona una imagen.', true);
        if (file.size > 5 * 1024 * 1024) return setFormStatus(avatarStatus, 'La imagen supera el límite de 5 MB.', true);
        const formData = new FormData();
        formData.append('avatar', file);
        const button = avatarForm.querySelector('button[type="submit"]');
        button?.setAttribute('disabled', 'disabled');
        setFormStatus(avatarStatus, 'Guardando foto…');
        try {
            const data = await readApiResponse(await fetch(`${basePath}/api.php?action=profile/avatar`, { method: 'POST', credentials: 'same-origin', body: formData }));
            const version = encodeURIComponent(data.user?.avatar_version || Date.now());
            avatarImages.forEach((image) => { image.src = `${basePath}/avatar.php?v=${version}`; image.hidden = false; });
            avatarFallbacks.forEach((fallback) => { fallback.hidden = true; });
            setFormStatus(avatarStatus, 'Foto de perfil actualizada.');
            avatarForm.reset();
            if (avatarFileName) avatarFileName.textContent = 'Ningún archivo seleccionado';
        } catch (error) {
            setFormStatus(avatarStatus, error.message, true);
        } finally {
            button?.removeAttribute('disabled');
        }
    }

    async function changePassword(event) {
        event.preventDefault();
        const formData = new FormData(passwordForm);
        const payload = Object.fromEntries(formData.entries());
        const button = passwordForm.querySelector('button[type="submit"]');
        button?.setAttribute('disabled', 'disabled');
        setFormStatus(passwordStatus, 'Actualizando contraseña…');
        try {
            await readApiResponse(await fetch(`${basePath}/api.php?action=profile/password`, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(payload) }));
            passwordForm.reset();
            setFormStatus(passwordStatus, 'Contraseña actualizada. Las demás sesiones fueron cerradas.');
        } catch (error) {
            setFormStatus(passwordStatus, error.message, true);
        } finally {
            button?.removeAttribute('disabled');
        }
    }

    async function approvePairing(event) {
        event.preventDefault();
        const pairingForm = event.currentTarget;
        const status = pairingForm.querySelector('[data-pairing-status]');
        const button = pairingForm.querySelector('button[type="submit"]');
        const code = String(new FormData(pairingForm).get('code') || '').trim();
        button?.setAttribute('disabled', 'disabled');
        setFormStatus(status, 'Aprobando pairing…');
        try {
            await readApiResponse(await fetch(`${basePath}/api.php?action=channel/approve`, {
                method: 'POST', credentials: 'same-origin',
                headers: {'Content-Type': 'application/json', Accept: 'application/json'},
                body: JSON.stringify({channel: pairingForm.dataset.channel, code}),
            }));
            setFormStatus(status, 'Pairing completado. Tu canal ya está vinculado a tu agente.');
            pairingForm.querySelector('input')?.setAttribute('disabled', 'disabled');
            button?.remove();
            const label = document.querySelector(`[data-pairing-status-label][data-channel="${CSS.escape(pairingForm.dataset.channel)}"]`);
            if (label) label.textContent = 'Vinculado';
        } catch (error) {
            setFormStatus(status, error.message, true);
        } finally {
            button?.removeAttribute('disabled');
        }
    }

    function bindPairingForm(pairingForm) {
        pairingForm.addEventListener('submit', approvePairing);
    }

    function messageId(message) {
        return String(message.id || message.message_id || `local-${Date.now()}-${Math.random().toString(16).slice(2)}`);
    }

    function messageIdentity(message) {
        if (message?.role === 'user' && message?.request_id) return `request:${String(message.request_id)}`;
        if (message?.role === 'assistant' && message?.run_id) return `run:${String(message.run_id)}`;
        if (message?.request_id) return `request:${String(message.request_id)}`;
        if (message?.run_id) return `run:${String(message.run_id)}`;
        return `id:${messageId(message)}`;
    }

    function formatTime(value) {
        if (!value) return '';
        const date = new Date(String(value).replace(' ', 'T') + (String(value).includes('Z') ? '' : 'Z'));
        if (Number.isNaN(date.getTime())) return '';
        return new Intl.DateTimeFormat('es-MX', { hour: '2-digit', minute: '2-digit' }).format(date);
    }

    function createMessageElement(message) {
        const role = message.role === 'user' ? 'user' : message.role === 'system' ? 'system' : 'assistant';
        const article = document.createElement('article');
        article.className = `message message--${role}${message.status === 'pending' ? ' message--pending' : ''}`;
        article.dataset.messageId = messageId(message);

        const bubble = document.createElement('div');
        bubble.className = 'message__bubble';
        if (message.text) bubble.appendChild(document.createTextNode(String(message.text)));
        article.appendChild(bubble);

        const meta = document.createElement('span');
        meta.className = 'message__meta';
        meta.textContent = formatTime(message.created_at || message.createdAt);
        article.appendChild(meta);
        return article;
    }

    function syncWelcome() {
        const hasMessages = messages.size > 0;
        if (welcome) welcome.hidden = hasMessages;
        if (quickActions) quickActions.hidden = hasMessages;
    }

    function addMessage(message, replace = false) {
        const id = messageId(message);
        const existing = messages.get(id);
        if (existing && !replace) return existing;
        messages.set(id, { ...message, id });

        if (existing) {
            const element = messageList.querySelector(`[data-message-id="${CSS.escape(id)}"]`);
            if (element) {
                const bubble = element.querySelector('.message__bubble');
                if (bubble) {
                    const replacement = createMessageElement({ ...message, id });
                    bubble.replaceWith(replacement.querySelector('.message__bubble'));
                }
                if (message.status && message.status !== 'pending') element.classList.remove('message--pending');
                return element;
            }
        }

        const element = createMessageElement({ ...message, id });
        messageList.appendChild(element);
        syncWelcome();
        requestAnimationFrame(() => { document.querySelector('[data-chat-body]')?.scrollTo({ top: document.querySelector('[data-chat-body]').scrollHeight, behavior: 'smooth' }); });
        return element;
    }

    function renderHistory(history) {
        messageList.replaceChildren();
        messages.clear();
        history.filter((message) => message && typeof message === 'object').forEach((message) => addMessage(message, true));
        syncWelcome();
    }

    function reconcileHistory(history) {
        const gatewayMessages = history.filter((message) => message && typeof message === 'object');
        const gatewayIdentities = new Set(gatewayMessages.map(messageIdentity));
        const existingByIdentity = new Map([...messages.values()].map((message) => [messageIdentity(message), message]));
        const reconciled = gatewayMessages.map((message) => {
            const existing = existingByIdentity.get(messageIdentity(message));
            // Keep the live message id (the assistant message id comes from the
            // relay database) while replacing its content with the recovered
            // Gateway snapshot.
            return existing && existing.id !== message.id ? { ...message, id: existing.id } : message;
        });
        const localMessages = [...messages.values()].filter((message) => {
            const local = String(message.id).startsWith('local-')
                || message.status === 'pending'
                || message.status === 'streaming';
            return local && !gatewayIdentities.has(messageIdentity(message));
        });
        renderHistory([...reconciled, ...localMessages]);
    }

    function setTyping(isTyping) {
        if (typingIndicator) typingIndicator.hidden = !isTyping;
        if (isTyping) {
            const body = document.querySelector('[data-chat-body]');
            body?.scrollTo({ top: body.scrollHeight, behavior: 'smooth' });
        }
    }

    function sendRaw(payload) {
        if (payload?.type === 'message.create' && payload.request_id) {
            inFlightOutbound.set(String(payload.request_id), payload);
        }
        if (socket && socket.readyState === WebSocket.OPEN) {
            socket.send(JSON.stringify(payload));
            return true;
        }
        pendingOutbound.push(payload);
        return false;
    }

    function flushOutbound() {
        while (pendingOutbound.length > 0 && socket?.readyState === WebSocket.OPEN) {
            const payload = pendingOutbound.shift();
            socket.send(JSON.stringify(payload));
        }
    }

    function requeueInFlight() {
        const queued = new Set(pendingOutbound.map((payload) => String(payload.request_id || '')));
        inFlightOutbound.forEach((payload, requestId) => {
            if (!queued.has(String(requestId))) pendingOutbound.unshift(payload);
        });
    }

    function scheduleReconnect() {
        if (closedByUser) return;
        window.clearTimeout(reconnectTimer);
        const exponent = Math.min(reconnectAttempts++, 5);
        const delay = Math.min(30000, 1000 * (2 ** exponent)) + Math.floor(Math.random() * 500);
        reconnectTimer = window.setTimeout(connect, delay);
    }

    function connect() {
        if (!window.WebSocket || !config.webSocketUrl || connectInProgress || socket?.readyState === WebSocket.OPEN) return;
        connectInProgress = true;
        closedByUser = false;
        setConnection('connecting', 'Conectando…');
        try {
            socket = new WebSocket(config.webSocketUrl);
        } catch (error) {
            connectInProgress = false;
            setConnection('error', 'No se pudo conectar');
            scheduleReconnect();
            return;
        }

        socket.addEventListener('open', () => {
            connectInProgress = false;
            reconnectAttempts = 0;
            sendRaw({ type: 'connect', token: config.token });
        });
        socket.addEventListener('message', (event) => {
            let payload;
            try { payload = JSON.parse(event.data); } catch (_) { return; }
            handleServerMessage(payload);
        });
        socket.addEventListener('error', () => setConnection('error', 'Servicio no disponible'));
        socket.addEventListener('close', () => {
            connectInProgress = false;
            requeueInFlight();
            socket = null;
            // Allow the next connection to reconcile Gateway history and close
            // runs that completed while this browser connection was unavailable.
            gatewayHistoryLoaded = false;
            setTyping(false);
            setConnection('error', 'Desconectado');
            scheduleReconnect();
        });
    }

    function handleServerMessage(payload) {
        switch (payload.type) {
            case 'connected':
                setConnection('connected', 'Conectado');
                gatewayHistoryLoaded = false;
                flushOutbound();
                break;
            case 'history':
                if (Array.isArray(payload.messages) && (!gatewayHistoryLoaded || payload.reconcile === true)) {
                    gatewayHistoryLoaded = true;
                    reconcileHistory(payload.messages);
                }
                break;
            case 'gateway.reconnected':
                setConnection('connected', 'Conectado');
                break;
            case 'typing':
                setTyping(Boolean(payload.on));
                break;
            case 'message.accepted':
                if (payload.request_id) inFlightOutbound.delete(String(payload.request_id));
                if (payload.run_id && payload.assistant_message_id) pendingRuns.set(String(payload.run_id), String(payload.assistant_message_id));
                setTyping(true);
                break;
            case 'message.delta':
                addMessage({ id: payload.message_id || pendingRuns.get(String(payload.run_id)), run_id: payload.run_id, role: 'assistant', text: payload.text || '', status: 'streaming' }, true);
                break;
            case 'message.final':
                addMessage({ id: payload.message_id || pendingRuns.get(String(payload.run_id)), run_id: payload.run_id, role: 'assistant', text: payload.text || '', status: 'completed' }, true);
                pendingRuns.delete(String(payload.run_id));
                setTyping(false);
                break;
            case 'error':
                if (payload.request_id) {
                    inFlightOutbound.delete(String(payload.request_id));
                    for (let index = pendingOutbound.length - 1; index >= 0; index--) {
                        if (String(pendingOutbound[index].request_id || '') === String(payload.request_id)) pendingOutbound.splice(index, 1);
                    }
                }
                if (payload.request_id) {
                    const pending = messages.get(String(payload.request_id));
                    if (pending) addMessage({ ...pending, status: 'failed' }, true);
                }
                if (!payload.retryable || !messages.has('transport-unavailable')) {
                    addMessage({ id: payload.retryable ? 'transport-unavailable' : `system-${Date.now()}`, role: 'system', text: payload.message || 'No fue posible procesar el mensaje.' });
                }
                if (!payload.run_id) setTyping(false);
                break;
            default:
                break;
        }
    }

    async function submitMessage(rawContent) {
        const content = String(rawContent || '').trim();
        if (!content || content.length > maxMessageLength) return;
        if (input) input.value = '';
        resizeInput();
        const requestId = (window.crypto?.randomUUID ? window.crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`).replace(/[^A-Za-z0-9_-]/g, '').slice(0, 80);
        addMessage({ id: requestId, request_id: requestId, role: 'user', text: content, status: 'pending', createdAt: new Date().toISOString() }, true);
        sendRaw({ type: 'message.create', request_id: requestId, content });
        connect();
    }

    function resizeInput() {
        if (!input) return;
        input.style.height = 'auto';
        input.style.height = `${Math.min(input.scrollHeight, 90)}px`;
    }

    function updateModeControls(mode) {
        const maximized = mode === 'maximized';
        const embedded = mode === 'embedded';
        maximizeButton?.setAttribute('aria-pressed', maximized ? 'true' : 'false');
        const label = maximized ? 'Restaurar asistente de IA' : 'Maximizar asistente de IA';
        maximizeButton?.setAttribute('aria-label', label);
        maximizeButton?.setAttribute('title', label);
        embedButton?.setAttribute('aria-pressed', embedded ? 'true' : 'false');
        const embedLabel = embedded ? 'Volver al modo flotante' : 'Integrar asistente de IA';
        embedButton?.setAttribute('aria-label', embedLabel);
        embedButton?.setAttribute('title', embedLabel);
    }

    function setChatMode(mode) {
        const nextMode = ['floating', 'embedded', 'maximized'].includes(mode) ? mode : 'floating';
        panel.dataset.chatMode = nextMode;
        panel.dataset.mode = nextMode;
        if (nextMode !== 'maximized') lastNonMaximizedMode = nextMode;
        updateModeControls(nextMode);
        window.requestAnimationFrame(updateMaximizedBounds);
    }

    function showPanel(mode = 'floating') {
        panel.hidden = false;
        launcher.hidden = true;
        launcher.setAttribute('aria-expanded', 'true');
        setChatMode(mode);
        connect();
        window.setTimeout(() => input?.focus(), 80);
    }

    function hidePanel() {
        panel.hidden = true;
        launcher.hidden = false;
        launcher.setAttribute('aria-expanded', 'false');
        setChatMode('floating');
        closedByUser = true;
        window.clearTimeout(reconnectTimer);
        socket?.close(1000, 'panel closed');
    }

    function disableSourceWindow(popup) {
        if (isPopupWindow) return;

        sourceModeBeforePopup = panel.dataset.chatMode || 'floating';
        popupWindow = popup;
        document.body.classList.add('popup-source-disabled');
        if (sourceDisabledNotice) {
            sourceDisabledNotice.hidden = false;
            sourceDisabledNotice.setAttribute('aria-hidden', 'false');
        }
        panel.hidden = true;
        launcher.hidden = true;
        launcher.setAttribute('aria-expanded', 'false');
        panel.inert = true;
        launcher.inert = true;
        panel.setAttribute('inert', '');
        launcher.setAttribute('inert', '');
        panel.setAttribute('aria-hidden', 'true');
        launcher.setAttribute('aria-disabled', 'true');
        closedByUser = true;
        window.clearTimeout(reconnectTimer);
        socket?.close(1000, 'popup opened');
    }

    function enableSourceWindow() {
        if (isPopupWindow) return;

        window.clearInterval(popupMonitor);
        popupMonitor = null;
        popupWindow = null;
        document.body.classList.remove('popup-source-disabled');
        if (sourceDisabledNotice) {
            sourceDisabledNotice.hidden = true;
            sourceDisabledNotice.setAttribute('aria-hidden', 'true');
        }
        panel.inert = false;
        launcher.inert = false;
        panel.removeAttribute('inert');
        launcher.removeAttribute('inert');
        panel.removeAttribute('aria-hidden');
        launcher.removeAttribute('aria-disabled');
        showPanel(sourceModeBeforePopup);
    }

    function monitorPopup(popup) {
        window.clearInterval(popupMonitor);
        popupMonitor = window.setInterval(() => {
            let closed = false;
            try { closed = popup.closed; } catch (_) { closed = true; }
            if (closed) enableSourceWindow();
        }, 300);
    }

    function minimizePanel() {
        if (isPopupWindow && window.opener && !window.opener.closed) {
            window.opener.postMessage({ type: 'webchat:return-floating' }, window.location.origin);
            window.opener.focus();
            window.close();
            return;
        }

        hidePanel();
    }

    function closePanel() {
        if (isPopupWindow) {
            window.close();
            return;
        }
        hidePanel();
    }

    function setSidebarCollapsed(collapsed) {
        if (!dashboardShell) return;
        dashboardShell.dataset.sidebarCollapsed = collapsed ? 'true' : 'false';
        sidebarToggle?.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        sidebarToggle?.setAttribute('aria-label', collapsed ? 'Expandir menú' : 'Colapsar menú');
        try { window.localStorage.setItem('iaamx-sidebar-collapsed', collapsed ? '1' : '0'); } catch (_) { /* storage is optional */ }
        window.requestAnimationFrame(updateMaximizedBounds);
    }

    function setActiveSidebarItem(item) {
        document.querySelectorAll('[data-sidebar-assistant], [data-sidebar-profile], [data-sidebar-settings]').forEach((link) => {
            link.classList.toggle('is-active', link === item);
        });
    }

    function hideProfile() {
        if (!profilePanel) return;
        profilePanel.hidden = true;
        sidebarProfile?.setAttribute('aria-expanded', 'false');
    }

    function hideSettings() {
        if (!settingsPanel) return;
        settingsPanel.hidden = true;
        sidebarSettings?.setAttribute('aria-expanded', 'false');
    }

    function showProfile() {
        if (!profilePanel) return;
        hidePanel();
        hideSettings();
        profilePanel.hidden = false;
        sidebarProfile?.setAttribute('aria-expanded', 'true');
        setActiveSidebarItem(sidebarProfile);
    }

    const systemThemeMedia = window.matchMedia?.('(prefers-color-scheme: dark)');

    function applyTheme(theme) {
        const preference = ['light', 'dark'].includes(theme) ? theme : 'system';
        const effectiveTheme = preference === 'system'
            ? (systemThemeMedia?.matches ? 'dark' : 'light')
            : preference;
        document.documentElement.dataset.theme = effectiveTheme;
        document.documentElement.dataset.themePreference = preference;
        document.body.dataset.theme = effectiveTheme;
        document.body.dataset.themePreference = preference;
        document.documentElement.style.colorScheme = effectiveTheme;
        document.querySelector('meta[name="color-scheme"]')?.setAttribute('content', effectiveTheme);
    }

    systemThemeMedia?.addEventListener?.('change', () => {
        const preference = document.documentElement.dataset.themePreference || 'system';
        if (preference === 'system') applyTheme('system');
    });

    function storedSettings() {
        try {
            return JSON.parse(window.localStorage.getItem('iaamx-settings') || '{}');
        } catch (_) {
            return {};
        }
    }

    function applyStoredSettings() {
        const settings = storedSettings();
        applyTheme(settings.theme || 'system');
        if (settingsSidebar) settingsSidebar.value = settings.sidebar === 'collapsed' ? 'collapsed' : 'expanded';
        if (settingsTheme) settingsTheme.value = ['system', 'light', 'dark'].includes(settings.theme) ? settings.theme : 'system';
        if (settings.sidebar === 'collapsed') setSidebarCollapsed(true);
    }

    function showSettings() {
        if (!settingsPanel) return;
        hidePanel();
        hideProfile();
        applyStoredSettings();
        settingsPanel.hidden = false;
        sidebarSettings?.setAttribute('aria-expanded', 'true');
        setActiveSidebarItem(sidebarSettings);
    }

    function toggleMaximized() {
        setChatMode(panel.dataset.chatMode === 'maximized' ? lastNonMaximizedMode : 'maximized');
    }

    function toggleEmbedded() {
        setChatMode(panel.dataset.chatMode === 'embedded' ? 'floating' : 'embedded');
    }

    function openPopout() {
        const popupUrl = new URL(window.location.href);
        popupUrl.searchParams.delete('chat_window');
        popupUrl.searchParams.set('window', 'floating');
        const bounds = panel.getBoundingClientRect();
        const left = Math.max(0, Math.round(window.screenX + bounds.left));
        const top = Math.max(0, Math.round(window.screenY + bounds.top));
        const popup = window.open(
            popupUrl.toString(),
            'iaamxFloatingChat',
            `popup=yes,width=390,height=620,left=${left},top=${top},resizable=yes,scrollbars=no`,
        );
        if (popup) {
            popup.focus();
            disableSourceWindow(popup);
            monitorPopup(popup);
            return;
        }
        setChatMode('floating');
    }

    window.addEventListener('message', (event) => {
        if (event.origin !== window.location.origin || event.data?.type !== 'webchat:return-floating') return;
        enableSourceWindow();
    });

    async function logout() {
        logoutButton?.setAttribute('disabled', 'disabled');
        try {
            await fetch(`${basePath}/api.php?action=logout`, { method: 'POST', credentials: 'same-origin' });
        } finally {
            window.location.href = `${basePath}/`;
        }
    }

    launcher?.addEventListener('click', () => showPanel());
    sidebarToggle?.addEventListener('click', () => setSidebarCollapsed(dashboardShell?.dataset.sidebarCollapsed !== 'true'));
    window.addEventListener('resize', updateMaximizedBounds);
    window.addEventListener('resize', updateViewportWarning);
    if (dashboardMain && 'ResizeObserver' in window) {
        const dashboardMainResizeObserver = new ResizeObserver(() => {
            window.requestAnimationFrame(updateMaximizedBounds);
        });
        dashboardMainResizeObserver.observe(dashboardMain);
    }
    sidebarAssistant?.addEventListener('click', (event) => { event.preventDefault(); hideProfile(); setActiveSidebarItem(sidebarAssistant); showPanel(); });
    sidebarProfile?.addEventListener('click', showProfile);
    profileClose?.addEventListener('click', () => { hideProfile(); setActiveSidebarItem(sidebarAssistant); });
    sidebarSettings?.addEventListener('click', showSettings);
    settingsClose?.addEventListener('click', () => { hideSettings(); setActiveSidebarItem(sidebarAssistant); });
    settingsForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        const settings = { theme: settingsTheme?.value || 'system', sidebar: settingsSidebar?.value || 'expanded' };
        try { window.localStorage.setItem('iaamx-settings', JSON.stringify(settings)); } catch (_) { /* storage is optional */ }
        applyTheme(settings.theme);
        setSidebarCollapsed(settings.sidebar === 'collapsed');
        if (settingsStatus) settingsStatus.textContent = 'Ajustes guardados en este navegador.';
    });
    avatarForm?.addEventListener('submit', saveAvatar);
    avatarInput?.addEventListener('change', () => {
        if (avatarFileName) avatarFileName.textContent = avatarInput.files?.[0]?.name || 'Ningún archivo seleccionado';
    });
    passwordForm?.addEventListener('submit', changePassword);
    pairingForms.forEach(bindPairingForm);
    document.querySelectorAll('[data-setting-help]').forEach((button) => button.addEventListener('click', () => {
        const help = document.getElementById(button.dataset.settingHelp || '');
        if (!help) return;
        const expanded = help.hidden;
        help.hidden = !expanded;
        button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }));
    embedButton?.addEventListener('click', toggleEmbedded);
    maximizeButton?.addEventListener('click', toggleMaximized);
    popoutButton?.addEventListener('click', openPopout);
    minimizeButton?.addEventListener('click', minimizePanel);
    closeButton?.addEventListener('click', closePanel);
    logoutButton?.addEventListener('click', logout);
    document.querySelectorAll('[data-prompt]').forEach((button) => button.addEventListener('click', () => submitMessage(button.dataset.prompt || '')));
    form?.addEventListener('submit', (event) => { event.preventDefault(); submitMessage(input?.value || ''); });
    input?.addEventListener('input', resizeInput);
    input?.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && !event.shiftKey) {
            event.preventDefault();
            form?.requestSubmit();
        }
    });

    renderHistory(initialHistory);
    let sidebarCollapsed = false;
    try { sidebarCollapsed = window.localStorage.getItem('iaamx-sidebar-collapsed') === '1'; } catch (_) { /* storage is optional */ }
    setSidebarCollapsed(sidebarCollapsed);
    applyStoredSettings();
    setConnection('idle', 'Listo para ayudarte');
    setChatMode('floating');
    updateViewportWarning();
    void checkSession();
    connect();
    window.addEventListener('pageshow', () => { void checkSession(); });

    if (isPopupWindow) {
        document.body.classList.add('popup-window');
        showPanel();
    }
})();
