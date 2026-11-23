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
      const identity = asText(identityValue);
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

    // The native adapter already sends the pairing code. Append the WebChat
    // destination and support email to that same outbound message.
    api.on("message_sending", async (event, context) => {
      const channel = normalizeChannel(context.channelId);
      if (!channel || context.isGroup === true) return;
      const target = asText(event.to).replace(new RegExp(`^${channel}:`, "i"), "");
      const key = pairingKey(channel, target);
      const destination = await (pairingEligibility.get(key) ?? Promise.resolve(null));
      const code = pairingRequests.get(key);
      // Only enrich the native pairing message itself. If the native adapter
      // did not expose that message through this hook, do not attach its
      // cached code to a later agent reply (for example, a stale greeting).
      if (!destination || !code || !asText(event.content).includes(code)) return;
      pairingRequests.delete(key);
      pairingEligibility.delete(key);
      return {content: `${event.content}\n\nCompleta tu pairing en WebChat:\nCódigo: ${code}\nURL: ${destination.webchatUrl}\nSoporte: ${supportEmail}`};
    }, {priority: 100});
  }
};
