#!/usr/bin/env python3
"""Fixed PHOS UAT metadata repair. Prepared only; no operator command bundled."""
import hashlib
import http.client
from itertools import islice
import json
import os
from pathlib import Path
import re
import secrets
import socket
import ssl
import stat
import sys
import tempfile

try:
    import pwd
except ImportError:
    pwd = None

APP = Path('/home/uat-admin.primehealers.com/public_html')
HOST = 'uat-admin.primehealers.com'
DATABASE = 'uata_primehealersos'
PRIVATE_CONFIG = Path('/root/phos-uat-pr8-backup-o46ztayl/target-config.json')
PHP = '/usr/local/lsws/lsphp82/bin/php'
UID = GID = 5006
BACKUP_PARENT = Path('/root')
BACKUP_PREFIX = 'phos-uat-permissions-backup-'
PRIVATE_DIRS = ('bootstrap/cache', 'storage/framework', 'storage/logs', 'storage/app/private', 'storage/app/pdf-runtime')
TRAVERSAL_DIRS = ('storage', 'storage/app', 'bootstrap')
CACHE_NAME = re.compile(r'(?:config|packages|services|events|routes-v[0-9]{1,3})\.php\Z')
PHASE = 'target'


class Refused(RuntimeError):
    pass


class ProbeCleanupRefused(Refused):
    def __init__(self, path):
        self.path = path


class PreflightRefused(Refused):
    def __init__(self, issues):
        self.issues = issues


class EntryRefused(Refused):
    def __init__(self, reason):
        self.reason = reason


def expect(value, reason):
    if not value:
        raise EntryRefused(reason)


def metadata_issue(label, reason, value=None):
    issue = {'label': label, 'reason': reason}
    if value is not None:
        issue.update({'observed_uid': value['uid'], 'observed_gid': value['gid'],
                      'observed_mode': oct(value['mode']), 'observed_kind': value['kind']})
    return issue


def need(value):
    if not value:
        raise Refused()


def no_links(path):
    for item in (path, *path.parents):
        need(not item.is_symlink())


def write_all(descriptor, data):
    remaining = memoryview(data)
    while remaining:
        written = os.write(descriptor, remaining)
        need(written > 0)
        remaining = remaining[written:]


def fingerprint_fd(descriptor):
    before = os.fstat(descriptor)
    kind = 'directory' if stat.S_ISDIR(before.st_mode) else 'file' if stat.S_ISREG(before.st_mode) else None
    need(kind is not None and (kind != 'file' or before.st_nlink == 1))
    value = {'device': before.st_dev, 'inode': before.st_ino, 'uid': before.st_uid, 'gid': before.st_gid,
             'mode': stat.S_IMODE(before.st_mode), 'kind': kind, 'ctime_ns': before.st_ctime_ns}
    if kind == 'file':
        need(before.st_size <= 4194304)
        os.lseek(descriptor, 0, os.SEEK_SET)
        digest = hashlib.sha256()
        while True:
            block = os.read(descriptor, 65536)
            if not block:
                break
            digest.update(block)
        value.update({'sha256': digest.hexdigest(), 'size': before.st_size, 'mtime_ns': before.st_mtime_ns, 'nlink': before.st_nlink})
    after = os.fstat(descriptor)
    need((before.st_dev, before.st_ino, before.st_mode, before.st_uid, before.st_gid, before.st_size,
          before.st_mtime_ns, before.st_ctime_ns) == (after.st_dev, after.st_ino, after.st_mode, after.st_uid,
          after.st_gid, after.st_size, after.st_mtime_ns, after.st_ctime_ns))
    return value


def open_checked(path):
    no_links(path)
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        value = fingerprint_fd(descriptor)
        current = path.lstat()
        need((current.st_dev, current.st_ino) == (value['device'], value['inode']))
        return descriptor, value
    except BaseException:
        os.close(descriptor)
        raise


def snapshot(path):
    descriptor, value = open_checked(path)
    os.close(descriptor)
    return value


def content_identity(value):
    return {key: item for key, item in value.items() if key not in ('uid', 'gid', 'mode', 'ctime_ns')}


