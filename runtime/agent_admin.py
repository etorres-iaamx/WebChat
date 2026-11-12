#!/usr/bin/env python3
"""Embedded runtime adapter for WebChat's OpenClaw and Hermes profiles."""
from __future__ import annotations

import argparse
import fcntl
import json
import os
import re
import subprocess
import sys
import tempfile
from pathlib import Path


class AdapterError(RuntimeError):
    pass


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


def bind_peer(args: argparse.Namespace) -> dict[str, object]:
    desired = {"channel": args.channel, "accountId": args.account,
               "peer": {"kind": "direct", "id": args.peer_id}}
    lock_path = os.environ.get("OPENCLAW_BINDING_LOCK", "/tmp/webchat-openclaw-bindings.lock")
    with open(lock_path, "a+", encoding="utf-8") as lock:
        fcntl.flock(lock.fileno(), fcntl.LOCK_EX)
        bindings = run_json(["openclaw", "config", "get", "bindings", "--json"])
        if not isinstance(bindings, list):
            raise AdapterError("invalid_bindings")
        for binding in bindings:
            if not isinstance(binding, dict) or binding.get("match") != desired:
                continue
            if binding.get("agentId") != args.agent_id:
                raise AdapterError("peer_binding_conflict")
            return {"already_present": True, "agent_id": args.agent_id, "match": desired}
        next_bindings = [item for item in bindings if isinstance(item, dict)]
        next_bindings.append({"type": "route", "agentId": args.agent_id, "match": desired})
        run_json(["openclaw", "config", "patch", "--stdin"],
                 stdin=json.dumps({"bindings": next_bindings}))
        readback = run_json(["openclaw", "config", "get", "bindings", "--json"])
        if not isinstance(readback, list) or not any(
            isinstance(item, dict) and item.get("agentId") == args.agent_id
            and item.get("match") == desired for item in readback
        ):
            raise AdapterError("peer_binding_readback_failed")
    return {"agent_id": args.agent_id, "match": desired}


def provision_openclaw(args: argparse.Namespace) -> dict[str, str]:
    agent_id = "webchat-" + args.agent_public_id.replace("-", "").lower()
    root = args.workspace_root.rstrip("/")
    workspace = f"{root}/{agent_id}"
    payload = run_json(["openclaw", "agents", "add", agent_id, "--workspace", workspace,
                        "--agent-dir", f"{root}/.agents/{agent_id}",
                        "--non-interactive", "--json"])
    external = payload.get("id", agent_id) if isinstance(payload, dict) else agent_id
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
    create.add_argument("--workspace-root", required=True)
    bind = sub.add_parser("bind-peer")
    bind.add_argument("--agent-id", required=True)
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
