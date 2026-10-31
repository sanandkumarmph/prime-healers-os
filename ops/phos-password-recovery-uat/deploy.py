#!/usr/bin/env python3
"""Reviewed, pinned nine-file password-recovery rollout for the fixed PHOS UAT."""
import argparse
from contextlib import contextmanager
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import signal
import stat
import subprocess
import sys
import tempfile
import time
import urllib.parse
import zipfile
try:
 import fcntl
 import pwd
 import resource
except ImportError:
 fcntl=pwd=resource=None
APP=Path('/home/uat-admin.primehealers.com/public_html')
HOST='uat-admin.primehealers.com'
DATABASE='uata_primehealersos'
OWNER_ID=5006
PHP=Path('/usr/local/lsws/lsphp82/bin/php')
PRIVATE_CONFIG=Path('/root/phos-uat-pr8-backup-o46ztayl/target-config.json')
BASELINE='90d81d9aa69951aad092caa89defa5524a9097c8'
SYSTEM_PATH='/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
BACKUP_PREFIX='phos-password-reset-backup-'
SLOT_PREFIX='.phos-password-reset-recovery-'
STAGE_PREFIX='.phos-password-reset-cache-'
MAX_FILE=3*1024*1024
MAX_PACKAGE=8*1024*1024
MAX_CAPTURE=2*1024*1024
MAX_TREE=256*1024*1024
CACHE_NAMES=('config.php','routes-v7.php')
NEW_PARENT='app/Services/Auth'
ACTIVATION_REVIEW_COMPLETE=True
PACKAGE_RELEASE='7e81f3fb20eea99fe13f19f48208f911b8369b56'
PACKAGE_MANIFEST_SHA='eb64dee656c8d7f75056e72fabf014cb597ba2732eefb167158598eff43cdc9a'
PACKAGE_ARCHIVE_SHA='34224f3c49b4caff1bfb3c566c37c834762951906b4073e71ce3966411708456'
LOG_ROOT=None
LOCK_PATHS=(Path('/root/phos-tabbed-dashboard-deploy.lock'),Path('/root/phos-zoho-mail-uat.lock'))
BASELINE_HASHES={'app/Http/Controllers/Auth/PasswordResetLinkController.php': '82a07fee8b04223bf3587e5d3458de77275335104efa304aaf9e4a2dad1dea8c', 'app/Http/Controllers/Auth/NewPasswordController.php': '64300ff2989baeb1924c6b0d698cdcd189796ed45d5b14135a03c4607e4007af', 'app/Services/Auth/PasswordRecovery.php': None, 'app/Services/Auth/RevokeAccountSessions.php': None, 'app/Providers/AppServiceProvider.php': '9ee213c409de9210cfa71c0f01bffb8e545fac222e8289658b3a759e4e658491', 'routes/auth.php': '65beacc98506a95db64754d5cbd35e450ce8e8f0d66e697bae2b840ca6489dae', 'resources/views/auth/forgot-password.blade.php': '15b091b089dac7eb2e0dae0c701088de8a91c265f5766ee624d310b6e61e4ca2', 'config/mail.php': 'c43ff49a31c5f32ce21eb7159f2a4b3cd2f4074eaed5838d265646dbfedd3474', 'app/Http/Middleware/PasswordRecoveryHeaders.php': None}

class Refused(RuntimeError):
    pass

def need(condition, message):
    if not condition:
        raise Refused(message)

def digest(data):
    return hashlib.sha256(data).hexdigest()

def unique_pairs(pairs):
    result = {}
    for key, value in pairs:
        need(key not in result, 'Duplicate JSON key.')
        result[key] = value
    return result

def json_bytes(value):
    return (json.dumps(value, sort_keys=True, indent=2) + '\n').encode()

def parse_json(raw):
    need(len(raw) <= MAX_CAPTURE, 'JSON exceeds reviewed limit.')
    return json.loads(raw, object_pairs_hook=unique_pairs)

def no_links(path):
    for item in (path, *path.parents):
        need(not item.is_symlink(), 'Symlink or linked ancestor refused.')

def regular(path, limit=MAX_FILE):
    no_links(path)
    value = path.stat()
    need(stat.S_ISREG(value.st_mode) and value.st_nlink == 1 and value.st_size <= limit,
         'A regular single-link file within size limit is required.')
    return value

def relative(name):
    need(isinstance(name, str) and 0 < len(name) < 240 and '\\' not in name and re.search(r'[\x00-\x1f\x7f]', name) is None,
         'Unsafe relative path.')
    path = PurePosixPath(name)
    need(not path.is_absolute() and str(path) == name and not set(path.parts) & {'..', '.'}, 'Unsafe relative path.')
    return name

def signature(path):
    if not path.exists() and not path.is_symlink():
        return None
    no_links(path)
    flags = os.O_RDONLY | getattr(os, 'O_NOFOLLOW', 0) | getattr(os, 'O_BINARY', 0)
    descriptor = os.open(path, flags)
    try:
        info = os.fstat(descriptor)
        need(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_size <= MAX_TREE,
             'Unsafe file identity for signature.')
        hasher = hashlib.sha256()
        while True:
            block = os.read(descriptor, 65536)
            if not block:
                break
            hasher.update(block)
        after = os.fstat(descriptor)
        keys = ('st_dev', 'st_ino', 'st_mode', 'st_uid', 'st_gid', 'st_size', 'st_mtime_ns', 'st_ctime_ns')
        current = path.lstat()
        # Windows path-stat and CRT fstat can expose different timestamp precision;
        # deployment is Linux-only and retains the complete strict comparison.
        compare_keys = keys if sys.platform.startswith('linux') else keys[:-2]
        need(all(getattr(info, key) == getattr(after, key) == getattr(current, key) for key in compare_keys),
             'File changed while its identity was inspected.')
        return {'sha256': hasher.hexdigest(), 'size': info.st_size, 'uid': info.st_uid,
                'gid': info.st_gid, 'mode': stat.S_IMODE(info.st_mode), 'inode': info.st_ino,
                'device': info.st_dev, 'mtime_ns': current.st_mtime_ns, 'ctime_ns': current.st_ctime_ns}
    finally:
        os.close(descriptor)

def same_contents(left, right):
    if left is None or right is None:
        return left is right
    return all(key in left and key in right and left[key] == right[key] for key in ('sha256', 'size', 'uid', 'gid', 'mode'))

def restored_signature_matches(current, expected):
    """Mail recovery atomically replaces known files while restoring their mtime."""
    if current is None or expected is None:
        return current is expected
    return same_contents(current, expected) and all(
        key in current and key in expected and current[key] == expected[key]
        for key in ('device', 'mtime_ns'))


def preserved_restoration_matches(root, expected):
    current = immutable_snapshot(root)
    return set(current) == set(expected) and all(
        restored_signature_matches(current[name], expected[name]) if name == '.env'
        else current[name] == expected[name] for name in expected)


def private_file(path, owner=0):
    info = regular(path)
    need(info.st_uid == owner and info.st_gid == owner and stat.S_IMODE(info.st_mode) == 0o600,
         'Private file owner/group/mode are not the reviewed values.')

