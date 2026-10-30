#!/usr/bin/env python3
"""PRIVATE REVIEW BUILD. Fixed PHOS UAT only; no public bootstrap is supplied.

The package and recovery primitives are testable offline. Activation is deliberately
disabled pending the repaired-server inventory and the final sandbox-compatible
application payload. Never run this review build on the server.
"""

import argparse
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
import tarfile
import tempfile
import time
import urllib.request
import urllib.parse
import zipfile

try:
    import fcntl
    import pwd
    import resource
    import socket
    import struct
except ImportError:
    fcntl = pwd = resource = socket = struct = None

APP = Path('/home/uat-admin.primehealers.com/public_html')
HOST = 'uat-admin.primehealers.com'
DATABASE = 'uata_primehealersos'
OWNER_ID = 5006
PHP = Path('/usr/local/lsws/lsphp82/bin/php')
PRIVATE_CONFIG = Path('/root/phos-uat-pr8-backup-o46ztayl/target-config.json')
BASELINE = '90d81d9aa69951aad092caa89defa5524a9097c8'
RELEASE = '808d1c71d33965a360f6794e8ae01ffbe4d979fd'
MANIFEST_SHA = '3b21d5545282c88c6456ff6332c26959b195b317c14c101c1b24257e9c2fb0a9'
ARCHIVE_SHA = '1acbf90f0f3721a59c5c4ebe899aebebae02b2ede8d8d1a3941dee489f1ea7b4'
FILE_COUNT = 67
PDF_DRIVER_SHA = 'ed38342a61fa9cbd091078f7f76787182be89738ed8effea1e713c58d3a5722f'
NODE_VERSION = 'v22.23.4'
NODE_NAME = 'node-v22.23.4-linux-x64'
NODE_URL = 'https://nodejs.org/dist/v22.23.4/' + NODE_NAME + '.tar.xz'
NODE_SHA = '1805bcd35e6815559bd2eb97db438d3fdb0eb3a5e6a977b7c1badca76337e9ce'
NODE_PARENT = Path('/opt/phos-uat-runtime')
SYSTEM_PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
BACKUP_PREFIX = 'phos-access-security-backup-'
STAGE_PREFIX = '.phos-access-stage-'
SLOT_PREFIX = '.phos-access-recovery-'
MAX_FILE = 8 * 1024 * 1024
MAX_PACKAGE = 16 * 1024 * 1024
MAX_CAPTURE = 2 * 1024 * 1024
MAX_TREE = 1024 * 1024 * 1024
PDF_ENV_KEYS = frozenset({'PDF_NODE_BINARY', 'PDF_NODE_MODULE_PATH', 'PDF_BROWSER_PATH', 'PDF_DISABLE_SANDBOX'})
CACHE_NAMES = ('config.php', 'packages.php', 'services.php', 'routes-v7.php')
PROTECTED = ('/dashboard', '/rentals', '/sales', '/customers', '/business-partners', '/renewal-center',
             '/pickup-center', '/communication-center', '/deliveries', '/knowledge')
# This is an explicit review boundary, not a substitute for any server gate.
ACTIVATION_REVIEW_COMPLETE = False


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


