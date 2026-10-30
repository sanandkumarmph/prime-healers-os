"""Offline guard/recovery tests. Never connects to UAT or invokes deployment."""

import importlib.util
import ast
import json
from pathlib import Path
import stat
import shutil
import subprocess
import sys
import tempfile
import unittest
import warnings
from unittest.mock import patch
import zipfile


sys.dont_write_bytecode = True
SPEC = importlib.util.spec_from_file_location("compact_pages_deploy", Path(__file__).with_name("deploy.py"))
D = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(D)
RELEASE = "a" * 40
REPO = Path(__file__).resolve().parents[2]
_SOURCE_BYTES = {}

def baseline_bytes(name):
    if name not in _SOURCE_BYTES:
        _SOURCE_BYTES[name] = subprocess.check_output(["git", "-C", str(REPO), "show", D.BASELINE + ":" + name])
    return _SOURCE_BYTES[name]


class PackageGuards(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="phos-compact-pages-guards-", dir=REPO)
        self.assertTrue(Path(self.temporary.name).resolve().is_relative_to(REPO))
        self.addCleanup(self.temporary.cleanup)
        self.folder = Path(self.temporary.name)
        self.archive = self.folder / "release.zip"
        self.manifest = self.folder / "manifest.json"
        self.payload = {name: ("Reviewed payload for " + name + "\n").encode() for name in D.BASELINE_HASHES}
        self.value = {
            "format": 1, "environment": "PHOS_UAT", "baseline": D.BASELINE, "release": RELEASE,
            "archive_sha256": "", "files": [
                {"path": name, "sha256": D.digest(data), "baseline_sha256": D.BASELINE_HASHES[name], "size": len(data)}
                for name, data in self.payload.items()
            ],
        }
        self.write_zip()

    def write_zip(self, special=None):
        with warnings.catch_warnings():
            warnings.simplefilter("ignore", UserWarning)
            with zipfile.ZipFile(self.archive, "w", zipfile.ZIP_DEFLATED) as package:
                for name, data in self.payload.items():
                    package.writestr(name, data)
                if special:
                    special(package)
        self.value["archive_sha256"] = D.digest(self.archive.read_bytes())
        self.write_manifest()

    def write_manifest(self):
        self.manifest.write_text(json.dumps(self.value), encoding="utf-8")

    def load(self):
        return D.load_package(self.manifest, self.archive, D.digest(self.manifest.read_bytes()),
                              D.digest(self.archive.read_bytes()), RELEASE)

    def test_valid_exact_package_checks_without_a_server(self):
        manifest, payload = self.load()
        self.assertEqual(manifest["release"], RELEASE)
        self.assertEqual(payload, self.payload)

    def test_pinned_outer_hash_refuses_tampering(self):
        expected = D.digest(self.manifest.read_bytes())
        self.manifest.write_bytes(self.manifest.read_bytes() + b" ")
        with self.assertRaisesRegex(D.Refused, "checksum"):
            D.load_package(self.manifest, self.archive, expected, D.digest(self.archive.read_bytes()), RELEASE)

    def test_wrong_release_is_refused(self):
        self.value["release"] = "b" * 40
        self.write_manifest()
        with self.assertRaisesRegex(D.Refused, "identity"):
            self.load()

    def test_older_pr8_package_baseline_is_refused(self):
        self.value["baseline"] = "09c6c23112dfb405c10b28cdde9d5717a16872e9"
        self.write_manifest()
        with self.assertRaisesRegex(D.Refused, "identity"):
            self.load()

    def test_nine_originals_and_four_new_paths_have_exact_baseline_contract(self):
        self.assertEqual(D.BASELINE, "c0fef0f918ab2ea01be4bc3222f811d9f4a3d664")
        self.assertEqual(len(D.BASELINE_HASHES), 13)
        self.assertEqual(sum(value is None for value in D.BASELINE_HASHES.values()), 4)
        self.assertEqual(sum(D.valid_hash(value) for value in D.BASELINE_HASHES.values()), 9)

    def test_altered_embedded_baseline_hash_is_refused(self):
        self.value["files"][0]["baseline_sha256"] = "b" * 64
        self.write_manifest()
        with self.assertRaisesRegex(D.Refused, "Baseline hash"):
            self.load()

    def test_unapproved_runtime_or_migration_path_is_refused(self):
        self.value["files"][0]["path"] = "database/migrations/unreviewed.php"
        self.write_manifest()
        with self.assertRaisesRegex(D.Refused, "allowlist"):
            self.load()

    def test_zip_traversal_never_extracts(self):
        self.write_zip(lambda package: package.writestr("../../outside", b"bad"))
        with self.assertRaises(D.Refused):
            self.load()
        self.assertFalse((self.folder.parent / "outside").exists())

    def test_duplicate_zip_entry_is_refused(self):
        name = next(iter(self.payload))
        self.write_zip(lambda package: package.writestr(name, self.payload[name]))
        with self.assertRaises(D.Refused):
            self.load()

    def test_symbolic_link_in_exact_archive_is_refused(self):
        name = next(iter(self.payload))
        with zipfile.ZipFile(self.archive, "w") as package:
            for path, data in self.payload.items():
                entry = zipfile.ZipInfo(path)
                entry.create_system = 3
                entry.external_attr = ((stat.S_IFLNK if path == name else stat.S_IFREG) | 0o644) << 16
                package.writestr(entry, data)
        self.value["archive_sha256"] = D.digest(self.archive.read_bytes())
        self.write_manifest()
        with self.assertRaisesRegex(D.Refused, "linked"):
            self.load()

    def test_manifest_file_hash_is_verified(self):
        self.value["files"][0]["sha256"] = "b" * 64
        self.write_manifest()
        with self.assertRaisesRegex(D.Refused, "checksum"):
            self.load()

    def test_duplicate_json_key_is_refused(self):
        raw = self.manifest.read_text(encoding="utf-8")
        self.manifest.write_text(raw[:-1] + ',"format":1}', encoding="utf-8")
        with self.assertRaisesRegex(D.Refused, "Duplicate JSON"):
            self.load()

    def test_production_target_is_always_refused(self):
        value = {"host": "admin.primehealers.com", "application": "/home/admin.primehealers.com/public_html",
                 "database": "primehealersos", "owner": "website", "php": "/usr/local/lsws/lsphp82/bin/php"}
        with self.assertRaisesRegex(D.Refused, "exact PHOS UAT"):
            D.assert_target_values(value)

    def test_no_arbitrary_php_or_root_owner(self):
        value = {"host": D.HOST, "application": str(D.APP), "database": D.DATABASE,
                 "owner": "root", "php": "/usr/bin/php"}
        with self.assertRaises(D.Refused):
            D.assert_target_values(value)

    def test_cli_check_package_is_offline_and_deploy_has_no_root_override(self):
        args = ["--manifest", str(self.manifest), "--archive", str(self.archive),
                "--manifest-sha256", D.digest(self.manifest.read_bytes()),
                "--archive-sha256", D.digest(self.archive.read_bytes()), "--expected-release", RELEASE]
        result = subprocess.run([__import__("sys").executable, str(Path(D.__file__)), "--check-package", *args],
                                capture_output=True, text=True, check=False)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn("PINNED_COMPACT_PAGES_PACKAGE_VERIFIED", result.stdout)
        self.assertNotIn("--app", __import__("inspect").getsource(D.main))

    def test_all_three_embedded_php_probes_compile(self):
        executable = shutil.which("php")
        if executable is None:
            self.skipTest("PHP is not installed for optional offline embedded-probe syntax checks.")
        tree = ast.parse(Path(D.__file__).read_text(encoding="utf-8"))
        probes = [node.value for node in ast.walk(tree) if isinstance(node, ast.Constant)
                  and isinstance(node.value, str)
                  and (node.value.lstrip().startswith("require 'vendor/autoload.php';")
                       or node.value.startswith("<?php\n"))]
        self.assertEqual(len(probes), 3)
        for index, code in enumerate(probes):
            path = self.folder / ("probe-" + str(index) + ".php")
            path.write_text(code if code.startswith("<?php") else "<?php\n" + code, encoding="utf-8")
            result = subprocess.run([executable, "-l", str(path)], capture_output=True, text=True, check=False)
            self.assertEqual(result.returncode, 0, result.stderr + result.stdout)