def attributes(value):
    return {key: value[key] for key in ('uid', 'gid', 'mode')}


def target():
    need(sys.platform.startswith('linux') and pwd is not None and os.geteuid() == 0)
    need(APP == Path('/home/uat-admin.primehealers.com/public_html') and HOST == 'uat-admin.primehealers.com'
         and UID == GID == 5006)
    private = snapshot(PRIVATE_CONFIG)
    need(private['kind'] == 'file' and private['uid'] == 0 and private['mode'] == 0o600 and private['size'] < 65536)
    parent = snapshot(PRIVATE_CONFIG.parent)
    need(parent['uid'] == 0 and parent['mode'] == 0o700)
    value = json.loads(PRIVATE_CONFIG.read_bytes())
    need(value.get('application') == str(APP) and value.get('host') == HOST
         and value.get('database') == DATABASE and value.get('php') == PHP)
    owner = value.get('owner')
    need(isinstance(owner, str) and re.fullmatch(r'[a-z_][a-z0-9_-]*', owner) is not None)
    account = pwd.getpwnam(owner)
    need(account.pw_uid == UID and account.pw_gid == GID and UID != 0)
    boundary_issues = []
    for path, label, mode in ((APP, 'application_boundary', 0o750), (APP / 'public', 'public_boundary', 0o755)):
        observed = snapshot(path)
        if observed['kind'] != 'directory' or attributes(observed) != {'uid': UID, 'gid': 65534, 'mode': mode}:
            boundary_issues.append(metadata_issue(label, 'UnexpectedBoundaryMetadata', observed))
    if boundary_issues:
        raise PreflightRefused(boundary_issues)
    vhost = Path('/usr/local/lsws/conf/vhosts') / HOST / 'vhost.conf'
    metadata = snapshot(vhost)
    need(metadata['kind'] == 'file' and attributes(metadata) == {'uid': 65534, 'gid': 65534, 'mode': 0o750}
         and metadata['size'] < 1048576)
    need(re.search(r'(?im)^\s*docRoot\s+\$VH_ROOT/public_html/public\s*$', vhost.read_text()) is not None)
    return account


def allowed_relative(relative):
    return relative == '.env' or relative in PRIVATE_DIRS or relative in TRAVERSAL_DIRS or (
        Path(relative).parent.as_posix() == 'bootstrap/cache' and CACHE_NAME.fullmatch(Path(relative).name) is not None)


def collect_boundaries():
    paths = [APP, *APP.parents, APP / 'public']
    values, issues = {}, []
    for index, path in enumerate(paths):
        label = 'public_boundary' if path == APP / 'public' else 'application_boundary' if path == APP else 'application_ancestor_' + str(index)
        try:
            value = snapshot(path)
            if value['kind'] != 'directory' or value['uid'] not in (0, UID) or value['mode'] & 0o022:
                issues.append(metadata_issue(label, 'UnsafeBoundaryOwnerOrWriteAccess', value))
            else:
                values[str(path)] = {key: value[key] for key in ('device', 'inode', 'uid', 'gid', 'mode', 'kind')}
        except (OSError, Refused):
            issues.append(metadata_issue(label, 'UnsafeBoundaryIdentity'))
    if issues:
        raise PreflightRefused(issues)
    return values


def assert_boundaries(expected):
    need(collect_boundaries() == expected)