def runtime_path(name):
    relative(name)
    need(name.startswith(('app/', 'config/', 'resources/', 'routes/', 'public/'))
         or name in ('bootstrap/app.php', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json'),
         'Path is outside the runtime payload contract.')
    need(not any(piece in name.split('/') for piece in ('vendor', 'node_modules', '.git', '.env', 'storage')),
         'Private, dependency, or repository path in payload.')
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


def load_package(folder):
    manifest_path, archive_path = folder / 'manifest.json', folder / 'payload.zip'
    regular(manifest_path, MAX_CAPTURE)
    regular(archive_path, MAX_PACKAGE)
    raw = manifest_path.read_bytes()
    need(digest(raw) == MANIFEST_SHA and digest(archive_path.read_bytes()) == ARCHIVE_SHA, 'Pinned payload checksum mismatch.')
    manifest = parse_json(raw)
    need(manifest.get('format') == 1 and manifest.get('purpose') == 'PHOS_UAT_REVIEW_PAYLOAD'
         and manifest.get('baseline') == BASELINE and manifest.get('release') == RELEASE
         and manifest.get('archive_sha256') == ARCHIVE_SHA, 'Payload identity mismatch.')
    files = manifest.get('files')
    need(isinstance(files, list) and len(files) == FILE_COUNT, 'Unexpected payload count.')
    records, data = {}, {}
    for entry in files:
        name = runtime_path(entry['path'])
        need(name not in records and entry['kind'] in ('git_source', 'composer_staging_input', 'vite_manifest', 'vite_asset'),
             'Duplicate or unsupported payload record.')
        for value in (entry['before'], entry['after']):
            need(value is None or (set(value) == {'sha256', 'size'} and re.fullmatch('[0-9a-f]{64}', value['sha256'])
                 and type(value['size']) is int and 0 < value['size'] <= MAX_FILE), 'Malformed payload digest record.')
        need(entry['after'] is not None, 'Deletion is not part of this rollout.')
        records[name] = entry
    with zipfile.ZipFile(archive_path) as archive:
        need(len(archive.infolist()) == FILE_COUNT and not archive.comment, 'Unexpected ZIP entries or comment.')
        for item in archive.infolist():
            name = runtime_path(item.filename)
            need(name in records and name not in data and not item.is_dir() and item.compress_type == zipfile.ZIP_STORED
                 and item.date_time == (1980, 1, 1, 0, 0, 0) and not item.extra and not item.comment
                 and stat.S_IFMT(item.external_attr >> 16) == stat.S_IFREG and not item.flag_bits & 1,
                 'Noncanonical or unsafe ZIP entry.')
            need(item.file_size == records[name]['after']['size'] and item.file_size <= MAX_FILE, 'Payload size mismatch.')
            content = archive.read(item)
            need(digest(content) == records[name]['after']['sha256'], 'Payload content checksum mismatch.')
            data[name] = content
    need(set(data) == set(records), 'Incomplete payload.')
    for lock, expected in manifest['locks'].items():
        need(lock in data and digest(data[lock]) == expected, 'Staging lock does not match payload.')
    assets = [entry for entry in files if entry['kind'].startswith('vite_')]
    need(len(assets) == 3 and sum(entry['kind'] == 'vite_manifest' for entry in assets) == 1, 'Unexpected Vite asset set.')
    return manifest, records, data


def verify_source(root, records, manifest):
    for name, entry in records.items():
        path = root / name
        no_links(path)
        need(path.parent.is_dir(), 'A reviewed destination parent is missing.')
        current = signature(path)
        before = entry['before']
        need((before is None and current is None) or (before is not None and current is not None
             and current['sha256'] == before['sha256'] and current['size'] == before['size']),
             'Composite baseline mismatch or new-file collision: ' + name)
    for entry in manifest['superseded_baseline_assets_to_preserve']:
        path = root / runtime_path(entry['path'])
        current = signature(path)
        need(current is not None and current['sha256'] == entry['sha256'] and current['size'] == entry['size'],
             'A preserved baseline Vite asset differs.')


def validate_inventory(value):
    need(isinstance(value, dict) and set(value) == {'format', 'reviewed', 'application', 'host', 'database',
         'uid', 'gid', 'php_version', 'permissions_repaired', 'web_effective_uid', 'composer', 'browser'},
         'Unexpected inventory schema; no runtime paths are inferred.')
    need(value['format'] == 1 and value['reviewed'] is True and value['permissions_repaired'] is True
         and value['application'] == str(APP) and value['host'] == HOST and value['database'] == DATABASE
         and value['uid'] == value['gid'] == value['web_effective_uid'] == OWNER_ID
         and re.fullmatch(r'8\.2\.[0-9]+', value['php_version']), 'Reviewed UAT runtime inventory is incomplete.')
    for key in ('composer', 'browser'):
        entry = value[key]
        need(isinstance(entry, dict) and set(entry) == {'path', 'sha256', 'version', 'reviewed_supported'}
             and entry['reviewed_supported'] is True and isinstance(entry['path'], str)
             and entry['path'].startswith(('/usr/', '/opt/', '/root/')) and '..' not in Path(entry['path']).parts
             and re.fullmatch(r'/[A-Za-z0-9_./+\-]+', entry['path'])
             and re.fullmatch('[0-9a-f]{64}', entry['sha256']) and isinstance(entry['version'], str),
             'An exact reviewed Composer/browser path, version and digest are required.')
    need(re.fullmatch(r'2\.[0-9]+\.[0-9]+', value['composer']['version']), 'Only a reviewed Composer 2 executable is supported.')
    need(re.fullmatch(r'[0-9]+(?:\.[0-9]+){2,3}', value['browser']['version']), 'An exact reviewed browser version is required.')
    return value


def trusted_executable(entry):
    path = Path(entry['path'])
    no_links(path)
    info = regular(path, 256 * 1024 * 1024)
    for parent in (path.parent, *path.parent.parents):
        record = parent.stat()
        need(record.st_uid == 0 and not stat.S_IMODE(record.st_mode) & 0o022, 'Runtime executable has a writable or non-root ancestor.')
    need(info.st_uid == 0 and not stat.S_IMODE(info.st_mode) & 0o022 and digest(path.read_bytes()) == entry['sha256'],
         'Trusted executable bytes/owner/mode differ from reviewed inventory.')
    return path


def runtime_environment(root, node=None):
    values = {'PATH': ((str(node / 'bin') + ':') if node else '') + SYSTEM_PATH,
              'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8', 'COMPOSER_ALLOW_SUPERUSER': '0',
              'COMPOSER_NO_INTERACTION': '1', 'COMPOSER_DISABLE_NETWORK': '0',
              'PUPPETEER_SKIP_DOWNLOAD': 'true', 'NPM_CONFIG_UPDATE_NOTIFIER': 'false',
              'NPM_CONFIG_AUDIT': 'false', 'NPM_CONFIG_FUND': 'false'}
    for key, folder in {'HOME': 'home', 'TMPDIR': 'tmp', 'TMP': 'tmp', 'TEMP': 'tmp',
                        'XDG_CONFIG_HOME': 'xdg-config', 'XDG_CACHE_HOME': 'xdg-cache',
                        'XDG_RUNTIME_DIR': 'xdg-runtime', 'COMPOSER_HOME': 'composer-home',
                        'COMPOSER_CACHE_DIR': 'composer-cache', 'NPM_CONFIG_CACHE': 'npm-cache'}.items():
        values[key] = str(root / folder)
    values['NPM_CONFIG_USERCONFIG'] = str(root / 'empty-npmrc')
    return values


def prepare_environment(root):
    for key, value in runtime_environment(root).items():
        if key in ('HOME', 'TMPDIR', 'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'XDG_RUNTIME_DIR',
                   'COMPOSER_HOME', 'COMPOSER_CACHE_DIR', 'NPM_CONFIG_CACHE'):
            path = Path(value)
            path.mkdir(mode=0o700)
            if hasattr(os, 'chown'):
                os.chown(path, OWNER_ID, OWNER_ID)
    atomic(root / 'empty-npmrc', b'', OWNER_ID, OWNER_ID, 0o600)


def owner_preexec():
    os.umask(0o077)
    os.setgroups([])
    os.setgid(OWNER_ID)
    os.setuid(OWNER_ID)
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
    resource.setrlimit(resource.RLIMIT_FSIZE, (128 * 1024 * 1024, 128 * 1024 * 1024))


def run(arguments, *, cwd, environment, owner=True, content=None, timeout=180):
    # Never log raw stdout/stderr: framework or dependency errors may contain .env values.
    capture = Path(tempfile.mkdtemp(prefix='capture-', dir=str(cwd)))
    os.chmod(capture, 0o700)
    stdout, stderr = capture / 'out', capture / 'err'
    process = None
    next_size_check = 0
    try:
        with stdout.open('xb') as output, stderr.open('xb') as error:
            process = subprocess.Popen([str(item) for item in arguments], cwd=cwd, env=environment,
                                       stdin=subprocess.PIPE if content is not None else subprocess.DEVNULL,
                                       stdout=output, stderr=error, start_new_session=True,
                                       preexec_fn=owner_preexec if owner else None)
            if content is not None:
                process.stdin.write(content)
                process.stdin.close()
            deadline = time.monotonic() + timeout
            while process.poll() is None:
                need(stdout.stat().st_size <= MAX_CAPTURE and stderr.stat().st_size <= MAX_CAPTURE, 'Command output limit exceeded.')
                need(time.monotonic() < deadline, 'Command time limit exceeded.')
                if time.monotonic() >= next_size_check:
                    bounded_directory(cwd)
                    next_size_check = time.monotonic() + 1
                time.sleep(0.1)
        need(stdout.stat().st_size <= MAX_CAPTURE and stderr.stat().st_size <= MAX_CAPTURE, 'Command output limit exceeded.')
        need(process.returncode == 0, 'A bounded dependency/runtime command failed; no raw output was disclosed.')
        return stdout.read_bytes()
    finally:
        if process and process.poll() is None:
            os.killpg(process.pid, signal.SIGKILL)
            process.wait(timeout=10)
        # Only exact files created above; no recursive deletion.
        for path in (stdout, stderr):
            if path.exists():
                path.unlink()
        capture.rmdir()


CONFIGURATION_PHP = r'''
ob_start();try {
 require 'vendor/autoload.php';
 final class PhosDeploymentConfiguration extends Illuminate\Foundation\Application {
  protected function registerBaseServiceProviders() {} protected function registerLaravelCloudServices() {}
 }
 final class PhosDeploymentEnvironment extends Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables {
  protected function writeErrorAndDie($exception) {throw $exception;}
 }
 $app=new PhosDeploymentConfiguration(getcwd());
 (new PhosDeploymentEnvironment)->bootstrap($app);
 (new Illuminate\Foundation\Bootstrap\LoadConfiguration)->bootstrap($app);
 if($app->getLoadedProviders()!==[])throw new RuntimeException();
 $config=$app['config'];$url=parse_url((string)$config->get('app.url',''));
 if($config->get('app.env')!=='uat'||!is_array($url)||($url['scheme']??'')!=='https'
 ||($url['host']??'')!=='uat-admin.primehealers.com'||isset($url['user'])||isset($url['pass'])
 ||isset($url['query'])||isset($url['fragment'])||!in_array($url['path']??'',['','/'],true)
 ||($url['port']??443)!==443||PHP_MAJOR_VERSION!==8||PHP_MINOR_VERSION!==2)throw new RuntimeException();
 $db=$config->get('database.connections.'.$config->get('database.default'));
 $db=(new Illuminate\Support\ConfigurationUrlParser)->parseConfiguration($db);
 if(($db['driver']??'')!=='mysql'||($db['database']??'')!=='uata_primehealersos'
 ||!in_array($db['host']??'',['localhost','127.0.0.1','::1'],true)||!empty($db['read'])||!empty($db['write'])
 ||(int)($db['port']??3306)!==3306||$config->get('app.debug')!==false
 ||$config->get('app.maintenance.driver','file')!=='file')throw new RuntimeException();
 if($app->storagePath()!==getcwd().'/storage'||$app->getCachedConfigPath()!==getcwd().'/bootstrap/cache/config.php'
 ||$app->getCachedPackagesPath()!==getcwd().'/bootstrap/cache/packages.php'
 ||$app->getCachedServicesPath()!==getcwd().'/bootstrap/cache/services.php'
 ||$app->getCachedRoutesPath()!==getcwd().'/bootstrap/cache/routes-v7.php'
 ||$config->get('view.compiled')!==getcwd().'/storage/framework/views')throw new RuntimeException();
 __ACTION__
 ob_end_clean();echo json_encode($result,JSON_THROW_ON_ERROR);
}catch(Throwable $e){ob_end_clean();echo json_encode(['error_class'=>get_class($e)]);exit(2);}
'''

IDENTITY_ACTION = r'''
$result=['identity'=>true,'php'=>PHP_VERSION,'providers'=>count($app->getLoadedProviders()),
 'uid'=>function_exists('posix_geteuid')?posix_geteuid():null];
'''

ROLE_COUNTS_ACTION = r'''
$dsn='mysql:host='.$db['host'].';port=3306;dbname='.$db['database'].';charset='.($db['charset']??'utf8mb4');
$pdo=new PDO($dsn,$db['username']??'',$db['password']??'',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='uata_primehealersos')throw new RuntimeException();
$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
$result=['active_users'=>(int)$pdo->query('SELECT COUNT(*) FROM users WHERE is_active=1')->fetchColumn(),
 'invalid_active_role_links'=>(int)$pdo->query('SELECT COUNT(*) FROM users u LEFT JOIN roles r ON r.id=u.role_id WHERE u.is_active=1 AND u.role_id IS NOT NULL AND (r.id IS NULL OR r.is_active<>1 OR r.organization_id IS NULL OR r.organization_id<>u.organization_id OR u.organization_id IS NULL)')->fetchColumn(),
 'active_users_missing_organization'=>(int)$pdo->query('SELECT COUNT(*) FROM users u LEFT JOIN organizations o ON o.id=u.organization_id WHERE u.is_active=1 AND o.id IS NULL')->fetchColumn(),
 'organizations_without_effective_owner'=>(int)$pdo->query("SELECT COUNT(*) FROM (SELECT DISTINCT organization_id FROM users WHERE is_active=1 AND organization_id IS NOT NULL) x WHERE NOT EXISTS (SELECT 1 FROM users u LEFT JOIN roles r ON r.id=u.role_id WHERE u.organization_id=x.organization_id AND u.is_active=1 AND ((u.role_id IS NULL AND u.role='super_admin') OR (r.id IS NOT NULL AND r.organization_id=u.organization_id AND r.is_active=1 AND r.slug='super_admin')))")->fetchColumn()];
$pdo->rollBack();
'''

SANDBOX_ACTION = r'''
$config->set('pdf.disable_sandbox',false);
Illuminate\Container\Container::setInstance($app);
$arguments=(new App\Support\PdfBrowsershotConfigurator)->chromiumArguments();
$safe=true;foreach($arguments as $argument){$key=strtolower(explode('=',ltrim($argument,'-'),2)[0]);
 if(str_contains($key,'sandbox')||in_array($key,['single-process','no-zygote'],true))$safe=false;}
$result=['safe_effective_arguments'=>$safe,'arguments'=>$arguments,'providers'=>count($app->getLoadedProviders()),
 'disable_sandbox'=>$config->get('pdf.disable_sandbox'),'node'=>$config->get('pdf.node_binary'),
 'modules'=>$config->get('pdf.node_module_path'),'browser'=>$config->get('pdf.browser_path')];
'''

LIVE_SANDBOX_ACTION = SANDBOX_ACTION.replace("$config->set('pdf.disable_sandbox',false);", '')


def php_configuration(root, action, environment):
    code = CONFIGURATION_PHP.replace('__ACTION__', action)
    return parse_json(run([PHP, '-d', 'opcache.enable_cli=0', '-r', code], cwd=root, environment=environment))


def role_counts(root, environment):
    value = php_configuration(root, ROLE_COUNTS_ACTION, environment)
    need(set(value) == {'active_users', 'invalid_active_role_links', 'active_users_missing_organization',
                        'organizations_without_effective_owner'} and all(type(item) is int and item >= 0 for item in value.values()),
         'Unexpected counts-only role audit result.')
    need(value['active_users'] > 0 and all(value[key] == 0 for key in value if key != 'active_users'),
         'Role integrity/owner access requires review; no accounts or roles were changed.')
    return value


def validate_target(inventory):
    need(sys.platform.startswith('linux') and os.geteuid() == 0 and fcntl is not None,
         'Only the Linux root operator can inspect this fixed UAT target.')
    need(os.uname().machine == 'x86_64', 'Only the reviewed Linux x86_64 runtime is supported.')
    no_links(APP)
    account = pwd.getpwuid(OWNER_ID)
    need(account.pw_gid == OWNER_ID and APP.is_dir() and APP.stat().st_uid == OWNER_ID
         and not stat.S_IMODE(APP.stat().st_mode) & 0o022, 'UAT owner identity differs from UID5006 or boundary is writable.')
    private_file(APP / '.env', OWNER_ID)
    for folder in (APP / 'bootstrap/cache', APP / 'storage/framework', APP / 'storage/logs'):
        no_links(folder)
        info = folder.stat()
        need(stat.S_ISDIR(info.st_mode) and info.st_uid == info.st_gid == OWNER_ID
             and not stat.S_IMODE(info.st_mode) & 0o077, 'Repaired private runtime directory metadata is required.')
    no_links(APP / 'public')
    web_info = (APP / 'public').stat()
    need(stat.S_ISDIR(web_info.st_mode) and web_info.st_uid == OWNER_ID
         and not stat.S_IMODE(web_info.st_mode) & 0o022, 'Unexpected public directory metadata.')
    # Repair intentionally retains shared-group traversal metadata on APP/storage;
    # private descendants provide the barrier. Do not silently demand a new repair.
    for folder in (APP / 'storage', APP / 'bootstrap'):
        no_links(folder)
        info = folder.stat()
        need(stat.S_ISDIR(info.st_mode) and info.st_uid in (0, OWNER_ID)
             and not stat.S_IMODE(info.st_mode) & 0o022, 'Unsafe traversal directory boundary.')
    for folder in (APP / 'storage/framework/views', APP / 'storage/framework/sessions'):
        no_links(folder)
        need(folder.is_dir() and folder.stat().st_uid == OWNER_ID, 'Unexpected private framework descendant.')
    for name in CACHE_NAMES:
        path = APP / 'bootstrap/cache' / name
        no_links(path)
        if path.exists():
            private_file(path, OWNER_ID)
    private_file(PRIVATE_CONFIG)
    config = parse_json(PRIVATE_CONFIG.read_bytes())
    need(config.get('application') == str(APP) and config.get('host') == HOST and config.get('database') == DATABASE
         and config.get('php') == str(PHP) and config.get('owner') == account.pw_name, 'Private UAT identity differs.')
    trusted_executable({'path': str(PHP), 'sha256': digest(PHP.read_bytes())})
    vhost = Path('/usr/local/lsws/conf/vhosts') / HOST / 'vhost.conf'
    regular(vhost)
    need(re.search(r'(?im)^\s*docRoot\s+\$VH_ROOT/public_html/public\s*$', vhost.read_text()), 'UAT document root differs.')
    for key in ('composer', 'browser'):
        trusted_executable(inventory[key])
    return account


def safe_node_members(archive):
    names, entries = set(), []
    total = 0
    for entry in archive:
        name = relative(entry.name.rstrip('/'))
        need(name == NODE_NAME or name.startswith(NODE_NAME + '/'), 'Node archive has an unexpected root.')
        need(name not in names and entry.size <= 128 * 1024 * 1024, 'Duplicate or oversized Node archive member.')
        need(entry.isdir() or entry.isfile() or entry.issym(), 'Special/hardlinked Node archive member refused.')
        if entry.issym():
            # Official npm/npx links are relative and stay inside the reviewed root.
            link = PurePosixPath(entry.linkname)
            need(not link.is_absolute() and '\\' not in entry.linkname, 'Unsafe Node symlink.')
            parts = list(PurePosixPath(name).parent.parts)
            for piece in link.parts:
                if piece == '..':
                    need(len(parts) > 1, 'Escaping Node symlink.')
                    parts.pop()
                elif piece != '.':
                    parts.append(piece)
            need(parts[0] == NODE_NAME, 'Escaping Node symlink.')
        total += entry.size
        need(total <= 384 * 1024 * 1024 and len(names) < 10000, 'Node archive exceeds limits.')
        names.add(name)
        entries.append(entry)
    for entry in entries:
        if entry.issym():
            # Neither files nor directories may be extracted through an archive symlink.
            need(not any(other != entry.name and other.startswith(entry.name.rstrip('/') + '/') for other in names),
                 'Node archive writes through a symlink.')
    need({NODE_NAME + '/bin/node', NODE_NAME + '/bin/npm', NODE_NAME + '/lib/node_modules/npm/bin/npm-cli.js'} <= names,
         'Required Node runtime members are missing.')
    return entries


def install_node(stage):
    archive_path = stage / 'node.tar.xz'
    request = urllib.request.Request(NODE_URL, headers={'User-Agent': 'PHOS-UAT-reviewed-runtime'})
    class NoRedirect(urllib.request.HTTPRedirectHandler):
        def redirect_request(self, request, file, code, message, headers, destination):
            return None
    opener = urllib.request.build_opener(NoRedirect())
    deadline = time.monotonic() + 150
    with opener.open(request, timeout=30) as response, archive_path.open('xb') as output:
        need(response.geturl() == NODE_URL, 'Node download redirect refused.')
        size = 0
        while True:
            need(time.monotonic() < deadline, 'Pinned Node download time limit exceeded.')
            chunk = response.read(1024 * 1024)
            if not chunk:
                break
            size += len(chunk)
            need(size <= 64 * 1024 * 1024, 'Node download exceeds limit.')
            output.write(chunk)
    need(digest(archive_path.read_bytes()) == NODE_SHA, 'Official pinned Node archive checksum mismatch.')
    with tarfile.open(archive_path, 'r:xz') as archive:
        entries = safe_node_members(archive)
        for entry in entries:
            path = stage / entry.name
            if entry.isdir():
                path.mkdir(mode=0o755, parents=True, exist_ok=True)
            elif entry.isfile():
                no_links(path)
                path.parent.mkdir(mode=0o755, parents=True, exist_ok=True)
                with archive.extractfile(entry) as source:
                    atomic(path, source.read(), 0, 0, 0o755 if entry.mode & 0o111 else 0o644)
        for entry in entries:
            if entry.issym():
                path = stage / entry.name
                path.symlink_to(entry.linkname)
                need(path.resolve().is_relative_to(stage / NODE_NAME) and path.resolve().is_file(), 'Node symlink target is invalid.')
    archive_path.unlink()
    return stage / NODE_NAME


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


def bounded_directory(root):
    total = entries = 0
    for parent, directories, files in os.walk(root, followlinks=False):
        for name in directories + files:
            path = Path(parent) / name
            value = path.lstat()
            if stat.S_ISREG(value.st_mode):
                total += value.st_size
            entries += 1
            need(total <= MAX_TREE and entries <= 100000, 'Private working directory exceeds its bounded size.')


def publish_node(node):
    """New app-specific root-owned runtime; no system executable is replaced."""
    no_links(NODE_PARENT)
    if not NODE_PARENT.exists():
        need(NODE_PARENT.parent == Path('/opt') and NODE_PARENT.parent.stat().st_uid == 0
             and not stat.S_IMODE(NODE_PARENT.parent.stat().st_mode) & 0o022, 'Untrusted isolated Node parent.')
        NODE_PARENT.mkdir(mode=0o755)
    info = NODE_PARENT.stat()
    need(info.st_uid == info.st_gid == 0 and stat.S_IMODE(info.st_mode) == 0o755, 'Isolated Node parent metadata differs.')
    destination = NODE_PARENT / NODE_NAME
    no_links(destination)
    need(not destination.exists(), 'App-specific Node destination already exists; review exact prior installation instead of overwriting it.')
    expected = tree_signature(node)
    for parent, directories, files in os.walk(node, followlinks=False):
        for path in [Path(parent)] + [Path(parent) / name for name in directories + files]:
            need(path.lstat().st_uid == path.lstat().st_gid == 0, 'Staged Node has unexpected ownership.')
    os.rename(node, destination)  # EXDEV refuses safely before maintenance.
    need(tree_signature(destination) == expected, 'Isolated Node changed during atomic publication.')
    return destination


def update_pdf_env(raw, changes):
    need(set(changes) == PDF_ENV_KEYS and all(isinstance(value, str) and not any(char in value for char in '\r\n\x00\"\\$`')
         for value in changes.values()), 'Only the four reviewed PDF environment values are accepted.')
    need(len(raw) <= MAX_CAPTURE and b'\x00' not in raw, 'Unsafe environment file.')
    lines = raw.splitlines(keepends=True)
    indexes = {}
    for index, line in enumerate(lines):
        match = re.match(rb'^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=', line)
        if match:
            key = match.group(1).decode('ascii')
            need(key not in indexes, 'Duplicate environment key refused.')
            indexes[key] = index
            # Multiline quoted dotenv values are outside this bounded editor.
            value = line[match.end():].strip()
            need(not value.startswith((b'\"', b"'")) or (len(value) >= 2 and value[-1:] == value[:1]),
                 'Multiline/ambiguous environment value refused.')
    for key, value in changes.items():
        new_line = (key + '="' + value + '"\n').encode()
        if key in indexes:
            lines[indexes[key]] = new_line
        else:
            need(not lines or lines[-1].endswith(b'\n'), 'Missing final environment line ending requires review; unrelated bytes were preserved.')
            lines.append(new_line)
    return b''.join(lines)


def unchanged_env_bytes(raw):
    return b''.join(line for line in raw.splitlines(keepends=True)
                    if not re.match(rb'^\s*(?:export\s+)?(?:' + b'|'.join(key.encode() for key in sorted(PDF_ENV_KEYS)) + rb')\s*=', line))


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


class DependencyRecovery:
    def __init__(self, root, slot):
        self.root, self.slot = root, slot
        self.entries = {}

    def live_path(self, name):
        need(name in ('vendor', 'node_modules', 'compiled_views'), 'Unreviewed directory switch.')
        return self.root / ('storage/framework/views' if name == 'compiled_views' else name)

    def verify_candidate_path(self, name, candidate):
        need(candidate.is_relative_to(self.root), 'Unreviewed candidate directory root.')
        pieces = candidate.relative_to(self.root).parts
        need(len(pieces) in (2, 3) and re.fullmatch(re.escape(STAGE_PREFIX) + '[0-9a-f]{24}', pieces[0]),
             'Unreviewed candidate stage identity.')
        expected = ('compiled-views',) if name == 'compiled_views' else ('candidate', name)
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
                'source_dependencies_restored': True, 'runtime_recovery_failed': True}
    return {'complete': True, 'unknown_state_preserved': [], 'maintenance_retained': False,
            'source_dependencies_restored': True}


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