def atomic(path, data, uid, gid, mode):
    no_links(path)
    need(path.parent.is_dir(), 'Destination parent is missing.')
    descriptor, temporary = tempfile.mkstemp(prefix='.phos-write-', dir=str(path.parent))
    try:
        with os.fdopen(descriptor, 'wb') as output:
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
        if hasattr(os, 'chown'):
            os.chown(temporary, uid, gid)
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)

def owner_preexec():
    os.umask(0o077)
    os.setgroups([])
    os.setgid(OWNER_ID)
    os.setuid(OWNER_ID)
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
    resource.setrlimit(resource.RLIMIT_FSIZE, (128 * 1024 * 1024, 128 * 1024 * 1024))

def tree_signature(root):
    no_links(root)
    info = root.stat()
    need(stat.S_ISDIR(info.st_mode), 'Expected dependency directory.')
    entries, total = [], 0
    for parent, directories, files in os.walk(root, followlinks=False):
        for name in sorted(directories + files):
            path = Path(parent) / name
            relative_name = str(path.relative_to(root)).replace('\\', '/')
            value = path.lstat()
            mode = stat.S_IMODE(value.st_mode)
            need(stat.S_ISLNK(value.st_mode) or (not mode & 0o002 and (not stat.S_ISREG(value.st_mode) or value.st_nlink == 1)),
                 'Unsafe dependency metadata.')
            if stat.S_ISLNK(value.st_mode):
                need(path.resolve().is_relative_to(root) and path.resolve().is_file(), 'Dependency symlink escapes tree.')
                content = 'link:' + os.readlink(path)
            elif stat.S_ISDIR(value.st_mode):
                content = 'directory'
            else:
                need(stat.S_ISREG(value.st_mode) and value.st_size <= 128 * 1024 * 1024, 'Special dependency entry.')
                total += value.st_size
                need(total <= MAX_TREE, 'Dependency tree exceeds size limit.')
                content = digest(path.read_bytes())
            entries.append([relative_name, content, value.st_uid, value.st_gid, mode, value.st_ino, value.st_dev])
            need(len(entries) <= 100000, 'Dependency tree entry limit exceeded.')
    return {'sha256': digest(json_bytes(sorted(entries))), 'inode': info.st_ino, 'device': info.st_dev,
            'uid': info.st_uid, 'gid': info.st_gid, 'mode': stat.S_IMODE(info.st_mode), 'entries': len(entries)}

class FileRecovery:
    """File rollback is independent from framework commands and HTTP availability."""
    def __init__(self, root, backup, names):
        self.root, self.backup, self.names = root, backup, tuple(names)
        self.entries = {}

    def snapshot(self):
        folder = self.backup / 'files'
        folder.mkdir(mode=0o700)
        for index, name in enumerate(self.names):
            relative(name)
            path = self.root / name
            old = signature(path)
            entry = {'before': old, 'after': None, 'write_started': False, 'copy': str(index)}
            if old is not None:
                content = path.read_bytes()
                need(digest(content) == old['sha256'] and signature(path) == old, 'Source drift during private backup.')
                atomic(folder / str(index), content, 0, 0, 0o600)
            self.entries[name] = entry

    def install(self, name, content, uid, gid, mode, save):
        entry = self.entries[name]
        need(signature(self.root / name) == entry['before'], 'Source/config drift before activation write.')
        # Record the expected new bytes before write. Crash between replace/signature
        # may be undone only when exact expected content and metadata match.
        entry['expected'] = {'sha256': digest(content), 'size': len(content), 'uid': uid, 'gid': gid, 'mode': mode}
        entry['write_started'] = True
        save()
        atomic(self.root / name, content, uid, gid, mode)
        entry['after'] = signature(self.root / name)
        save()

    def restore_files(self):
        uncertain = []
        for name, entry in self.entries.items():
            path = self.root / name
            try:
                current = signature(path)
                original = entry['before']
                if current == original or same_contents(current, original):
                    continue
                # Atomic installs never delete an existing destination first.
                # A missing original therefore belongs to unknown concurrent
                # activity, not to this helper, even after a failed activation.
                if current is None and original is not None:
                    uncertain.append(name)
                    continue
                known_new = (entry['after'] is not None and current == entry['after']) or (
                    entry['after'] is None and entry.get('expected') is not None and same_contents(current, entry['expected']))
                if name == 'bootstrap/cache/config.php' and entry['after'] is not None:
                    known_new = known_new or restored_signature_matches(current, entry['after'])
                # Unknown present bytes must also survive for manual review.
                if not known_new and current is not None:
                    uncertain.append(name)
                    continue
                if original is None:
                    if known_new:
                        path.unlink()
                else:
                    content = (self.backup / 'files' / entry['copy']).read_bytes()
                    need(digest(content) == original['sha256'], 'Recovery copy checksum mismatch.')
                    atomic(path, content, original['uid'], original['gid'], original['mode'])
            except (OSError, Refused):
                uncertain.append(name)
        return uncertain

class CompiledViewRecovery:
    def __init__(self, root, slot):
        self.root, self.slot = root, slot
        self.entries = {}

    def live_path(self, name):
        need(name == 'compiled_views', 'Unreviewed directory switch.')
        return self.root / ('storage/framework/views' if name == 'compiled_views' else name)

    def verify_candidate_path(self, name, candidate):
        need(candidate.is_relative_to(self.root), 'Unreviewed candidate directory root.')
        pieces = candidate.relative_to(self.root).parts
        need(len(pieces) in (2, 3) and re.fullmatch(re.escape(STAGE_PREFIX) + '[0-9a-f]{24}', pieces[0]),
             'Unreviewed candidate stage identity.')
        expected = ('compiled-views',)
        need(pieces[1:] == expected, 'Unreviewed candidate directory layout.')
        no_links(candidate)

    def switch(self, name, candidate, save):
        live = self.live_path(name)
        self.verify_candidate_path(name, candidate)
        old = self.slot / name
        need(not old.exists(), 'Recovery dependency destination collision.')
        entry = {'before': tree_signature(live), 'new': tree_signature(candidate), 'candidate': str(candidate),
                 'old_moved': False, 'new_moved': False}
        self.entries[name] = entry
        save()
        need(tree_signature(live) == entry['before'] and tree_signature(candidate) == entry['new'], 'Dependency drift before switch.')
        os.rename(live, old)
        entry['old_moved'] = True
        save()
        os.rename(candidate, live)
        entry['new_moved'] = True
        save()

    def restore(self):
        uncertain = []
        for name, entry in self.entries.items():
            live, old = self.live_path(name), self.slot / name
            try:
                # Inspect actual paths, not phase booleans: process can die after rename.
                if old.exists():
                    need(tree_signature(old) == entry['before'], 'Original dependency backup drifted.')
                    if live.exists():
                        need(tree_signature(live) == entry['new'], 'Unknown live dependency tree retained.')
                        failed = self.slot / (name + '-failed')
                        need(not failed.exists(), 'Failed dependency collision.')
                        os.rename(live, failed)
                    else:
                        candidate = Path(entry['candidate'])
                        self.verify_candidate_path(name, candidate)
                        # Only the first known rename owns an absent live path.
                        # A completed install followed by deletion/rename, or an
                        # uncaptured completion with missing candidate, is foreign.
                        need(entry['new_moved'] is False and candidate.exists()
                             and tree_signature(candidate) == entry['new'],
                             'Unknown missing dependency tree retained for manual review.')
                    os.rename(old, live)
                else:
                    need(live.exists() and tree_signature(live) == entry['before'], 'Original dependency tree is unavailable.')
            except (OSError, Refused):
                uncertain.append(name)
        return uncertain

