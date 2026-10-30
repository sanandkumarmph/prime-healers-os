"""Offline operator-helper tests. No SMTP, HTTP, database or server is contacted."""
import ast
import base64
import contextlib
import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import re
import shutil
import ssl
import subprocess
import tempfile
import unittest
from unittest import mock

HERE = Path(__file__).resolve().parent
REVIEW = HERE.parents[2] / 'access-security-implementation-review'
spec = importlib.util.spec_from_file_location('zoho_mail_helper', HERE / 'setup-zoho-mail.py')
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ZohoMailSetupTests(unittest.TestCase):
    def setUp(self):
        if os.name == 'nt':
            native_utime = os.utime
            def fixture_utime(path, *args, **kwargs):
                kwargs.pop('follow_symlinks', None)
                return native_utime(path, *args, **kwargs)
            adapter = mock.patch.object(module.os, 'utime', side_effect=fixture_utime)
            adapter.start()
            self.addCleanup(adapter.stop)

    def values(self, port=465, password='SYNTHETIC_APP_PASSWORD'):
        return module.settings('smtppro.zoho.in', port, password, 'Prime Healers')

    def test_only_documented_exact_hosts_and_tls_ports_are_accepted(self):
        for host in module.ZOHO_HOSTS:
            for port in (465, 587):
                values = module.settings(host, port, 'syntheticPassword', 'Prime Healers')
                self.assertEqual('smtps' if port == 465 else 'smtp', values['MAIL_SCHEME'])
                self.assertEqual('support@primehealers.com', values['MAIL_USERNAME'])
                self.assertEqual('support@primehealers.com', values['MAIL_FROM_ADDRESS'])
        for host in ['evil.test', 'smtp.zoho.in.evil.test', 'https://smtp.zoho.in', 'smtp.zoho.in.', 'SMTP.ZOHO.IN', '127.0.0.1']:
            with self.assertRaises(module.Refused): module.settings(host, 465, 'password', 'Prime Healers')
        for port in [25, 2525, '465', True]:
            with self.assertRaises(module.Refused): module.settings('smtp.zoho.com', port, 'password', 'Prime Healers')

    def test_inputs_reject_control_injection_and_credentials_never_become_extra_keys(self):
        for bad in ['', 'line\nbreak', 'line\rbreak', 'null\0byte', 'tab\tvalue']:
            with self.assertRaises(module.Refused): self.values(password=bad)
        with self.assertRaises(module.Refused): self.values(password=' trailingSpace ')
        with self.assertRaises(module.Refused): module.settings('smtp.zoho.com', 465, 'password', 'Name\nMAIL_HOST=evil.test')
        self.assertEqual(set(module.MAIL_KEYS), set(self.values()))
        self.assertNotIn('APP_KEY', module.MAIL_KEYS)
        self.assertFalse(any(key.startswith('DB_') for key in module.MAIL_KEYS))

    def test_environment_patch_preserves_unrelated_bytes_and_newline_style(self):
        original = b'# private UAT file\r\nAPP_KEY="unchanged$secret"\r\nDB_PASSWORD="untouched#value"\r\nMAIL_MAILER=log # previous mailer\r\nMAIL_HOST=127.0.0.1\r\nSESSION_SECURE_COOKIE=true\r\n'
        changed = module.patch_environment(original, self.values())
        for untouched in [b'# private UAT file\r\n', b'APP_KEY="unchanged$secret"\r\n', b'DB_PASSWORD="untouched#value"\r\n', b'SESSION_SECURE_COOKIE=true\r\n']:
            self.assertIn(untouched, changed)
        self.assertNotIn(b'\n', changed.replace(b'\r\n', b''))
        self.assertEqual(1, changed.count(b'MAIL_MAILER='))
        self.assertIn(b'MAIL_SCHEME="smtps"\r\n', changed)

    def test_duplicate_keys_and_unreviewed_environment_updates_are_refused(self):
        for value in [b'APP_KEY=one\nAPP_KEY=two\n', b'MAIL_HOST=one\nexport MAIL_HOST=two\n', b'A=\0bad']:
            with self.assertRaises(module.Refused): module.patch_environment(value, self.values())
        with self.assertRaises(module.Refused): module.patch_environment(b'APP_KEY=original\n', {**self.values(), 'APP_KEY': 'changed'})

    def test_real_php_dotenv_parser_round_trips_secret_punctuation_and_rejects_url_or_duplicates(self):
        windows_php = Path('C:/xampp/php/php.exe')
        php = str(windows_php) if windows_php.is_file() else shutil.which('php')
        if not php: self.skipTest('PHP parser unavailable.')
        vendor = HERE.parents[1] / 'vendor/autoload.php'
        code = module.PHP_INSPECT.replace("require 'vendor/autoload.php';", 'require ' + json.dumps(vendor.as_posix()) + ';')
        values = self.values(password='punctuation$#"\\\'12')
        candidate = module.patch_environment(b'APP_ENV=uat\nMAIL_URL=\n', values)
        content = json.dumps({'candidate_b64': base64.b64encode(candidate).decode(), 'expected': values}).encode()
        result = subprocess.run([str(php), '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', code], input=content, capture_output=True, check=False)
        self.assertEqual(0, result.returncode, result.stdout)
        self.assertEqual({'candidate_valid': True}, json.loads(result.stdout))
        for bad in [b'MAIL_URL="smtp://SYNTHETIC_SECRET@evil.test"\n', b'APP_ENV=uat\nAPP_ENV=production\n', b'NOT_MAIL="multi\nline"\n']:
            result = subprocess.run([str(php), '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', code],
                                    input=json.dumps({'candidate_b64': base64.b64encode(bad).decode(), 'expected': {}}).encode(), capture_output=True, check=False)
            self.assertEqual(2, result.returncode)
            self.assertIn('error_class', json.loads(result.stdout))
            self.assertNotIn(b'SYNTHETIC_SECRET', result.stdout)
            self.assertEqual(b'', result.stderr)

    def smtp_harness(self, port, *, starttls=True, authentication_error=False):
        calls = []
        class FakeSocket:
            def version(self): return 'TLSv1.3'
        class FakeClient:
            esmtp_features = {'auth': 'PLAIN LOGIN'}
            sock = FakeSocket()
            def __init__(self, host, number, **kwargs):
                calls.append(('connect', number, kwargs.get('context')))
            def ehlo(self): calls.append(('ehlo',))
            def has_extn(self, extension): return starttls
            def starttls(self, *, context): calls.append(('starttls', context))
            def auth(self, mechanism, callback, **kwargs):
                calls.append(('auth', mechanism, callback()))
                if authentication_error: raise RuntimeError('SYNTHETIC_PASSWORD_MUST_NOT_PRINT')
            def close(self): calls.append(('close',))
            def sendmail(self, *args): raise AssertionError('Mail must never be sent.')
            def send_message(self, *args): raise AssertionError('Mail must never be sent.')
        import smtplib
        output = io.StringIO()
        with mock.patch.object(smtplib, 'SMTP', FakeClient), mock.patch.object(smtplib, 'SMTP_SSL', FakeClient), \
             mock.patch('sys.stdin', io.StringIO(json.dumps({'host': 'smtppro.zoho.in', 'port': port, 'password': 'SYNTHETIC_PASSWORD'}))), \
             contextlib.redirect_stdout(output):
            try: exec(compile(module.SMTP_AUTH, '<offline-smtp-auth>', 'exec'), {})
            except SystemExit: pass
        return calls, output.getvalue()

    def test_smtps_and_starttls_verify_certificates_and_authenticate_exactly_once_without_mail(self):
        for port in (465, 587):
            calls, output = self.smtp_harness(port)
            self.assertTrue(json.loads(output)['authenticated'])
            auth = [item for item in calls if item[0] == 'auth']
            self.assertEqual(1, len(auth))
            self.assertEqual('PLAIN', auth[0][1])
            self.assertEqual('\0support@primehealers.com\0SYNTHETIC_PASSWORD', auth[0][2])
            contexts = [item[2] for item in calls if item[0] == 'connect' and item[2] is not None] + [item[1] for item in calls if item[0] == 'starttls']
            self.assertTrue(contexts)
            for context in contexts:
                self.assertTrue(context.check_hostname)
                self.assertEqual(ssl.CERT_REQUIRED, context.verify_mode)
                self.assertGreaterEqual(context.minimum_version, ssl.TLSVersion.TLSv1_2)
            self.assertNotIn('SYNTHETIC_PASSWORD', output)

    def test_starttls_absence_or_auth_failure_never_falls_back_or_exposes_exception_messages(self):
        calls, output = self.smtp_harness(587, starttls=False)
        self.assertEqual([], [item for item in calls if item[0] == 'auth'])
        self.assertIn('error_class', json.loads(output))
        calls, output = self.smtp_harness(465, authentication_error=True)
        self.assertEqual(1, len([item for item in calls if item[0] == 'auth']))
        self.assertNotIn('SYNTHETIC_PASSWORD', output)

    def fixture(self, root):
        app = root / 'app'
        backup = root / 'backup'
        for folder in ['bootstrap/cache', 'storage/framework', 'public']:
            (app / folder).mkdir(parents=True, exist_ok=True)
        backup.mkdir()
        (app / '.env').write_bytes(b'APP_KEY=untouched\nMAIL_MAILER=log\n')
        (app / 'bootstrap/cache/config.php').write_bytes(b'original private config cache')
        original = module.snapshot(app / '.env')
        cached = module.snapshot(app / 'bootstrap/cache/config.php')
        changed = module.patch_environment(original['data'], self.values())
        state = {'phase': 'test', 'files': {}, 'new_environment_sha256': module.digest(changed),
                 'original_mail_fingerprint': 'original', 'protected_configuration_fingerprint': 'protected',
                 'cache_mutation_started': False}
        for name, value in [('environment', original), ('config-cache', cached)]:
            state['files'][name] = {key: item for key, item in value.items() if key != 'data'}
            (backup / (name + '.before')).write_bytes(value['data'])
        maintenance = b'{"status":503,"retry":60,"phos_mail_setup":"synthetic"}'
        (backup / 'maintenance').write_bytes(maintenance)
        state['maintenance_sha256'] = module.digest(maintenance)
        return app, backup, state, changed

    def test_persistent_cache_probe_failure_cannot_block_original_file_restore_and_keeps_maintenance(self):
        with tempfile.TemporaryDirectory(prefix='zoho-restore-', dir=REVIEW if REVIEW.is_dir() else None) as directory:
            app, backup, state, changed = self.fixture(Path(directory))
            state['cache_mutation_started'] = True
            (app / '.env').write_bytes(changed)
            (app / 'bootstrap/cache/config.php').write_bytes(b'changed cache')
            state['new_cache_sha256'] = module.digest(b'changed cache')
            state['new_cache_signature'] = module.file_signature(module.snapshot(app / 'bootstrap/cache/config.php'))
            (app / 'unrelated.txt').write_bytes(b'keep exactly')
            output = io.StringIO()
            with mock.patch.object(module, 'APP', app), mock.patch.object(module, 'UID', 0), mock.patch.object(module, 'GID', 0), \
                 mock.patch.object(module.os, 'fchown', create=True), mock.patch.object(module.os, 'fchmod', create=True), \
                 mock.patch.object(module, 'web_cache', side_effect=RuntimeError('SYNTHETIC_SECRET')), \
                 contextlib.redirect_stdout(output):
                self.assertFalse(module.recover(backup, state))
            self.assertEqual((backup / 'environment.before').read_bytes(), (app / '.env').read_bytes())
            self.assertEqual((backup / 'config-cache.before').read_bytes(), (app / 'bootstrap/cache/config.php').read_bytes())
            self.assertTrue((app / 'storage/framework/down').is_file())
            self.assertEqual(b'keep exactly', (app / 'unrelated.txt').read_bytes())
            self.assertNotIn('SYNTHETIC_SECRET', output.getvalue())

    def test_config_cache_failure_restores_files_before_any_failed_runtime_reverification(self):
        with tempfile.TemporaryDirectory(prefix='zoho-cache-fail-', dir=REVIEW if REVIEW.is_dir() else None) as directory:
            app, backup, state, changed = self.fixture(Path(directory))
            before = {'identity_verified': True, 'providers_loaded': 0, 'mail_fingerprint': 'original', 'protected_configuration_fingerprint': 'protected'}
            calls = []
            def inspect(value=None): return {'candidate_valid': True} if value else before
            def cache(invalidate):
                calls.append(invalidate)
                if invalidate: raise RuntimeError('Persistent probe failure')
            with mock.patch.object(module, 'APP', app), mock.patch.object(module, 'UID', 0), mock.patch.object(module, 'GID', 0), \
                 mock.patch.object(module.os, 'fchown', create=True), mock.patch.object(module.os, 'fchmod', create=True), \
                 mock.patch.object(module, 'inspect_configuration', side_effect=inspect), \
                 mock.patch.object(module, 'authenticate_once') as authentication, mock.patch.object(module, 'make_backup', return_value=(backup, state)), \
                 mock.patch.object(module, 'artisan', side_effect=RuntimeError('Synthetic cache error')), \
                 mock.patch.object(module, 'web_cache', side_effect=cache), contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(2, module.configure(self.values()))
                authentication.assert_called_once()
            self.assertEqual([False, True], calls)
            self.assertEqual((backup / 'environment.before').read_bytes(), (app / '.env').read_bytes())
            self.assertEqual((backup / 'config-cache.before').read_bytes(), (app / 'bootstrap/cache/config.php').read_bytes())
            self.assertTrue((app / 'storage/framework/down').exists())

    def test_unrelated_concurrent_environment_edit_is_not_overwritten_during_recovery(self):
        with tempfile.TemporaryDirectory(prefix='zoho-drift-', dir=REVIEW if REVIEW.is_dir() else None) as directory:
            app, backup, state, changed = self.fixture(Path(directory))
            drift = b'APP_KEY=external-change\n'
            state['cache_mutation_started'] = True
            (app / '.env').write_bytes(drift)
            (app / 'bootstrap/cache/config.php').write_bytes(b'changed cache')
            state['new_cache_sha256'] = module.digest(b'changed cache')
            state['new_cache_signature'] = module.file_signature(module.snapshot(app / 'bootstrap/cache/config.php'))
            with mock.patch.object(module, 'APP', app), mock.patch.object(module.os, 'fchown', create=True), mock.patch.object(module.os, 'fchmod', create=True):
                with self.assertRaises(module.Refused): module.restore_files(backup, state)
            self.assertEqual(drift, (app / '.env').read_bytes())
            self.assertEqual((backup / 'config-cache.before').read_bytes(), (app / 'bootstrap/cache/config.php').read_bytes())

    def test_successful_recovery_verifies_original_files_before_removing_maintenance(self):
        with tempfile.TemporaryDirectory(prefix='zoho-recovered-', dir=REVIEW if REVIEW.is_dir() else None) as directory:
            app, backup, state, changed = self.fixture(Path(directory))
            state['cache_mutation_started'] = True
            (app / '.env').write_bytes(changed)
            (app / 'bootstrap/cache/config.php').write_bytes(b'changed cache')
            state['new_cache_sha256'] = module.digest(b'changed cache')
            state['new_cache_signature'] = module.file_signature(module.snapshot(app / 'bootstrap/cache/config.php'))
            def verify():
                self.assertEqual((backup / 'environment.before').read_bytes(), (app / '.env').read_bytes())
                self.assertEqual((backup / 'config-cache.before').read_bytes(), (app / 'bootstrap/cache/config.php').read_bytes())
                self.assertTrue((app / 'storage/framework/down').is_file())
                return {'identity_verified': True, 'mail_fingerprint': 'original', 'protected_configuration_fingerprint': 'protected'}
            with mock.patch.object(module, 'APP', app), mock.patch.object(module, 'UID', 0), mock.patch.object(module, 'GID', 0), \
                 mock.patch.object(module.os, 'fchown', create=True), mock.patch.object(module.os, 'fchmod', create=True), \
                 mock.patch.object(module, 'web_cache'), mock.patch.object(module, 'inspect_configuration', side_effect=verify), \
                 contextlib.redirect_stdout(io.StringIO()):
                self.assertTrue(module.recover(backup, state))
            self.assertFalse((app / 'storage/framework/down').exists())
            self.assertEqual('rollback_complete', state['phase'])

    def test_manual_recovery_refuses_unrelated_config_cache_drift(self):
        with tempfile.TemporaryDirectory(prefix='zoho-cache-drift-', dir=REVIEW if REVIEW.is_dir() else None) as directory:
            app, backup, state, changed = self.fixture(Path(directory))
            state['cache_mutation_started'] = True
            state['new_cache_sha256'] = module.digest(b'known activated cache')
            (app / 'bootstrap/cache/config.php').write_bytes(b'known activated cache')
            state['new_cache_signature'] = module.file_signature(module.snapshot(app / 'bootstrap/cache/config.php'))
            (app / '.env').write_bytes(changed)
            (app / 'bootstrap/cache/config.php').write_bytes(b'external newer cache')
            with mock.patch.object(module, 'APP', app), mock.patch.object(module.os, 'fchown', create=True), mock.patch.object(module.os, 'fchmod', create=True):
                with self.assertRaises(module.Refused): module.restore_files(backup, state)
            self.assertEqual((backup / 'environment.before').read_bytes(), (app / '.env').read_bytes())
            self.assertEqual(b'external newer cache', (app / 'bootstrap/cache/config.php').read_bytes())

    def test_failed_cache_command_without_verified_signature_preserves_foreign_cache_and_reports_uncertain(self):
        for baseline_cached in [True, False]:
            with self.subTest(baseline_cached=baseline_cached), tempfile.TemporaryDirectory(prefix='zoho-midcache-drift-', dir=REVIEW if REVIEW.is_dir() else None) as directory:
                app, backup, state, changed = self.fixture(Path(directory))
                cache_path = app / 'bootstrap/cache/config.php'
                if not baseline_cached:
                    cache_path.unlink()
                    state['files']['config-cache'] = None
                before = {'identity_verified': True, 'providers_loaded': 0, 'mail_fingerprint': 'original', 'protected_configuration_fingerprint': 'protected'}
                def artisan(command):
                    if command == 'config:clear':
                        if cache_path.exists(): cache_path.unlink()
                    else:
                        cache_path.write_bytes(b'foreign edit after failed cache command')
                        raise RuntimeError('Synthetic cache command failure')
                output = io.StringIO()
                with mock.patch.object(module, 'APP', app), mock.patch.object(module, 'UID', 0), mock.patch.object(module, 'GID', 0), \
                     mock.patch.object(module.os, 'fchown', create=True), mock.patch.object(module.os, 'fchmod', create=True), \
                     mock.patch.object(module, 'inspect_configuration', side_effect=lambda content=None: {'candidate_valid': True} if content else before), \
                     mock.patch.object(module, 'authenticate_once'), mock.patch.object(module, 'make_backup', return_value=(backup, state)), \
                     mock.patch.object(module, 'web_cache') as probe, mock.patch.object(module, 'artisan', side_effect=artisan), \
                     contextlib.redirect_stdout(output):
                    self.assertEqual(2, module.configure(self.values()))
                    probe.assert_called_once_with(False)
                self.assertNotIn('new_cache_signature', state)
                self.assertEqual((backup / 'environment.before').read_bytes(), (app / '.env').read_bytes())
                self.assertEqual(b'foreign edit after failed cache command', cache_path.read_bytes())
                self.assertTrue((app / 'storage/framework/down').is_file())
                self.assertEqual('rollback_uncertain', state['phase'])
                self.assertIn('ROLLBACK_COMPLETE=false ORIGINAL_FILES_RESTORED=false', output.getvalue())
                self.assertNotIn('ZOHO_MAIL_CONFIGURATION_COMPLETE=true', output.getvalue())

    def test_absent_cache_after_framework_clear_can_restore_the_original_known_cache(self):
        with tempfile.TemporaryDirectory(prefix='zoho-absent-cache-', dir=REVIEW if REVIEW.is_dir() else None) as directory:
            app, backup, state, changed = self.fixture(Path(directory))
            state['cache_mutation_started'] = True
            (app / '.env').write_bytes(changed)
            (app / 'bootstrap/cache/config.php').unlink()
            with mock.patch.object(module, 'APP', app), mock.patch.object(module.os, 'fchown', create=True), mock.patch.object(module.os, 'fchmod', create=True):
                module.restore_files(backup, state)
            self.assertEqual((backup / 'environment.before').read_bytes(), (app / '.env').read_bytes())
            self.assertEqual((backup / 'config-cache.before').read_bytes(), (app / 'bootstrap/cache/config.php').read_bytes())

    def test_admin_environment_or_cache_edit_during_maintenance_creation_is_preserved_and_fails_closed(self):
        for target in ['environment', 'cache']:
            with self.subTest(target=target), tempfile.TemporaryDirectory(prefix='zoho-activation-drift-', dir=REVIEW if REVIEW.is_dir() else None) as directory:
                app, backup, state, changed = self.fixture(Path(directory))
                native_maintenance = module.ensure_maintenance
                injected = []
                drift = b'APP_KEY=concurrent-admin-change\n' if target == 'environment' else b'concurrent-admin-cache'
                path = app / '.env' if target == 'environment' else app / 'bootstrap/cache/config.php'
                def maintenance(backup_path, recovery_state):
                    native_maintenance(backup_path, recovery_state)
                    if not injected:
                        path.write_bytes(drift)
                        injected.append(True)
                before = {'identity_verified': True, 'providers_loaded': 0, 'mail_fingerprint': 'original', 'protected_configuration_fingerprint': 'protected'}
                output = io.StringIO()
                with mock.patch.object(module, 'APP', app), mock.patch.object(module, 'UID', 0), mock.patch.object(module, 'GID', 0), \
                     mock.patch.object(module.os, 'fchown', create=True), mock.patch.object(module.os, 'fchmod', create=True), \
                     mock.patch.object(module, 'inspect_configuration', side_effect=lambda content=None: {'candidate_valid': True} if content else before), \
                     mock.patch.object(module, 'authenticate_once'), mock.patch.object(module, 'make_backup', return_value=(backup, state)), \
                     mock.patch.object(module, 'web_cache'), mock.patch.object(module, 'ensure_maintenance', side_effect=maintenance), \
                     mock.patch.object(module, 'artisan') as commands, contextlib.redirect_stdout(output):
                    self.assertEqual(2, module.configure(self.values()))
                    commands.assert_not_called()
                self.assertEqual(drift, path.read_bytes())
                self.assertTrue((app / 'storage/framework/down').is_file())
                self.assertEqual('rollback_uncertain', state['phase'])
                self.assertNotIn('ZOHO_MAIL_CONFIGURATION_COMPLETE=true', output.getvalue())

    def test_cache_edit_after_mail_environment_activation_is_not_overwritten_by_rollback(self):
        with tempfile.TemporaryDirectory(prefix='zoho-precache-drift-', dir=REVIEW if REVIEW.is_dir() else None) as directory:
            app, backup, state, changed = self.fixture(Path(directory))
            native_save = module.save_state
            injected = []
            def save(backup_path, recovery_state):
                native_save(backup_path, recovery_state)
                if recovery_state['phase'] == 'mail_environment_changed' and not injected:
                    (app / 'bootstrap/cache/config.php').write_bytes(b'concurrent-precache-edit')
                    injected.append(True)
            before = {'identity_verified': True, 'providers_loaded': 0, 'mail_fingerprint': 'original', 'protected_configuration_fingerprint': 'protected'}
            with mock.patch.object(module, 'APP', app), mock.patch.object(module, 'UID', 0), mock.patch.object(module, 'GID', 0), \
                 mock.patch.object(module.os, 'fchown', create=True), mock.patch.object(module.os, 'fchmod', create=True), \
                 mock.patch.object(module, 'inspect_configuration', side_effect=lambda content=None: {'candidate_valid': True} if content else before), \
                 mock.patch.object(module, 'authenticate_once'), mock.patch.object(module, 'make_backup', return_value=(backup, state)), \
                 mock.patch.object(module, 'web_cache'), mock.patch.object(module, 'save_state', side_effect=save), \
                 mock.patch.object(module, 'artisan') as commands, contextlib.redirect_stdout(io.StringIO()):
                self.assertEqual(2, module.configure(self.values()))
                commands.assert_not_called()
            self.assertEqual((backup / 'environment.before').read_bytes(), (app / '.env').read_bytes())
            self.assertEqual(b'concurrent-precache-edit', (app / 'bootstrap/cache/config.php').read_bytes())
            self.assertTrue((app / 'storage/framework/down').is_file())

    def test_same_byte_metadata_drift_is_rejected_at_activation_boundary(self):
        with tempfile.TemporaryDirectory(prefix='zoho-metadata-drift-', dir=REVIEW if REVIEW.is_dir() else None) as directory:
            path = Path(directory) / 'environment'
            path.write_bytes(b'unchanged bytes')
            original = module.snapshot(path)
            os.utime(path, ns=(original['atime_ns'], original['mtime_ns'] + 1000000000))
            with self.assertRaises(module.Refused): module.require_unchanged(path, original)

    def test_unchanged_mail_rerun_does_not_undo_an_external_metadata_edit(self):
        with tempfile.TemporaryDirectory(prefix='zoho-nochange-drift-', dir=REVIEW if REVIEW.is_dir() else None) as directory:
            app, backup, state, changed = self.fixture(Path(directory))
            original = state['files']['environment']
            state['new_environment_sha256'] = original['sha256']
            modified_time = original['mtime_ns'] + 1000000000
            os.utime(app / '.env', ns=(original['atime_ns'], modified_time))
            with mock.patch.object(module, 'APP', app), mock.patch.object(module.os, 'fchown', create=True), mock.patch.object(module.os, 'fchmod', create=True):
                with self.assertRaises(module.Refused): module.restore_files(backup, state)
            self.assertEqual(modified_time, (app / '.env').stat().st_mtime_ns)

    def test_absent_cache_target_with_a_linked_parent_is_refused(self):
        with tempfile.TemporaryDirectory(prefix='zoho-linked-parent-', dir=REVIEW if REVIEW.is_dir() else None) as directory:
            root = Path(directory)
            outside = root / 'outside'
            outside.mkdir()
            link = root / 'linked-cache'
            try: os.symlink(outside, link, target_is_directory=True)
            except OSError: self.skipTest('This Windows account cannot create directory symlinks; Linux CI exercises this guard.')
            with self.assertRaises(module.Refused): module.snapshot(link / 'absent-config.php')

    def test_custom_cache_and_storage_path_environment_overrides_are_refused_by_real_php_parser(self):
        windows_php = Path('C:/xampp/php/php.exe')
        php = str(windows_php) if windows_php.is_file() else shutil.which('php')
        if not php: self.skipTest('PHP parser unavailable.')
        vendor = HERE.parents[1] / 'vendor/autoload.php'
        code = module.PHP_INSPECT.replace("require 'vendor/autoload.php';", 'require ' + json.dumps(vendor.as_posix()) + ';')
        for key in ['APP_CONFIG_CACHE','APP_SERVICES_CACHE','APP_PACKAGES_CACHE','APP_ROUTES_CACHE','APP_EVENTS_CACHE','LARAVEL_STORAGE_PATH']:
            with self.subTest(key=key):
                for value in ['', '/unreviewed/outside-uat', '${UNREVIEWED_PATH}']:
                    candidate = (key + '=' + module.dotenv_value(value) + '\n').encode() if value else (key + '=\n').encode()
                    result = subprocess.run([str(php), '-d', 'display_errors=0', '-d', 'log_errors=0', '-r', code],
                        input=json.dumps({'candidate_b64': base64.b64encode(candidate).decode(), 'expected': {}}).encode(), capture_output=True, check=False)
                    self.assertEqual(2, result.returncode)
                    self.assertEqual({'error_class': 'RuntimeException'}, json.loads(result.stdout))
                    self.assertEqual(b'', result.stderr)

    def test_source_guards_no_secret_cli_options_no_send_and_only_two_artisan_commands(self):
        source = (HERE / 'setup-zoho-mail.py').read_text(encoding='utf-8')
        ast.parse(source)
        ast.parse(module.SMTP_AUTH)
        self.assertIn("command in ('config:clear', 'config:cache')", source)
        self.assertNotIn('shell=True', source)
        self.assertNotIn('str(exception)', source)
        self.assertNotIn('getMessage()', source)
        self.assertNotIn('opcache_reset', source)
        self.assertNotIn('sendmail(', module.SMTP_AUTH)
        self.assertNotIn('send_message(', module.SMTP_AUTH)
        self.assertNotIn('set_debuglevel', module.SMTP_AUTH)
        self.assertIn("regular(path)", source)
        self.assertIn("(0o600, 0o640)", source)
        self.assertIn("open('/dev/tty'", source)
        self.assertIn('getpass.getpass', source)
        self.assertIn("['127.0.0.1','::1']", source)
        self.assertIn("hash_equals('__TOKEN__'", source)
        self.assertIn("context=ssl.create_default_context()", module.SMTP_AUTH)
        candidate_return = module.PHP_INSPECT.index('echo \'{"candidate_valid":true}\';exit;')
        self.assertLess(candidate_return, module.PHP_INSPECT.index('$app=new PhosMailConfiguration'))
        self.assertLess(candidate_return, module.PHP_INSPECT.index('new PDO('))
        sql = re.findall(r"query\('([^']+)'\)", module.PHP_INSPECT)
        self.assertEqual(['SELECT DATABASE()'], sql)


if __name__ == '__main__':
    unittest.main()