def maintenance_enter(root, backup):
    marker = root / 'storage/framework/down'
    no_links(marker)
    need(signature(marker) is None, 'Existing maintenance state requires review.')
    token = secrets.token_hex(32)
    data = json_bytes({'except': [], 'redirect': None, 'retry': 60, 'refresh': 15,
                       'status': 503, 'template': None, 'secret': token})
    atomic(marker, data, OWNER_ID, OWNER_ID, 0o600)
    value = signature(marker)
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


def online_checks(manifest, backup):
    for local in (True, False):
        for path in ('/login', '/up'):
            status, _, headers = http_check(path, backup, local=local)
            need(status == '200', 'UAT login/up health check failed.')
            need(re.search(r'(?im)^x-content-type-options:\s*nosniff\s*$', headers), 'Security response headers are missing.')
            if path == '/login':
                need(re.search(r'(?im)^cache-control:[^\r\n]*no-store', headers)
                     and re.search(r'(?im)^referrer-policy:\s*no-referrer\s*$', headers), 'Login privacy headers differ.')
        for entry in manifest['files']:
            if entry['kind'].startswith('vite_') or entry['path'].startswith(('public/css/', 'public/js/')):
                status, raw, _ = http_check('/' + entry['path'].removeprefix('public/'), backup, local=local)
                need(status == '200' and digest(raw) == entry['after']['sha256'], 'UAT asset does not match the release.')
        for entry in manifest['superseded_baseline_assets_to_preserve']:
            status, raw, _ = http_check('/' + entry['path'].removeprefix('public/'), backup, local=local)
            need(status == '200' and digest(raw) == entry['sha256'], 'A superseded baseline asset was not preserved.')
    for path in PROTECTED:
        status, _, headers = http_check(path, backup, local=True)
        locations = re.findall(r'(?im)^location:\s*(.*?)\s*$', headers)
        need(status == '302' and len(locations) == 1, 'Protected UAT route does not redirect to login.')
        location = urllib.parse.urlsplit(locations[0])
        need(location.path.rstrip('/') == '/login' and location.hostname in (None, HOST)
             and location.username is None and location.password is None, 'Unexpected protected-route redirect.')
    # Invented non-business filenames test Apache denial, never existing proof bytes.
    for prefix in ('/storage/customer-proofs/', '/storage/delivery-proofs/', '/storage/app/private/'):
        status, _, _ = http_check(prefix + 'phos-security-nonexistent-probe.txt', backup, local=True)
        need(status in ('403', '404'), 'Private proof namespace was not denied.')