def collect_plan():
    records = []
    missing = []
    issues = []
    def record(relative, desired):
        value = None
        label = relative if relative in ('.env', *PRIVATE_DIRS, *TRAVERSAL_DIRS) else 'generated_cache_php'
        try:
            value = snapshot(APP / relative)
            proposed = desired(value)
            records.append({'path': relative, 'before': value, 'desired': proposed, 'after': value,
                            'changed': False, 'state': 'prepared'})
            return True
        except EntryRefused as exception:
            issues.append(metadata_issue(label, exception.reason, value))
        except FileNotFoundError:
            issues.append(metadata_issue(label, 'MissingRequiredEntry'))
        except Refused:
            issues.append(metadata_issue(label, 'UnsafeFilesystemIdentity'))
        except OSError:
            issues.append(metadata_issue(label, 'MetadataUnavailable'))
        return False
    def private(value):
        expect(value['kind'] == 'directory', 'UnexpectedEntryType')
        expect(value['uid'] == UID, 'UnexpectedOwner')
        return {'uid': UID, 'gid': GID, 'mode': 0o700}
    def traversal(value):
        expect(value['kind'] == 'directory', 'UnexpectedEntryType')
        if value['uid'] == UID:
            return {'uid': UID, 'gid': value['gid'], 'mode': 0o750}
        expect(value['uid'] == 0, 'UnexpectedOwner')
        expect(value['mode'] & 0o022 == 0, 'RootTraversalParentWritable')
        return attributes(value)
    def environment(value):
        expect(value['kind'] == 'file', 'UnexpectedEntryType')
        expect(value['uid'] == UID, 'UnexpectedOwner')
        expect(value['size'] <= 1048576, 'EnvironmentSizeLimit')
        return {'uid': UID, 'gid': GID, 'mode': 0o600}
    record('.env', environment)
    # Close shared-group rename access on traversal parents before child barriers.
    for relative in TRAVERSAL_DIRS:
        record(relative, traversal)
    for relative in PRIVATE_DIRS:
        path = APP / relative
        try:
            no_links(path)
            if path.exists():
                record(relative, private)
            elif relative != 'bootstrap/cache':
                missing.append(relative)
            else:
                issues.append(metadata_issue(relative, 'MissingRequiredEntry'))
        except Refused:
            issues.append(metadata_issue(relative, 'UnsafeFilesystemIdentity'))
    cache = APP / 'bootstrap/cache'
    cache_safe = any(entry['path'] == 'bootstrap/cache' for entry in records)
    try:
        entries = list(islice(cache.iterdir(), 129)) if cache_safe else []
    except OSError:
        issues.append(metadata_issue('bootstrap/cache', 'MetadataUnavailable'))
        entries = []
    if len(entries) > 128:
        issues.append({'label': 'bootstrap/cache', 'reason': 'DirectoryEntryLimit', 'entry_count': len(entries)})
        entries = []
    generated = []
    unsafe_entries = 0
    for path in entries:
        if path.is_symlink():
            unsafe_entries += 1
        elif path.suffix.lower() == '.php' and CACHE_NAME.fullmatch(path.name) is None:
            unsafe_entries += 1
        elif path.suffix.lower() == '.php':
            generated.append(path.name)
    if unsafe_entries:
        issues.append({'label': 'bootstrap/cache', 'reason': 'UnreviewedOrLinkedCacheEntry', 'entry_count': unsafe_entries})
    if len(generated) > 32:
        issues.append({'label': 'bootstrap/cache', 'reason': 'GeneratedCacheFileLimit', 'entry_count': len(generated)})
        generated = []
    for name in sorted(generated):
        def cached(value):
            expect(value['kind'] == 'file', 'UnexpectedEntryType')
            expect(value['uid'] in (0, UID), 'UnexpectedOwner')
            return {'uid': UID, 'gid': GID, 'mode': 0o600}
        record('bootstrap/cache/' + name, cached)
    if issues:
        raise PreflightRefused(issues)
    need(len(records) <= 41 and len({entry['path'] for entry in records}) == len(records))
    return records, missing, sorted(generated)


def assert_plan_current(records, missing, cache_names):
    for entry in records:
        need(snapshot(APP / entry['path']) == entry['after'])
    for relative in missing:
        no_links(APP / relative)
        need(not (APP / relative).exists())
    current = []
    for path in (APP / 'bootstrap/cache').iterdir():
        need(not path.is_symlink())
        if path.suffix.lower() == '.php':
            need(CACHE_NAME.fullmatch(path.name) is not None)
            current.append(path.name)
    need(sorted(current) == cache_names)


class FixedUatHttps(http.client.HTTPSConnection):
    def __init__(self):
        self.verified_context = ssl.create_default_context()
        need(self.verified_context.check_hostname and self.verified_context.verify_mode == ssl.CERT_REQUIRED)
        super().__init__(HOST, 443, timeout=12, context=self.verified_context)

    def connect(self):
        connection = socket.create_connection(('127.0.0.1', 443), timeout=12)
        try:
            self.sock = self.verified_context.wrap_socket(connection, server_hostname=HOST)
        except BaseException:
            connection.close()
            raise


