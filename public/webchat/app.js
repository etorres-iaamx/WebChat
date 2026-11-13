(() => {
    'use strict';

    const config = window.IAAMX_WEBCHAT || {};
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
    const sidebarToggle = document.querySelector('[data-sidebar-toggle]');
    const sidebarAssistant = document.querySelector('[data-sidebar-assistant]');
    const sidebarProfile = document.querySelector('[data-sidebar-profile]');
    const sidebarSettings = document.querySelector('[data-sidebar-settings]');
    const profilePanel = document.querySelector('[data-profile-panel]');
    const profileClose = document.querySelector('[data-profile-close]');
    const settingsPanel = document.querySelector('[data-settings-panel]');
    const settingsClose = document.querySelector('[data-settings-close]');
    const settingsForm = document.querySelector('[data-settings-form]');
    const settingsTheme = document.querySelector('[data-setting-theme]');
    const settingsSidebar = document.querySelector('[data-setting-sidebar]');
    const settingsAssistantMode = document.querySelector('[data-setting-assistant-mode]');
    const settingsStatus = document.querySelector('[data-settings-status]');
    const logoutButton = document.querySelector('[data-action="logout"]');
    const sourceDisabledNotice = document.querySelector('[data-popup-source-disabled]');
    const query = new URLSearchParams(window.location.search);
    const isPopupWindow = query.get('window') === 'floating' || query.get('chat_window') === 'floating';
    const maxMessageLength = Number(config.maxMessageLength || 4000);
    if (input) input.maxLength = maxMessageLength;

    let socket = null;
    let reconnectTimer = null;
    let connectInProgress = false;
    let closedByUser = false;
    let gatewayHistoryLoaded = false;
    let lastNonMaximizedMode = 'floating';
    let popupWindow = null;
    let popupMonitor = null;
    let sourceModeBeforePopup = 'floating';
    const messages = new Map();
    const pendingOutbound = [];
    const pendingRuns = new Map();

    function setConnection(state, label) {
        if (connectionLabel) connectionLabel.textContent = label;
        if (connectionDot) {
            connectionDot.dataset.connected = state === 'connected' ? 'true' : 'false';
            connectionDot.dataset.error = state === 'error' ? 'true' : 'false';
        }
    }

    function messageId(message) {
        return String(message.id || message.message_id || `local-${Date.now()}-${Math.random().toString(16).slice(2)}`);
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
        bubble.textContent = String(message.text || '');
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
                if (bubble) bubble.textContent = String(message.text || '');
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

    function setTyping(isTyping) {
        if (typingIndicator) typingIndicator.hidden = !isTyping;
        if (isTyping) {
            const body = document.querySelector('[data-chat-body]');
            body?.scrollTo({ top: body.scrollHeight, behavior: 'smooth' });
        }
    }

    function sendRaw(payload) {
        if (socket && socket.readyState === WebSocket.OPEN) {
            socket.send(JSON.stringify(payload));
            return true;
        }
        pendingOutbound.push(payload);
        return false;
    }

    function flushOutbound() {
        while (pendingOutbound.length > 0 && socket?.readyState === WebSocket.OPEN) {
            socket.send(JSON.stringify(pendingOutbound.shift()));
        }
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
            return;
        }

        socket.addEventListener('open', () => {
            connectInProgress = false;
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
            socket = null;
            setTyping(false);
            setConnection('error', 'Desconectado');
            if (!closedByUser && !panel.hidden) {
                window.clearTimeout(reconnectTimer);
                reconnectTimer = window.setTimeout(connect, 2500);
            }
        });
    }

    function handleServerMessage(payload) {
        switch (payload.type) {
            case 'connected':
                setConnection('connected', 'Conectado');
                flushOutbound();
                break;
            case 'history':
                if (Array.isArray(payload.messages) && !gatewayHistoryLoaded) {
                    gatewayHistoryLoaded = true;
                    const localMessages = [...messages.values()].filter((message) => String(message.id).startsWith('local-') || message.status === 'pending');
                    renderHistory([...payload.messages, ...localMessages]);
                }
                break;
            case 'typing':
                setTyping(Boolean(payload.on));
                break;
            case 'message.accepted':
                if (payload.run_id && payload.assistant_message_id) pendingRuns.set(String(payload.run_id), String(payload.assistant_message_id));
                setTyping(true);
                break;
            case 'message.delta':
                addMessage({ id: payload.message_id || pendingRuns.get(String(payload.run_id)), role: 'assistant', text: payload.text || '', status: 'streaming' }, true);
                break;
            case 'message.final':
                addMessage({ id: payload.message_id || pendingRuns.get(String(payload.run_id)), role: 'assistant', text: payload.text || '', status: 'completed' }, true);
                pendingRuns.delete(String(payload.run_id));
                setTyping(false);
                break;
            case 'error':
                if (payload.request_id) {
                    const pending = messages.get(String(payload.request_id));
                    if (pending) addMessage({ ...pending, status: 'failed' }, true);
                }
                addMessage({ id: `system-${Date.now()}`, role: 'system', text: payload.message || 'No fue posible procesar el mensaje.' });
                if (!payload.run_id) setTyping(false);
                break;
            default:
                break;
        }
    }

    function submitMessage(rawContent) {
        const content = String(rawContent || '').trim();
        if (!content || content.length > maxMessageLength) return;
        if (input) input.value = '';
        resizeInput();
        const requestId = (window.crypto?.randomUUID ? window.crypto.randomUUID() : `${Date.now()}-${Math.random().toString(16).slice(2)}`).replace(/[^A-Za-z0-9_-]/g, '').slice(0, 80);
        addMessage({ id: requestId, role: 'user', text: content, status: 'pending', createdAt: new Date().toISOString() }, true);
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

    function applyTheme(theme) {
        document.body.dataset.theme = theme === 'dark' || theme === 'light' ? theme : 'system';
    }

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
        if (settingsAssistantMode) settingsAssistantMode.value = settings.assistantMode === 'embedded' ? 'embedded' : 'floating';
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
            await fetch('../api.php?action=logout', { method: 'POST', credentials: 'same-origin' });
        } finally {
            window.location.href = '../index.php';
        }
    }

    launcher?.addEventListener('click', () => showPanel(storedSettings().assistantMode === 'embedded' ? 'embedded' : 'floating'));
    sidebarToggle?.addEventListener('click', () => setSidebarCollapsed(dashboardShell?.dataset.sidebarCollapsed !== 'true'));
    sidebarAssistant?.addEventListener('click', (event) => { event.preventDefault(); hideProfile(); setActiveSidebarItem(sidebarAssistant); showPanel(); });
    sidebarProfile?.addEventListener('click', showProfile);
    profileClose?.addEventListener('click', () => { hideProfile(); setActiveSidebarItem(sidebarAssistant); });
    sidebarSettings?.addEventListener('click', showSettings);
    settingsClose?.addEventListener('click', () => { hideSettings(); setActiveSidebarItem(sidebarAssistant); });
    settingsForm?.addEventListener('submit', (event) => {
        event.preventDefault();
        const settings = { theme: settingsTheme?.value || 'system', sidebar: settingsSidebar?.value || 'expanded', assistantMode: settingsAssistantMode?.value || 'floating' };
        try { window.localStorage.setItem('iaamx-settings', JSON.stringify(settings)); } catch (_) { /* storage is optional */ }
        applyTheme(settings.theme);
        setSidebarCollapsed(settings.sidebar === 'collapsed');
        if (settingsStatus) settingsStatus.textContent = 'Ajustes guardados en este navegador.';
    });
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

    if (isPopupWindow) {
        document.body.classList.add('popup-window');
        showPanel();
    }
})();
