const DEFAULT_RESOLVE_URL = "http://127.0.0.1:8082/channel.php";

function asText(value) {
  return typeof value === "string" || typeof value === "number" ? String(value).trim() : "";
}

function normalizeChannel(value) {
  const channel = asText(value).toLowerCase();
  return channel === "telegram" || channel === "whatsapp" ? channel : null;
}

function normalizeIdentity(value) {
  const identity = asText(value);
  return identity.includes(":") ? identity.toLowerCase() : identity;
}

function onboardingReply(url, channel, action) {
  if (action === "continue_pairing") {
    return {
      text: `Tu solicitud de ${channel} ya está pendiente. Continúa el proceso en WebChat:\n${url}`
    };
  }
  return {
    text: `Para usar este asistente primero crea y verifica tu cuenta en WebChat. Después podrás aprobar el pairing de ${channel}:\n${url}`
  };
}

export default {
  id: "webchat-identity",
  name: "WebChat identity routing",
  description: "Blocks unknown Telegram and WhatsApp identities until they register in WebChat.",
  kind: "feature",
  register(api) {
    const config = api.pluginConfig ?? {};
    const resolveUrl = asText(config.resolveUrl) || process.env.WEBCHAT_CHANNEL_RESOLVE_URL || DEFAULT_RESOLVE_URL;
    const resolveSecret = asText(config.resolveSecret) || process.env.WEBCHAT_CHANNEL_RESOLVE_SECRET;
    const publicBaseUrl = asText(config.publicBaseUrl) || process.env.WEBCHAT_PUBLIC_BASE_URL || "http://127.0.0.1:8080/";
    const pairingEventUrl = asText(config.pairingEventUrl) || process.env.WEBCHAT_PAIRING_EVENT_URL;
    const pairingEventSecret = asText(config.pairingEventSecret) || process.env.WEBCHAT_PAIRING_EVENT_SECRET;
    const adminIdentities = new Set((Array.isArray(config.adminIdentities) ? config.adminIdentities : ["telegram:1557679246"]).map(normalizeIdentity));
    const timeoutMs = Number.isInteger(config.timeoutMs) ? config.timeoutMs : 3000;
    const supportEmail = asText(config.supportEmail) || "xochitl.bot.iaamx@gmail.com";
    const pairingRequests = new Map();

    function pairingKey(channel, identity) {
      return `${normalizeChannel(channel) || ""}:${asText(identity)}`;
    }

    async function notifyPairingEvent(event, channelValue, identityValue) {
      const channel = normalizeChannel(channelValue);
      const identity = asText(identityValue);
      if (!pairingEventUrl || !pairingEventSecret || !channel || !identity) return;
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), Math.min(timeoutMs, 1500));
      try {
        await fetch(pairingEventUrl, {
          method: "POST",
          headers: {
            accept: "application/json",
            "content-type": "application/json",
            "x-webchat-pairing-secret": pairingEventSecret
          },
          body: JSON.stringify({
            event,
            channel,
            account_id: "default",
            external_identity: identity,
            request_id: identity
          }),
          signal: controller.signal
        });
      } catch {
        // The native pairing remains authoritative; WebChat keeps its CLI
        // fallback when event delivery is temporarily unavailable.
      } finally {
        clearTimeout(timer);
      }
    }

    async function pairingInstructions(channelValue, identityValue, code) {
      const channel = normalizeChannel(channelValue);
      const identity = asText(identityValue);
      if (!channel || !identity) return "";
      const url = new URL(resolveUrl);
      url.searchParams.set("channel", channel);
      url.searchParams.set("external_identity", identity);
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), timeoutMs);
      try {
        const headers = {accept: "application/json"};
        if (resolveSecret) headers["x-webchat-resolve-secret"] = resolveSecret;
        const response = await fetch(url, {headers, signal: controller.signal});
        const result = response.ok ? await response.json() : {};
        const webchatUrl = asText(result?.webchat_url) || publicBaseUrl;
        return `\n\nCompleta tu pairing en WebChat:\nCódigo: ${asText(code)}\nURL: ${webchatUrl}\nSoporte: ${supportEmail}`;
      } catch {
        return `\n\nTu código de pairing es: ${asText(code)}\nCompleta el proceso en WebChat y, si necesitas ayuda, escribe a ${supportEmail}.`;
      } finally {
        clearTimeout(timer);
      }
    }

    async function resolveOnboarding(channelValue, identityValue) {
      const channel = normalizeChannel(channelValue);
      const externalIdentity = asText(identityValue);
      if (!channel || !externalIdentity) return;
      if (adminIdentities.has(normalizeIdentity(`${channel}:${externalIdentity}`))) return;

      const url = new URL(resolveUrl);
      url.searchParams.set("channel", channel);
      url.searchParams.set("external_identity", externalIdentity);

      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), timeoutMs);
      try {
        const headers = {accept: "application/json"};
        if (resolveSecret) headers["x-webchat-resolve-secret"] = resolveSecret;
        const response = await fetch(url, {headers, signal: controller.signal});
        if (!response.ok) {
          return {text: "No puedo validar tu cuenta en este momento. Intenta de nuevo más tarde."};
        }
        const result = await response.json();
        if (result?.registered === true && result?.action === "route_to_agent") return;
        // A reserved identity must reach OpenClaw's native dmPolicy=pairing
        // flow so it receives a code. WebChat approves that code later and
        // creates the agent peer binding; this hook only owns first contact.
        if (result?.action === "continue_pairing") return;

        const registrationUrl = asText(result?.webchat_url) || process.env.WEBCHAT_PUBLIC_BASE_URL || "http://127.0.0.1:8080/";
        return {text: onboardingReply(registrationUrl, channel, asText(result?.action)).text};
      } catch {
        return {text: "No puedo validar tu cuenta en este momento. Intenta de nuevo más tarde."};
      } finally {
        clearTimeout(timer);
      }
    }

    // Claim unregistered identities before OpenClaw evaluates the channel's
    // native dmPolicy. This keeps the registration message exclusive: the
    // native pairing reply must only be allowed after the resolver returns
    // continue_pairing.
    api.on("before_dispatch", async (event, context) => {
      const channel = event.channel ?? context.channelId;
      const externalIdentity = event.senderId ?? context.senderId;
      if (event.isGroup === true) return;
      const result = await resolveOnboarding(channel, externalIdentity);
      if (!result) return;
      return {handled: true, text: result.text};
    }, {priority: 100});

    // Fallback for runtimes that do not expose the early dispatch hook to the
    // channel adapter. Normal Gateway Telegram/WhatsApp traffic is claimed by
    // before_dispatch above, so these hooks do not produce a second message.
    api.on("before_agent_reply", async (_event, context) => {
      const channel = context.channel ?? context.messageProvider;
      const externalIdentity = context.senderId
        ?? context.senderExternalId
        ?? context.channelContext?.sender?.id
        ?? context.chatId
        ?? context.channelContext?.chat?.id;
      if (context.channelContext?.isGroup === true) return;
      const result = await resolveOnboarding(channel, externalIdentity);
      if (!result) return;
      return {handled: true, reply: {text: result.text}};
    }, {priority: 100});

    // Telegram direct chats may use message-tool-only delivery. In that mode
    // the agent-reply hook is not the owning delivery path, so claim the
    // dispatch and enqueue the onboarding text through the channel dispatcher.
    api.on("reply_dispatch", async (event, context) => {
      const source = event.ctx ?? {};
      const channel = source.Provider ?? source.provider;
      const rawIdentity = source.SenderId
        ?? source.senderId
        ?? source.From
        ?? source.from;
      if (source.ChatType === "group" || source.chatType === "group") return;
      const identityText = asText(rawIdentity);
      const channelPrefix = `${asText(channel)}:`;
      const externalIdentity = identityText.startsWith(channelPrefix)
        ? identityText.slice(channelPrefix.length)
        : identityText;
      const result = await resolveOnboarding(channel, externalIdentity);
      if (!result) return;

      const queued = context.dispatcher.sendFinalReply({text: result.text});
      return {
        handled: true,
        queuedFinal: queued,
        counts: context.dispatcher.getQueuedCounts()
      };
    }, {priority: 100});

    // OpenClaw emits the native code through this hook. Keep it associated
    // with the exact sender so the normal pairing reply can be enriched with
    // WebChat's URL and support contact without exposing another request's code.
    api.on("channel_pairing_requested", (event) => {
      const channel = normalizeChannel(event.channel);
      const identity = asText(event.senderId);
      if (!channel || !identity || adminIdentities.has(normalizeIdentity(`${channel}:${identity}`))) return;
      pairingRequests.set(pairingKey(channel, identity), asText(event.code));
      void notifyPairingEvent("pairing_created", channel, identity);
      setTimeout(() => pairingRequests.delete(pairingKey(channel, identity)), 15 * 60 * 1000);
    });

    // The native adapter already sends the pairing code. Append the WebChat
    // destination and support email to that same outbound message.
    api.on("message_sending", async (event, context) => {
      const channel = normalizeChannel(context.channelId);
      if (!channel || context.isGroup === true) return;
      const target = asText(event.to).replace(new RegExp(`^${channel}:`, "i"), "");
      const code = pairingRequests.get(pairingKey(channel, target));
      // Only enrich the native pairing message itself. If the native adapter
      // did not expose that message through this hook, do not attach its
      // cached code to a later agent reply (for example, a stale greeting).
      if (!code || !asText(event.content).includes(code)) return;
      pairingRequests.delete(pairingKey(channel, target));
      return {content: `${event.content}${await pairingInstructions(channel, target, code)}`};
    }, {priority: 100});
  }
};