RUNTIME_GUARDS_PHP = r'''
ob_start();try{
 require 'vendor/autoload.php';$app=require 'bootstrap/app.php';
 $kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$kernel->bootstrap();
 if($app->environment()!=='uat'||parse_url(config('app.url'),PHP_URL_HOST)!=='uat-admin.primehealers.com'
 ||config('app.debug')!==false||config('auth.allow_public_registration')!==false)throw new RuntimeException();
 $router=$app['router'];$routes=$router->getRoutes();
 $required=['password.email'=>'throttle:password-recovery','password.store'=>'throttle:password-reset',
  'deliveries.proofs.view'=>'module:deliveries,read','customers.id-proof.download'=>'permission:customers.proof.download'];
 foreach($required as$name=>$middleware){$route=$routes->getByName($name);if(!$route||!in_array($middleware,$route->gatherMiddleware(),true))throw new RuntimeException();}
 if($routes->getByName('register')!==null)throw new RuntimeException();
 $web=$router->getMiddlewareGroups()['web']??[];
 foreach([App\Http\Middleware\EnsureActiveAccount::class,Illuminate\Session\Middleware\AuthenticateSession::class]as$class){if(!in_array($class,$web,true))throw new RuntimeException();}
 if(!in_array(App\Http\Middleware\SecureHeaders::class,$kernel->getMiddleware(),true))throw new RuntimeException();
 foreach([App\Support\SafeCsv::class,App\Services\Access\RoleGrantGuard::class,App\Services\Deliveries\DeliveryProofService::class]as$class){if(!class_exists($class))throw new RuntimeException();}
 ob_end_clean();echo json_encode(['runtime_guards'=>true,'routes'=>count($required),'registration_disabled'=>true]);
}catch(Throwable$e){ob_end_clean();echo json_encode(['error_class'=>get_class($e)]);exit(2);}
'''


