#!/usr/bin/env bash
set -Eeuo pipefail

PUBLIC_BASE_URL="${WEBCHAT_PUBLIC_BASE_URL:-}"
SUPPORT_EMAIL="${WEBCHAT_SUPPORT_EMAIL:-xochitl.bot.iaamx@gmail.com}"
[[ -n "$PUBLIC_BASE_URL" ]] || { echo 'ERROR: WEBCHAT_PUBLIC_BASE_URL debe estar definido.' >&2; exit 1; }
WEBCHAT_URL="${PUBLIC_BASE_URL%/}/webchat/"

candidate="${OPENCLAW_WHATSAPP_MONITOR:-}"
if [[ -z "$candidate" ]]; then
  candidate=$(rg -l 'Your WhatsApp phone number:' ~/.openclaw/npm/projects 2>/dev/null | head -n 1 || true)
fi
[[ -n "$candidate" && -f "$candidate" ]] || { echo 'ERROR: no se encontró el adaptador WhatsApp de OpenClaw.' >&2; exit 1; }

cp "$candidate" "$candidate.webchat-backup"
python3 - "$candidate" "$WEBCHAT_URL" "$SUPPORT_EMAIL" <<'PY'
import pathlib
import re
import sys

path = pathlib.Path(sys.argv[1])
webchat_url = sys.argv[2]
support_email = sys.argv[3]
text = path.read_text()
pattern = re.compile(
    r'(?ms)^([\t ]*)senderIdLine: `Your WhatsApp phone number: \$\{candidate\}`,\n'
    r'\1buildReplyText: \(\{ code \}\) => \[.*?^\1meta:',
)
match = pattern.search(text)
if not match:
    pattern = re.compile(
        r'(?m)^([\t ]*)senderIdLine: `Your WhatsApp phone number: \$\{candidate\}`,(?:\n)\1meta:'
    )
    match = pattern.search(text)
if not match:
    raise SystemExit('No se encontró el bloque exacto de pairing WhatsApp.')
indent = match.group(1)
block = (
    indent + 'senderIdLine: `Your WhatsApp phone number: ${candidate}`,\n'
    + indent + 'buildReplyText: ({ code }) => [\n'
    + indent + '\t"Completa tu pairing en WebChat:",\n'
    + indent + '\t`Código: ${code}`,\n'
    + indent + '\t`URL: ' + webchat_url + '`,\n'
    + indent + '\t`Soporte: ' + support_email + '`\n'
    + indent + '\t].join("\\n"),\n'
    + indent + 'meta:'
)
text = text[:match.start()] + block + text[match.end():]
path.write_text(text)
PY

echo "WhatsApp pairing personalizado en $candidate"