def https_get(path, *, token=None):
    need(path in ('/login', '/up') or re.fullmatch(r'/phos-permission-probe-[0-9a-f]{32}\.php', path) is not None)
    connection = FixedUatHttps()
    try:
        headers = {'Host': HOST, 'Cache-Control': 'no-store', 'Connection': 'close'}
        if token is not None:
            need(re.fullmatch(r'[0-9a-f]{64}', token) is not None)
            headers['X-Phos-Permission-Probe'] = token
        connection.request('GET', path, headers=headers)
        response = connection.getresponse()
        need(response.status == 200)
        if path in ('/login', '/up'):
            return b''
        body = response.read(16385)
        need(len(body) <= 16384)
        return body
    finally:
        connection.close()


WEB_PHP = r'''<?php
declare(strict_types=1);
if($_SERVER['REQUEST_METHOD']!=='GET'||!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)
 ||($_SERVER['HTTP_HOST']??'')!=='uat-admin.primehealers.com'
 ||!hash_equals('__TOKEN__',(string)($_SERVER['HTTP_X_PHOS_PERMISSION_PROBE']??''))){http_response_code(404);exit;}
header('Content-Type: application/json');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
try{
 $effective=static function(string $kind):int{
  $method=$kind==='Uid'?'posix_geteuid':'posix_getegid';if(function_exists($method))return (int)$method();
  $status=@file_get_contents('/proc/self/status');if(!is_string($status)||!preg_match('/^'.$kind.':\s+\d+\s+(\d+)\s+/m',$status,$match))throw new RuntimeException();
  return (int)$match[1];};
 $base='/home/uat-admin.primehealers.com/public_html';$dirs=['cache'=>'bootstrap/cache','framework'=>'storage/framework','logs'=>'storage/logs','private'=>'storage/app/private','pdf'=>'storage/app/pdf-runtime'];$checks=[];
 foreach($dirs as $key=>$relative){$path=$base.'/'.$relative;$checks[$key]=!file_exists($path)?null:(is_dir($path)&&is_readable($path)&&is_executable($path));}
 $cacheReadable=true;$cacheCount=0;foreach(new DirectoryIterator($base.'/bootstrap/cache') as $file){if(preg_match('/^(?:config|packages|services|events|routes-v[0-9]{1,3})\.php$/D',$file->getFilename())){$cacheCount++;$cacheReadable=$cacheReadable&&$file->isFile()&&is_readable($file->getPathname());}}
 echo json_encode(['effective_uid'=>$effective('Uid'),'effective_gid'=>$effective('Gid'),'env_readable'=>is_readable($base.'/.env'),
 'cache_files_readable'=>$cacheReadable,'cache_file_count'=>$cacheCount,'directories'=>$checks],JSON_THROW_ON_ERROR);
}catch(Throwable $exception){http_response_code(503);echo '{"error_class":"WebIdentityUnavailable"}';}
'''