def runtime_guards(environment):
    result = parse_json(run([PHP, '-d', 'opcache.enable_cli=0', '-r', RUNTIME_GUARDS_PHP], cwd=APP, environment=environment))
    need(result == {'runtime_guards': True, 'routes': 4, 'registration_disabled': True},
         'Active framework route/access/runtime guards are incomplete.')


def artisan_cache(root, environment, files, dependencies, save):
    # Generate caches in a private isolated directory. A framework-command failure
    # cannot turn unrelated concurrently edited live cache bytes into "our" output.
    folder = root / (STAGE_PREFIX + secrets.token_hex(12))
    folder.mkdir(mode=0o700)
    os.chown(folder, OWNER_ID, OWNER_ID)
    temporary_environment = dict(environment)
    temporary_environment.update({'APP_CONFIG_CACHE': str(folder / 'config.php'),
                                  'APP_PACKAGES_CACHE': str(folder / 'packages.php'),
                                  'APP_SERVICES_CACHE': str(folder / 'services.php'),
                                  'APP_ROUTES_CACHE': str(folder / 'routes-v7.php')})
    for command in ('package:discover', 'config:cache', 'route:cache'):
        run([PHP, '-d', 'opcache.enable_cli=0', 'artisan', command, '--no-interaction'],
            cwd=root, environment=temporary_environment, timeout=240)
    compiled = folder / 'compiled-views'
    compiled.mkdir(mode=0o700)
    os.chown(compiled, OWNER_ID, OWNER_ID)
    view_environment = dict(temporary_environment)
    view_environment['APP_CONFIG_CACHE'] = str(folder / 'uncached-view-config.php')
    view_environment['VIEW_COMPILED_PATH'] = str(compiled)
    run([PHP, '-d', 'opcache.enable_cli=0', 'artisan', 'view:cache', '--no-interaction'],
        cwd=root, environment=view_environment, timeout=240)
    for name in CACHE_NAMES:
        source = folder / name
        need(source.exists(), 'An expected private framework cache was not produced.')
        regular(source)
        files.install('bootstrap/cache/' + name, source.read_bytes(), OWNER_ID, OWNER_ID, 0o600, save)
    dependencies.switch('compiled_views', compiled, save)
    # Retain private build caches for recovery inspection; no recursive deletion.


def activate_reviewed(stage, inventory, manifest, records, payload, staged, backup):
    """Unissued activation coordinator. CLI blocks this until independent review.

    Rollback always restores known source/config and dependency bytes before any
    framework/runtime operation, and preserves unknown state in maintenance.
    """
    need(ACTIVATION_REVIEW_COMPLETE, 'Activation review boundary has not been cleared.')
    verify_source(APP, records, manifest)
    private_file(APP / '.env', OWNER_ID)
    environment = runtime_environment(stage, staged['node'])
    php_configuration(APP, IDENTITY_ACTION, environment)
    role_counts(APP, environment)
    web_php_probe(backup, False)
    slot = APP / (SLOT_PREFIX + secrets.token_hex(12))
    slot.mkdir(mode=0o700)
    files = FileRecovery(APP, backup, list(records) + ['.env'] + ['bootstrap/cache/' + name for name in CACHE_NAMES])
    files.snapshot()
    dependencies = DependencyRecovery(APP, slot)
    state = {'format': 1, 'application': str(APP), 'release': RELEASE, 'baseline': BASELINE,
             'manifest_sha256': MANIFEST_SHA, 'source': files.entries, 'dependencies': dependencies.entries,
             'slot': str(slot), 'stage': str(stage), 'phase': 'backed_up', 'complete': False}
    save = lambda: atomic(backup / 'recovery-state.json', json_bytes(state), 0, 0, 0o600)
    save()
    marker = maintenance_enter(APP, backup)
    state['maintenance_marker'] = marker
    save()
    try:
        state['phase'] = 'source_activation'
        save()
        for name, content in payload.items():
            if records[name]['kind'] == 'composer_staging_input':
                continue
            original = files.entries[name]['before']
            files.install(name, content, OWNER_ID, OWNER_ID, original['mode'] if original else 0o644, save)
        dependencies.switch('vendor', staged['candidate'] / 'vendor', save)
        dependencies.switch('node_modules', staged['candidate'] / 'node_modules', save)
        raw_env = (APP / '.env').read_bytes()
        revised = update_pdf_env(raw_env, {'PDF_NODE_BINARY': str(staged['node'] / 'bin/node'),
                                 'PDF_NODE_MODULE_PATH': str(APP / 'node_modules'),
                                 'PDF_BROWSER_PATH': inventory['browser']['path'], 'PDF_DISABLE_SANDBOX': 'false'})
        need(unchanged_env_bytes(raw_env) == unchanged_env_bytes(revised),
             'An unrelated environment byte would change.')
        files.install('.env', revised, OWNER_ID, OWNER_ID, 0o600, save)
        state['phase'] = 'cache_activation'
        save()
        artisan_cache(APP, environment, files, dependencies, save)
        configuration = php_configuration(APP, LIVE_SANDBOX_ACTION, environment)
        need(configuration.get('safe_effective_arguments') is True and configuration.get('disable_sandbox') is False
             and configuration.get('node') == str(staged['node'] / 'bin/node')
             and configuration.get('modules') == str(APP / 'node_modules')
             and configuration.get('browser') == inventory['browser']['path'], 'Live effective PDF runtime/sandbox settings differ.')
        runtime_guards(environment)
        web_php_probe(backup, True)
        state['phase'] = 'online_checks'
        save()
        maintenance_leave(APP, marker)
        online_checks(manifest, backup)
        state['phase'] = 'complete'
        state['complete'] = True
        save()
    except BaseException:
        def ensure_maintenance():
            nonlocal marker
            marker = restore_maintenance(APP, marker)
            state['maintenance_marker'] = marker
        # No Artisan invocation is allowed to prevent restoration of known bytes.
        def runtime_recover():
            web_php_probe(backup, True)
            php_configuration(APP, IDENTITY_ACTION, environment)
            maintenance_leave(APP, marker)
            for local in (True, False):
                for path in ('/login', '/up'):
                    need(http_check(path, backup, local=local)[0] == '200', 'Restored UAT health failed.')
        recovery = recover_with_maintenance(files, dependencies, ensure_maintenance, save, runtime_recover)
        if not recovery['complete']:
            try:
                state['maintenance_marker'] = restore_maintenance(APP, marker)
            except Exception:
                recovery.setdefault('preparation_errors', []).append('maintenance_state_uncertain')
                recovery['maintenance_retained'] = None
                recovery['maintenance_uncertain'] = True
        state['recovery'] = recovery
        state['phase'] = 'rolled_back' if recovery['complete'] else 'rollback_incomplete_maintenance_retained'
        try:
            save()
        except Exception:
            recovery.setdefault('preparation_errors', []).append('journal_write_failed')
            recovery['complete'] = False
            try:
                state['maintenance_marker'] = restore_maintenance(APP, marker)
                recovery['maintenance_retained'] = True
            except Exception:
                recovery['maintenance_retained'] = None
                recovery['maintenance_uncertain'] = True
            state['phase'] = 'rollback_incomplete_maintenance_retained_or_uncertain'
        raise Refused('Activation failed. Recovery=' + state['phase'] + '; private evidence retained.')


