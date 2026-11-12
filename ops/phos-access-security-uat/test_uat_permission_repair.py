import copy
import hashlib
import importlib.util
import json
from pathlib import Path
import types
import unittest
from unittest import mock

SCRIPT = Path(__file__).with_name('phos_uat_permission_repair.py')
spec = importlib.util.spec_from_file_location('permission_probe', SCRIPT)
repair = importlib.util.module_from_spec(spec)
spec.loader.exec_module(repair)


def metadata(kind='file', *, uid=5006, gid=65534, mode=None, inode=123):
    result = {'device': 1, 'inode': inode, 'uid': uid, 'gid': gid,
              'mode': mode if mode is not None else (0o644 if kind == 'file' else 0o775),
              'kind': kind, 'ctime_ns': 10}
    if kind == 'file':
        result.update({'size': 4, 'sha256': hashlib.sha256(b'test').hexdigest(), 'mtime_ns': 5, 'nlink': 1})
    return result


class PermissionRepairTests(unittest.TestCase):
    def plan(self, names=('config.php', 'routes-v7.php')):
        items = {'.env': metadata()}
        for relative in repair.PRIVATE_DIRS + repair.TRAVERSAL_DIRS:
            items[relative] = metadata('directory')
        for name in names:
            items['bootstrap/cache/' + name] = metadata(inode=200 + len(items))
        def read(path):
            return copy.deepcopy(items[Path(path).relative_to(repair.APP).as_posix()])
        entries = [repair.APP / 'bootstrap/cache' / name for name in names]
        return items, read, entries

    def test_plan_is_bounded_and_preserves_static_serving_group(self):
        _, read, entries = self.plan()
        with mock.patch.object(repair, 'snapshot', side_effect=read), mock.patch.object(repair, 'no_links'), \
             mock.patch.object(Path, 'exists', return_value=True), mock.patch.object(Path, 'iterdir', return_value=iter(entries)):
            records, missing, names = repair.collect_plan()
        planned = {record['path']: record['desired'] for record in records}
        self.assertEqual({'uid': 5006, 'gid': 5006, 'mode': 0o600}, planned['.env'])
        self.assertEqual({'uid': 5006, 'gid': 5006, 'mode': 0o700}, planned['bootstrap/cache'])
        self.assertEqual({'uid': 5006, 'gid': 65534, 'mode': 0o750}, planned['storage'])
        self.assertEqual({'uid': 5006, 'gid': 65534, 'mode': 0o750}, planned['storage/app'])
        self.assertEqual([], missing)
        self.assertEqual(['config.php', 'routes-v7.php'], names)
        self.assertLessEqual(len(records), 41)
        self.assertTrue(all(repair.allowed_relative(record['path']) for record in records))
        self.assertFalse(any('public' in record['path'] or 'images' in record['path'] for record in records))
        order = [record['path'] for record in records]
        self.assertLess(order.index('storage'), order.index('storage/framework'))
        self.assertLess(order.index('bootstrap'), order.index('bootstrap/cache'))

    def test_preflight_reports_all_fixed_entry_ownership_issues_without_writes(self):
        items, read, entries = self.plan()
        items['storage/framework']['uid'] = 0
        items['storage/logs']['uid'] = 5007
        with mock.patch.object(repair, 'snapshot', side_effect=read), mock.patch.object(repair, 'no_links'), \
             mock.patch.object(Path, 'exists', return_value=True), mock.patch.object(Path, 'iterdir', return_value=iter(entries)):
            with self.assertRaises(repair.PreflightRefused) as caught:
                repair.collect_plan()
        self.assertEqual({'storage/framework', 'storage/logs'}, {issue['label'] for issue in caught.exception.issues})
        self.assertTrue(all(issue['reason'] == 'UnexpectedOwner' for issue in caught.exception.issues))

    def test_non_target_group_writable_boundary_is_refused(self):
        def read(path):
            return metadata('directory', mode=0o775 if path == repair.APP / 'public' else 0o750)
        with mock.patch.object(repair, 'snapshot', side_effect=read):
            with self.assertRaises(repair.PreflightRefused) as caught:
                repair.collect_boundaries()
        self.assertEqual('public_boundary', caught.exception.issues[0]['label'])

    def test_unknown_or_linked_cache_file_prevents_plan(self):
        _, read, _ = self.plan()
        for name, linked in [('unexpected.php', False), ('config.php', True), ('other.PHP', False)]:
            with self.subTest(name=name), mock.patch.object(repair, 'snapshot', side_effect=read), \
                 mock.patch.object(repair, 'no_links'), mock.patch.object(Path, 'exists', return_value=True), \
                 mock.patch.object(Path, 'iterdir', return_value=iter([repair.APP / 'bootstrap/cache' / name])), \
                 mock.patch.object(Path, 'is_symlink', return_value=linked):
                with self.assertRaises(repair.Refused):
                    repair.collect_plan()

    def test_root_nonwritable_traversal_parent_is_preserved(self):
        items, read, entries = self.plan()
        items['storage'] = metadata('directory', uid=0, gid=0, mode=0o755)
        with mock.patch.object(repair, 'snapshot', side_effect=read), mock.patch.object(repair, 'no_links'), \
             mock.patch.object(Path, 'exists', return_value=True), mock.patch.object(Path, 'iterdir', return_value=iter(entries)):
            records, _, _ = repair.collect_plan()
        self.assertEqual({'uid': 0, 'gid': 0, 'mode': 0o755}, next(item for item in records if item['path'] == 'storage')['desired'])

    def test_wrong_file_owner_and_unsafe_root_parent_are_refused(self):
        for path, value in [('.env', metadata(uid=5007)), ('storage', metadata('directory', uid=0, mode=0o775))]:
            items, read, entries = self.plan()
            items[path] = value
            with self.subTest(path=path), mock.patch.object(repair, 'snapshot', side_effect=read), \
                 mock.patch.object(repair, 'no_links'), mock.patch.object(Path, 'exists', return_value=True), \
                 mock.patch.object(Path, 'iterdir', return_value=iter(entries)):
                with self.assertRaises(repair.Refused):
                    repair.collect_plan()

    def test_effective_web_uid_is_required_but_effective_gid_can_differ(self):
        path = repair.APP / 'public' / ('phos-permission-probe-' + 'a' * 32 + '.php')
        value = {'effective_uid': 5006, 'effective_gid': 65534, 'env_readable': True,
                 'cache_files_readable': True, 'cache_file_count': 4,
                 'directories': dict.fromkeys(['cache', 'framework', 'logs', 'private', 'pdf'], True)}
        with mock.patch.object(repair, 'https_get', return_value=json.dumps(value).encode()):
            self.assertEqual(65534, repair.verify_web(path, 'b' * 64)['effective_gid'])
        for invalid in ({**value, 'effective_uid': 5007}, {**value, 'env_readable': False},
                        {**value, 'directories': {**value['directories'], 'cache': False}}):
            with self.subTest(invalid=invalid), mock.patch.object(repair, 'https_get', return_value=json.dumps(invalid).encode()):
                with self.assertRaises(repair.Refused):
                    repair.verify_web(path, 'b' * 64)
        with mock.patch.object(repair, 'https_get', return_value=json.dumps({**value, 'cache_files_readable': False}).encode()):
            self.assertFalse(repair.verify_web(path, 'b' * 64)['cache_files_readable'])
            with self.assertRaises(repair.Refused):
                repair.verify_web(path, 'b' * 64, require_cache_files=True)
        self.assertIn('posix_geteuid', repair.WEB_PHP)
        self.assertIn('/proc/self/status', repair.WEB_PHP)
        for wrong in ('getmyuid', 'getmygid', 'get_current_user', 'vendor/autoload', 'getenv', 'PDO'):
            self.assertNotIn(wrong, repair.WEB_PHP)

    def test_tls_connect_is_loopback_with_fixed_verified_host(self):
        raw = mock.MagicMock()
        context = mock.MagicMock()
        connection = types.SimpleNamespace(verified_context=context)
        with mock.patch.object(repair.socket, 'create_connection', return_value=raw) as connect:
            repair.FixedUatHttps.connect(connection)
        connect.assert_called_once_with(('127.0.0.1', 443), timeout=12)
        context.wrap_socket.assert_called_once_with(raw, server_hostname='uat-admin.primehealers.com')
        self.assertIn('ssl.create_default_context()', SCRIPT.read_text())
        self.assertIn('check_hostname', SCRIPT.read_text())
        self.assertIn('ssl.CERT_REQUIRED', SCRIPT.read_text())
        for path in ('http://127.0.0.1', 'https://other.example/login', '/admin', '/.env'):
            with self.subTest(path=path), self.assertRaises(repair.Refused):
                repair.https_get(path)

    def test_health_checks_do_not_read_login_body(self):
        connection = mock.MagicMock()
        connection.getresponse.return_value.status = 200
        with mock.patch.object(repair, 'FixedUatHttps', return_value=connection):
            self.assertEqual(b'', repair.https_get('/login'))
        connection.getresponse.return_value.read.assert_not_called()
        connection.request.assert_called_once()
        self.assertEqual('GET', connection.request.call_args.args[0])

    def test_redirect_or_tls_failure_has_no_fallback(self):
        connection = mock.MagicMock()
        connection.getresponse.return_value.status = 302
        with mock.patch.object(repair, 'FixedUatHttps', return_value=connection):
            with self.assertRaises(repair.Refused):
                repair.https_get('/up')
        self.assertEqual(1, connection.request.call_count)
        connection.close.assert_called_once()

    def test_wrong_web_identity_changes_no_target_metadata_or_backup(self):
        path = repair.APP / 'public' / ('phos-permission-probe-' + 'a' * 32 + '.php')
        with mock.patch.object(repair, 'target'), mock.patch.object(repair, 'collect_plan', return_value=([], [], [])), \
             mock.patch.object(repair, 'collect_boundaries', return_value={}), \
             mock.patch.object(repair, 'create_probe', return_value=(path, 'b' * 64, metadata())), \
             mock.patch.object(repair, 'verify_web', side_effect=repair.Refused), \
             mock.patch.object(repair, 'make_backup') as backup, mock.patch.object(repair, 'set_record') as change, \
             mock.patch.object(repair, 'remove_probe') as remove:
            report = repair.apply()
        self.assertEqual('refused', report['status'])
        self.assertEqual('web_identity', report['phase'])
        self.assertTrue(report['probe_removed'])
        backup.assert_not_called()
        change.assert_not_called()
        remove.assert_called_once()

    def test_probe_cleanup_refuses_changed_inode_or_bytes(self):
        path = repair.APP / 'public' / ('phos-permission-probe-' + 'a' * 32 + '.php')
        expected = metadata()
        for drift in ({**expected, 'inode': 999}, {**expected, 'sha256': 'changed'}):
            with self.subTest(drift=drift), mock.patch.object(repair, 'snapshot', return_value=drift), \
                 mock.patch.object(Path, 'unlink') as unlink:
                with self.assertRaises(repair.Refused):
                    repair.remove_probe(path, expected)
                unlink.assert_not_called()

    def test_fd_permission_change_and_partial_failure_are_journalled_and_reversible(self):
        before = metadata()
        state = copy.deepcopy(before)
        entry = {'path': '.env', 'before': before, 'after': copy.deepcopy(before), 'desired': {'uid': 5006, 'gid': 5006, 'mode': 0o600}, 'changed': False, 'state': 'prepared'}
        journal = {'records': [entry]}
        def chmod(_, mode):
            state['mode'] = mode; state['ctime_ns'] += 1
        attempts = [0]
        def chown(_, uid, gid):
            attempts[0] += 1
            if attempts[0] == 1:
                raise PermissionError('SYNTHETIC_SECRET_MUST_NOT_BE_PRINTED')
            state.update(uid=uid, gid=gid); state['ctime_ns'] += 1
        with mock.patch.object(repair, 'open_checked', side_effect=lambda _: (42, copy.deepcopy(state))), \
             mock.patch.object(repair, 'fingerprint_fd', side_effect=lambda _: copy.deepcopy(state)), \
             mock.patch.object(repair.os, 'fchmod', side_effect=chmod, create=True), \
             mock.patch.object(repair.os, 'fchown', side_effect=chown, create=True), \
             mock.patch.object(repair.os, 'close'), mock.patch.object(repair, 'write_journal'):
            with self.assertRaises(PermissionError):
                repair.set_record(entry, Path('/root/private'), journal)
            self.assertTrue(entry['changed'])
            self.assertEqual('failed_after_verified_mutation', entry['state'])
            self.assertEqual([], repair.rollback_records([entry]))
        self.assertEqual(repair.attributes(before), repair.attributes(state))
        self.assertEqual(before['sha256'], state['sha256'])
        self.assertEqual('restored', entry['state'])

    def test_root_cache_becomes_owner_readable_before_restrictive_mode(self):
        before = metadata(uid=0, gid=0)
        state, events = copy.deepcopy(before), []
        entry = {'path': 'bootstrap/cache/config.php', 'before': before, 'after': copy.deepcopy(before),
                 'desired': {'uid': 5006, 'gid': 5006, 'mode': 0o600}, 'changed': False, 'state': 'prepared'}
        def chmod(_, mode):
            events.append('mode'); state['mode'] = mode; state['ctime_ns'] += 1
        def chown(_, uid, gid):
            events.append('owner'); state.update(uid=uid, gid=gid); state['ctime_ns'] += 1
        with mock.patch.object(repair, 'open_checked', return_value=(42, copy.deepcopy(before))), \
             mock.patch.object(repair, 'fingerprint_fd', side_effect=lambda _: copy.deepcopy(state)), \
             mock.patch.object(repair.os, 'fchmod', side_effect=chmod, create=True), \
             mock.patch.object(repair.os, 'fchown', side_effect=chown, create=True), \
             mock.patch.object(repair.os, 'close'), mock.patch.object(repair, 'write_journal'):
            repair.set_record(entry, Path('/root/private'), {'records': [entry]})
        self.assertEqual(['owner', 'mode'], events)
        self.assertEqual(entry['desired'], repair.attributes(state))

    def test_rollback_never_overwrites_foreign_metadata_content_or_replaced_file(self):
        before = metadata()
        after = {**before, 'mode': 0o600, 'gid': 5006, 'ctime_ns': 20}
        entry = {'path': '.env', 'before': before, 'after': after, 'changed': True, 'state': 'applied'}
        for drift in ({**after, 'inode': 999}, {**after, 'sha256': 'changed'}, {**after, 'ctime_ns': 21}, {**after, 'mode': 0o640}):
            with self.subTest(drift=drift), mock.patch.object(repair, 'open_checked', return_value=(42, drift)), \
                 mock.patch.object(repair.os, 'close'), mock.patch.object(repair.os, 'fchmod', create=True) as chmod, \
                 mock.patch.object(repair.os, 'fchown', create=True) as chown:
                self.assertEqual(['.env'], repair.rollback_records([copy.deepcopy(entry)]))
                chmod.assert_not_called(); chown.assert_not_called()

    def test_crash_between_mutation_and_post_journal_is_uncertain(self):
        before = metadata()
        entry = {'path': '.env', 'before': before, 'after': before, 'changed': False, 'state': 'applying'}
        with mock.patch.object(repair, 'snapshot', return_value={**before, 'mode': 0o600, 'ctime_ns': 20}):
            self.assertEqual(['.env'], repair.rollback_records([entry]))

    def test_failure_restores_attributes_before_any_later_health_request(self):
        events = []
        path = repair.APP / 'public' / ('phos-permission-probe-' + 'a' * 32 + '.php')
        record = {'path': '.env', 'changed': True, 'state': 'applied'}
        journal = {'records': [record]}
        with mock.patch.object(repair, 'target'), mock.patch.object(repair, 'collect_plan', return_value=([record], [], [])), \
             mock.patch.object(repair, 'collect_boundaries', return_value={}), mock.patch.object(repair, 'assert_boundaries'), \
             mock.patch.object(repair, 'create_probe', return_value=(path, 'b' * 64, metadata())), \
             mock.patch.object(repair, 'verify_web', return_value={'effective_uid': 5006}), \
             mock.patch.object(repair, 'assert_plan_current'), mock.patch.object(repair, 'make_backup', return_value=(Path('/root/private'), journal)), \
             mock.patch.object(repair, 'set_record', side_effect=lambda *args: (_ for _ in ()).throw(PermissionError('secret'))), \
             mock.patch.object(repair, 'rollback_records', side_effect=lambda _: events.append('rollback') or []), \
             mock.patch.object(repair, 'https_get', side_effect=lambda *args: events.append('health')), \
             mock.patch.object(repair, 'write_journal'), mock.patch.object(repair, 'remove_probe'):
            report = repair.apply()
        self.assertEqual(['rollback'], events)
        self.assertTrue(report['attributes_restored'])
        self.assertNotIn('secret', json.dumps(report))

    def test_recovery_journal_cannot_grant_an_unreviewed_owner_or_mode(self):
        before = metadata()
        entry = {'path': '.env', 'before': before, 'after': copy.deepcopy(before),
                 'desired': {'uid': 5006, 'gid': 5006, 'mode': 0o600}, 'changed': False, 'state': 'prepared'}
        repair.validate_record(entry)
        for drift in ({**entry, 'path': 'public/index.php'}, {**entry, 'desired': {'uid': 0, 'gid': 0, 'mode': 0o777}},
                      {**entry, 'after': {**before, 'sha256': '0' * 64}}):
            with self.subTest(drift=drift), self.assertRaises(repair.Refused):
                repair.validate_record(drift)

    def test_no_recursion_source_write_framework_command_or_process_identity_change(self):
        source = SCRIPT.read_text()
        for forbidden in ('os.walk', 'rglob', 'rmtree', 'artisan', 'subprocess', 'setuid', 'setgid', 'opcache_', 'config:cache', 'chmod -R', 'chown -R'):
            self.assertNotIn(forbidden, source)
        for path in ('public/images', 'storage/app/public', 'storage/public'):
            self.assertFalse(repair.allowed_relative(path))


if __name__ == '__main__':
    unittest.main(verbosity=2)
