#!/usr/bin/env python3
"""Persist and reinstall per-agent packages without relying on Docker."""
from __future__ import annotations

import argparse
import re
import subprocess
import sys
from pathlib import Path


MANAGERS = {"apt", "pip", "npm"}
PACKAGE_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9+_.:@/%=-]{0,127}$")


class ManifestError(RuntimeError):
    pass


def parse_manifest(path: Path) -> list[tuple[str, str]]:
    if not path.exists():
        return []
    entries: list[tuple[str, str]] = []
    for line_number, raw in enumerate(path.read_text(encoding="utf-8").splitlines(), 1):
        line = raw.split("#", 1)[0].strip()
        if not line:
            continue
        fields = line.split(None, 1)
        if len(fields) != 2 or fields[0] not in MANAGERS or not PACKAGE_RE.fullmatch(fields[1]):
            raise ManifestError(f"invalid_manifest_line:{line_number}")
        entries.append((fields[0], fields[1]))
    return entries


def write_manifest(path: Path, entries: list[tuple[str, str]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    unique = sorted(set(entries), key=lambda item: (item[0], item[1]))
    content = "# WebChat per-agent packages; one '<manager> <package>' per line.\n"
    content += "# Supported managers: apt, pip, npm.\n"
    content += "".join(f"{manager} {package}\n" for manager, package in unique)
    path.write_text(content, encoding="utf-8")
    path.chmod(0o600)


def add_packages(path: Path, packages: list[str]) -> int:
    entries = parse_manifest(path)
    for item in packages:
        fields = item.split(None, 1)
        if len(fields) != 2 or fields[0] not in MANAGERS or not PACKAGE_RE.fullmatch(fields[1]):
            raise ManifestError("invalid_package:use '<manager> <package>'")
        entries.append((fields[0], fields[1]))
    write_manifest(path, entries)
    return len(set(entries))


def install_packages(path: Path, *, dry_run: bool = False) -> list[list[str]]:
    commands: list[list[str]] = []
    for manager, package in parse_manifest(path):
        if manager == "apt":
            commands.append(["apt-get", "install", "-y", "--no-install-recommends", package])
        elif manager == "pip":
            commands.append([sys.executable, "-m", "pip", "install", package])
        else:
            commands.append(["npm", "install", "--global", package])
    if dry_run:
        return commands
    for command in commands:
        result = subprocess.run(command, check=False, shell=False)
        if result.returncode:
            raise ManifestError(f"package_install_failed:{command[0]}:{command[-1]}")
    return commands


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--manifest", required=True, type=Path)
    sub = parser.add_subparsers(dest="operation", required=True)
    sub.add_parser("validate")
    add = sub.add_parser("add")
    add.add_argument("packages", nargs="+")
    install = sub.add_parser("install")
    install.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()
    try:
        if args.operation == "validate":
            entries = parse_manifest(args.manifest)
            print(f"valid:{len(entries)}")
        elif args.operation == "add":
            print(f"packages:{add_packages(args.manifest, args.packages)}")
        else:
            for command in install_packages(args.manifest, dry_run=args.dry_run):
                print(" ".join(command))
        return 0
    except (ManifestError, OSError) as exc:
        print(str(exc), file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