def validate_backup_state(backup):
    need(backup.parent == Path('/root') and re.fullmatch(BACKUP_PREFIX + r'[a-z0-9_]{8,32}', backup.name),
         'Only an exact private PHOS access/security backup may be recovered.')
    no_links(backup)
    info = backup.stat()
    need(stat.S_ISDIR(info.st_mode) and info.st_uid == info.st_gid == 0 and stat.S_IMODE(info.st_mode) == 0o700,
         'Recovery directory must be root-private.')
    for name in ('manifest.json', 'recovery-state.json'):
        private_file(backup / name)
    raw = (backup / 'manifest.json').read_bytes()
    need(digest(raw) == MANIFEST_SHA, 'Recovery manifest does not match this exact helper release.')
    manifest = parse_json(raw)
    state = parse_json((backup / 'recovery-state.json').read_bytes())
    need(state.get('format') == 1 and state.get('application') == str(APP) and state.get('release') == RELEASE
         and state.get('baseline') == BASELINE and state.get('manifest_sha256') == MANIFEST_SHA,
         'Recovery state target/release differs.')
    names = [runtime_path(entry['path']) for entry in manifest['files']] + ['.env'] + ['bootstrap/cache/' + name for name in CACHE_NAMES]
    need(len(names) == FILE_COUNT + 5 and set(state['source']) == set(names), 'Recovery path set is incomplete or unreviewed.')
    for index, name in enumerate(names):
        entry = state['source'][name]
        need(isinstance(entry, dict) and set(entry) <= {'before', 'after', 'write_started', 'copy', 'expected'}
             and set(entry) >= {'before', 'after', 'write_started', 'copy'} and entry['copy'] == str(index)
             and type(entry['write_started']) is bool, 'Unexpected file recovery record.')
        for key in ('before', 'after', 'expected'):
            value = entry.get(key)
            if value is not None:
                need(isinstance(value, dict) and set(value) >= {'sha256', 'size', 'uid', 'gid', 'mode'}
                     and re.fullmatch('[0-9a-f]{64}', value['sha256']) and type(value['size']) is int
                     and 0 <= value['size'] <= MAX_TREE and value['uid'] in (0, OWNER_ID)
                     and value['gid'] in (0, OWNER_ID, 65534) and type(value['mode']) is int
                     and not value['mode'] & 0o022, 'Unsafe file recovery metadata.')
        if entry['before'] is not None:
            copy_path = backup / 'files' / str(index)
            private_file(copy_path)
            need(digest(copy_path.read_bytes()) == entry['before']['sha256'], 'Private recovery copy differs.')
    slot = Path(state['slot'])
    stage = Path(state['stage'])
    for path, prefix in ((slot, SLOT_PREFIX), (stage, STAGE_PREFIX)):
        need(path.parent == APP and re.fullmatch(re.escape(prefix) + '[0-9a-f]{24}', path.name), 'Unexpected recovery/stage path.')
        no_links(path)
    slot_info = slot.stat()
    need(slot_info.st_uid == slot_info.st_gid == 0 and stat.S_IMODE(slot_info.st_mode) == 0o700,
         'Dependency recovery slot is not root-private.')
    need(isinstance(state['dependencies'], dict) and set(state['dependencies']) <= {'vendor', 'node_modules', 'compiled_views'},
         'Unreviewed dependency recovery path.')
    for name, entry in state['dependencies'].items():
        need(set(entry) == {'before', 'new', 'candidate', 'old_moved', 'new_moved'} and type(entry['old_moved']) is bool
             and type(entry['new_moved']) is bool, 'Malformed directory recovery record.')
        candidate = Path(entry['candidate'])
        DependencyRecovery(APP, slot).verify_candidate_path(name, candidate)
        for key in ('before', 'new'):
            value = entry[key]
            need(isinstance(value, dict) and set(value) == {'sha256', 'inode', 'device', 'uid', 'gid', 'mode', 'entries'}
                 and re.fullmatch('[0-9a-f]{64}', value['sha256']) and all(type(value[field]) is int and value[field] >= 0
                     for field in ('inode', 'device', 'uid', 'gid', 'mode', 'entries')), 'Malformed directory identity.')
    return state, manifest, names


def recover_backup(backup):
    # Runtime executable availability, Composer and new framework boot are not
    # prerequisites for undoing known source/dependency bytes.
    need(sys.platform.startswith('linux') and os.geteuid() == 0 and APP == Path('/home/uat-admin.primehealers.com/public_html'),
         'Recovery requires the fixed Linux root UAT operator context.')
    no_links(APP)
    need(APP.is_dir() and APP.stat().st_uid == OWNER_ID and not stat.S_IMODE(APP.stat().st_mode) & 0o022,
         'Recovery UAT boundary is unsafe.')
    private_file(PRIVATE_CONFIG)
    identity = parse_json(PRIVATE_CONFIG.read_bytes())
    need(identity.get('application') == str(APP) and identity.get('host') == HOST and identity.get('database') == DATABASE,
         'Recovery private target is not this exact UAT.')
    state, manifest, names = validate_backup_state(backup)
    marker = state.get('maintenance_marker')
    files = FileRecovery(APP, backup, names)
    files.entries = state['source']
    dependencies = DependencyRecovery(APP, Path(state['slot']))
    dependencies.entries = state['dependencies']
    stage = Path(state['stage'])
    def runtime_recover():
        web_php_probe(backup, True)
        php_configuration(APP, IDENTITY_ACTION, runtime_environment(stage))
        maintenance_leave(APP, marker)
        for local in (True, False):
            for path in ('/login', '/up'):
                need(http_check(path, backup, local=local)[0] == '200', 'Restored UAT HTTP health failed.')
    def ensure_maintenance():
        nonlocal marker
        marker = restore_maintenance(APP, marker)
        state['maintenance_marker'] = marker
    save = lambda: atomic(backup / 'recovery-state.json', json_bytes(state), 0, 0, 0o600)
    result = recover_with_maintenance(files, dependencies, ensure_maintenance, save, runtime_recover)
    if not result['complete']:
        try:
            state['maintenance_marker'] = restore_maintenance(APP, marker)
        except Exception:
            result.setdefault('preparation_errors', []).append('maintenance_state_uncertain')
            result['maintenance_retained'] = None
            result['maintenance_uncertain'] = True
    state['recovery'] = result
    state['phase'] = 'rolled_back' if result['complete'] else 'rollback_incomplete_maintenance_retained'
    try:
        save()
    except Exception:
        result['complete'] = False
        result.setdefault('preparation_errors', []).append('journal_write_failed')
        try:
            state['maintenance_marker'] = restore_maintenance(APP, marker)
            result['maintenance_retained'] = True
        except Exception:
            result['maintenance_retained'] = None
            result['maintenance_uncertain'] = True
    return result


def load_pdf_contract(path):
    # Parse the reviewed Python source; never import/execute its full worker or CLI.
    import ast
    raw = path.read_bytes()
    need(digest(raw) == PDF_DRIVER_SHA, 'Reviewed PDF diagnostic source checksum differs.')
    tree = ast.parse(raw)
    assignments = {}
    for statement in tree.body:
        if isinstance(statement, ast.Assign):
            for target in statement.targets:
                if isinstance(target, ast.Name) and target.id == 'NODE_PROBE':
                    assignments['node'] = ast.literal_eval(statement.value)
    need(isinstance(assignments.get('node'), str), 'PDF driver literal is missing.')
    return assignments['node']


