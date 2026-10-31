"""Offline contracts; no live application, network, mail or business records."""
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import tempfile
import unittest
from unittest.mock import patch
import zipfile

SPEC = importlib.util.spec_from_file_location('consolidated_contract', Path(__file__).with_name('deploy.py'))
d = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(d)


class Contracts(unittest.TestCase):
    def changes(self):
        return {key: ('false' if key == 'PDF_DISABLE_SANDBOX' else '/synthetic/path') for key in d.PDF_ENV_KEYS}

    def test_six_pdf_keys_preserve_every_unrelated_byte(self):
        raw = b'MAIL_PASSWORD="synthetic-only"\r\nAPP_TIMEZONE=Asia/Kolkata\r\nPDF_TEMP_PATH="invalid"\r\n'
        revised = d.update_pdf_env(raw, self.changes())
        self.assertEqual(d.unchanged_env_bytes(raw), d.unchanged_env_bytes(revised))
        self.assertIn(b'MAIL_PASSWORD="synthetic-only"\r\n', revised)
        self.assertIn(b'PDF_TEMP_PATH="/synthetic/path"\n', revised)

    def test_env_editor_rejects_secret_mutation_duplicate_and_injection(self):
        changes = self.changes()
        changes['MAIL_PASSWORD'] = 'forbidden'
        with self.assertRaises(d.Refused):
            d.update_pdf_env(b'', changes)
        with self.assertRaises(d.Refused):
            d.update_pdf_env(b'MAIL_HOST=one\nMAIL_HOST=two\n', self.changes())
        changes = self.changes()
        changes['PDF_BROWSER_PATH'] = '$(injected)'
        with self.assertRaises(d.Refused):
            d.update_pdf_env(b'', changes)

    def archive(self, names):
        raw = io.BytesIO()
        with zipfile.ZipFile(raw, 'w') as archive:
            for name, mode in names:
                info = zipfile.ZipInfo(name)
                info.external_attr = mode << 16
                archive.writestr(info, b'synthetic')
        raw.seek(0)
        return zipfile.ZipFile(raw)

    def test_browser_archive_refuses_links_traversal_duplicates_and_wrong_roots(self):
        for names in ([('chrome-linux64/chrome', stat.S_IFLNK | 0o777)],
                      [('chrome-linux64/../escape', stat.S_IFREG | 0o644)],
                      [('other/chrome', stat.S_IFREG | 0o755)],
                      [('chrome-linux64/chrome', stat.S_IFREG | 0o755)] * 2):
            with self.subTest(names=names), self.archive(names) as archive, self.assertRaises(d.Refused):
                d.browser_members(archive)

    def test_actual_wrapper_probe_requires_pipe_and_sandbox(self):
        self.assertIn("createPdfCommand()", d.ACTUAL_PDF_ACTION)
        self.assertIn("['options']['pipe']", d.ACTUAL_PDF_ACTION)
        self.assertIn("'pdf.disable_sandbox')!==false", d.ACTUAL_PDF_ACTION)
        self.assertIn("actual_php_pdf_wrapper", Path(d.__file__).read_text())

    def test_production_target_cannot_be_selected_by_cli(self):
        source = Path(d.__file__).read_text()
        self.assertNotIn("add_argument('--app'", source)
        self.assertNotIn("add_argument('--host'", source)
        self.assertEqual(d.APP.as_posix(), '/home/uat-admin.primehealers.com/public_html')
        self.assertEqual(d.DATABASE, 'uata_primehealersos')

    def test_database_restore_is_never_part_of_rollback(self):
        import inspect
        self.assertNotIn('persistent_backups', inspect.getsource(d.recover_backup))
        self.assertNotIn('mysql', inspect.getsource(d.recover_backup))
        self.assertIn('database_restore_automatic', inspect.getsource(d.persistent_backups))


@unittest.skipUnless(os.name == 'posix' and hasattr(os, 'geteuid') and os.geteuid() == 0, 'Disposable Linux root fixture only')
class DirectoryRecovery(unittest.TestCase):
    def test_directory_permissions_and_missing_source_parent_restore(self):
        with tempfile.TemporaryDirectory(prefix='phos-consolidated-dir-test-') as tmp:
            root = Path(tmp)
            app, backup = root / 'synthetic-app', root / 'backup'
            app.mkdir(); backup.mkdir()
            for name in ('app', 'app/Services', 'storage', 'storage/app', 'storage/app/pdf-runtime'):
                (app / name).mkdir()
                os.chown(app / name, d.OWNER_ID, d.OWNER_ID)
            home = app / 'storage/app/pdf-runtime/home'
            home.mkdir(); os.chown(home, d.OWNER_ID, 65534); os.chmod(home, 0o775)
            original = d.directory_metadata(home)
            with patch.object(d, 'APP', app), patch.object(d, 'persistent_backups'):
                d.prepare_live_directories(backup, {'app/Services/Access/Example.php': {}})
                self.assertEqual(stat.S_IMODE(home.stat().st_mode), 0o700)
                self.assertTrue((app / 'app/Services/Access').is_dir())
                self.assertEqual(d.restore_directories(backup), [])
                self.assertEqual(d.directory_metadata(home), original)
                self.assertFalse((app / 'app/Services/Access').exists())

    def test_foreign_directory_metadata_is_preserved(self):
        with tempfile.TemporaryDirectory(prefix='phos-consolidated-dir-test-') as tmp:
            root = Path(tmp); app = root / 'app'; app.mkdir(); backup = root / 'backup'; backup.mkdir()
            name = 'storage/app/pdf-runtime'
            path = app / name; path.mkdir(parents=True); os.chown(path, d.OWNER_ID, d.OWNER_ID); os.chmod(path, 0o700)
            after = d.directory_metadata(path)
            d.atomic(backup / 'runtime-directories.json', d.json_bytes({name: {'before': None, 'after': after, 'started': True}}), 0, 0, 0o600)
            os.chmod(path, 0o750)
            with patch.object(d, 'APP', app):
                self.assertEqual(d.restore_directories(backup), [name])
                self.assertEqual(stat.S_IMODE(path.stat().st_mode), 0o750)


if __name__ == '__main__':
    unittest.main()
