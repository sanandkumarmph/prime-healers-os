import importlib.util
import json
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tempfile
import types
import unittest
from unittest import mock

SCRIPT = Path(__file__).with_name('phos_uat_pdf_diagnostic.py')
spec = importlib.util.spec_from_file_location('phos_pdf_probe', SCRIPT)
probe = importlib.util.module_from_spec(spec)
spec.loader.exec_module(probe)


class PdfDiagnosticTests(unittest.TestCase):
    def target(self):
        return {'application': str(probe.APP), 'host': probe.HOST, 'database': probe.DATABASE,
                'php': probe.PHP, 'owner': 'website_owner'}

    def test_only_exact_fixed_uat_target_is_accepted(self):
        self.assertEqual('website_owner', probe.validate_target_values(self.target()))
        for key, value in [('application', '/home/admin/public_html'), ('host', 'admin.primehealers.com'),
                           ('database', 'production'), ('php', '/usr/bin/php'), ('owner', 'root')]:
            with self.subTest(key=key), self.assertRaises(probe.Refused):
                probe.validate_target_values({**self.target(), key: value})

    def test_symlinked_parent_is_refused(self):
        path = Path('/tmp/fixed/child')
        with mock.patch.object(Path, 'is_symlink', lambda item: item == path.parent):
            with self.assertRaises(probe.Refused):
                probe.no_links(path)

    def test_vhost_accepts_root_metadata_and_only_the_exact_observed_uat_exception(self):
        for uid, gid, mode in [(0, 0, 0o600), (0, 5006, 0o640), (0, 65534, 0o750), (65534, 65534, 0o750)]:
            with self.subTest(uid=uid, gid=gid, mode=mode):
                probe.validate_vhost_metadata(types.SimpleNamespace(st_uid=uid, st_gid=gid,
                    st_mode=stat.S_IFREG | mode, st_nlink=1))

    def test_vhost_rejects_other_owners_groups_modes_links_and_nonregular_files(self):
        refused = [(5006, 5006, 0o750), (65534, 5006, 0o750), (65534, 0, 0o750),
                   (65534, 65534, 0o640), (65534, 65534, 0o755), (65534, 65534, 0o770),
                   (65534, 65534, 0o775), (0, 0, 0o660), (0, 0, 0o602)]
        for uid, gid, mode in refused:
            with self.subTest(uid=uid, gid=gid, mode=mode), self.assertRaises(probe.Refused):
                probe.validate_vhost_metadata(types.SimpleNamespace(st_uid=uid, st_gid=gid,
                    st_mode=stat.S_IFREG | mode, st_nlink=1))
        for kind, links in [(stat.S_IFREG, 2), (stat.S_IFDIR, 1), (stat.S_IFLNK, 1)]:
            with self.subTest(kind=kind, links=links), self.assertRaises(probe.Refused):
                probe.validate_vhost_metadata(types.SimpleNamespace(st_uid=65534, st_gid=65534,
                    st_mode=kind | 0o750, st_nlink=links))

    def test_vhost_exception_preserves_exact_path_regular_guard_and_document_root(self):
        path = Path('/usr/local/lsws/conf/vhosts/uat-admin.primehealers.com/vhost.conf')
        info = types.SimpleNamespace(st_uid=65534, st_gid=65534, st_mode=stat.S_IFREG | 0o750, st_nlink=1)
        with mock.patch.object(probe, 'regular', return_value=info) as regular, \
             mock.patch.object(Path, 'read_text', return_value='docRoot $VH_ROOT/public_html/public\n'):
            probe.validate_vhost(path)
            regular.assert_called_once_with(path, 1048576)
        with mock.patch.object(probe, 'regular') as regular, self.assertRaises(probe.Refused):
            probe.validate_vhost(Path('/usr/local/lsws/conf/vhosts/admin.primehealers.com/vhost.conf'))
        regular.assert_not_called()
        with mock.patch.object(probe, 'regular', return_value=info), \
             mock.patch.object(Path, 'read_text', return_value='docRoot $VH_ROOT/other_application/public\n'), \
             self.assertRaises(probe.Refused):
            probe.validate_vhost(path)
        with mock.patch.object(probe, 'regular', side_effect=probe.Refused), \
             mock.patch.object(Path, 'read_text') as read, self.assertRaises(probe.Refused):
            probe.validate_vhost(path)
        read.assert_not_called()

    def test_private_environment_drops_runtime_injection_and_credentials(self):
        with mock.patch.dict(os.environ, {'NODE_OPTIONS': '--require secret', 'NODE_PATH': '/other', 'MAIL_PASSWORD': 'SYNTHETIC_SECRET'}):
            env = probe.private_environment(Path('/tmp/private'))
        self.assertEqual(set(env), {'PATH', 'LANG', 'LC_ALL', 'HOME', 'TMPDIR', 'TMP', 'TEMP',
                                   'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'XDG_RUNTIME_DIR'})
        self.assertTrue(all(value.replace('\\', '/').startswith('/tmp/private') for key, value in env.items() if key not in ('PATH', 'LANG', 'LC_ALL')))

    def test_sandbox_variant_filters_switch_spellings_and_values(self):
        self.assertEqual(['--disable-gpu'], probe.safe_arguments([
            '--no-sandbox', 'disable-setuid-sandbox', '--single-process=1', '---no-zygote',
            '--disable-seccomp-filter-sandbox=yes', 'disable-gpu']))
        for value in ('--proxy-server=http://example.test', '--user-data-dir=/tmp/shared', '--load-extension=/tmp/code'):
            with self.subTest(value=value), self.assertRaises(probe.Refused):
                probe.safe_arguments([value])
        self.assertEqual(['--single-process', '--no-sandbox'], probe.safe_arguments(['single-process', '--no-sandbox'], control=True))
        with self.assertRaises(probe.Refused):
            probe.safe_arguments(['--no-sandbox=1'], control=True)

    def test_pdf_paths_must_remain_standard_private_storage_paths(self):
        value = {'temp': (probe.APP / 'storage/app/pdf-runtime/tmp').as_posix(),
                 'profile': (probe.APP / 'storage/app/pdf-runtime/profile').as_posix(),
                 'environment': {'HOME': (probe.APP / 'storage/app/pdf-runtime/home').as_posix()}}
        with mock.patch.object(probe, 'no_links'):
            self.assertEqual(value, probe.safe_pdf_paths(value))
            for path in ('/tmp/shared', str(probe.APP / 'public/profile'), str(probe.APP / 'storage/../public')):
                with self.subTest(path=path), self.assertRaises(probe.Refused):
                    probe.safe_pdf_paths({**value, 'temp': path})

    def test_php_checks_cache_overrides_before_including_configuration(self):
        source = probe.PHP_CONFIGURATION
        for key in probe.STANDARD_PATHS:
            self.assertIn(key, source)
        self.assertLess(source.index('$value!==$paths[$name]'), source.index('LoadConfiguration)->bootstrap'))
        self.assertIn('getLoadedProviders()!==[]', source)
        self.assertNotIn('new PDO', source)
        self.assertNotIn('SELECT ', source)
        self.assertNotIn('MAIL_', source)
        self.assertNotIn('require_tls', source)
        self.assertNotIn('artisan', source)

    def test_namespace_is_not_inferred_from_inherited_sysfs(self):
        self.assertNotIn('/sys/class/net', SCRIPT.read_text())
        current = types.SimpleNamespace(st_ino=123)
        with mock.patch.object(probe.os, 'stat', return_value=current):
            with self.assertRaises(probe.Refused):
                probe.empty_namespace(123)
        text = {'/proc/net/dev': 'header\nheader\n lo: 0 0 0\n', '/proc/net/route': 'header\n', '/proc/net/if_inet6': ''}
        socket_instance = mock.MagicMock()
        socket_instance.__enter__.return_value = socket_instance
        flags = b'\0' * 16 + b'\0\0' + b'\0' * 16
        fake_fcntl = types.SimpleNamespace(ioctl=mock.Mock(return_value=flags))
        with mock.patch.object(probe.os, 'stat', return_value=current), \
             mock.patch.object(Path, 'read_text', lambda path: text[str(path).replace('\\', '/')]), \
             mock.patch.object(probe.socket, 'socket', return_value=socket_instance), \
             mock.patch.object(probe, 'fcntl', fake_fcntl):
            probe.empty_namespace(122)
            fake_fcntl.ioctl.return_value = b'\0' * 16 + b'\x01\0' + b'\0' * 16
            with self.assertRaises(probe.Refused):
                probe.empty_namespace(122)

    def test_privilege_drop_or_namespace_failure_prevents_all_runtime_calls(self):
        with mock.patch.object(probe.os, 'geteuid', return_value=1001, create=True), mock.patch.object(probe, 'bounded_run') as run:
            with self.assertRaises(probe.Refused):
                probe.worker({})
            run.assert_not_called()
        payload = {'target': self.target(), 'uid': 1001, 'gid': 1001, 'parent_net_inode': 5}
        account = types.SimpleNamespace(pw_uid=1001, pw_gid=1001)
        fake_pwd = types.SimpleNamespace(getpwnam=lambda _: account)
        with mock.patch.object(probe.os, 'geteuid', return_value=0, create=True), mock.patch.object(probe, 'pwd', fake_pwd), \
             mock.patch.object(probe, 'empty_namespace', side_effect=probe.Refused), mock.patch.object(probe, 'bounded_run') as run:
            with self.assertRaises(probe.Refused):
                probe.worker(payload)
            run.assert_not_called()

    def test_temp_cleanup_requires_exact_identity_and_safe_removal(self):
        directory = Path('/tmp/phos-uat-pdf-probe-abcdefgh')
        with mock.patch.object(probe, 'temp_identity', return_value=(1, 2, 1001, 448)), \
             mock.patch.object(probe.shutil, 'rmtree') as remove:
            with self.assertRaises(probe.Refused):
                probe.cleanup_temp(directory, 1001, (1, 3, 1001, 448))
            remove.assert_not_called()
        with self.assertRaises(probe.Refused):
            probe.temp_identity(Path('/var/tmp/phos-uat-pdf-probe-abcdefgh'), 1001)

    def test_only_dedicated_group_is_terminated(self):
        process = types.SimpleNamespace(pid=73421)
        with mock.patch.object(probe.os, 'killpg', create=True) as kill, mock.patch.object(probe.time, 'sleep'), \
             mock.patch.object(probe.signal, 'SIGKILL', 9, create=True):
            probe.stop_group(process)
        self.assertEqual([mock.call(73421, probe.signal.SIGTERM), mock.call(73421, 9)], kill.call_args_list)

    def test_namespace_launch_failure_has_no_fallback_and_cleans_only_fixture(self):
        account = types.SimpleNamespace(pw_uid=1001, pw_gid=1001, pw_name='website_owner')
        temporary = Path('/tmp/phos-uat-pdf-probe-abcdefgh')
        process = mock.MagicMock(returncode=1, pid=73421)
        process.communicate.return_value = (b'SYNTHETIC_PASSWORD', b'SYNTHETIC_TOKEN')
        process.poll.return_value = 1
        original_stat = probe.os.stat
        def inspected_stat(path, *args, **kwargs):
            return types.SimpleNamespace(st_ino=42) if str(path) == '/proc/self/ns/net' else original_stat(path, *args, **kwargs)
        with mock.patch.object(probe, 'load_target', return_value=(account, probe.PHP)), \
             mock.patch.object(probe, 'trusted_executable', side_effect=lambda value: value), \
             mock.patch.object(probe, 'create_owner_temp', return_value=(temporary, (1, 2, 1001, 448))), \
             mock.patch.object(probe.os, 'stat', side_effect=inspected_stat), \
             mock.patch.object(probe.subprocess, 'Popen', return_value=process) as launch, \
             mock.patch.object(probe, 'stop_group') as stop, mock.patch.object(probe, 'cleanup_temp') as cleanup:
            result = probe.inspect()
        self.assertEqual('refused', result['status'])
        self.assertFalse(result['strict_network_isolation'])
        self.assertFalse(result['network_fallback_attempted'])
        self.assertTrue(result['cleanup_complete'])
        self.assertNotIn('SYNTHETIC_', json.dumps(result))
        self.assertEqual(1, launch.call_count)
        self.assertEqual(['/usr/bin/unshare', '--net', '--'], launch.call_args.args[0][:3])
        self.assertTrue(launch.call_args.kwargs['start_new_session'])
        stop.assert_called_once_with(process)
        cleanup.assert_called_once_with(temporary, 1001, (1, 2, 1001, 448))

    def test_disappearing_chrome_temp_entry_does_not_abort_size_monitor(self):
        with mock.patch.object(probe.os, 'walk', return_value=[('/tmp/private', [], ['vanished'])]), \
             mock.patch.object(Path, 'lstat', side_effect=FileNotFoundError):
            self.assertEqual(0, probe.directory_size(Path('/tmp/private')))

    def test_raw_browser_errors_or_unknown_fields_are_not_reportable(self):
        base = {'launch': False, 'pdf': False, 'sandbox_status': 'unconfirmed', 'external_requests': 0}
        self.assertEqual(base, probe.validate_probe_result(base, probe.APP / 'node_modules'))
        for extra in ({'stderr': 'SYNTHETIC_PASSWORD'}, {'error_class': 'SYNTHETIC_PASSWORD'},
                      {'browser_version': 'Chrome/123\nTOKEN=secret'}, {'pdf_bytes': 'secret'}):
            with self.subTest(extra=extra), self.assertRaises(probe.Refused):
                probe.validate_probe_result({**base, **extra}, probe.APP / 'node_modules')

    def test_worker_phase_and_error_only_are_preserved(self):
        value = {'diagnostic': 'PHOS_UAT_PDF_RUNTIME_PRIVATE', 'status': 'refused', 'error_class': 'Refused',
                 'phase': 'modules', 'strict_network_isolation': True, 'website_owner_execution': True,
                 'network_fallback_attempted': False}
        self.assertEqual({'error_class': 'Refused', 'phase': 'modules', 'strict_network_isolation': True,
                          'website_owner_execution': True}, probe.worker_error(value))
        for extra in ({'stderr': 'SYNTHETIC_SECRET'}, {'phase': 'SECRET_TOKEN'}, {'error_class': 'SYNTHETIC_SECRET'}):
            with self.subTest(extra=extra), self.assertRaises(probe.Refused):
                probe.worker_error({**value, **extra})

    def test_module_entry_cannot_escape_approved_root(self):
        with self.assertRaises(probe.Refused):
            probe.validate_probe_result({'launch': False, 'pdf': False, 'sandbox_status': 'unconfirmed',
                'external_requests': 0, 'module_entry': '/tmp/other/index.js'}, probe.APP / 'node_modules')

    def test_no_install_download_global_change_or_artisan_operation_exists(self):
        source = SCRIPT.read_text()
        for value in ('npm install', 'composer install', 'composer update', 'test-pdf-render', 'config:clear', 'config:cache', 'opcache_reset', 'iptables'):
            self.assertNotIn(value, source)
        self.assertIn("[unshare, '--net', '--'", source)
        self.assertIn("'--no-plugins', '--no-scripts', '--version'", source)
        self.assertIn('detached:false', probe.NODE_PROBE)
        self.assertIn("await page.setJavaScriptEnabled(internal)", probe.NODE_PROBE)
        self.assertIn("await prepare(statusPage,true)", probe.NODE_PROBE)

    @unittest.skipUnless(shutil.which('node'), 'Node is not installed for the synthetic module API test')
    def test_async_default_args_and_internal_status_with_synthetic_module_no_real_browser(self):
        with tempfile.TemporaryDirectory(prefix='phos-pdf-unit-', dir=SCRIPT.parent) as folder:
            root = Path(folder)
            self.assertEqual(SCRIPT.parent.resolve(), root.resolve().parent)
            module = root / 'node_modules/puppeteer'
            module.mkdir(parents=True)
            script = root / 'fake-browser.cjs'
            script.write_text('setTimeout(()=>{},5000);')
            (module / 'package.json').write_text(json.dumps({'name': 'puppeteer', 'type': 'module', 'main': './index.js'}))
            (module / 'index.js').write_text('''
const page=()=>({js:false,async setJavaScriptEnabled(v){this.js=v},async setRequestInterception(){},on(){},async goto(){if(!this.js)throw Error('internal JS required')},async waitForFunction(){},async evaluate(fn){return fn.toString().includes('command_line')?'chrome --headless=new':[['PID namespaces','Yes'],['Seccomp-BPF sandbox','Yes']]},async close(){},async setContent(){if(this.js)throw Error('synthetic HTML JS enabled')},async pdf(){return Buffer.from('%PDF-1.4\\n'+'x'.repeat(800)+' /Type /Page')}});
export default {async defaultArgs(){return [''' + json.dumps(str(script)) + ''']},async connect(){return{async version(){return 'Chrome/123.0.0.0'},async newPage(){return page()},async close(){}}}};
''')
            payload = {'modules': str(root / 'node_modules'), 'browser': shutil.which('node'),
                       'directory': str(root), 'sandbox': True, 'arguments': []}
            process = subprocess.run([shutil.which('node'), '--input-type=module', '-e', probe.NODE_PROBE],
                input=json.dumps(payload).encode(), cwd=root, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=15)
            self.assertEqual(0, process.returncode, process.stderr.decode(errors='replace')[-400:])
            result = json.loads(process.stdout)
            self.assertTrue(result['launch'])
            self.assertTrue(result['pdf'])
            self.assertEqual('verified', result['sandbox_status'])
            self.assertEqual(1, result['page_object_count'])
            self.assertNotIn('stderr', result)


if __name__ == '__main__':
    unittest.main(verbosity=2)