def sandbox_arguments(arguments):
    need(isinstance(arguments, list) and len(arguments) <= 40, 'Invalid effective Chromium arguments.')
    safe = {'disable-dev-shm-usage', 'disable-gpu', 'disable-software-rasterizer'}
    result = []
    for argument in arguments:
        need(isinstance(argument, str), 'Invalid Chromium argument.')
        key = argument.lstrip('-').split('=', 1)[0].lower()
        need(key in safe and argument.lstrip('-') == key, 'Unreviewed or sandbox-disabling effective argument.')
        result.append('--' + key)
    return list(dict.fromkeys(result))


def validate_pdf_result(value, modules, browser_version=None):
    allowed = {'launch', 'pdf', 'sandbox_status', 'namespace_sandbox', 'seccomp_sandbox', 'external_requests',
               'module_entry', 'sandbox_disabling_flags', 'actual_sandbox_flags_absent', 'browser_version',
               'pdf_bytes', 'page_object_count'}
    need(isinstance(value, dict) and set(value) <= allowed and value.get('launch') is True and value.get('pdf') is True
         and value.get('sandbox_status') == 'verified' and value.get('namespace_sandbox') is True
         and value.get('seccomp_sandbox') is True and value.get('actual_sandbox_flags_absent') is True
         and value.get('sandbox_disabling_flags') is False and type(value.get('external_requests')) is int
         and value['external_requests'] == 0 and type(value.get('pdf_bytes')) is int
         and 500 <= value['pdf_bytes'] <= 2097152 and type(value.get('page_object_count')) is int
         and value['page_object_count'] == 1,
         'Staged PDF did not prove sandboxed, isolated, one-page rendering.')
    entry = Path(value.get('module_entry', ''))
    need(entry.is_absolute() and entry.is_relative_to(modules), 'PDF module did not resolve inside staged production dependencies.')
    if browser_version is not None:
        need(isinstance(value.get('browser_version'), str) and re.fullmatch(r'[A-Za-z ]{1,40}/[0-9.]{1,35}', value['browser_version'])
             and value['browser_version'].split('/')[-1] == browser_version, 'Launched browser version differs from reviewed inventory.')
    return {key: value[key] for key in ('launch', 'pdf', 'sandbox_status', 'external_requests', 'pdf_bytes', 'page_object_count')}


def empty_network_namespace(parent_inode):
    need(os.stat('/proc/self/ns/net').st_ino != parent_inode, 'Network namespace was not isolated.')
    interfaces = {line.split(':', 1)[0].strip() for line in Path('/proc/net/dev').read_text().splitlines()[2:]}
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as interface:
        flags = fcntl.ioctl(interface.fileno(), 0x8913, struct.pack('16sH', b'lo', 0))
    need(interfaces == {'lo'} and struct.unpack_from('H', flags, 16)[0] & 1 == 0
         and not Path('/proc/net/route').read_text().splitlines()[1:], 'Namespace has an active interface or route.')
    ipv6 = Path('/proc/net/if_inet6')
    need(not ipv6.exists() or not ipv6.read_text().strip(), 'Namespace has an IPv6 address.')


def namespace_worker(raw):
    need(sys.platform.startswith('linux') and os.geteuid() == 0, 'Isolated worker requires Linux root supervisor.')
    value = parse_json(raw)
    empty_network_namespace(value['parent_net_inode'])
    stage = Path(value['stage'])
    no_links(stage)
    need(stage.parent == APP and stage.name.startswith(STAGE_PREFIX) and re.fullmatch(r'\.phos-access-stage-[0-9a-f]{24}', stage.name),
         'Invalid isolated stage.')
    node = stage / NODE_NAME / 'bin/node'
    modules = stage / 'candidate/node_modules'
    browser = trusted_executable(value['browser'])
    no_links(node)
    need(node.is_file() and modules.is_dir(), 'Staged Node/modules are missing.')
    directory = stage / 'pdf-probe'
    need(not directory.exists(), 'PDF probe collision.')
    directory.mkdir(mode=0o700)
    os.chown(directory, OWNER_ID, OWNER_ID)
    atomic(directory / 'package.json', json_bytes({'puppeteer': {'skipDownload': True,
           'cacheDirectory': str(stage / 'xdg-cache/puppeteer'), 'temporaryDirectory': str(stage / 'tmp')}}),
           OWNER_ID, OWNER_ID, 0o600)
    environment = runtime_environment(stage, stage / NODE_NAME)
    command = [node, '--input-type=module', '-e', value['driver']]
    data = {'modules': str(modules), 'browser': str(browser), 'directory': str(directory),
            'sandbox': True, 'arguments': sandbox_arguments(value['arguments'])}
    # run() performs permanent supplementary-group/GID/UID drop before Node starts.
    result = parse_json(run(command, cwd=directory, environment=environment, content=json_bytes(data), timeout=65))
    proof = validate_pdf_result(result, modules, value['browser']['version'])
    proof['new_empty_network_namespace'] = True
    return proof


def isolated_pdf(stage, inventory, arguments, driver_path):
    driver = load_pdf_contract(driver_path)
    unshare = Path('/usr/bin/unshare')
    trusted_executable({'path': str(unshare), 'sha256': digest(unshare.read_bytes())})
    python = Path(sys.executable).resolve()
    trusted_executable({'path': str(python), 'sha256': digest(python.read_bytes())})
    source = Path(__file__).read_text()
    content = {'stage': str(stage), 'browser': inventory['browser'], 'arguments': arguments,
               'driver': driver, 'parent_net_inode': os.stat('/proc/self/ns/net').st_ino}
    output = run([unshare, '--net', '--', python, '-I', '-B', '-c', source, '--namespace-worker'],
                 cwd=stage, environment=runtime_environment(stage), owner=False, content=json_bytes(content), timeout=80)
    return parse_json(output)


def clone_candidate(stage, data):
    candidate = stage / 'candidate'
    candidate.mkdir(mode=0o700)
    os.chown(candidate, OWNER_ID, OWNER_ID)
    # Copy application code required by optimized PSR-4 autoloading; never storage,
    # proofs, journals, logs, sessions, node_modules, vendor, .git or public/storage.
    for directory in ('app', 'config', 'routes', 'bootstrap', 'database', 'resources/views'):
        source = APP / directory
        for parent, dirs, files in os.walk(source, followlinks=False):
            dirs[:] = [name for name in dirs if name not in ('cache', '.git')]
            for name in dirs + files:
                no_links(Path(parent) / name)
            for name in files:
                path = Path(parent) / name
                if directory == 'database' and path.suffix != '.php':
                    continue
                regular(path)
                destination = candidate / path.relative_to(APP)
                destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
                atomic(destination, path.read_bytes(), OWNER_ID, OWNER_ID, 0o600)
    for name in ('artisan', 'composer.json', 'composer.lock', 'package.json', 'package-lock.json', '.env'):
        atomic(candidate / name, (APP / name).read_bytes(), OWNER_ID, OWNER_ID, 0o600)
    for name, content in data.items():
        destination = candidate / name
        destination.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
        atomic(destination, content, OWNER_ID, OWNER_ID, 0o600)
    for parent, dirs, files in os.walk(candidate):
        os.chown(parent, OWNER_ID, OWNER_ID)
        for name in dirs:
            os.chown(Path(parent) / name, OWNER_ID, OWNER_ID)
    for name in ('bootstrap/cache', 'storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs'):
        folder = candidate / name
        folder.mkdir(mode=0o700, parents=True, exist_ok=True)
        for item in (folder, *folder.parents):
            if item == candidate.parent:
                break
            os.chown(item, OWNER_ID, OWNER_ID)
    return candidate