def recover_sources_first(files, dependencies, runtime_recover):
    errors = []
    try:
        errors += files.restore_files()
    except Exception:
        errors.append('file_recovery_failed')
    try:
        errors += dependencies.restore()
    except Exception:
        errors.append('dependency_recovery_failed')
    if errors:
        return {'complete': False, 'unknown_state_preserved': sorted(errors), 'maintenance_retained': True}
    try:
        runtime_recover()
    except Exception:
        return {'complete': False, 'unknown_state_preserved': [], 'maintenance_retained': True,
                'source_cache_and_view_state_restored': True, 'runtime_recovery_failed': True}
    return {'complete': True, 'unknown_state_preserved': [], 'maintenance_retained': False,
            'source_cache_and_view_state_restored': True}

def recover_with_maintenance(files, dependencies, ensure_maintenance, save_state, runtime_recover):
    """Neither a foreign marker nor journal I/O may prevent known-byte undo."""
    preparation_errors = []
    try:
        ensure_maintenance()
    except Exception:
        preparation_errors.append('maintenance_state_uncertain')
    try:
        save_state()
    except Exception:
        preparation_errors.append('journal_write_failed')
    def runtime():
        need(not preparation_errors, 'Runtime recovery withheld because maintenance/journal is uncertain.')
        runtime_recover()
    result = recover_sources_first(files, dependencies, runtime)
    if preparation_errors:
        result['preparation_errors'] = preparation_errors
        result['complete'] = False
        result['maintenance_retained'] = True
        if 'maintenance_state_uncertain' in preparation_errors:
            result['maintenance_retained'] = None
            result['maintenance_uncertain'] = True
    return result

def maintenance_enter(root, backup, state=None, save=None):
    marker = root / 'storage/framework/down'
    no_links(marker)
    need(signature(marker) is None, 'Existing maintenance state requires review.')
    token = secrets.token_hex(32)
    data = json_bytes({'except': [], 'redirect': None, 'retry': 60, 'refresh': 15,
                       'status': 503, 'template': None, 'secret': token})
    if state is not None:
        state['maintenance_plan']={'sha256':digest(data),'size':len(data),'uid':OWNER_ID,'gid':OWNER_ID,'mode':0o600}
        save()
    atomic(marker, data, OWNER_ID, OWNER_ID, 0o600)
    value = signature(marker)
    if state is not None:
        state['maintenance_marker']=value
        save()
    atomic(backup / 'maintenance.json', json_bytes({'signature': value}), 0, 0, 0o600)
    return value

def maintenance_leave(root, expected):
    path = root / 'storage/framework/down'
    need(signature(path) == expected, 'Maintenance marker changed; it was retained for review.')
    path.unlink()

def restore_maintenance(root, expected):
    path = root / 'storage/framework/down'
    if signature(path) is None:
        # New marker after failed online checks, no bypass secret is disclosed.
        atomic(path, json_bytes({'except': [], 'redirect': None, 'retry': 60, 'refresh': 15,
                                'status': 503, 'template': None, 'secret': secrets.token_hex(32)}),
               OWNER_ID, OWNER_ID, 0o600)
        return signature(path)
    need(signature(path) == expected, 'Foreign maintenance marker retained.')
    return expected

def http_check(path, backup, *, local, header=None):
    need(path.startswith('/') and not path.startswith('//') and '\r' not in path and '\n' not in path,
         'Invalid fixed-host HTTP path.')
    body = backup / ('http-body-' + secrets.token_hex(8))
    headers = backup / ('http-headers-' + secrets.token_hex(8))
    curl = Path('/usr/bin/curl')
    regular(curl)
    command = [curl, '--silent', '--show-error', '--connect-timeout', '8', '--max-time', '25',
               '--max-redirs', '0', '--proto', '=https', '--output', body, '--dump-header', headers,
               '--write-out', '%{http_code}']
    if local:
        command += ['--resolve', HOST + ':443:127.0.0.1']
    if header:
        command += ['--header', '@-']
    command += ['https://' + HOST + path]
    try:
        code = run(command, cwd=backup, environment={'PATH': SYSTEM_PATH, 'LANG': 'C.UTF-8'},
                   owner=False, content=header, timeout=35).decode('ascii')
        need(body.stat().st_size <= MAX_FILE and headers.stat().st_size <= 65536, 'HTTP result exceeds limit.')
        return code, body.read_bytes(), headers.read_text(errors='replace')
    finally:
        for item in (body, headers):
            if item.exists():
                item.unlink()

def web_php_probe(backup, invalidate):
    token = secrets.token_hex(32)
    path = APP / 'public' / ('phos-access-runtime-' + secrets.token_hex(16) + '.php')
    need(signature(path) is None, 'Temporary web probe collision.')
    code = r'''<?php
if(!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)
 ||!hash_equals('__TOKEN__',$_SERVER['HTTP_X_PHOS_RELEASE']??'')){http_response_code(404);exit;}
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');header('Content-Type: application/json');
$uid=function_exists('posix_geteuid')?posix_geteuid():null;
if($uid===null&&is_readable('/proc/self/status')){preg_match('/^Uid:\\s+\\d+\\s+(\\d+)/m',file_get_contents('/proc/self/status'),$m);$uid=isset($m[1])?(int)$m[1]:null;}
if($uid!==5006||PHP_MAJOR_VERSION!==8||PHP_MINOR_VERSION!==2){http_response_code(500);echo '{"ok":false}';exit;}
$count=0;$enabled=filter_var(ini_get('opcache.enable'),FILTER_VALIDATE_BOOLEAN);
if($enabled){if(!function_exists('opcache_get_status')||!function_exists('opcache_invalidate')){http_response_code(500);exit;}
$status=@opcache_get_status(true);if(!is_array($status)){http_response_code(500);exit;}
if(__INVALIDATE__)foreach(array_keys($status['scripts']??[])as$file){if(str_starts_with($file,'__APP__/')&&is_file($file)){
 if(!@opcache_invalidate($file,true)){http_response_code(500);exit;}$count++;}}}
echo json_encode(['ok'=>true,'effective_uid'=>$uid,'php'=>PHP_VERSION,'invalidated'=>$count]);
'''.replace('__TOKEN__', token).replace('__APP__', str(APP)).replace('__INVALIDATE__', 'true' if invalidate else 'false')
    atomic(path, code.encode(), OWNER_ID, OWNER_ID, 0o644)
    expected = signature(path)
    try:
        status, raw, _ = http_check('/' + path.name, backup, local=True,
                                    header=('X-PHOS-Release: ' + token + '\n').encode())
        result = parse_json(raw)
        need(status == '200' and result.get('ok') is True and result.get('effective_uid') == OWNER_ID
             and re.fullmatch(r'8\.2\.[0-9]+', result.get('php', '')), 'Actual web PHP identity/OPcache probe failed.')
        return result
    finally:
        need(signature(path) == expected, 'Temporary probe changed; unknown file was retained.')
        path.unlink()

