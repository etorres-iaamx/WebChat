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

function normalizeChannelIdentity(channel, value) {
  let identity = asText(value).replace(new RegExp(`^${channel}:`, "i"), "");
  identity = identity.split("@", 1)[0];
  if (channel === "whatsapp" && /^\d{10,15}$/.test(identity)) {
    return `+${identity}`;
  }
  return identity;
}

export default {
  id: "webchat-identity",
  name: "WebChat identity routing",
  description: "Adds the WebChat onboarding link and support contact to native channel pairing replies.",
  kind: "feature",
  register(api) {
    const config = api.pluginConfig ?? {};
    const resolveUrl = asText(config.resolveUrl) || process.env.WEBCHAT_CHANNEL_RESOLVE_URL || DEFAULT_RESOLVE_URL;
    const resolveSecret = asText(config.resolveSecret) || process.env.WEBCHAT_CHANNEL_RESOLVE_SECRET;
    const publicBaseUrl = asText(config.publicBaseUrl) || process.env.WEBCHAT_PUBLIC_BASE_URL || "http://127.0.0.1:8080/";
    const adminIdentities = new Set((Array.isArray(config.adminIdentities) ? config.adminIdentities : ["telegram:1557679246"]).map(normalizeIdentity));
    const timeoutMs = Number.isInteger(config.timeoutMs) ? config.timeoutMs : 3000;
    const supportEmail = asText(config.supportEmail) || "xochitl.bot.iaamx@gmail.com";
    const pairingRequests = new Map();
    const pairingEligibility = new Map();

    function fallbackWebchatUrl() {
      const base = publicBaseUrl.replace(/\/+$/, "");
      return base.endsWith("/webchat") ? `${base}/` : `${base}/webchat/`;
    }

    function pairingKey(channel, identity) {
      const normalizedChannel = normalizeChannel(channel);
      return `${normalizedChannel || ""}:${normalizeChannelIdentity(normalizedChannel || "", identity)}`;
    }

    async function pairingDestination(channelValue, identityValue) {
      const channel = normalizeChannel(channelValue);
      const identity = normalizeChannelIdentity(channel || "", asText(identityValue));
      if (!channel || !identity) return null;
      const fallback = fallbackWebchatUrl();
      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), timeoutMs);
      try {
        if (!resolveUrl) return {webchatUrl: fallback};
        const url = new URL(resolveUrl);
        url.searchParams.set("channel", channel);
        url.searchParams.set("external_identity", identity);
        const headers = {accept: "application/json"};
        if (resolveSecret) headers["x-webchat-resolve-secret"] = resolveSecret;
        const response = await fetch(url, {headers, signal: controller.signal});
        if (!response.ok) return {webchatUrl: fallback};
        const result = await response.json();
        // A native pairing event is authoritative. Even if the resolver still
        // sees a stale/active agent route, the sender must receive the complete
        // pairing instructions and be able to sign in at WebChat.
        const webchatUrl = asText(result?.webchat_url) || fallback;
        return webchatUrl ? {webchatUrl} : null;
      } catch {
        // A resolver outage must not remove the URL from a valid native
        // pairing. The public WebChat URL is the safe, agent-independent
        // fallback for this message.
        return {webchatUrl: fallback};
      } finally {
        clearTimeout(timer);
      }
    }

    // OpenClaw emits the native code through this hook. Keep it associated
    // with the exact sender so the normal pairing reply can be enriched with
    // WebChat's URL and support contact without exposing another request's code.
    api.on("channel_pairing_requested", (event) => {
      const channel = normalizeChannel(event.channel);
      const identity = asText(event.senderId);
      if (!channel || !identity || adminIdentities.has(normalizeIdentity(`${channel}:${identity}`))) return;
      const key = pairingKey(channel, identity);
      const destination = pairingDestination(channel, identity);
      // Store the code synchronously. OpenClaw issues the hook without awaiting
      // it, then sends the native pairing reply immediately.
      pairingRequests.set(key, asText(event.code));
      pairingEligibility.set(key, destination);
      setTimeout(() => {
        pairingRequests.delete(key);
        pairingEligibility.delete(key);
      }, 15 * 60 * 1000);
    });

    async function enrichPairingPayload(channel, targets, content) {
      const normalizedChannel = normalizeChannel(channel);
      if (!normalizedChannel) return null;
      const candidates = Array.isArray(targets) ? targets : [targets];
      for (const target of candidates) {
        const normalizedTarget = asText(target).replace(new RegExp(`^${normalizedChannel}:`, "i"), "");
        if (!normalizedTarget) continue;
        const key = pairingKey(normalizedChannel, normalizedTarget);
        const code = pairingRequests.get(key);
        const destination = pairingEligibility.get(key);
        // Only alter the exact native pairing message. Never append a cached
        // code to later agent replies or messages to another sender.
        if (!destination || !code || !asText(content).includes(code)) continue;
        const resolvedDestination = await destination;
        if (!resolvedDestination) continue;
        return {
          key,
          content: `${content}\n\nCompleta tu pairing en WebChat:\nCódigo: ${code}\nURL: ${resolvedDestination.webchatUrl}\nSoporte: ${supportEmail}`,
        };
      }

      // WhatsApp delivery contexts do not always expose a sender/conversation
      // field. The exact native code is unique and authoritative for routing
      // the already-issued pairing message.
      for (const [key, code] of pairingRequests.entries()) {
        if (!key.startsWith(`${normalizedChannel}:`)) continue;
        if (!asText(content).includes(code)) continue;
        const destination = pairingEligibility.get(key);
        if (!destination) continue;
        const resolvedDestination = await destination;
        if (!resolvedDestination) continue;
        return {
          key,
          content: `${content}\n\nCompleta tu pairing en WebChat:\nCódigo: ${code}\nURL: ${resolvedDestination.webchatUrl}\nSoporte: ${supportEmail}`,
        };
      }
      return null;
    }

    function consumePairing(key) {
      pairingRequests.delete(key);
      pairingEligibility.delete(key);
    }

    // Modern payload hook used by the outbound dispatcher.
    api.on("reply_payload_sending", async (event, context) => {
      const channel = normalizeChannel(event.channel ?? context.channelId);
      if (!channel || context.isGroup === true) return;
      const content = asText(event.payload?.text ?? event.payload?.content);
      if (!content) return;
      const targets = [context.to, context.conversationId, context.senderId, context.sender_id];
      const enriched = await enrichPairingPayload(channel, targets, content);
      if (!enriched) return;
      consumePairing(enriched.key);
      return {payload: {...event.payload, text: enriched.content, content: enriched.content}};
    }, {priority: 100});

    // Legacy dispatcher hook retained for channel/plugin compatibility.
    api.on("message_sending", async (event, context) => {
      const channel = normalizeChannel(context.channelId);
      if (!channel || context.isGroup === true) return;
      const targets = [event.to, event.conversationId, event.senderId, event.sender_id];
      const enriched = await enrichPairingPayload(channel, targets, event.content);
      if (!enriched) return;
      consumePairing(enriched.key);
      return {content: enriched.content};
    }, {priority: 100});
  }
};
