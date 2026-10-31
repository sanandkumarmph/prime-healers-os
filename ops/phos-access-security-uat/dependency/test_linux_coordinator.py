"""Disposable Linux/root coordinator integration; NO real APP/network/PHP.

This module is safely skipped on other platforms and without root. It imports an
isolated helper module, patches APP and the activation flag only inside each
temporary fixture, and executes the real coordinator/atomic/rename/cache/recovery
code. All network, PHP, DB and service callbacks are replaced, never executed.
"""

from contextlib import ExitStack
import importlib.util
import json
import os
from pathlib import Path
import signal
import stat
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

HERE = Path(__file__).resolve().parent
SPEC = importlib.util.spec_from_file_location('disposable_linux_coordinator_helper', HERE / 'deploy.py')
d = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(d)
REAL_APP = d.APP
ROOT_LINUX = sys.platform.startswith('linux') and hasattr(os, 'geteuid') and os.geteuid() == 0


@unittest.skipUnless(ROOT_LINUX, 'Requires disposable Linux/root ownership fixture; NOT run on this Windows workstation.')
class LinuxCoordinatorIntegration(unittest.TestCase):
    def setUp(self):
        self.assertFalse(d.ACTIVATION_REVIEW_COMPLETE)
        self.assertEqual(d.APP, REAL_APP)
        self.temporary = tempfile.TemporaryDirectory(prefix='phos-dependency-linux-fixture-')
        self.boundary = Path(self.temporary.name).resolve()
        self.assertNotEqual(self.boundary, Path('/'))
        self.assertTrue(self.boundary.name.startswith('phos-dependency-linux-fixture-'))
        self.assertFalse(self.boundary.is_relative_to(REAL_APP))
        # Traversal for the numeric owner child is necessary; only root can list
        # or write the random outer fixture. All actual fixture data is synthetic.
        os.chmod(self.boundary, 0o711)
        self.app = self.boundary / 'synthetic-application'
        self.mkdir(self.app, d.OWNER_ID, 0o750)
        self.backup = self.boundary / 'private-backup'
        self.mkdir(self.backup, 0, 0o700)
        self.stage = self.app / (d.STAGE_PREFIX + 'a' * 24)
        self.mkdir(self.stage, d.OWNER_ID, 0o700)
        self.candidate = self.stage / 'candidate'
        self.mkdir(self.candidate, d.OWNER_ID, 0o700)
        for name in ('app', 'public', 'public/build', 'public/build/assets', 'bootstrap', 'bootstrap/cache',
                     'storage', 'storage/framework', 'storage/framework/views', 'storage/framework/sessions'):
            self.mkdir(self.app / name, d.OWNER_ID, 0o700 if name not in ('public', 'public/build', 'public/build/assets') else 0o755)
        self.before, self.payload, self.records = {}, {}, {}
        self.add_source('app/Example.php', b'<?php /* old synthetic source */\n', b'<?php /* new synthetic source */\n')
        self.add_source('public/build/manifest.json', b'{"fixture":"old"}\n', b'{"fixture":"new"}\n', 'vite_manifest')
        self.add_source('public/build/assets/new.js', None, b'/* new synthetic js */\n', 'vite_asset')
        self.add_source('public/build/assets/new.css', None, b'/* new synthetic css */\n', 'vite_asset')
        self.add_source('composer.json', b'{"fixture":"staging-input"}\n', b'{"fixture":"staging-input"}\n', 'composer_staging_input')
        self.env = (b'APP_ENV=uat\nAPP_DEBUG=false\n'
                    b'APP_URL=https://uat-admin.primehealers.com\nDB_DATABASE=uata_primehealersos\n'
                    b'MAIL_PASSWORD="synthetic-not-a-credential"\n'
                    b'PDF_NODE_BINARY="/synthetic/old/node"\n'
                    b'PDF_NODE_MODULE_PATH="/synthetic/old/modules"\n'
                    b'PDF_BROWSER_PATH="/synthetic/old/browser"\nPDF_DISABLE_SANDBOX=true\n')
        self.write(self.app / '.env', self.env, d.OWNER_ID, 0o600)
        for name in d.CACHE_NAMES:
            self.write(self.app / 'bootstrap/cache' / name, ('<?php /* old ' + name + ' */\n').encode(), d.OWNER_ID, 0o600)
        self.write(self.app / 'storage/framework/views/old.php', b'<?php /* old compiled synthetic view */\n', d.OWNER_ID, 0o600)
        for name in ('vendor', 'node_modules'):
            self.mkdir(self.app / name, d.OWNER_ID, 0o700)
            self.write(self.app / name / 'old.txt', ('old-' + name).encode(), d.OWNER_ID, 0o600)
            self.mkdir(self.candidate / name, d.OWNER_ID, 0o700)
            self.write(self.candidate / name / 'new.txt', ('new-' + name).encode(), d.OWNER_ID, 0o600)
        self.node = self.stage / d.NODE_NAME
        self.mkdir(self.node, 0, 0o755)
        self.mkdir(self.node / 'bin', 0, 0o755)
        self.write(self.node / 'bin/node', b'fixture bytes: never executed\n', 0, 0o755)
        self.write(self.app / 'public/build/assets/old-preserved.js', b'old-preserved-asset\n', d.OWNER_ID, 0o644)
        self.write(self.app / 'unrelated.txt', b'unrelated-synthetic-file\n', 0, 0o640)
        self.write(self.app / 'storage/synthetic-private-proof-journal.txt', b'synthetic sentinel; never an actual proof\n', d.OWNER_ID, 0o600)
        self.manifest = {'files': list(self.records.values()), 'superseded_baseline_assets_to_preserve': [
            {'path': 'public/build/assets/old-preserved.js', 'sha256': d.digest(b'old-preserved-asset\n'), 'size': len(b'old-preserved-asset\n')}]}
        self.inventory = {'browser': {'path': '/opt/phos-disposable-fixture/never-executed-chrome'}}
        self.staged = {'candidate': self.candidate, 'node': self.node}
        self.original_files = {name: d.signature(self.app / name) for name in list(self.before) + ['.env']
                               + ['bootstrap/cache/' + name for name in d.CACHE_NAMES]}
        self.original_directories = {name: d.tree_signature(self.app / relative) for name, relative in
            [('vendor', 'vendor'), ('node_modules', 'node_modules'), ('compiled_views', 'storage/framework/views')]}
        self.preserved = {name: d.signature(self.app / name) for name in
            ('composer.json', 'public/build/assets/old-preserved.js', 'unrelated.txt', 'storage/synthetic-private-proof-journal.txt')}
        self.events = []
        self.fault = None
        self.fired = False
        self.context = ExitStack()
        self.context.enter_context(patch.multiple(d, APP=self.app, ACTIVATION_REVIEW_COMPLETE=True,
            php_configuration=self.fake_php_configuration, role_counts=self.fake_role_counts,
            web_php_probe=self.fake_web_probe, runtime_guards=self.fake_runtime_guards,
            online_checks=self.fake_online_checks, http_check=self.fake_http, run=self.fake_artisan))
        self.addCleanup(self.cleanup_fixture)

    def cleanup_fixture(self):
        self.context.close()
        self.assertFalse(d.ACTIVATION_REVIEW_COMPLETE)
        self.assertEqual(d.APP, REAL_APP)
        # Validate the exact resolved outer root before tempfile's recursive cleanup.
        self.assertEqual(Path(self.temporary.name).resolve(), self.boundary)
        self.assertTrue(self.boundary.name.startswith('phos-dependency-linux-fixture-'))
        self.assertFalse(self.boundary.is_relative_to(REAL_APP))
        self.temporary.cleanup()

    def mkdir(self, path, uid, mode):
        path.mkdir(exist_ok=True)
        os.chown(path, uid, uid)
        os.chmod(path, mode)

    def write(self, path, data, uid, mode):
        self.assertTrue(path.is_relative_to(self.boundary))
        d.atomic(path, data, uid, uid, mode)

    def add_source(self, name, old, new, kind='git_source'):
        if old is not None:
            self.write(self.app / name, old, 0, 0o644)
            self.before[name] = old
        self.payload[name] = new
        self.records[name] = {'path': name, 'kind': kind, 'before': None if old is None else {'sha256': d.digest(old), 'size': len(old)},
                              'after': {'sha256': d.digest(new), 'size': len(new)}}

    def owner_write(self, values):
        """Real permanent UID/GID5006 drop, no command, network, or user lookup."""
        for path in values:
            self.assertTrue(path.is_relative_to(self.app))
            d.no_links(path)
        reader, writer = os.pipe()
        pid = os.fork()
        if pid == 0:
            os.close(reader)
            try:
                os.setgroups([])
                os.setgid(d.OWNER_ID)
                os.setuid(d.OWNER_ID)
                os.umask(0o077)
                if os.geteuid() != d.OWNER_ID or os.getegid() != d.OWNER_ID:
                    os._exit(80)
                for path, data in values.items():
                    with path.open('wb') as output:
                        output.write(data)
                    os.chmod(path, 0o600)
                os._exit(0)
            except BaseException as error:
                os.write(writer, type(error).__name__.encode()[:100])
                os._exit(81)
        os.close(writer)
        deadline = time.monotonic() + 10
        status = None
        while time.monotonic() < deadline:
            ended, value = os.waitpid(pid, os.WNOHANG)
            if ended:
                status = value
                break
            time.sleep(0.02)
        if status is None:
            os.kill(pid, signal.SIGKILL)
            os.waitpid(pid, 0)
        message = os.read(reader, 1024).decode(errors='replace')
        os.close(reader)
        self.assertIsNotNone(status, 'Bounded synthetic owner writer timed out.')
        self.assertTrue(os.WIFEXITED(status) and os.WEXITSTATUS(status) == 0, message)
        for path in values:
            value = path.stat()
            self.assertEqual((value.st_uid, value.st_gid, stat.S_IMODE(value.st_mode)), (d.OWNER_ID, d.OWNER_ID, 0o600))

    def fake_php_configuration(self, root, action, environment):
        self.assertEqual(root, self.app)
        self.events.append('php-configuration')
        if action == d.IDENTITY_ACTION:
            return {'identity': True, 'uid': d.OWNER_ID, 'providers': 0}
        self.assertEqual(action, d.LIVE_SANDBOX_ACTION)
        self.assert_installed()
        return {'safe_effective_arguments': True, 'disable_sandbox': False,
                'node': str(self.node / 'bin/node'), 'modules': str(self.app / 'node_modules'),
                'browser': self.inventory['browser']['path']}

    def fake_role_counts(self, root, environment):
        self.assertEqual(root, self.app)
        self.events.append('counts-only-stub')
        return {'active_users': 1, 'invalid_active_role_links': 0, 'active_users_missing_organization': 0,
                'organizations_without_effective_owner': 0}

    def fake_web_probe(self, backup, invalidate):
        self.assertEqual(backup, self.backup)
        self.events.append(('web-probe', invalidate, (self.app / 'app/Example.php').read_bytes()))
        if self.fault == 'persistent-web-runtime' and invalidate:
            self.fired = True
            raise RuntimeError('synthetic persistent web runtime failure')
        return {'ok': True, 'effective_uid': d.OWNER_ID, 'php': '8.2.30', 'invalidated': 1 if invalidate else 0}

    def fake_runtime_guards(self, environment):
        self.events.append('runtime-guards-stub')
        self.assert_installed()

    def fake_artisan(self, arguments, *, cwd, environment, **unused):
        self.assertEqual(cwd, self.app)
        self.assertEqual(str(arguments[0]), str(d.PHP))
        self.assertEqual(arguments[3], 'artisan')
        command = arguments[4]
        self.assertIn(command, ('package:discover', 'config:cache', 'route:cache', 'view:cache'))
        self.events.append(('artisan-service-stub', command))
        if command == 'package:discover':
            values = {Path(environment['APP_PACKAGES_CACHE']): b'<?php /* new packages */\n',
                      Path(environment['APP_SERVICES_CACHE']): b'<?php /* new services */\n'}
        elif command == 'config:cache':
            values = {Path(environment['APP_CONFIG_CACHE']): b'<?php /* new config */\n'}
        elif command == 'route:cache':
            values = {Path(environment['APP_ROUTES_CACHE']): b'<?php /* new routes */\n'}
        else:
            values = {Path(environment['VIEW_COMPILED_PATH']) / 'new.php': b'<?php /* new compiled synthetic view */\n'}
        if self.fault == 'cache-command-foreign' and command == 'config:cache':
            values[self.app / 'bootstrap/cache/config.php'] = b'<?php /* foreign concurrent cache */\n'
            self.owner_write(values)
            self.fired = True
            raise RuntimeError('synthetic cache command failure with foreign live bytes')
        self.owner_write(values)
        return b''

    def fake_online_checks(self, manifest, backup):
        self.assertFalse((self.app / 'storage/framework/down').exists())
        self.assert_installed()
        self.events.append('online-checks-stub')
        if self.fault == 'foreign-online':
            self.owner_write({self.app / 'app/Example.php': b'<?php /* foreign concurrent source */\n',
                              self.app / '.env': b'MAIL_PASSWORD="synthetic foreign env"\n',
                              self.app / 'bootstrap/cache/config.php': b'<?php /* foreign concurrent cache */\n'})
            os.rename(self.app / 'vendor', self.app / 'admin-held-vendor')
            self.fired = True
            raise RuntimeError('synthetic failed online check with concurrent edits')

    def fake_http(self, path, backup, *, local, header=None):
        self.assertIn(path, ('/login', '/up'))
        self.assertEqual(backup, self.backup)
        self.assert_restored()
        self.assertFalse((self.app / 'storage/framework/down').exists())
        self.events.append(('http-recovery-stub', path, local))
        return '200', b'fixture', ''

    def invoke(self):
        self.assertTrue(self.app.is_relative_to(self.boundary))
        self.assertNotEqual(self.app, REAL_APP)
        return d.activate_reviewed(self.stage, self.inventory, self.manifest, self.records,
                                   self.payload, self.staged, self.backup)

    def state(self):
        return json.loads((self.backup / 'recovery-state.json').read_bytes())

    def assert_preserved(self):
        for name, expected in self.preserved.items():
            self.assertEqual(d.signature(self.app / name), expected, name)

    def assert_installed(self):
        for name, value in self.payload.items():
            self.assertEqual((self.app / name).read_bytes(), value, name)
            if self.records[name]['kind'] != 'composer_staging_input':
                record = (self.app / name).stat()
                self.assertEqual((record.st_uid, record.st_gid, stat.S_IMODE(record.st_mode)),
                                 (d.OWNER_ID, d.OWNER_ID, 0o644), name)
        self.assertEqual((self.app / 'vendor/new.txt').read_bytes(), b'new-vendor')
        self.assertEqual((self.app / 'node_modules/new.txt').read_bytes(), b'new-node_modules')
        self.assertTrue((self.app / 'storage/framework/views/new.php').exists())
        self.assertFalse((self.app / 'storage/framework/views/old.php').exists())
        self.assertIn(b'PDF_DISABLE_SANDBOX="false"\n', (self.app / '.env').read_bytes())
        self.assertEqual(d.unchanged_env_bytes(self.env), d.unchanged_env_bytes((self.app / '.env').read_bytes()))
        for name in ('.env',):
            record = (self.app / name).stat()
            self.assertEqual((record.st_uid, record.st_gid, stat.S_IMODE(record.st_mode)), (d.OWNER_ID, d.OWNER_ID, 0o600))
        for name in ('vendor', 'node_modules', 'storage/framework/views'):
            record = (self.app / name).stat()
            self.assertEqual((record.st_uid, record.st_gid, stat.S_IMODE(record.st_mode)), (d.OWNER_ID, d.OWNER_ID, 0o700))
        for name in d.CACHE_NAMES:
            value = (self.app / 'bootstrap/cache' / name).stat()
            self.assertEqual((value.st_uid, value.st_gid, stat.S_IMODE(value.st_mode)), (d.OWNER_ID, d.OWNER_ID, 0o600))
        self.assert_preserved()

    def assert_restored(self):
        for name, expected in self.original_files.items():
            current = d.signature(self.app / name)
            self.assertTrue(d.same_contents(current, expected), name)
        for name, relative in [('vendor', 'vendor'), ('node_modules', 'node_modules'), ('compiled_views', 'storage/framework/views')]:
            # Original directory inode and every contained inode survive renames.
            self.assertEqual(d.tree_signature(self.app / relative), self.original_directories[name], name)
        for name, entry in self.records.items():
            if entry['before'] is None:
                self.assertFalse((self.app / name).exists(), name)
        self.assert_preserved()

    def test_complete_activation_installs_real_files_modes_and_directories(self):
        self.invoke()
        self.assert_installed()
        state = self.state()
        self.assertTrue(state['complete'])
        self.assertEqual(state['phase'], 'complete')
        self.assertFalse((self.app / 'storage/framework/down').exists())
        slot = Path(state['slot'])
        for name in ('vendor', 'node_modules', 'compiled_views'):
            self.assertEqual(d.tree_signature(slot / name), self.original_directories[name])

    def test_mid_source_failure_undoes_only_written_source_and_new_assets(self):
        original_install = d.FileRecovery.install
        def install_then_fail(instance, name, *args):
            value = original_install(instance, name, *args)
            if name == 'public/build/assets/new.js':
                self.fired = True
                raise RuntimeError('synthetic mid-source interruption')
            return value
        with patch.object(d.FileRecovery, 'install', new=install_then_fail), self.assertRaises(d.Refused):
            self.invoke()
        self.assertTrue(self.fired)
        self.assert_restored()
        self.assertTrue(self.state()['recovery']['complete'])
        self.assertFalse((self.app / 'storage/framework/down').exists())

    def test_post_cache_persistent_runtime_failure_restores_before_probes(self):
        self.fault = 'persistent-web-runtime'
        with self.assertRaises(d.Refused):
            self.invoke()
        self.assert_restored()
        recovery = self.state()['recovery']
        self.assertTrue(recovery['source_dependencies_restored'])
        self.assertFalse(recovery['complete'])
        self.assertTrue(recovery['runtime_recovery_failed'])
        self.assertTrue((self.app / 'storage/framework/down').exists())
        probes = [entry for entry in self.events if isinstance(entry, tuple) and entry[0] == 'web-probe']
        self.assertEqual(probes[-1][2], self.before['app/Example.php'])

    def test_foreign_source_env_cache_and_missing_dependency_are_preserved(self):
        self.fault = 'foreign-online'
        with self.assertRaises(d.Refused):
            self.invoke()
        self.assertEqual((self.app / 'app/Example.php').read_bytes(), b'<?php /* foreign concurrent source */\n')
        self.assertEqual((self.app / '.env').read_bytes(), b'MAIL_PASSWORD="synthetic foreign env"\n')
        self.assertEqual((self.app / 'bootstrap/cache/config.php').read_bytes(), b'<?php /* foreign concurrent cache */\n')
        self.assertFalse((self.app / 'vendor').exists())
        self.assertEqual((self.app / 'admin-held-vendor/new.txt').read_bytes(), b'new-vendor')
        state = self.state()
        self.assertEqual(d.tree_signature(Path(state['slot']) / 'vendor'), self.original_directories['vendor'])
        self.assertEqual(set(state['recovery']['unknown_state_preserved']), {'app/Example.php', '.env', 'bootstrap/cache/config.php', 'vendor'})
        self.assertTrue((self.app / 'storage/framework/down').exists())
        self.assertEqual(d.tree_signature(self.app / 'node_modules'), self.original_directories['node_modules'])
        self.assertEqual(d.tree_signature(self.app / 'storage/framework/views'), self.original_directories['compiled_views'])
        self.assertFalse(any(isinstance(event, tuple) and event[0] == 'http-recovery-stub' for event in self.events))
        self.assert_preserved()

    def injected_rename(self, after_candidate):
        real_rename = os.rename
        def rename_then_fail(source, destination):
            value = real_rename(source, destination)
            is_candidate = Path(source) == self.candidate / 'vendor'
            is_old = Path(source) == self.app / 'vendor' and Path(destination).parent.name.startswith(d.SLOT_PREFIX)
            if not self.fired and (is_candidate if after_candidate else is_old):
                self.fired = True
                raise RuntimeError('synthetic rename/journal interruption')
            return value
        with patch.object(d.os, 'rename', side_effect=rename_then_fail), self.assertRaises(d.Refused):
            self.invoke()
        self.assertTrue(self.fired)
        self.assert_restored()
        self.assertTrue(self.state()['recovery']['complete'])

    def test_actual_first_dependency_rename_failure_recovers_owned_gap(self):
        self.injected_rename(after_candidate=False)

    def test_actual_candidate_rename_before_journal_failure_recovers_known_tree(self):
        self.injected_rename(after_candidate=True)

    def test_failed_cache_command_does_not_take_ownership_of_foreign_live_cache(self):
        self.fault = 'cache-command-foreign'
        with self.assertRaises(d.Refused):
            self.invoke()
        self.assertEqual((self.app / 'app/Example.php').read_bytes(), self.before['app/Example.php'])
        self.assertEqual((self.app / '.env').read_bytes(), self.env)
        self.assertEqual((self.app / 'bootstrap/cache/config.php').read_bytes(), b'<?php /* foreign concurrent cache */\n')
        for name, relative in [('vendor', 'vendor'), ('node_modules', 'node_modules'), ('compiled_views', 'storage/framework/views')]:
            self.assertEqual(d.tree_signature(self.app / relative), self.original_directories[name])
        self.assertEqual(self.state()['recovery']['unknown_state_preserved'], ['bootstrap/cache/config.php'])
        self.assertTrue((self.app / 'storage/framework/down').exists())
        self.assert_preserved()

    def test_persistent_journal_failure_cannot_block_source_or_dependency_undo(self):
        real_atomic = d.atomic
        def atomic_with_failed_journal(path, *args):
            if path == self.backup / 'recovery-state.json':
                if (self.app / 'app/Example.php').read_bytes() == self.payload['app/Example.php']:
                    self.fired = True
                if self.fired:
                    raise OSError('synthetic persistent private journal write failure')
            return real_atomic(path, *args)
        with patch.object(d, 'atomic', side_effect=atomic_with_failed_journal), self.assertRaisesRegex(d.Refused, 'rollback_incomplete'):
            self.invoke()
        self.assertTrue(self.fired)
        self.assert_restored()
        self.assertTrue((self.app / 'storage/framework/down').exists())


if __name__ == '__main__':
    unittest.main()