CONFIGURATION_PHP="\nob_start();try {\n require 'vendor/autoload.php';\n final class PhosDeploymentConfiguration extends Illuminate\\Foundation\\Application {\n  protected function registerBaseServiceProviders() {} protected function registerLaravelCloudServices() {}\n }\n final class PhosDeploymentEnvironment extends Illuminate\\Foundation\\Bootstrap\\LoadEnvironmentVariables {\n  protected function writeErrorAndDie($exception) {throw $exception;}\n }\n $app=new PhosDeploymentConfiguration(getcwd());\n (new PhosDeploymentEnvironment)->bootstrap($app);\n (new Illuminate\\Foundation\\Bootstrap\\LoadConfiguration)->bootstrap($app);\n if($app->getLoadedProviders()!==[])throw new RuntimeException();\n $config=$app['config'];$url=parse_url((string)$config->get('app.url',''));\n if($config->get('app.env')!=='uat'||!is_array($url)||($url['scheme']??'')!=='https'\n ||($url['host']??'')!=='uat-admin.primehealers.com'||isset($url['user'])||isset($url['pass'])\n ||isset($url['query'])||isset($url['fragment'])||!in_array($url['path']??'',['','/'],true)\n ||($url['port']??443)!==443||PHP_MAJOR_VERSION!==8||PHP_MINOR_VERSION!==2)throw new RuntimeException();\n $db=$config->get('database.connections.'.$config->get('database.default'));\n $db=(new Illuminate\\Support\\ConfigurationUrlParser)->parseConfiguration($db);\n if(($db['driver']??'')!=='mysql'||($db['database']??'')!=='uata_primehealersos'\n ||!in_array($db['host']??'',['localhost','127.0.0.1','::1'],true)||!empty($db['read'])||!empty($db['write'])\n ||(int)($db['port']??3306)!==3306||$config->get('app.debug')!==false\n ||$config->get('app.maintenance.driver','file')!=='file')throw new RuntimeException();\n if($app->storagePath()!==getcwd().'/storage'||$app->getCachedConfigPath()!==getcwd().'/bootstrap/cache/config.php'\n ||$app->getCachedPackagesPath()!==getcwd().'/bootstrap/cache/packages.php'\n ||$app->getCachedServicesPath()!==getcwd().'/bootstrap/cache/services.php'\n ||$app->getCachedRoutesPath()!==getcwd().'/bootstrap/cache/routes-v7.php'\n ||$config->get('view.compiled')!==getcwd().'/storage/framework/views')throw new RuntimeException();\n __ACTION__\n ob_end_clean();echo json_encode($result,JSON_THROW_ON_ERROR);\n}catch(Throwable $e){ob_end_clean();echo json_encode(['error_class'=>get_class($e)]);exit(2);}\n"


def bounded_directory(root):
    total=entries=0
    for parent, directories, files in os.walk(root, followlinks=False):
        for name in directories+files:
            info=(Path(parent)/name).lstat()
            total+=info.st_size if stat.S_ISREG(info.st_mode) else 0
            entries+=1
            need(total<=MAX_TREE and entries<=10000,'Private working directory exceeds limit.')


def run(arguments, *, cwd, environment=None, owner=True, content=None, timeout=180):
    need(LOG_ROOT is not None,'Private command capture must be initialized.')
    root=LOG_ROOT
    no_links(root)
    stem='command-'+secrets.token_hex(8)
    stdout,stderr=root/(stem+'.stdout'),root/(stem+'.stderr')
    process=None
    def limits():
        resource.setrlimit(resource.RLIMIT_CORE,(0,0))
        resource.setrlimit(resource.RLIMIT_FSIZE,(MAX_FILE,MAX_FILE))
        if owner:
            os.setgroups([]);os.setgid(OWNER_ID);os.setuid(OWNER_ID);os.umask(0o077)
    try:
        with stdout.open('xb') as output,stderr.open('xb') as error:
            os.chmod(stdout,0o600);os.chmod(stderr,0o600)
            process=subprocess.Popen([str(value) for value in arguments],cwd=cwd,
                env=environment or {'PATH':SYSTEM_PATH,'LANG':'C.UTF-8'},stdin=subprocess.PIPE if content is not None else subprocess.DEVNULL,
                stdout=output,stderr=error,start_new_session=True,preexec_fn=limits)
            if content is not None:
                process.stdin.write(content);process.stdin.close()
            deadline=time.monotonic()+timeout
            while process.poll() is None:
                need(stdout.stat().st_size<=MAX_CAPTURE and stderr.stat().st_size<=MAX_CAPTURE,'Private command output limit exceeded.')
                need(time.monotonic()<deadline,'Private command time limit exceeded.')
                time.sleep(0.1)
        need(stdout.stat().st_size<=MAX_CAPTURE and stderr.stat().st_size<=MAX_CAPTURE,'Private command output limit exceeded.')
        need(process.returncode==0,'A bounded command failed; inspect only the root-private capture.')
        return stdout.read_bytes()
    finally:
        if process and process.poll() is None:
            os.killpg(process.pid,signal.SIGKILL);process.wait(timeout=10)


def runtime_environment():
    return {'PATH':SYSTEM_PATH,'LANG':'C.UTF-8','LC_ALL':'C.UTF-8'}


def load_package(folder):
    need(isinstance(PACKAGE_RELEASE,str) and re.fullmatch('[0-9a-f]{40}',PACKAGE_RELEASE)
        and isinstance(PACKAGE_MANIFEST_SHA,str) and re.fullmatch('[0-9a-f]{64}',PACKAGE_MANIFEST_SHA)
        and isinstance(PACKAGE_ARCHIVE_SHA,str) and re.fullmatch('[0-9a-f]{64}',PACKAGE_ARCHIVE_SHA),
        'STOP: reviewed source commit and package hashes have not been pinned.')
    manifest_path,archive_path=folder/'manifest.json',folder/'payload.zip'
    regular(manifest_path,65536);regular(archive_path,MAX_PACKAGE)
    raw=manifest_path.read_bytes()
    need(digest(raw)==PACKAGE_MANIFEST_SHA and digest(archive_path.read_bytes())==PACKAGE_ARCHIVE_SHA,'Package checksum mismatch.')
    manifest=parse_json(raw)
    need(set(manifest)=={'format','environment','baseline','release','archive_sha256','files'}
        and manifest['format']==1 and manifest['environment']=='PHOS_UAT'
        and manifest['baseline']==BASELINE and manifest['release']==PACKAGE_RELEASE
        and manifest['archive_sha256']==PACKAGE_ARCHIVE_SHA,'Package identity/schema mismatch.')
    need(isinstance(manifest['files'],list) and len(manifest['files'])==len(BASELINE_HASHES),'Unexpected source file count.')
    records,payload={},{}
    for entry in manifest['files']:
        need(set(entry)=={'path','sha256','baseline_sha256','size'},'Unexpected source record.')
        name=relative(entry['path'])
        need(name in BASELINE_HASHES and name not in records and entry['baseline_sha256']==BASELINE_HASHES[name]
            and re.fullmatch('[0-9a-f]{64}',entry['sha256']) and type(entry['size']) is int and 0<entry['size']<=MAX_FILE,
            'Source allowlist/baseline/hash mismatch.')
        records[name]=entry
    need(set(records)==set(BASELINE_HASHES),'Missing reviewed source.')
    with zipfile.ZipFile(archive_path) as archive:
        need(len(archive.infolist())==len(records) and not archive.comment,'Unexpected ZIP entry count/comment.')
        for item in archive.infolist():
            name=relative(item.filename)
            need(name in records and name not in payload and not item.is_dir() and item.compress_type==zipfile.ZIP_STORED
                and stat.S_IFMT(item.external_attr>>16)==stat.S_IFREG and not item.flag_bits&1
                and item.date_time==(1980,1,1,0,0,0) and not item.extra and not item.comment,
                'Unsafe/noncanonical ZIP entry.')
            need(item.file_size==records[name]['size'] and item.file_size<=MAX_FILE,'ZIP file size mismatch.')
            data=archive.read(item)
            need(digest(data)==records[name]['sha256'],'Source file checksum mismatch.')
            payload[name]=data
    need(set(payload)==set(records),'Incomplete source archive.')
    return manifest,payload


