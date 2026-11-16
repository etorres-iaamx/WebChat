const DEFAULT_RESOLVE_URL = "http://127.0.0.1:8080/channel.php";

function asText(value) {
  return typeof value === "string" ? value.trim() : "";
}

function normalizeChannel(value) {
  const channel = asText(value).toLowerCase();
  return channel === "telegram" || channel === "whatsapp" ? channel : null;
}

function registrationReply(url) {
  return {
    text: `Para usar este asistente primero crea y verifica tu cuenta en WebChat:\n${url}`
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
    const timeoutMs = Number.isInteger(config.timeoutMs) ? config.timeoutMs : 3000;

    api.on("inbound_claim", async (event) => {
      const channel = normalizeChannel(event.channel);
      const externalIdentity = asText(event.senderId);
      if (!channel || !externalIdentity || event.isGroup === true) return;
      const message = asText(event.bodyForAgent || event.content || event.text);

      const url = new URL(resolveUrl);
      url.searchParams.set("channel", channel);
      url.searchParams.set("external_identity", externalIdentity);
      if (message) url.searchParams.set("message", message);

      const controller = new AbortController();
      const timer = setTimeout(() => controller.abort(), timeoutMs);
      try {
        const response = await fetch(url, {headers: {accept: "application/json"}, signal: controller.signal});
        if (!response.ok) {
          return {handled: true, reply: {text: "No puedo validar tu cuenta en este momento. Intenta de nuevo más tarde."}};
        }
        const result = await response.json();
        if (result?.registered === true && result?.action === "route_to_agent") return;

        const registrationUrl = asText(result?.webchat_url) || process.env.WEBCHAT_PUBLIC_BASE_URL || "http://127.0.0.1:8080/";
        return {handled: true, reply: registrationReply(registrationUrl)};
      } catch {
        return {handled: true, reply: {text: "No puedo validar tu cuenta en este momento. Intenta de nuevo más tarde."}};
      } finally {
        clearTimeout(timer);
      }
    }, {priority: 100});
  }
};