def create_probe():
    token = secrets.token_hex(32)
    name = 'phos-permission-probe-' + secrets.token_hex(16) + '.php'
    path = APP / 'public' / name
    no_links(path)
    content = WEB_PHP.replace('__TOKEN__', token).encode()
    descriptor = os.open(path, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    created = os.fstat(descriptor)
    try:
        write_all(descriptor, content)
        os.fchown(descriptor, UID, GID)
        os.fsync(descriptor)
    except BaseException:
        try:
            current = snapshot(path)
            need((current['device'], current['inode']) == (created.st_dev, created.st_ino)
                 and current['kind'] == 'file' and current['mode'] == 0o600
                 and current['uid'] in (0, UID) and current['size'] <= len(content)
                 and current['sha256'] == hashlib.sha256(content[:current['size']]).hexdigest())
            path.unlink()
        except BaseException:
            raise ProbeCleanupRefused(path) from None
        raise
    finally:
        os.close(descriptor)
    value = snapshot(path)
    need(value['sha256'] == hashlib.sha256(content).hexdigest())
    return path, token, value


def verify_web(path, token, *, require_cache_files=False):
    value = json.loads(https_get('/' + path.name, token=token))
    need(isinstance(value, dict) and set(value) == {'effective_uid', 'effective_gid', 'env_readable', 'directories', 'cache_files_readable', 'cache_file_count'})
    need(type(value['effective_uid']) is int and value['effective_uid'] == UID and type(value['effective_gid']) is int)
    need(value['env_readable'] is True and isinstance(value['directories'], dict)
         and set(value['directories']) == {'cache', 'framework', 'logs', 'private', 'pdf'}
         and all(item is None or item is True for item in value['directories'].values()))
    need(type(value['cache_files_readable']) is bool and type(value['cache_file_count']) is int
         and 0 <= value['cache_file_count'] <= 32 and (not require_cache_files or value['cache_files_readable']))
    return {'effective_uid': value['effective_uid'], 'effective_gid': value['effective_gid'],
            'env_readable': True, 'existing_private_directories_accessible': True,
            'cache_files_readable': value['cache_files_readable'], 'cache_file_count': value['cache_file_count']}


def remove_probe(path, expected):
    need(path.parent == APP / 'public' and re.fullmatch(r'phos-permission-probe-[0-9a-f]{32}\.php', path.name) is not None)
    need(snapshot(path) == expected)
    path.unlink()
    need(not path.exists())


def write_journal(folder, value):
    destination = folder / 'metadata.json'
    temporary = folder / ('metadata-' + secrets.token_hex(8) + '.new')
    data = json.dumps(value, sort_keys=True, indent=2).encode()
    descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        write_all(descriptor, data)
        os.fsync(descriptor)
    finally:
        os.close(descriptor)
    os.replace(temporary, destination)
    descriptor = os.open(folder, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def make_backup(records, missing, names):
    need(snapshot(BACKUP_PARENT)['uid'] == 0)
    folder = Path(tempfile.mkdtemp(prefix=BACKUP_PREFIX, dir=BACKUP_PARENT))
    os.chmod(folder, 0o700)
    need(snapshot(folder)['uid'] == 0 and snapshot(folder)['mode'] == 0o700)
    source = Path(__file__).read_bytes()
    descriptor = os.open(folder / 'recovery-helper.py', os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
    try:
        write_all(descriptor, source)
        os.fsync(descriptor)
    finally:
        os.close(descriptor)
    value = {'schema': 1, 'application': str(APP), 'host': HOST, 'uid': UID, 'gid': GID,
             'source_sha256': hashlib.sha256(source).hexdigest(), 'records': records,
             'missing': missing, 'cache_names': names, 'state': 'prepared'}
    write_journal(folder, value)
    return folder, value


def set_record(entry, folder, journal):
    path = APP / entry['path']
    descriptor, value = open_checked(path)
    try:
        need(value == entry['after'])
        if attributes(value) == entry['desired']:
            entry['state'] = 'unchanged'
            return
        entry['state'] = 'applying'
        write_journal(folder, journal)
        try:
            owner_first = entry['before']['uid'] == 0 and entry['desired']['uid'] == UID
            operations = ('owner', 'mode') if owner_first else ('mode', 'owner')
            for operation in operations:
                if operation == 'mode':
                    os.fchmod(descriptor, entry['desired']['mode'])
                else:
                    os.fchown(descriptor, entry['desired']['uid'], entry['desired']['gid'])
                entry['changed'] = True
                entry['after'] = fingerprint_fd(descriptor)
                write_journal(folder, journal)
            need(content_identity(entry['after']) == content_identity(entry['before'])
                 and attributes(entry['after']) == entry['desired'])
            entry['state'] = 'applied'
            write_journal(folder, journal)
        except BaseException:
            current = fingerprint_fd(descriptor)
            variants = (attributes(entry['before']), {**attributes(entry['before']), 'mode': entry['desired']['mode']},
                        {**entry['desired'], 'mode': entry['before']['mode']}, entry['desired'])
            need(content_identity(current) == content_identity(entry['before']) and attributes(current) in variants)
            entry['changed'] = current != entry['before']
            entry['after'] = current
            entry['state'] = 'failed_after_verified_mutation'
            write_journal(folder, journal)
            raise
    finally:
        os.close(descriptor)


def rollback_records(records):
    failures = []
    for entry in reversed(records):
        try:
            need(allowed_relative(entry['path']))
            if not entry['changed'] or entry['state'] == 'restored':
                # An interrupted intent may have changed metadata before its
                # post-state was journalled. Never call that a clean rollback.
                need(snapshot(APP / entry['path']) == entry['after'])
                continue
            descriptor, value = open_checked(APP / entry['path'])
            try:
                need(value == entry['after'])
                before = entry['before']
                os.fchown(descriptor, before['uid'], before['gid'])
                os.fchmod(descriptor, before['mode'])
                restored = fingerprint_fd(descriptor)
                need(content_identity(restored) == content_identity(before) and attributes(restored) == attributes(before))
                entry['after'] = restored
                entry['state'] = 'restored'
            finally:
                os.close(descriptor)
        except BaseException:
            failures.append(entry['path'])
    return failures


def validate_record(entry):
    need(isinstance(entry, dict) and set(entry) == {'path', 'before', 'desired', 'after', 'changed', 'state'}
         and allowed_relative(entry['path']) and type(entry['changed']) is bool
         and entry['state'] in ('prepared', 'unchanged', 'applying', 'applied', 'failed_after_verified_mutation', 'restored'))
    before, after = entry['before'], entry['after']
    for value in (before, after):
        need(isinstance(value, dict) and value.get('kind') in ('file', 'directory'))
        for key in ('device', 'inode', 'uid', 'gid', 'mode', 'ctime_ns'):
            need(type(value.get(key)) is int and value[key] >= 0)
        need(value['mode'] <= 0o7777 and value['inode'] > 0)
        if value['kind'] == 'file':
            need(value.get('nlink') == 1 and type(value.get('size')) is int and 0 <= value['size'] <= 4194304
                 and re.fullmatch(r'[0-9a-f]{64}', value.get('sha256', '')) is not None)
    relative = entry['path']
    if relative in PRIVATE_DIRS:
        need(before['kind'] == 'directory' and before['uid'] == UID)
        desired = {'uid': UID, 'gid': GID, 'mode': 0o700}
    elif relative in TRAVERSAL_DIRS:
        need(before['kind'] == 'directory' and before['uid'] in (0, UID))
        if before['uid'] == 0:
            need(before['mode'] & 0o022 == 0)
            desired = attributes(before)
        else:
            desired = {'uid': UID, 'gid': before['gid'], 'mode': 0o750}
    else:
        need(before['kind'] == 'file' and before['uid'] in ((UID,) if relative == '.env' else (0, UID)))
        desired = {'uid': UID, 'gid': GID, 'mode': 0o600}
    need(entry['desired'] == desired and content_identity(before) == content_identity(after))
    variants = (attributes(before), {**attributes(before), 'mode': desired['mode']}, {**desired, 'mode': before['mode']}, desired)
    need(attributes(after) in variants)


def validate_backup(folder):
    need(folder.parent == BACKUP_PARENT and re.fullmatch(BACKUP_PREFIX + r'[a-z0-9_]{6,20}', folder.name) is not None)
    value = snapshot(folder)
    need(value['uid'] == 0 and value['mode'] == 0o700)
    metadata = snapshot(folder / 'metadata.json')
    recovery = snapshot(folder / 'recovery-helper.py')
    need(metadata['uid'] == recovery['uid'] == 0 and metadata['mode'] == recovery['mode'] == 0o600)
    journal = json.loads((folder / 'metadata.json').read_bytes())
    need(journal.get('schema') == 1 and journal.get('application') == str(APP) and journal.get('host') == HOST
         and journal.get('uid') == UID and journal.get('gid') == GID and recovery['sha256'] == journal.get('source_sha256'))
    records = journal.get('records')
    need(isinstance(records, list) and 1 <= len(records) <= 41
         and len({entry['path'] for entry in records}) == len(records)
         and all(allowed_relative(entry['path']) for entry in records))
    for entry in records:
        validate_record(entry)
    return journal


def apply():
    global PHASE
    target()
    PHASE = 'plan'
    records, missing, names = collect_plan()
    boundaries = collect_boundaries()
    probe = token = probe_identity = folder = journal = None
    report = {'diagnostic': 'PHOS_UAT_PERMISSION_REPAIR', 'status': 'refused', 'existing_file_bytes_mutation_requested': False,
              'production_changed': False, 'direct_database_operations': False, 'probe_removed': False}
    try:
        PHASE = 'web_identity'
        probe, token, probe_identity = create_probe()
        report['before_web'] = verify_web(probe, token)
        PHASE = 'backup'
        assert_boundaries(boundaries)
        assert_plan_current(records, missing, names)
        folder, journal = make_backup(records, missing, names)
        journal['boundaries'] = boundaries
        write_journal(folder, journal)
        report['private_backup'] = str(folder)
        # Reconfirm the real web account immediately before any target chmod.
        verify_web(probe, token)
        assert_boundaries(boundaries)
        assert_plan_current(records, missing, names)
        PHASE = 'metadata'
        for entry in records:
            assert_boundaries(boundaries)
            set_record(entry, folder, journal)
        PHASE = 'verify'
        assert_plan_current(records, missing, names)
        assert_boundaries(boundaries)
        report['after_web'] = verify_web(probe, token, require_cache_files=True)
        https_get('/login')
        https_get('/up')
        assert_plan_current(records, missing, names)
        assert_boundaries(boundaries)
        journal['state'] = 'complete'
        write_journal(folder, journal)
        report.update({'status': 'complete', 'metadata_paths_reviewed': len(records),
                       'metadata_paths_changed': sum(entry['changed'] for entry in records),
                       'file_hashes_unchanged': True, 'local_https_login_up': True})
    except BaseException as exception:
        report.update({'error_class': type(exception).__name__, 'phase': PHASE})
        if isinstance(exception, ProbeCleanupRefused):
            report.update({'private_probe': str(exception.path), 'manual_review_required': True})
        if journal is not None:
            # Undo attributes before any network or framework verification.
            failures = rollback_records(records)
            report['attributes_restored'] = not failures
            report['manual_review_required'] = bool(failures)
            journal['state'] = 'rollback_uncertain' if failures else 'rolled_back'
            try:
                write_journal(folder, journal)
            except BaseException:
                report['manual_review_required'] = True
    finally:
        if probe is not None:
            try:
                remove_probe(probe, probe_identity)
                report['probe_removed'] = True
            except BaseException:
                if journal is not None and report.get('status') == 'complete':
                    failures = rollback_records(records)
                    report['attributes_restored'] = not failures
                    journal['state'] = 'rollback_uncertain' if failures else 'rolled_back'
                    try:
                        write_journal(folder, journal)
                    except BaseException:
                        pass
                report.update({'status': 'refused', 'phase': 'probe_cleanup', 'error_class': 'ProbeCleanupRefused',
                               'private_probe': str(probe), 'manual_review_required': True})
    return report


def main():
    try:
        if len(sys.argv) == 3 and sys.argv[1] == '--rollback':
            target()
            folder = Path(sys.argv[2])
            journal = validate_backup(folder)
            failures = rollback_records(journal['records'])
            journal['state'] = 'rollback_uncertain' if failures else 'rolled_back'
            write_journal(folder, journal)
            result = {'diagnostic': 'PHOS_UAT_PERMISSION_REPAIR', 'status': 'refused' if failures else 'rolled_back',
                      'attributes_restored': not failures, 'manual_review_required': bool(failures), 'private_backup': str(folder)}
        else:
            need(len(sys.argv) == 1)
            result = apply()
        print(json.dumps(result, sort_keys=True, indent=2))
        return 0 if result.get('status') in ('complete', 'rolled_back') else 2
    except BaseException as exception:
        result = {'diagnostic': 'PHOS_UAT_PERMISSION_REPAIR', 'status': 'refused',
                  'phase': PHASE, 'error_class': type(exception).__name__}
        if isinstance(exception, PreflightRefused):
            result.update({'preflight_issues': exception.issues, 'target_metadata_changed': False})
        print(json.dumps(result, sort_keys=True))
        return 2


if __name__ == '__main__':
    sys.exit(main())
