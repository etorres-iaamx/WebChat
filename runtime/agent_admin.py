#!/usr/bin/env python3
"""Embedded runtime adapter for WebChat's OpenClaw and Hermes profiles."""
from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path


class AdapterError(RuntimeError):
    pass


def profile_value(value: str) -> str:
    """Keep user-entered profile values on one bounded Markdown line."""
    return " ".join(value.replace("`", "' ").split())[:500]


def run(command: list[str], *, stdin: str | None = None, timeout: float = 30.0) -> str:
    try:
        result = subprocess.run(command, input=stdin, capture_output=True, text=True,
                                timeout=timeout, check=False, shell=False)
    except FileNotFoundError as exc:
        raise AdapterError(f"command_not_found: {command[0]}") from exc
    except subprocess.TimeoutExpired as exc:
        raise AdapterError("command_timeout") from exc
    if result.returncode:
        raise AdapterError(result.stderr.strip() or result.stdout.strip() or "command_failed")
    return result.stdout


def manager_script() -> str:
    script = os.environ.get("AGENT_ADMIN_MANAGER_SCRIPT", "")
    if not script:
        raise AdapterError("AGENT_ADMIN_MANAGER_SCRIPT is required")
    return script


def run_manager(args: list[str]) -> dict[str, object]:
    try:
        payload = json.loads(run([
            os.environ.get("AGENT_ADMIN_MANAGER_PYTHON", "python3"), manager_script(), *args,
        ]))
    except json.JSONDecodeError as exc:
        raise AdapterError("agent_admin_manager_invalid_json") from exc
    if not isinstance(payload, dict) or payload.get("ok") is not True:
        raise AdapterError("agent_admin_manager_failed")
    results = payload.get("results")
    if not isinstance(results, list) or not results or not isinstance(results[0], dict):
        raise AdapterError("agent_admin_manager_invalid_response")
    return results[0]


def bind_peer(args: argparse.Namespace) -> dict[str, object]:
    return run_manager([
        "bind-peer", args.agent_id, "--channel", args.channel,
        "--account", args.account, "--peer-id", args.peer_id,
        "--openclaw-bin", args.openclaw_binary,
    ])


def provision_openclaw(args: argparse.Namespace) -> dict[str, str]:
    result = run_manager([
        "provision-webchat", "--agent-public-id", args.agent_public_id,
        "--workspace-root", args.workspace_root,
        "--display-name", args.display_name, "--email", args.email,
        "--phone", args.phone, "--country-code", args.country_code,
        "--openclaw-bin", args.openclaw_binary,
        "--sandbox-backend", os.environ.get("OPENCLAW_SANDBOX_BACKEND", "ssh"),
        "--ssh-target", os.environ.get("OPENCLAW_SSH_TARGET", ""),
        "--ssh-workspace-root", os.environ.get("OPENCLAW_SSH_WORKSPACE_ROOT", ""),
    ])
    return {
        "external_agent_id": str(result.get("external_agent_id", "")),
        "workspace": str(result.get("workspace", "")),
    }


def atomic_write(path: Path, content: str) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.NamedTemporaryFile(mode="w", encoding="utf-8", dir=path.parent,
                                     prefix=f".{path.name}.", delete=False) as stream:
        temporary = Path(stream.name)
        stream.write(content)
        stream.flush()
        os.fsync(stream.fileno())
    os.chmod(temporary, 0o600)
    os.replace(temporary, path)


def provision_hermes(args: argparse.Namespace) -> dict[str, str]:
    if not re.fullmatch(r"[a-z0-9][a-z0-9-]{0,63}", args.profile):
        raise AdapterError("invalid_hermes_profile")
    result = subprocess.run(["hermes", "profile", "create", args.profile, "--no-alias",
                             "--description", args.description], capture_output=True,
                            text=True, timeout=30, check=False, shell=False)
    if result.returncode and "already" not in (result.stderr + result.stdout).lower():
        raise AdapterError(result.stderr.strip() or result.stdout.strip() or "hermes_create_failed")
    root = Path(args.home).expanduser().resolve() / "profiles" / args.profile
    atomic_write(root / "SOUL.md", f"# SOUL\n\nPerfil WebChat de {args.display_name}.\n")
    atomic_write(root / "memories" / "USER.md", f"# USER\n\nNombre: {args.display_name}\nCorreo: {args.email}\n")
    atomic_write(root / "AGENTS.md", "# AGENTS\n\nEste perfil solo opera sobre su propia conversación de WebChat.\n")
    return {"external_agent_id": args.profile, "workspace": str(root)}


def main() -> int:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="operation", required=True)
    create = sub.add_parser("provision-openclaw")
    create.add_argument("--agent-public-id", required=True)
    create.add_argument("--openclaw-binary", default=os.environ.get("OPENCLAW_BINARY", "openclaw"))
    create.add_argument("--workspace-root", required=True)
    create.add_argument("--display-name", required=True)
    create.add_argument("--email", required=True)
    create.add_argument("--phone", default="")
    create.add_argument("--country-code", default="")
    bind = sub.add_parser("bind-peer")
    bind.add_argument("--agent-id", required=True)
    bind.add_argument("--openclaw-binary", default=os.environ.get("OPENCLAW_BINARY", "openclaw"))
    bind.add_argument("--channel", choices=("telegram", "whatsapp"), required=True)
    bind.add_argument("--account", default="default")
    bind.add_argument("--peer-id", required=True)
    hermes = sub.add_parser("provision-hermes")
    hermes.add_argument("--profile", required=True)
    hermes.add_argument("--description", required=True)
    hermes.add_argument("--home", required=True)
    hermes.add_argument("--display-name", required=True)
    hermes.add_argument("--email", required=True)
    args = parser.parse_args()
    try:
        if args.operation == "provision-openclaw": result = provision_openclaw(args)
        elif args.operation == "bind-peer": result = bind_peer(args)
        else: result = provision_hermes(args)
        print(json.dumps({"ok": True, "result": result}, ensure_ascii=False))
        return 0
    except (AdapterError, OSError) as exc:
        print(json.dumps({"ok": False, "error": str(exc)}), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
