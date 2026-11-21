#!/usr/bin/env python3
import importlib.util
import tempfile
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location("package_manifest", ROOT / "runtime/package_manifest.py")
MODULE = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(MODULE)


class PackageManifestTest(unittest.TestCase):
    def test_add_validate_and_deduplicate(self):
        with tempfile.TemporaryDirectory() as directory:
            manifest = Path(directory) / ".webchat" / "packages.manifest"
            self.assertEqual(MODULE.add_packages(manifest, ["apt curl", "pip requests", "apt curl"]), 2)
            self.assertEqual(MODULE.parse_manifest(manifest), [("apt", "curl"), ("pip", "requests")])

    def test_install_dry_run_never_executes(self):
        with tempfile.TemporaryDirectory() as directory:
            manifest = Path(directory) / "packages.manifest"
            MODULE.add_packages(manifest, ["apt curl", "pip requests", "npm typescript"])
            self.assertEqual(MODULE.install_packages(manifest, dry_run=True), [
                ["apt-get", "install", "-y", "--no-install-recommends", "curl"],
                ["npm", "install", "--global", "typescript"],
                [MODULE.sys.executable, "-m", "pip", "install", "requests"],
            ])

    def test_rejects_shell_metacharacters(self):
        with tempfile.TemporaryDirectory() as directory:
            manifest = Path(directory) / "packages.manifest"
            with self.assertRaises(MODULE.ManifestError):
                MODULE.add_packages(manifest, ["apt curl;touch /tmp/pwned"])


if __name__ == "__main__":
    unittest.main()
