"""Offline verification of the three-view package and source-only recovery."""
import importlib.util
import json
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

SPEC = importlib.util.spec_from_file_location('visibility_deploy', Path(__file__).with_name('deploy.py'))
D = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(D)
REPO = Path(__file__).resolve().parents[2]
FOLDER = Path(__file__).resolve().parent


class VisibilityDeployment(unittest.TestCase):
    def test_exact_package_is_verified_offline(self):
        manifest = FOLDER / 'manifest.json'
        archive = FOLDER / 'payload.zip'
        value = json.loads(manifest.read_bytes())
        checked, payload = D.load_package(manifest, archive, D.digest(manifest.read_bytes()),
                                         D.digest(archive.read_bytes()), value['release'])
        self.assertEqual(len(payload), 3)
        self.assertEqual(sum(value is None for value in D.BASELINE_HASHES.values()), 1)
        for name, data in payload.items():
            original = subprocess.check_output(['git', '-C', str(REPO), 'show', checked['release'] + ':' + name])
            self.assertEqual(data, original)

    def test_tampered_package_is_refused(self):
        with self.assertRaisesRegex(D.Refused, 'checksum'):
            D.load_package(FOLDER / 'manifest.json', FOLDER / 'payload.zip', 'a' * 64,
                           'b' * 64, 'c' * 40)

    def test_only_the_fixed_uat_target_is_allowed(self):
        with self.assertRaises(D.Refused):
            D.assert_target_values({'host': 'admin.primehealers.com'})

    def test_unreviewed_file_is_refused(self):
        with self.assertRaises(D.Refused):
            D.safe_relative('.env')
        with self.assertRaises(D.Refused):
            D.safe_relative('../../outside')

    def test_partial_install_can_restore_original_views_without_changing_mail(self):
        with tempfile.TemporaryDirectory(dir=REPO, prefix='visibility-recovery-test-') as temporary:
            root = Path(temporary)
            app, backup = root / 'app', root / 'backup'
            app.mkdir(); backup.mkdir()
            original = {}
            for name, digest in D.BASELINE_HASHES.items():
                path = app / name
                path.parent.mkdir(parents=True, exist_ok=True)
                if digest:
                    data = subprocess.check_output(['git', '-C', str(REPO), 'show', D.BASELINE + ':' + name])
                    self.assertEqual(D.digest(data), digest)
                    path.write_bytes(data)
                    original[name] = data
            for name, digest in D.PRESERVED_SOURCE_HASHES.items():
                path = app / name
                path.parent.mkdir(parents=True, exist_ok=True)
                # Preserved source hashes span the already deployed independent batches.
                data = subprocess.check_output(['git', '-C', str(REPO), 'show', D.BASELINE + ':' + name])
                self.assertEqual(D.digest(data), digest)
                path.write_bytes(data)
            mail = app / '.env'
            mail.write_bytes(b'MAIL_PASSWORD=synthetic-private-sentinel\n')
            payload = {name: b'new-view-' + name.encode() for name in D.BASELINE_HASHES}
            with patch.object(D, 'APP', app), patch.object(D, 'note'), patch.object(D, 'UID', 1), patch.object(D, 'GID', 1):
                _, state = D.make_backup({'release': 'a' * 40}, payload, {}, backup)
                (app / 'storage/framework').mkdir(parents=True)
                down = b'{"status":503}'
                (backup / 'maintenance-down').write_bytes(down)
                state['maintenance_sha256'] = D.digest(down)
                for name, data in payload.items():
                    (app / name).write_bytes(data)
                with patch.object(D.os, 'chown', create=True), patch.object(D, 'artisan'), \
                     patch.object(D, 'web_cache'), patch.object(D, 'preserved'), \
                     patch.object(D, 'runtime_guards'), patch.object(D, 'health'), patch.object(D, 'preserved_assets'):
                    D.restore(backup, state)
                for name, data in original.items():
                    self.assertEqual((app / name).read_bytes(), data)
                self.assertFalse((app / 'resources/views/components/password-input.blade.php').exists())
                self.assertEqual(mail.read_bytes(), b'MAIL_PASSWORD=synthetic-private-sentinel\n')
                self.assertTrue(state['rolled_back'])


if __name__ == '__main__':
    unittest.main()