def stage_dependencies(stage, inventory, payload):
    node = install_node(stage)
    candidate = clone_candidate(stage, payload)
    environment = runtime_environment(stage, node)
    # Runtime caches are isolated from live APP before any vendor bootstrap.
    environment.update({'APP_CONFIG_CACHE': str(candidate / 'bootstrap/cache/config.php'),
                        'APP_PACKAGES_CACHE': str(candidate / 'bootstrap/cache/packages.php'),
                        'APP_SERVICES_CACHE': str(candidate / 'bootstrap/cache/services.php'),
                        'APP_ROUTES_CACHE': str(candidate / 'bootstrap/cache/routes-v7.php'),
                        'VIEW_COMPILED_PATH': str(candidate / 'storage/framework/views'),
                        'LARAVEL_STORAGE_PATH': str(candidate / 'storage')})
    composer_source = trusted_executable(inventory['composer'])
    # An operator-provided root-private pinned PHAR is copied for owner execution;
    # no root home permissions are weakened and no Composer version is downloaded.
    composer = stage / 'reviewed-composer.phar'
    atomic(composer, composer_source.read_bytes(), OWNER_ID, OWNER_ID, 0o600)
    version = run([PHP, composer, '--no-plugins', '--no-scripts', '--version', '--no-ansi'], cwd=stage, environment=environment)
    need(re.search(rb'Composer version ' + re.escape(inventory['composer']['version'].encode()) + rb'\b', version),
         'Composer version differs from inventory.')
    node_version = run([node / 'bin/node', '--version'], cwd=stage, environment=environment).strip()
    need(node_version == NODE_VERSION.encode(), 'Pinned isolated Node version differs.')
    browser_version = run([inventory['browser']['path'], '--version'], cwd=stage, environment=environment)
    need(re.search(rb'\b' + re.escape(inventory['browser']['version'].encode()) + rb'\b', browser_version),
         'Actual browser version differs from reviewed inventory.')
    run([PHP, composer, 'install', '--no-dev', '--no-plugins', '--no-scripts', '--prefer-dist',
         '--optimize-autoloader', '--no-interaction', '--no-progress'], cwd=candidate, environment=environment, timeout=900)
    run([PHP, composer, '--no-plugins', '--no-scripts', 'check-platform-reqs', '--no-dev', '--no-interaction'],
        cwd=candidate, environment=environment)
    run([node / 'bin/node', node / 'lib/node_modules/npm/bin/npm-cli.js', 'ci', '--omit=dev', '--ignore-scripts',
         '--no-audit', '--no-fund'], cwd=candidate, environment=environment, timeout=900)
    for name in ('composer.json', 'composer.lock', 'package.json', 'package-lock.json'):
        need((candidate / name).read_bytes() == payload[name], 'Dependency staging modified a reviewed lock/input.')
    dependency = parse_json(run([PHP, '-r', r'''require 'vendor/autoload.php';
$classes=['App\\Models\\User','App\\Models\\DeliveryTask','Database\\Factories\\UserFactory'];
$ok=true;foreach($classes as $class){$ok=$ok&&class_exists($class);}
echo json_encode(['autoload'=>$ok]);'''], cwd=candidate, environment=environment))
    need(dependency == {'autoload': True}, 'Staged optimized autoload does not resolve application/database classes.')
    configuration = php_configuration(candidate, SANDBOX_ACTION, environment)
    need(configuration.get('safe_effective_arguments') is True and configuration.get('providers') == 0,
         'Effective staged PDF configurator does not support enabled sandbox.')
    arguments = sandbox_arguments(configuration['arguments'])
    puppeteer = parse_json((candidate / 'node_modules/puppeteer/package.json').read_bytes())
    need(re.fullmatch(r'25\.[0-9]+\.[0-9]+', puppeteer.get('version', '')), 'Staged Puppeteer is not the reviewed major25.')
    return {'candidate': candidate, 'node': node, 'environment': environment, 'arguments': arguments,
            'vendor': tree_signature(candidate / 'vendor'), 'node_modules': tree_signature(candidate / 'node_modules')}


def main():
    if sys.argv[1:] == ['--namespace-worker']:
        result = namespace_worker(sys.stdin.buffer.read(MAX_CAPTURE + 1))
        print(json.dumps(result))
        return
    parser = argparse.ArgumentParser(description='Private review build; fixed PHOS UAT, activation disabled.')
    parser.add_argument('mode', choices=('verify-package', 'plan', 'stage', 'activate', 'rollback'))
    parser.add_argument('--package', type=Path)
    parser.add_argument('--backup', type=Path)
    parser.add_argument('--inventory', type=Path)
    parser.add_argument('--inventory-sha256')
    parser.add_argument('--pdf-driver', type=Path)
    args = parser.parse_args()
    if args.mode == 'rollback':
        need(args.backup is not None, 'An exact private backup directory is required.')
        print(json.dumps(recover_backup(args.backup)))
        return
    need(args.package is not None, 'The exact reviewed package directory is required.')
    manifest, records, payload = load_package(args.package)
    if args.mode == 'verify-package':
        print(json.dumps({'verified': True, 'release': RELEASE, 'files': len(records), 'activation_review_complete': False}))
        return
    need(args.inventory is not None and re.fullmatch('[0-9a-f]{64}', args.inventory_sha256 or ''),
         'STOP: repaired server runtime inventory must be reviewed and hash-pinned first.')
    private_file(args.inventory)
    raw = args.inventory.read_bytes()
    need(digest(raw) == args.inventory_sha256, 'Runtime inventory checksum differs.')
    inventory = validate_inventory(parse_json(raw))
    validate_target(inventory)
    verify_source(APP, records, manifest)
    identity = php_configuration(APP, IDENTITY_ACTION, {'PATH': SYSTEM_PATH, 'LANG': 'C.UTF-8'})
    need(identity.get('identity') is True and identity.get('uid') == OWNER_ID and identity.get('providers') == 0,
         'Actual providerless UAT configuration/owner identity differs.')
    if args.mode == 'plan':
        print(json.dumps({'plan_verified': True, 'release': RELEASE, 'files': len(records),
                          'activation_review_complete': ACTIVATION_REVIEW_COMPLETE}))
        return
    need(args.mode != 'activate' or ACTIVATION_REVIEW_COMPLETE,
         'STOP: activation is intentionally disabled in this private review build pending final inventory, payload and recovery review.')
    # Staging is also not issued to an operator in this review build. It may be
    # exercised only after the explicit repaired-target and executable checks above.
    need(args.pdf_driver is not None, 'Reviewed PDF driver source is required.')
    load_pdf_contract(args.pdf_driver)
    stage = APP / (STAGE_PREFIX + secrets.token_hex(12))
    stage.mkdir(mode=0o700)
    os.chown(stage, OWNER_ID, OWNER_ID)
    prepare_environment(stage)
    counts = role_counts(APP, runtime_environment(stage))
    # This verifies the real request process UID before staging any dependencies.
    preflight = Path(tempfile.mkdtemp(prefix='phos-access-security-preflight-', dir='/root'))
    os.chmod(preflight, 0o700)
    web_php_probe(preflight, False)
    result = stage_dependencies(stage, inventory, payload)
    proof = isolated_pdf(stage, inventory, result['arguments'], args.pdf_driver)
    if args.mode == 'activate':
        need(ACTIVATION_REVIEW_COMPLETE, 'Activation review boundary has not been cleared.')
        result['node'] = publish_node(result['node'])
        result['environment'] = runtime_environment(stage, result['node'])
        backup = Path(tempfile.mkdtemp(prefix=BACKUP_PREFIX, dir='/root'))
        os.chmod(backup, 0o700)
        atomic(backup / 'reviewed-helper.py', Path(__file__).read_bytes(), 0, 0, 0o600)
        atomic(backup / 'manifest.json', (args.package / 'manifest.json').read_bytes(), 0, 0, 0o600)
        atomic(backup / 'inventory.json', raw, 0, 0, 0o600)
        activate_reviewed(stage, inventory, manifest, records, payload, result, backup)
        print(json.dumps({'deployment_complete': True, 'environment': 'PHOS_UAT', 'release': RELEASE,
                          'migrations_run': 0, 'business_writes': False, 'database_restored': False,
                          'production_changed': False, 'backup': str(backup)}))
        return
    print(json.dumps({'staged': True, 'release': RELEASE, 'role_counts': counts, 'pdf': proof,
                      'activation_review_complete': ACTIVATION_REVIEW_COMPLETE}))


if __name__ == '__main__':
    try:
        main()
    except (Exception, KeyboardInterrupt) as error:
        # Never print raw exception text: third-party errors may include credentials.
        safe = str(error) if isinstance(error, Refused) else type(error).__name__
        print('STOPPED=' + safe, file=sys.stderr)
        sys.exit(2)