class SourceAndRecoveryGuards(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix="phos-compact-pages-fake-uat-", dir=REPO)
        self.assertTrue(Path(self.temporary.name).resolve().is_relative_to(REPO))
        self.addCleanup(self.temporary.cleanup)
        self.folder = Path(self.temporary.name)
        self.app = self.folder / "fake-uat"
        self.backup = self.folder / "backup"
        self.backup.mkdir()
        self.originals = {}
        for name, expected in D.BASELINE_HASHES.items():
            if expected is not None:
                data = baseline_bytes(name)
                self.assertEqual(D.digest(data), expected)
                path = self.app / name
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(data)
                self.originals[name] = data
        for name in D.BASELINE_HASHES:
            (self.app / name).parent.mkdir(parents=True, exist_ok=True)
        for name, expected in D.PRESERVED_SOURCE_HASHES.items():
            data = baseline_bytes(name)
            self.assertEqual(D.digest(data), expected)
            path = self.app / name
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(data)
        self.state = {"format": 1, "application": str(D.APP), "baseline": D.BASELINE,
                      "release": RELEASE, "files": [], "created_directories": []}
        for index, name in enumerate(D.BASELINE_HASHES):
            record = {"path": name, "new_sha256": D.digest(b"deployed " + name.encode()),
                      "existed": name in self.originals}
            if record["existed"]:
                saved = "original-" + str(index)
                (self.backup / saved).write_bytes(self.originals[name])
                record.update(saved=saved, old_sha256=D.BASELINE_HASHES[name], uid=1, gid=1, mode=0o644)
            self.state["files"].append(record)

    def test_exact_deployed_files_are_verified_without_using_git_head(self):
        D.verify_baseline(self.app)
        self.assertEqual(len(self.originals), 9)
        self.assertFalse((self.app / ".git").exists())

    def test_actual_backup_records_nine_originals_four_new_intents_and_preserves_previous_backup(self):
        destination = self.folder / "upgrade-backup"
        destination.mkdir()
        previous = self.folder / "previous-dashboard-backup"
        previous.mkdir()
        prior_helper = previous / "rollback-helper.py"
        prior_helper.write_bytes(b"previous release recovery helper must remain unchanged")
        payload = {name: b"deployed " + name.encode() for name in D.BASELINE_HASHES}
        with patch.object(D, "APP", self.app):
            backup, state = D.make_backup({"release": RELEASE}, payload, {}, destination)
            D.validate_restore(self.app, backup, state)
        self.assertEqual(len(state["files"]), 13)
        self.assertEqual(sum(record["existed"] for record in state["files"]), 9)
        for record in state["files"]:
            path = self.app / record["path"]
            if record["existed"]:
                info = path.stat()
                self.assertEqual((backup / record["saved"]).read_bytes(), path.read_bytes())
                self.assertEqual((record["uid"], record["gid"], record["mode"]),
                                 (info.st_uid, info.st_gid, stat.S_IMODE(info.st_mode)))
            else:
                self.assertEqual(set(record), {"path", "new_sha256", "existed"})
                self.assertFalse(path.exists())
        self.assertEqual(prior_helper.read_bytes(), b"previous release recovery helper must remain unchanged")

    def test_unknown_source_edit_stops(self):
        path = self.app / next(iter(self.originals))
        path.write_bytes(b"unexpected edit")
        with self.assertRaisesRegex(D.Refused, "c0f baseline source differs"):
            D.verify_baseline(self.app)

    def test_previously_added_asset_missing_from_deployed_baseline_stops(self):
        name = "public/js/phos-executive-dashboard.js"
        path = self.app / name
        path.unlink()
        with self.assertRaisesRegex(D.Refused, "regular"):
            D.verify_baseline(self.app)

    def test_original_pr8_files_cannot_be_used_as_upgrade_baseline(self):
        name = "resources/views/layouts/app.blade.php"
        previous = subprocess.check_output(["git", "-C", str(REPO), "show",
                                            "09c6c23112dfb405c10b28cdde9d5717a16872e9:" + name])
        (self.app / name).write_bytes(previous)
        with self.assertRaisesRegex(D.Refused, "c0f baseline source differs"):
            D.verify_baseline(self.app)

    def test_partial_activation_can_be_recovered_without_inspecting_business_history(self):
        name = next(iter(self.originals))
        (self.app / name).write_bytes(b"deployed " + name.encode())
        sentinel = self.app / "storage" / "valid-pr8-workflow-history.sentinel"
        sentinel.parent.mkdir()
        sentinel.write_bytes(b"existing business history must survive")
        D.validate_restore(self.app, self.backup, self.state)
        self.assertEqual(sentinel.read_bytes(), b"existing business history must survive")

    def partial_activation(self):
        changed = ["resources/views/rentals/index.blade.php", "resources/views/layouts/app.blade.php",
                   "resources/views/components/phos-list-filter.blade.php",
                   "resources/views/components/phos-action-icon.blade.php",
                   "public/css/phos-operations.css", "public/js/phos-operations.js"]
        self.state["application"] = str(self.app)
        self.state["preserved"] = {}
        for name in changed:
            (self.app / name).write_bytes(b"deployed " + name.encode())
        (self.app / "storage/framework").mkdir(parents=True)
        maintenance = b'{"time":12345,"retry":60,"secret":null,"status":503}'
        (self.backup / "maintenance-down").write_bytes(maintenance)
        self.state["maintenance_sha256"] = D.digest(maintenance)
        sentinel = self.app / "storage" / "valid-pr8-history.sentinel"
        sentinel.write_bytes(b"must survive code rollback")
        return sentinel

    def test_real_file_restore_after_partial_activation_preserves_unrelated_history(self):
        # Runtime/ownership calls are mocked for Windows; actual file validation,
        # restoration of nine originals and removal of four exact new files and baseline verification run here.
        sentinel = self.partial_activation()
        with patch.object(D, "APP", self.app), patch.object(D, "WEB", self.app / "public"), \
             patch.object(D.os, "chown", lambda *args: None, create=True), \
             patch.object(D, "artisan"), patch.object(D, "web_cache"), patch.object(D, "preserved"), \
             patch.object(D, "runtime_guards"), patch.object(D, "health"), patch.object(D, "preserved_assets"), patch.object(D, "note"):
            D.restore(self.backup, self.state)
        D.verify_baseline(self.app)
        self.assertTrue(all((self.app / name).is_file() if old is not None else not (self.app / name).exists() for name, old in D.BASELINE_HASHES.items()))
        self.assertEqual(sentinel.read_bytes(), b"must survive code rollback")
        self.assertTrue(self.state["rolled_back"])

    def test_persistent_web_cache_failure_cannot_prevent_source_undo(self):
        sentinel = self.partial_activation()
        with patch.object(D, "APP", self.app), patch.object(D, "WEB", self.app / "public"), \
             patch.object(D.os, "chown", lambda *args: None, create=True), \
             patch.object(D, "artisan") as artisan, \
             patch.object(D, "web_cache", side_effect=D.Refused("persistent web probe failure")), \
             patch.object(D, "preserved"), patch.object(D, "runtime_guards"), \
             patch.object(D, "health"), patch.object(D, "preserved_assets"), patch.object(D, "note"):
            with self.assertRaisesRegex(D.Refused, "persistent web probe failure"):
                D.restore(self.backup, self.state)
        D.verify_baseline(self.app)
        self.assertEqual(sentinel.read_bytes(), b"must survive code rollback")
        self.assertEqual((self.app / "storage/framework/down").read_bytes(),
                         (self.backup / "maintenance-down").read_bytes())
        self.assertNotIn("up", [call.args[0] for call in artisan.call_args_list])
        self.assertNotIn("down", [call.args[0] for call in artisan.call_args_list])

    def test_activated_artisan_down_failure_cannot_block_file_undo(self):
        sentinel = self.partial_activation()
        commands = []
        def activated_artisan(command, *args):
            commands.append(command)
            if command == "down":
                raise D.Refused("activated code cannot boot artisan down")
        with patch.object(D, "APP", self.app), patch.object(D, "WEB", self.app / "public"), \
             patch.object(D.os, "chown", lambda *args: None, create=True), \
             patch.object(D, "artisan", side_effect=activated_artisan), patch.object(D, "web_cache"), \
             patch.object(D, "preserved"), patch.object(D, "runtime_guards"), \
             patch.object(D, "health"), patch.object(D, "preserved_assets"), patch.object(D, "note"):
            D.restore(self.backup, self.state)
        D.verify_baseline(self.app)
        self.assertEqual(sentinel.read_bytes(), b"must survive code rollback")
        self.assertNotIn("down", commands)
        self.assertIn("up", commands)

    def test_failed_post_restore_health_reestablishes_maintenance_after_source_undo(self):
        sentinel = self.partial_activation()
        def baseline_artisan(command, *args):
            if command == "up":
                (self.app / "storage/framework/down").unlink()
        with patch.object(D, "APP", self.app), patch.object(D, "WEB", self.app / "public"), \
             patch.object(D.os, "chown", lambda *args: None, create=True), \
             patch.object(D, "artisan", side_effect=baseline_artisan), patch.object(D, "web_cache"), \
             patch.object(D, "preserved"), patch.object(D, "runtime_guards"), \
             patch.object(D, "health", side_effect=D.Refused("post-restore health failure")), \
             patch.object(D, "preserved_assets"), patch.object(D, "note"):
            with self.assertRaisesRegex(D.Refused, "post-restore health failure"):
                D.restore(self.backup, self.state)
        D.verify_baseline(self.app)
        self.assertTrue((self.app / "storage/framework/down").is_file())
        self.assertEqual(sentinel.read_bytes(), b"must survive code rollback")

    def test_missing_baseline_parent_is_refused_without_creating_directories(self):
        destination = self.app / "public/css/phos-executive-dashboard.css"
        destination.unlink()
        destination.parent.rmdir()
        with patch.object(D, "APP", self.app):
            with self.assertRaisesRegex(D.Refused, "parent is missing"):
                D.verify_parents(destination)
        self.assertFalse(destination.parent.exists())

    def test_rollback_cannot_mark_existing_dashboard_assets_as_new(self):
        record = next(row for row in self.state["files"] if row["path"] == "resources/views/layouts/app.blade.php")
        record["existed"] = False
        with self.assertRaisesRegex(D.Refused, "distinguish nine originals from four new files"):
            D.validate_restore(self.app, self.backup, self.state)

    def test_rollback_cannot_remove_previous_release_directories(self):
        self.state["created_directories"] = ["public/css"]
        with self.assertRaisesRegex(D.Refused, "must not create or remove"):
            D.validate_restore(self.app, self.backup, self.state)

    def test_rollback_cannot_mark_new_asset_as_a_baseline_file(self):
        record = next(row for row in self.state["files"] if row["path"] == "public/css/phos-operations.css")
        record["existed"] = True
        with self.assertRaisesRegex(D.Refused, "distinguish nine originals from four new files"):
            D.validate_restore(self.app, self.backup, self.state)

    def test_recovery_refuses_newer_operator_edit_before_restoring_any_file(self):
        name = next(iter(self.originals))
        (self.app / name).write_bytes(b"someone changed this after deployment")
        with self.assertRaisesRegex(D.Refused, "changed after deployment"):
            D.validate_restore(self.app, self.backup, self.state)

    def test_corrupt_backup_stops_recovery(self):
        saved = next(record["saved"] for record in self.state["files"] if record["existed"])
        (self.backup / saved).write_bytes(b"corrupt")
        with self.assertRaisesRegex(D.Refused, "backup hash"):
            D.validate_restore(self.app, self.backup, self.state)

    def test_extra_recovery_destination_is_refused(self):
        self.state["files"][0]["path"] = ".env"
        with self.assertRaisesRegex(D.Refused, "destinations"):
            D.validate_restore(self.app, self.backup, self.state)

    def test_symlink_parent_is_refused_when_platform_permits(self):
        real = self.folder / "real"
        real.mkdir()
        link = self.folder / "link"
        try:
            link.symlink_to(real, target_is_directory=True)
        except OSError:
            self.skipTest("Creating Windows symlinks requires a privilege unavailable to this process.")
        with self.assertRaisesRegex(D.Refused, "Linked"):
            D.no_links(link / "file")

    def test_new_file_collision_is_refused_for_each_absent_destination(self):
        for name, expected in D.BASELINE_HASHES.items():
            if expected is not None:
                continue
            with self.subTest(path=name):
                path = self.app / name
                path.write_bytes(b"operator-owned collision")
                try:
                    with self.assertRaisesRegex(D.Refused, "already exists"):
                        D.verify_baseline(self.app)
                finally:
                    path.unlink()

    def test_existing_dashboard_and_knowledge_source_drift_stops(self):
        for name in ("resources/views/dashboard.blade.php", "app/Http/Controllers/KnowledgeController.php"):
            with self.subTest(path=name):
                path = self.app / name
                original = path.read_bytes()
                path.write_bytes(b"unexpected preserved-source edit")
                try:
                    with self.assertRaisesRegex(D.Refused, "dashboard/Knowledge source changed"):
                        D.verify_baseline(self.app)
                finally:
                    path.write_bytes(original)

    def test_new_asset_modified_after_activation_is_not_removed(self):
        self.partial_activation()
        path = self.app / "public/css/phos-operations.css"
        path.write_bytes(b"a later operator edit")
        original = (self.app / "resources/views/rentals/index.blade.php").read_bytes()
        with patch.object(D, "APP", self.app):
            with self.assertRaisesRegex(D.Refused, "changed after deployment"):
                D.validate_restore(self.app, self.backup, self.state)
        self.assertEqual(path.read_bytes(), b"a later operator edit")
        self.assertEqual((self.app / "resources/views/rentals/index.blade.php").read_bytes(), original)

    def test_protected_route_redirect_cannot_escape_to_another_host(self):
        def response(path, local=True, token=None):
            if path in ("/up", "/login"):
                return "200", b"ok", ""
            return "302", b"", "Location: https://example.com/login\r\n"
        with patch.object(D, "http", response), patch.object(D, "note"):
            with self.assertRaisesRegex(D.Refused, "unexpected redirect"):
                D.health()

    def test_all_eight_operations_pages_are_protected_route_checks(self):
        visited = []
        def response(path, local=True, token=None):
            visited.append(path)
            if path in ("/up", "/login"):
                return "200", b"ok", ""
            return "302", b"", "Location: https://uat-admin.primehealers.com/login\r\n"
        with patch.object(D, "http", response), patch.object(D, "note") as note:
            D.health()
        self.assertIn("/communication-center", visited)
        self.assertIn("/business-partners", visited)
        note.assert_any_call("PROTECTED_ROUTES_VERIFIED=8")


if __name__ == "__main__":
    unittest.main(verbosity=2)
