import hashlib
import importlib.util
import json
from pathlib import Path
import stat
import types
import unittest
from unittest import mock

SCRIPT = Path(__file__).with_name('phos_uat_pdf_path_inventory.py')
spec = importlib.util.spec_from_file_location('path_inventory', SCRIPT)
inventory = importlib.util.module_from_spec(spec)
spec.loader.exec_module(inventory)


def info(*, kind=stat.S_IFDIR, uid=5006, gid=5006, mode=0o700, size=0, inode=123, mtime=7):
    return types.SimpleNamespace(st_dev=1, st_ino=inode, st_uid=uid, st_gid=gid,
        st_mode=kind | mode, st_size=size, st_mtime_ns=mtime, st_ctime_ns=8)


class RuntimePathInventoryTests(unittest.TestCase):
    def paths(self, environment=None):
        root = inventory.APP.as_posix() + '/storage/app/pdf-runtime'
        return {'temp': root + '/tmp', 'profile': root + '/profile',
                'environment': {'HOME': root + '/home'} if environment is None else environment}

    def test_valid_hyphenated_paths_pass_without_reading_or_creating_them(self):
        with mock.patch.object(Path, 'lstat', return_value=info()), \
             mock.patch.object(Path, 'open') as opened, mock.patch.object(Path, 'mkdir') as created:
            result = inventory.audit_pdf_paths(self.paths())
        self.assertTrue(result['published_gate_compatible'])
        self.assertFalse(result['configured_paths_used_for_execution'])
        self.assertTrue(result['paths']['temp']['metadata_inspected'])
        opened.assert_not_called()
        created.assert_not_called()

    def test_empty_php_array_serialization_is_reported_without_hiding_other_fields(self):
        with mock.patch.object(Path, 'lstat', return_value=info()):
            result = inventory.audit_pdf_paths(self.paths([]))
        self.assertFalse(result['published_gate_compatible'])
        self.assertEqual(['EmptyEnvironmentSerializedAsArray'], result['schema_issues'])
        self.assertEqual('list', result['environment_serialized_type'])
        self.assertEqual({'temp', 'profile'}, set(result['paths']))
        self.assertTrue(all(item['published_gate_compatible'] for item in result['paths'].values()))

    def test_actual_empty_object_is_valid_and_nonempty_array_is_not_normalized(self):
        with mock.patch.object(Path, 'lstat', return_value=info()):
            self.assertTrue(inventory.audit_pdf_paths(self.paths({}))['published_gate_compatible'])
            result = inventory.audit_pdf_paths(self.paths(['SECRET_TOKEN']))
        self.assertEqual(['EnvironmentNotObject'], result['schema_issues'])
        self.assertNotIn('SECRET_TOKEN', json.dumps(result))

    def test_every_known_field_is_classified_and_unknown_environment_keys_are_redacted(self):
        values = self.paths({'HOME': 'SECRET_TOKEN\n', 'AUTH_SECRET_KEY': 'SECRET_VALUE'})
        values['temp'] = '/tmp/outside'
        values['profile'] = inventory.APP.as_posix() + '/storage/../public'
        with mock.patch.object(Path, 'lstat', return_value=info()):
            result = inventory.audit_pdf_paths(values)
        self.assertEqual(3, len(result['paths']))
        self.assertIn('OutsideApplicationStorage', result['paths']['temp']['reasons'])
        self.assertIn('ParentTraversal', result['paths']['profile']['reasons'])
        self.assertEqual(['ControlCharacters', 'NotAbsolutePosixPath'], result['paths']['environment.HOME']['reasons'])
        self.assertEqual(1, result['unrecognized_environment_key_count'])
        self.assertNotIn('AUTH_SECRET_KEY', json.dumps(result))
        self.assertNotIn('SECRET_', json.dumps(result))

    def test_linked_ancestor_is_not_followed_or_opened(self):
        value = inventory.APP.as_posix() + '/storage/app/pdf-runtime/link/child'
        seen = []
        def inspected(path):
            seen.append(path.as_posix())
            return info(kind=stat.S_IFLNK) if path.name == 'link' else info()
        with mock.patch.object(Path, 'lstat', inspected), mock.patch.object(Path, 'open') as opened, \
             mock.patch.object(Path, 'resolve') as resolved:
            result = inventory.configured_path_details(value)
        self.assertFalse(result['published_gate_compatible'])
        self.assertTrue(result['linked_component'])
        self.assertNotIn(value, seen)
        opened.assert_not_called()
        resolved.assert_not_called()

    def test_missing_directory_is_informational_and_is_not_created(self):
        def inspected(path):
            if path.name == 'absent':
                raise FileNotFoundError()
            return info()
        with mock.patch.object(Path, 'lstat', inspected), mock.patch.object(Path, 'mkdir') as created:
            result = inventory.configured_path_details(inventory.APP.as_posix() + '/storage/app/pdf-runtime/absent/child')
        self.assertFalse(result['exists'])
        self.assertTrue(result['published_gate_compatible'], 'Published path gate does not require directories to exist.')
        created.assert_not_called()

    def test_inaccessible_metadata_is_a_named_failure_not_a_missing_path(self):
        with mock.patch.object(Path, 'lstat', side_effect=PermissionError()):
            result = inventory.configured_path_details(self.paths()['temp'])
        self.assertEqual(['MetadataUnavailable'], result['reasons'])
        self.assertNotIn('exists', result)

    def test_malformed_path_values_never_reach_filesystem_metadata(self):
        with mock.patch.object(Path, 'lstat') as inspected:
            for value in [None, 12, 'secret-token', '/tmp/secret\nvalue', '/tmp/USER:PASSWORD', '/tmp/' + 'x' * 401]:
                result = inventory.configured_path_details(value)
                self.assertTrue(result['configured_value_redacted'])
                self.assertFalse(result['published_gate_compatible'])
        inspected.assert_not_called()

    def test_root_trusted_runtime_hash_is_streamed_bounded_and_metadata_verified(self):
        raw = b'#!/usr/bin/php\n<?php echo 1;'
        value = info(kind=stat.S_IFREG, uid=0, gid=0, mode=0o755, size=len(raw))
        with mock.patch.object(inventory.os, 'O_NOFOLLOW', 0, create=True), \
             mock.patch.object(inventory.os, 'O_NONBLOCK', 0, create=True), \
             mock.patch.object(inventory.os, 'open', return_value=42), \
             mock.patch.object(inventory.os, 'fstat', return_value=value), \
             mock.patch.object(inventory.os, 'read', side_effect=[raw, b'']) as read, \
             mock.patch.object(inventory.os, 'close') as closed:
            result, header = inventory.runtime_fingerprint('/usr/bin/node')
        self.assertEqual(hashlib.sha256(raw).hexdigest(), result['sha256'])
        self.assertEqual(len(raw), result['size'])
        self.assertEqual(0, result['uid'])
        self.assertEqual('0o755', result['mode'])
        self.assertEqual(raw, header)
        self.assertTrue(all(call.args == (42, 65536) for call in read.call_args_list))
        closed.assert_called_once_with(42)

    def test_untrusted_or_oversized_runtime_file_is_not_read(self):
        for value in [info(kind=stat.S_IFREG, uid=5006), info(kind=stat.S_IFREG, uid=0, mode=0o777),
                      info(kind=stat.S_IFREG, uid=0, mode=0o755, size=536870913)]:
            with mock.patch.object(inventory.os, 'O_NOFOLLOW', 0, create=True), \
                 mock.patch.object(inventory.os, 'O_NONBLOCK', 0, create=True), \
                 mock.patch.object(inventory.os, 'open', return_value=42), \
                 mock.patch.object(inventory.os, 'fstat', return_value=value), \
                 mock.patch.object(inventory.os, 'read') as read, mock.patch.object(inventory.os, 'close'), \
                 self.assertRaises(inventory.Refused):
                inventory.runtime_fingerprint('/usr/bin/node')
            read.assert_not_called()

    def runtime_case(self, selected='/usr/bin/node', *, php=None, drift=False):
        value = info(kind=stat.S_IFREG, uid=0, gid=0, mode=0o755, size=123)
        metadata = {'device': 1, 'inode': 123, 'uid': 0, 'gid': 0, 'mode': '0o755', 'size': 123,
                    'sha256': 'a' * 64, 'mtime_ns': 7, 'ctime_ns': 8}
        header = b'<?php' if php else b'ELF'
        with mock.patch.object(inventory.shutil, 'which', return_value=selected), \
             mock.patch.object(inventory, 'trusted_executable', return_value=selected), \
             mock.patch.object(inventory, 'runtime_fingerprint', return_value=(metadata, header)), \
             mock.patch.object(Path, 'lstat', return_value=info(kind=stat.S_IFREG, uid=0, gid=0, mode=0o755, size=123, mtime=9) if drift else value), \
             mock.patch.object(inventory, 'version', return_value='Composer version 2.9.5' if php else 'v22.22.2') as version:
            result = inventory.executable_inventory(selected, Path('/tmp/private'), r'pattern', executable=php is None, php=php)
        return result, version

    def test_version_commands_receive_only_fixed_flags_and_private_workdir(self):
        result, version = self.runtime_case()
        self.assertTrue(result['available'])
        self.assertEqual('a' * 64, result['file']['sha256'])
        version.assert_called_once_with(['/usr/bin/node', '--version'], Path('/tmp/private'), 'pattern')
        result, version = self.runtime_case('/usr/local/bin/composer.phar', php=inventory.PHP)
        self.assertTrue(result['available'])
        self.assertEqual([inventory.PHP, '/usr/local/bin/composer.phar', '--no-plugins', '--no-scripts', '--version', '--no-ansi'], version.call_args.args[0])

    def test_runtime_change_during_version_is_reported_without_claiming_availability(self):
        result, _ = self.runtime_case(drift=True)
        self.assertFalse(result['available'])
        self.assertTrue(result['version_executed'])
        self.assertEqual('RuntimeChangedDuringVersion', result['reason'])

    def test_untrusted_or_malformed_runtime_never_executes(self):
        with mock.patch.object(inventory, 'version') as version:
            result = inventory.executable_inventory('node --eval SECRET_VALUE', Path('/tmp/private'), 'pattern')
        self.assertNotIn('SECRET_VALUE', json.dumps(result))
        version.assert_not_called()
        with mock.patch.object(inventory.shutil, 'which', return_value='/tmp/node'), \
             mock.patch.object(inventory, 'trusted_executable', side_effect=inventory.Refused), \
             mock.patch.object(inventory, 'version') as version:
            result = inventory.executable_inventory('/tmp/node', Path('/tmp/private'), 'pattern')
        self.assertFalse(result['available'])
        version.assert_not_called()

    def test_modules_outside_approved_root_are_not_opened_or_imported(self):
        with mock.patch.object(inventory, 'regular') as opened:
            result = inventory.module_inventory('/some-other-app/node_modules')
        self.assertEqual('UnapprovedModuleRoot', result['reason'])
        self.assertFalse(result['import_attempted'])
        opened.assert_not_called()

    def test_only_known_package_metadata_fields_and_hashes_are_returned(self):
        def content(path):
            return json.dumps({'name': path.parent.name, 'version': '25.16.0', 'engines': {'node': '>=22.12.0'},
                               '_authToken': 'SECRET_VALUE'}).encode()
        with mock.patch.object(inventory, 'no_links'), mock.patch.object(Path, 'is_dir', return_value=True), \
             mock.patch.object(inventory, 'regular'), mock.patch.object(Path, 'read_bytes', content):
            result = inventory.module_inventory((inventory.APP / 'node_modules').as_posix())
        self.assertEqual({'puppeteer', 'puppeteer-core'}, set(result['packages']))
        self.assertTrue(all(item['available'] and len(item['metadata_sha256']) == 64 for item in result['packages'].values()))
        self.assertNotIn('SECRET_VALUE', json.dumps(result))

    def test_all_path_and_runtime_failures_are_collected_in_one_inventory(self):
        cfg = {'configuration_cached': True, 'disable_sandbox': True, 'standard_paths': {},
               'pdf_paths': {**self.paths([]), 'temp': '/tmp/outside'}, 'node': 'node',
               'modules': '/some-other-app/node_modules', 'browser': '/usr/bin/chromium',
               'browser_selection': 'fallback', 'arguments': ['--proxy-server=SECRET_VALUE']}
        with mock.patch.object(Path, 'lstat', return_value=info()), \
             mock.patch.object(inventory, 'executable_inventory', return_value={'available': False, 'reason': 'Unavailable'}) as executions:
            result = inventory.inventory_configuration(cfg, Path('/tmp/private'), inventory.PHP)
        self.assertEqual(6, executions.call_count)
        self.assertFalse(result['synthetic_pdf_attempted'])
        self.assertFalse(result['browser_render_session_attempted'])
        self.assertFalse(result['deployment_readiness_claimed'])
        self.assertEqual({'pdf_paths', 'standard_paths', 'node', 'modules', 'browser', 'composer', 'chromium_arguments'},
                         {item['group'] for item in result['input_check_failures']})
        self.assertNotIn('SECRET_VALUE', json.dumps(result))

    def test_failed_network_namespace_stops_before_configuration_or_runtime_commands(self):
        account = types.SimpleNamespace(pw_uid=5006, pw_gid=5006)
        payload = {'target': {'application': str(inventory.APP), 'host': inventory.HOST, 'database': inventory.DATABASE,
                             'php': inventory.PHP, 'owner': 'website_owner'}, 'uid': 5006, 'gid': 5006, 'parent_net_inode': 5}
        with mock.patch.object(inventory.os, 'geteuid', return_value=0, create=True), \
             mock.patch.object(inventory, 'pwd', types.SimpleNamespace(getpwnam=lambda name: account)), \
             mock.patch.object(inventory, 'empty_namespace', side_effect=inventory.Refused), \
             mock.patch.object(inventory, 'bounded_run') as execution, self.assertRaises(inventory.Refused):
            inventory.worker(payload)
        execution.assert_not_called()

    def test_source_has_no_browser_pdf_database_mail_or_configuration_mutation_path(self):
        source = SCRIPT.read_text()
        for forbidden in ('NODE_PROBE', 'puppeteer.launch', 'puppeteer.connect', 'page.pdf', 'new PDO', 'SELECT ',
                          'sendResetLink', 'config:cache', 'config:clear', 'chmod -R'):
            self.assertNotIn(forbidden, source)
        self.assertIn("'providers_loaded'=>0", inventory.PHP_CONFIGURATION)
        self.assertIn("[unshare, '--net', '--'", source)
        self.assertIn("os.setuid(account.pw_uid)", source)


if __name__ == '__main__':
    unittest.main(verbosity=2)