def verify_source(root):
    for name,expected in BASELINE_HASHES.items():
        path=root/name
        no_links(path)
        if not path.parent.is_dir():
            need(str(path.parent.relative_to(root)).replace('\\','/')==NEW_PARENT and not path.parent.exists(),
                'An unreviewed source parent is missing.')
        current=signature(path)
        need((expected is None and current is None) or (expected is not None and current is not None and current['sha256']==expected),
            'Composite baseline drift or new-file collision: '+name)
        if current is not None:
            need(restorable_metadata(current),'Baseline source metadata cannot be safely recovered: '+name)
    no_links(root/NEW_PARENT)
    need(not (root/NEW_PARENT).exists(),'New Auth service directory already exists; review drift before deployment.')


def restorable_metadata(value):
    return value['uid'] in (0,OWNER_ID) and value['gid'] in (0,OWNER_ID,65534) and not value['mode']&0o022


@contextmanager
def mutation_lock():
    need(sys.platform.startswith('linux') and os.geteuid()==0 and fcntl is not None,'Linux root locking context required.')
    handles=[]
    try:
        for path in LOCK_PATHS:
            no_links(path)
            descriptor=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_NOFOLLOW,0o600)
            handle=os.fdopen(descriptor,'wb');handles.append(handle)
            info=os.fstat(handle.fileno())
            need(stat.S_ISREG(info.st_mode) and info.st_nlink==1 and info.st_uid==info.st_gid==0
                and stat.S_IMODE(info.st_mode)==0o600,'Mutation lock must be root-private.')
            try:fcntl.flock(handle.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
            except BlockingIOError:raise Refused('Another UAT rollout or mail setup is active; no live change made.')
        yield
    finally:
        for handle in reversed(handles):handle.close()


def directory_identity(path):
    no_links(path)
    info=path.lstat()
    need(stat.S_ISDIR(info.st_mode),'Expected an ordinary directory.')
    return {'inode':info.st_ino,'device':info.st_dev,'uid':info.st_uid,'gid':info.st_gid,'mode':stat.S_IMODE(info.st_mode)}


class NewAuthParent:
    def __init__(self,root):
        self.root=root
        self.entry={'before':None,'after':None,'started':False}
    def create(self,save):
        path=self.root/NEW_PARENT
        no_links(path)
        need(not path.exists() and path.parent.is_dir(),'Auth service parent collision.')
        self.entry['started']=True;save()
        path.mkdir(mode=0o700)
        self.entry['after']=directory_identity(path);save()
        os.chown(path,OWNER_ID,OWNER_ID);os.chmod(path,0o755)
        self.entry['after']=directory_identity(path);save()
    def restore(self):
        path=self.root/NEW_PARENT
        try:
            no_links(path)
            if not path.exists():
                return []
            need(self.entry['started'] and self.entry['after'] is not None
                and directory_identity(path)==self.entry['after'],'Unknown Auth parent retained.')
            need(not any(path.iterdir()),'Nonempty Auth parent retained.')
            path.rmdir()
            return []
        except (OSError,Refused):
            return [NEW_PARENT]


class NarrowDirectoryRecovery:
    def __init__(self,compiled,parent):
        self.compiled,self.parent=compiled,parent
    def restore(self):
        errors=[]
        for operation in (self.compiled.restore,self.parent.restore):
            try:errors+=operation()
            except Exception:errors.append('directory_recovery_failed')
        return errors


def immutable_snapshot(root):
    names=['.env','composer.json','composer.lock','package.json','package-lock.json','vendor/autoload.php',
        'vendor/composer/installed.php','vendor/composer/installed.json','bootstrap/cache/packages.php','bootstrap/cache/services.php']
    for folder in ('public/build','public/css','public/js'):
        path=root/folder
        no_links(path)
        if path.is_dir():
            names += [str(item.relative_to(root)).replace('\\','/') for item in path.rglob('*') if item.is_file() or item.is_symlink()]
    need(len(names)<=300,'Unexpected preserved-file count.')
    result={}
    for name in sorted(set(names)):
        no_links(root/name)
        result[name]=signature(root/name)
    result['@vendor-directory']=directory_identity(root/'vendor')
    result['@node_modules-directory']=directory_identity(root/'node_modules')
    return result


IDENTITY_ACTION=r'''
$default=$config->get('database.default');
$sessionConnection=$config->get('session.connection')?:$default;
if($config->get('session.driver')!=='database'||$config->get('session.table')!=='sessions'||$sessionConnection!==$default
 ||$config->get('auth.defaults.passwords')!=='users'||$config->get('auth.providers.users.model')!==App\Models\User::class
 ||$config->get('auth.passwords.users.provider')!=='users'||$config->get('auth.passwords.users.table')!=='password_reset_tokens'
 ||(int)$config->get('auth.passwords.users.expire')<1||(int)$config->get('auth.passwords.users.expire')>120
 ||(int)$config->get('auth.passwords.users.throttle')<60)throw new RuntimeException();
$socket=(string)($db['unix_socket']??'');
if($socket!==''&&(!preg_match('#^/[A-Za-z0-9_./-]+$#D',$socket)||str_contains($socket,'..')))throw new RuntimeException();
$dsn=$socket!==''?'mysql:unix_socket='.$socket.';dbname=uata_primehealersos;charset=utf8mb4'
 :'mysql:host='.$db['host'].';port=3306;dbname=uata_primehealersos;charset=utf8mb4';
$pdo=new PDO($dsn,$db['username']??'',$db['password']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5]);
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='uata_primehealersos')throw new RuntimeException();
$query=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=? AND table_name IN (?,?)');
$query->execute(['uata_primehealersos','password_reset_tokens','sessions']);
if((int)$query->fetchColumn()!==2)throw new RuntimeException();
$result=['identity'=>true,'php'=>PHP_VERSION,'providers'=>count($app->getLoadedProviders()),
 'session_default_connection_same'=>true,'tables_present'=>2];
'''


def php_configuration():
    code=CONFIGURATION_PHP.replace('__ACTION__',IDENTITY_ACTION)
    value=parse_json(run([PHP,'-d','display_errors=0','-d','log_errors=0','-d','opcache.enable_cli=0','-r',code],
        cwd=APP,environment=runtime_environment()))
    need(value.get('identity') is True and value.get('providers')==0 and value.get('session_default_connection_same') is True
        and value.get('tables_present')==2,'Fixed UAT identity/session/reset schema guard failed.')
    return value


def validate_target():
    need(sys.platform.startswith('linux') and os.geteuid()==0 and pwd is not None,'Only the Linux root UAT operator context is accepted.')
    need(APP==Path('/home/uat-admin.primehealers.com/public_html') and HOST=='uat-admin.primehealers.com'
        and DATABASE=='uata_primehealersos','Only this exact UAT target is supported.')
    no_links(APP)
    need(APP.is_dir() and APP.stat().st_uid==OWNER_ID and not stat.S_IMODE(APP.stat().st_mode)&0o022,'Unsafe UAT boundary.')
    account=pwd.getpwuid(OWNER_ID)
    need(account.pw_gid==OWNER_ID,'Unexpected website primary group.')
    private_file(PRIVATE_CONFIG)
    private_file(APP/'.env',OWNER_ID)
    config=parse_json(PRIVATE_CONFIG.read_bytes())
    need(config.get('application')==str(APP) and config.get('host')==HOST and config.get('database')==DATABASE
        and config.get('php')==str(PHP) and config.get('owner')==account.pw_name,'Private UAT identity differs.')
    regular(PHP,MAX_TREE)
    for path in (PHP,*PHP.parents):
        info=path.stat()
        need(info.st_uid==0 and not stat.S_IMODE(info.st_mode)&0o022,'Untrusted PHP executable or ancestor.')
    for name in ('bootstrap/cache','storage/framework','storage/logs'):
        path=APP/name;no_links(path);info=path.stat()
        need(path.is_dir() and info.st_uid==info.st_gid==OWNER_ID and stat.S_IMODE(info.st_mode)==0o700,'Repaired private directory required.')
    for name in CACHE_NAMES:
        path=APP/'bootstrap/cache'/name
        if path.exists() or path.is_symlink():private_file(path,OWNER_ID)
    for name in ('storage','bootstrap','public'):
        path=APP/name;no_links(path);info=path.stat()
        need(path.is_dir() and info.st_uid in (0,OWNER_ID) and not stat.S_IMODE(info.st_mode)&0o022,'Unsafe UAT traversal boundary.')
    vhost=Path('/usr/local/lsws/conf/vhosts')/HOST/'vhost.conf'
    regular(vhost)
    info=vhost.stat()
    need(info.st_uid in (0,65534) and not stat.S_IMODE(info.st_mode)&0o022,'Untrusted UAT vhost metadata.')
    need(re.search(r'(?im)^\s*docRoot\s+\$VH_ROOT/public_html/public\s*$',vhost.read_text()),'UAT document root differs.')
    php_configuration()


def rebuild_caches(files,compiled,save):
    folder=APP/(STAGE_PREFIX+secrets.token_hex(12))
    folder.mkdir(mode=0o700);os.chown(folder,OWNER_ID,OWNER_ID)
    env=runtime_environment()
    env.update({'APP_CONFIG_CACHE':str(folder/'config.php'),'APP_ROUTES_CACHE':str(folder/'routes-v7.php'),
        'APP_PACKAGES_CACHE':str(folder/'packages.php'),'APP_SERVICES_CACHE':str(folder/'services.php')})
    for name in ('packages.php','services.php'):
        source=APP/'bootstrap/cache'/name
        if source.exists():
            regular(source)
            atomic(folder/name,source.read_bytes(),OWNER_ID,OWNER_ID,0o600)
    for command in ('config:cache','route:cache'):
        run([PHP,'-d','display_errors=0','-d','log_errors=0','-d','opcache.enable_cli=0','artisan',command,'--no-interaction'],
            cwd=APP,environment=env,timeout=240)
    views=folder/'compiled-views';views.mkdir(mode=0o700);os.chown(views,OWNER_ID,OWNER_ID)
    env=dict(env);env['APP_CONFIG_CACHE']=str(folder/'uncached-views.php');env['VIEW_COMPILED_PATH']=str(views)
    run([PHP,'-d','display_errors=0','-d','log_errors=0','-d','opcache.enable_cli=0','artisan','view:cache','--no-interaction'],
        cwd=APP,environment=env,timeout=240)
    for name in CACHE_NAMES:
        regular(folder/name)
        files.install('bootstrap/cache/'+name,(folder/name).read_bytes(),OWNER_ID,OWNER_ID,0o600,save)
    compiled.switch('compiled_views',views,save)


RUNTIME_GUARD_PHP=r'''
ob_start();try{
 require 'vendor/autoload.php';$app=require 'bootstrap/app.php';
 $app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
 if($app->environment()!=='uat'||parse_url(config('app.url'),PHP_URL_HOST)!=='uat-admin.primehealers.com'
  ||config('app.debug')!==false)throw new RuntimeException();
 $default=config('database.default');
 if(config('session.driver')!=='database'||config('session.table')!=='sessions'||(config('session.connection')?:$default)!==$default)throw new RuntimeException();
 $routes=$app['router']->getRoutes();
 foreach(['password.request','password.email','password.reset','password.store']as$name){
  $route=$routes->getByName($name);if(!$route)throw new RuntimeException();
  $middleware=$app['router']->resolveMiddleware($route->gatherMiddleware());
  if(!in_array(App\Http\Middleware\PasswordRecoveryHeaders::class,$middleware,true))throw new RuntimeException();
 }
 foreach(['password.email'=>'throttle:password-recovery','password.store'=>'throttle:password-reset']as$name=>$throttle){
  if(!in_array($throttle,$routes->getByName($name)->gatherMiddleware(),true))throw new RuntimeException();
 }
 foreach([App\Services\Auth\PasswordRecovery::class,App\Services\Auth\RevokeAccountSessions::class]as$class){if(!class_exists($class))throw new RuntimeException();}
 $mail=config('mail.mailers.smtp');
 if(!is_array($mail)||($mail['transport']??'')!=='smtp'||($mail['require_tls']??null)!==true
  ||!empty($mail['url'])||($mail['auto_tls']??true)===false||($mail['verify_peer']??true)===false
  ||($mail['stream']['ssl']['verify_peer']??true)===false||($mail['stream']['ssl']['verify_peer_name']??true)===false)throw new RuntimeException();
 foreach([['smtp',587],['smtps',465]]as[$scheme,$port]){
  $safe=$mail;$safe['host']='smtp.invalid';$safe['port']=$port;$safe['scheme']=$scheme;$safe['username']=null;$safe['password']=null;
  $transport=$app->make('mail.manager')->createSymfonyTransport($safe);
  if(!method_exists($transport,'isTlsRequired')||!$transport->isTlsRequired()
   ||!method_exists($transport,'isAutoTls')||!$transport->isAutoTls()
   ||($scheme==='smtps'&&!$transport->getStream()->isTLS()))throw new RuntimeException();
 }
 ob_end_clean();echo json_encode(['reset_routes'=>4,'throttles'=>2,'smtp_require_tls'=>true,'transport_tls_options'=>2,'messages_sent'=>0]);
}catch(Throwable$e){ob_end_clean();echo json_encode(['error_class'=>get_class($e)]);exit(2);}
'''


def runtime_guards():
    value=parse_json(run([PHP,'-d','display_errors=0','-d','log_errors=0','-d','opcache.enable_cli=0','-r',RUNTIME_GUARD_PHP],
        cwd=APP,environment=runtime_environment()))
    need(value=={'reset_routes':4,'throttles':2,'smtp_require_tls':True,'transport_tls_options':2,'messages_sent':0},
        'Reset route/configuration/TLS guard failed.')


def health():
    for local in (True,False):
        for path in ('/login','/up','/forgot-password','/reset-password/phos-nonexistent-reset-probe'):
            status,_,headers=http_check(path,LOG_ROOT,local=local)
            need(status=='200','UAT login/health/reset GET failed.')
            if path not in ('/login','/up'):
                need(re.search(r'(?im)^cache-control:[^\r\n]*no-store',headers)
                    and re.search(r'(?im)^referrer-policy:\s*no-referrer\s*$',headers),'Reset privacy headers missing.')
    for path in ('/dashboard','/rentals','/customers'):
        status,_,headers=http_check(path,LOG_ROOT,local=True)
        locations=re.findall(r'(?im)^location:\s*(.*?)\s*$',headers)
        need(status=='302' and len(locations)==1,'Protected route guard failed.')
        location=urllib.parse.urlsplit(locations[0])
        need(location.hostname in (None,HOST) and location.path.rstrip('/')=='/login','Unexpected protected redirect.')


def activate(payload,backup):
    need(ACTIVATION_REVIEW_COMPLETE,'Private review build: activation is disabled until source/tests/pins/recovery review.')
    verify_source(APP)
    php_configuration()
    preserved=immutable_snapshot(APP)
    web_php_probe(backup,False)
    slot=APP/(SLOT_PREFIX+secrets.token_hex(12));slot.mkdir(mode=0o700)
    files=FileRecovery(APP,backup,list(BASELINE_HASHES)+['bootstrap/cache/'+name for name in CACHE_NAMES]);files.snapshot()
    parent=NewAuthParent(APP);compiled=CompiledViewRecovery(APP,slot);directories=NarrowDirectoryRecovery(compiled,parent)
    state={'format':1,'application':str(APP),'baseline':BASELINE,'release':PACKAGE_RELEASE,'manifest_sha256':PACKAGE_MANIFEST_SHA,
        'source':files.entries,'compiled_views':compiled.entries,'new_parent':parent.entry,'preserved':preserved,
        'slot':str(slot),'phase':'backed_up','complete':False}
    save=lambda:atomic(backup/'recovery-state.json',json_bytes(state),0,0,0o600)
    save()
    marker=None
    try:
        marker=maintenance_enter(APP,backup,state,save)
        # Recheck both source and effective session connection immediately before writes.
        verify_source(APP);php_configuration()
        need(immutable_snapshot(APP)==preserved,'Preserved configuration/dependency/assets changed.')
        state['phase']='source_activation';save()
        parent.create(save)
        for name,data in payload.items():
            before=files.entries[name]['before']
            files.install(name,data,OWNER_ID,OWNER_ID,before['mode'] if before else 0o644,save)
        state['phase']='cache_activation';save()
        rebuild_caches(files,compiled,save)
        php_configuration();runtime_guards()
        need(immutable_snapshot(APP)==preserved,'Preserved environment/dependencies/assets changed.')
        web_php_probe(backup,True)
        maintenance_leave(APP,marker)
        health()
        state['phase']='complete';state['complete']=True;save()
    except BaseException:
        # Entry failures have not changed source/cache/dependency state. A marker
        # whose exact planned bytes match may be removed even if its post-write
        # journal/capture failed; no source mutation is inferred from this path.
        if not any(entry['write_started'] for entry in files.entries.values()) and not parent.entry['started'] and not compiled.entries:
            try:
                current=signature(APP/'storage/framework/down')
                known=current is None or current==marker or same_contents(current,state.get('maintenance_plan'))
                originals=all(signature(APP/name)==entry['before'] for name,entry in files.entries.items())
                if known and originals and immutable_snapshot(APP)==preserved:
                    if current is not None:maintenance_leave(APP,current)
                    state['phase']='pre_activation_failed_original_state_retained'
                    try:save()
                    except Exception:pass
                    raise Refused('Pre-activation failed; original source retained and owned maintenance marker removed.')
            except Refused as error:
                if str(error).startswith('Pre-activation failed;'):raise
            except Exception:pass
        def ensure_maintenance():
            nonlocal marker
            if marker is None:
                current=signature(APP/'storage/framework/down')
                if same_contents(current,state.get('maintenance_plan')):marker=current
            marker=restore_maintenance(APP,marker);state['maintenance_marker']=marker
        def recovered_runtime():
            need(immutable_snapshot(APP)==preserved,'Unknown preserved state retained; manual review required.')
            web_php_probe(backup,True);php_configuration()
            maintenance_leave(APP,marker)
            for local in (True,False):
                for path in ('/login','/up'):
                    need(http_check(path,backup,local=local)[0]=='200','Restored baseline HTTP health failed.')
        recovery=recover_with_maintenance(files,directories,ensure_maintenance,save,recovered_runtime)
        if not recovery['complete']:
            try:ensure_maintenance()
            except Exception:recovery['maintenance_retained']=None;recovery['maintenance_uncertain']=True
        state['recovery']=recovery;state['phase']='rolled_back' if recovery['complete'] else 'rollback_incomplete'
        try:save()
        except Exception:
            recovery['complete']=False;state['phase']='rollback_incomplete'
            try:ensure_maintenance();recovery['maintenance_retained']=True
            except Exception:recovery['maintenance_retained']=None;recovery['maintenance_uncertain']=True
        raise Refused('Activation failed; '+state['phase']+'; root-private evidence retained.')


def restore_backup(backup):
    global LOG_ROOT
    need(sys.platform.startswith('linux') and os.geteuid()==0 and APP==Path('/home/uat-admin.primehealers.com/public_html'),
        'Recovery requires fixed Linux root UAT context.')
    no_links(APP);private_file(PRIVATE_CONFIG)
    need(APP.is_dir() and APP.stat().st_uid==OWNER_ID and not stat.S_IMODE(APP.stat().st_mode)&0o022,'Unsafe recovery target.')
    identity=parse_json(PRIVATE_CONFIG.read_bytes())
    need(identity.get('application')==str(APP) and identity.get('host')==HOST and identity.get('database')==DATABASE,'Recovery target differs.')
    need(backup.parent==Path('/root') and re.fullmatch(BACKUP_PREFIX+r'[a-z0-9_]{8,32}',backup.name),'Unknown backup path.')
    no_links(backup);info=backup.stat()
    need(info.st_uid==info.st_gid==0 and stat.S_IMODE(info.st_mode)==0o700,'Backup must be root-private.')
    for name in ('manifest.json','recovery-state.json'):
        private_file(backup/name)
    raw=(backup/'manifest.json').read_bytes()
    need(PACKAGE_MANIFEST_SHA is not None and digest(raw)==PACKAGE_MANIFEST_SHA,'Recovery package pin differs.')
    manifest=parse_json(raw);state=parse_json((backup/'recovery-state.json').read_bytes())
    need(manifest.get('baseline')==BASELINE and manifest.get('release')==PACKAGE_RELEASE
        and state.get('application')==str(APP) and state.get('baseline')==BASELINE and state.get('release')==PACKAGE_RELEASE
        and state.get('manifest_sha256')==PACKAGE_MANIFEST_SHA,'Recovery release identity differs.')
    need(set(item['path'] for item in manifest['files'])==set(BASELINE_HASHES),'Recovery source allowlist differs.')
    names=list(BASELINE_HASHES)+['bootstrap/cache/'+name for name in CACHE_NAMES]
    need(set(state['source'])==set(names),'Recovery file set differs.')
    for index,name in enumerate(names):
        entry=state['source'][name]
        need(set(entry)>={'before','after','copy','write_started'} and set(entry)<={'before','after','copy','write_started','expected'}
            and entry['copy']==str(index) and type(entry['write_started']) is bool,'Unsafe recovery record.')
        if name in BASELINE_HASHES:
            need((BASELINE_HASHES[name] is None and entry['before'] is None)
                or (entry['before'] is not None and entry['before']['sha256']==BASELINE_HASHES[name]),'Recovery original source differs.')
        for key in ('before','after','expected'):
            value=entry.get(key)
            if value is not None:
                need(set(value)>={'sha256','size','uid','gid','mode'} and re.fullmatch('[0-9a-f]{64}',value['sha256'])
                    and type(value['size']) is int and 0<=value['size']<=MAX_FILE and value['uid'] in (0,OWNER_ID)
                    and value['gid'] in (0,OWNER_ID,65534) and type(value['mode']) is int and not value['mode']&0o022,
                    'Unsafe recovery metadata.')
        if entry['before'] is not None:
            path=backup/'files'/str(index);private_file(path)
            need(digest(path.read_bytes())==entry['before']['sha256'],'Recovery copy checksum mismatch.')
    slot=Path(state['slot'])
    need(slot.parent==APP and re.fullmatch(re.escape(SLOT_PREFIX)+'[0-9a-f]{24}',slot.name),'Unknown compiled-view recovery slot.')
    no_links(slot);info=slot.stat()
    need(info.st_uid==info.st_gid==0 and stat.S_IMODE(info.st_mode)==0o700,'Compiled-view recovery slot must remain root-private.')
    files=FileRecovery(APP,backup,names);files.entries=state['source']
    parent=NewAuthParent(APP)
    need(set(state['new_parent'])=={'before','after','started'} and state['new_parent']['before'] is None
        and type(state['new_parent']['started']) is bool,'Unknown Auth parent recovery record.')
    parent.entry=state['new_parent']
    if parent.entry['after'] is not None:
        value=parent.entry['after']
        need(set(value)=={'inode','device','uid','gid','mode'} and all(type(value[key]) is int and value[key]>=0 for key in value)
            and value['uid'] in (0,OWNER_ID) and value['gid'] in (0,OWNER_ID) and value['mode'] in (0o700,0o755),
            'Unsafe Auth parent recovery identity.')
    compiled=CompiledViewRecovery(APP,slot)
    need(set(state['compiled_views'])<= {'compiled_views'},'Dependency-directory mutation is forbidden.')
    compiled.entries=state['compiled_views']
    for name,entry in compiled.entries.items():
        need(set(entry)=={'before','new','candidate','old_moved','new_moved'} and type(entry['old_moved']) is bool
            and type(entry['new_moved']) is bool,'Malformed compiled-view recovery record.')
        compiled.verify_candidate_path(name,Path(entry['candidate']))
        for key in ('before','new'):
            value=entry[key]
            need(set(value)=={'sha256','inode','device','uid','gid','mode','entries'}
                and re.fullmatch('[0-9a-f]{64}',value['sha256']) and all(type(value[field]) is int and value[field]>=0
                    for field in ('inode','device','uid','gid','mode','entries')),'Malformed compiled-view identity.')
    directories=NarrowDirectoryRecovery(compiled,parent)
    LOG_ROOT=backup;marker=state.get('maintenance_marker')
    if marker is None:
        current=signature(APP/'storage/framework/down')
        if same_contents(current,state.get('maintenance_plan')):marker=current
    save=lambda:atomic(backup/'recovery-state.json',json_bytes(state),0,0,0o600)
    def ensure_maintenance():
        nonlocal marker
        marker=restore_maintenance(APP,marker);state['maintenance_marker']=marker
    def recovered_runtime():
        need(preserved_restoration_matches(APP,state['preserved']),'Unknown preserved state retained.')
        web_php_probe(backup,True);php_configuration();maintenance_leave(APP,marker)
        for local in (True,False):
            for path in ('/login','/up'):
                need(http_check(path,backup,local=local)[0]=='200','Restored baseline HTTP health failed.')
    result=recover_with_maintenance(files,directories,ensure_maintenance,save,recovered_runtime)
    if not result['complete']:
        try:ensure_maintenance()
        except Exception:result['maintenance_retained']=None;result['maintenance_uncertain']=True
    state['recovery']=result;state['phase']='rolled_back' if result['complete'] else 'rollback_incomplete'
    try:save()
    except Exception:
        result['complete']=False
        try:ensure_maintenance();result['maintenance_retained']=True
        except Exception:result['maintenance_retained']=None;result['maintenance_uncertain']=True
    return result


def main():
    global LOG_ROOT
    parser=argparse.ArgumentParser(description='Pinned nine-file password-recovery UAT release.')
    parser.add_argument('mode',choices=('verify-package','deploy','rollback'))
    parser.add_argument('--package',type=Path)
    parser.add_argument('--backup',type=Path)
    args=parser.parse_args()
    if args.mode=='rollback':
        need(args.backup is not None,'Exact private backup directory is required.')
        with mutation_lock():print(json.dumps(restore_backup(args.backup)))
        return
    need(args.package is not None,'Exact reviewed package directory is required.')
    manifest,payload=load_package(args.package)
    if args.mode=='verify-package':
        print(json.dumps({'verified':True,'files':len(payload),'release':PACKAGE_RELEASE,'activation_enabled':ACTIVATION_REVIEW_COMPLETE}))
        return
    need(ACTIVATION_REVIEW_COMPLETE,'Private review build: no deployment command is enabled.')
    need(sys.platform.startswith('linux') and os.geteuid()==0,'Linux root required.')
    with mutation_lock():
        LOG_ROOT=Path(tempfile.mkdtemp(prefix=BACKUP_PREFIX,dir='/root'));os.chmod(LOG_ROOT,0o700)
        atomic(LOG_ROOT/'reviewed-helper.py',Path(__file__).read_bytes(),0,0,0o600)
        atomic(LOG_ROOT/'manifest.json',(args.package/'manifest.json').read_bytes(),0,0,0o600)
        validate_target();verify_source(APP)
        activate(payload,LOG_ROOT)
    print(json.dumps({'deployment_complete':True,'environment':'PHOS_UAT','release':PACKAGE_RELEASE,'files':len(payload),
        'backup':str(LOG_ROOT),'dependencies_changed':False,'migrations_run':0,'messages_sent':0,
        'account_posts':0,'business_records_changed':False,'production_changed':False}))


if __name__=='__main__':
    try:main()
    except (Exception,KeyboardInterrupt) as error:
        print('STOPPED='+(str(error) if isinstance(error,Refused) else type(error).__name__),file=sys.stderr)
        sys.exit(2)
