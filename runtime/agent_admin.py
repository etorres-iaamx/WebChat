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


def run_json(command: list[str], *, stdin: str | None = None) -> object:
    try:
        return json.loads(run(command, stdin=stdin))
    except json.JSONDecodeError as exc:
        raise AdapterError("invalid_json") from exc


def openclaw_command() -> str:
    return os.environ.get("OPENCLAW_BINARY", "openclaw")


def provision_openclaw(args: argparse.Namespace) -> dict[str, str]:
    agent_id = "webchat-" + args.agent_public_id.replace("-", "").lower()
    root = args.workspace_root.rstrip("/")
    workspace = f"{root}/{agent_id}"
    payload = run_json([args.openclaw_binary, "agents", "add", agent_id, "--workspace", workspace,
                        "--agent-dir", f"{root}/.agents/{agent_id}",
                        "--non-interactive", "--json"])
    external = payload.get("id", agent_id) if isinstance(payload, dict) else agent_id
    atomic_write(Path(workspace) / "AGENTS.md", (
        "# WebChat agent isolation\n\n"
        "This workspace belongs only to the authenticated WebChat user identified in USER.md.\n"
        "Never claim or imply access to other users, agents, sessions, workspaces, host paths, files, tools, or Gateway internals.\n"
        "Do not enumerate persistent agents or reveal infrastructure paths. If asked for another user's data, refuse clearly.\n"
        "Use only the current conversation and the user's own information explicitly provided in this workspace.\n"
        "If you cannot verify access, say so; never guess or hallucinate access.\n"
    ))
    atomic_write(Path(workspace) / "USER.md", (
        "# USER\n\n"
        f"- Nombre: {profile_value(args.display_name)}\n"
        f"- Correo: {profile_value(args.email)}\n"
        f"- Teléfono: {profile_value(args.phone) or 'No proporcionado'}\n"
        f"- País/código: {profile_value(args.country_code) or 'No proporcionado'}\n"
        f"- Identificador WebChat: {profile_value(args.agent_public_id)}\n"
    ))
    atomic_write(Path(workspace) / ".webchat" / "packages.manifest", (
        "# WebChat per-agent packages; one '<manager> <package>' per line.\n"
        "# Supported managers: apt, pip, npm.\n"
    ))
    return {"external_agent_id": str(external), "workspace": workspace}


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
    create.add_argument("--openclaw-binary", default=openclaw_command())
    create.add_argument("--workspace-root", required=True)
    create.add_argument("--display-name", required=True)
    create.add_argument("--email", required=True)
    create.add_argument("--phone", default="")
    create.add_argument("--country-code", default="")
    hermes = sub.add_parser("provision-hermes")
    hermes.add_argument("--profile", required=True)
    hermes.add_argument("--description", required=True)
    hermes.add_argument("--home", required=True)
    hermes.add_argument("--display-name", required=True)
    hermes.add_argument("--email", required=True)
    args = parser.parse_args()
    try:
        if args.operation == "provision-openclaw": result = provision_openclaw(args)
        else: result = provision_hermes(args)
        print(json.dumps({"ok": True, "result": result}, ensure_ascii=False))
        return 0
    except (AdapterError, OSError) as exc:
        print(json.dumps({"ok": False, "error": str(exc)}), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
