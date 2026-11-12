#!/usr/bin/env python3
"""Read-only PHOS UAT path/runtime inventory; version commands only, no PDF or browser session."""
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import signal
import socket
import stat
import struct
import subprocess
import sys
import tempfile
import time

try:
    import pwd
    import resource
    import fcntl
except ImportError:
    pwd = resource = fcntl = None

APP = Path('/home/uat-admin.primehealers.com/public_html')
HOST = 'uat-admin.primehealers.com'
DATABASE = 'uata_primehealersos'
PRIVATE_CONFIG = Path('/root/phos-uat-pr8-backup-o46ztayl/target-config.json')
PHP = '/usr/local/lsws/lsphp82/bin/php'
SYSTEM_PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
TEMP_PARENT = Path('/tmp')
PREFIX = 'phos-uat-pdf-probe-'
MAX_CAPTURE = 131072
MAX_TREE = 67108864
PHASE = 'target'
NETWORK_ISOLATED = False
OWNER_DROPPED = False
PHASES = frozenset({'target', 'namespace', 'worker', 'cleanup', 'configuration', 'paths', 'node',
                   'modules', 'browser', 'composer', 'sandbox', 'control', 'unchanged'})
ERROR_CLASSES = frozenset({'Refused', 'FileNotFoundError', 'PermissionError', 'TimeoutExpired', 'JSONDecodeError',
                           'ValueError', 'OSError', 'MemoryError', 'UnicodeDecodeError', 'KeyError', 'TypeError', 'ProbeError'})
SAFE_ARGS = frozenset({'disable-dev-shm-usage', 'disable-gpu', 'disable-software-rasterizer'})
DISABLING_ARGS = frozenset({'no-sandbox', 'disable-setuid-sandbox', 'single-process', 'no-zygote'})
STANDARD_PATHS = {
    'APP_CONFIG_CACHE': 'bootstrap/cache/config.php', 'APP_SERVICES_CACHE': 'bootstrap/cache/services.php',
    'APP_PACKAGES_CACHE': 'bootstrap/cache/packages.php', 'APP_ROUTES_CACHE': 'bootstrap/cache/routes-v7.php',
    'APP_EVENTS_CACHE': 'bootstrap/cache/events.php', 'LARAVEL_STORAGE_PATH': 'storage',
}


class Refused(RuntimeError):
    pass


def need(condition):
    if not condition:
        raise Refused()


def phase(value):
    global PHASE
    need(value in PHASES)
    PHASE = value


def safe_error(exception):
    value = type(exception).__name__
    return value if value in ERROR_CLASSES else 'ProbeError'


def worker_error(value):
    need(isinstance(value, dict) and set(value) == {'diagnostic', 'status', 'error_class', 'phase',
         'strict_network_isolation', 'website_owner_execution', 'network_fallback_attempted'})
    need(value['diagnostic'] == 'PHOS_UAT_PDF_PATH_INVENTORY_PRIVATE' and value['status'] == 'refused'
         and value['error_class'] in ERROR_CLASSES and value['phase'] in PHASES
         and type(value['strict_network_isolation']) is bool and type(value['website_owner_execution']) is bool
         and value['network_fallback_attempted'] is False)
    return {key: value[key] for key in ('error_class', 'phase', 'strict_network_isolation', 'website_owner_execution')}


def no_links(path):
    path = Path(path)
    for item in (path, *path.parents):
        need(not item.is_symlink())


def regular(path, limit=4194304):
    no_links(path)
    info = path.stat()
    need(stat.S_ISREG(info.st_mode) and info.st_nlink == 1 and info.st_size <= limit)
    return info


def validate_target_values(value):
    need(isinstance(value, dict) and value.get('application') == str(APP)
         and value.get('host') == HOST and value.get('database') == DATABASE and value.get('php') == PHP)
    owner = value.get('owner')
    need(isinstance(owner, str) and re.fullmatch(r'[a-z_][a-z0-9_-]*', owner) is not None and owner != 'root')
    return owner


def trusted_executable(value, *, executable=True):
    need(isinstance(value, str) and re.fullmatch(r'/[A-Za-z0-9_+./-]{1,255}', value) is not None and '..' not in Path(value).parts)
    requested = Path(value)
    resolved = requested.resolve(strict=True)
    # A root-owned symlink is allowed only through root-owned, non-writable parents.
    for candidate in (requested, resolved):
        for item in (candidate, *candidate.parents):
            info = item.lstat()
            need(info.st_uid == 0)
            if not stat.S_ISLNK(info.st_mode):
                need(stat.S_IMODE(info.st_mode) & 0o022 == 0)
    need(resolved.is_file() and os.access(resolved, os.X_OK if executable else os.R_OK))
    return str(resolved)


def validate_vhost_metadata(info):
    need(stat.S_ISREG(info.st_mode) and info.st_nlink == 1)
    mode = stat.S_IMODE(info.st_mode)
    root_owned = info.st_uid == 0 and mode & 0o022 == 0
    observed_uat = info.st_uid == 65534 and info.st_gid == 65534 and mode == 0o750
    # This exact observed UAT exception permits read-only identity parsing only.
    # It neither executes this file nor certifies the hosting ownership policy.
    need(root_owned or observed_uat)


def validate_vhost(path):
    need(path == Path('/usr/local/lsws/conf/vhosts/uat-admin.primehealers.com/vhost.conf'))
    validate_vhost_metadata(regular(path, 1048576))
    need(re.search(r'(?im)^\s*docRoot\s+\$VH_ROOT/public_html/public\s*$', path.read_text()) is not None)


def load_target():
    need(sys.platform.startswith('linux') and pwd is not None and os.geteuid() == 0)
    need(APP == Path('/home/uat-admin.primehealers.com/public_html') and HOST == 'uat-admin.primehealers.com'
         and DATABASE == 'uata_primehealersos' and PHP == '/usr/local/lsws/lsphp82/bin/php')
    regular(PRIVATE_CONFIG, 65536)
    for path in (PRIVATE_CONFIG, PRIVATE_CONFIG.parent):
        info = path.stat()
        need(info.st_uid == 0 and stat.S_IMODE(info.st_mode) & 0o077 == 0)
    owner = validate_target_values(json.loads(PRIVATE_CONFIG.read_bytes()))
    account = pwd.getpwnam(owner)
    need(account.pw_uid > 0)
    no_links(APP)
    need(APP.is_dir() and APP.stat().st_uid == account.pw_uid)
    for path in (APP / 'public', APP / 'storage', APP / 'bootstrap/cache'):
        no_links(path)
        need(path.is_dir() and path.stat().st_uid == account.pw_uid)
    for relative in ('.env', 'vendor/autoload.php', 'public/index.php', 'config/app.php', 'config/pdf.php'):
        regular(APP / relative)
    environment = (APP / '.env').stat()
    need(environment.st_uid == account.pw_uid and environment.st_gid == account.pw_gid
         and stat.S_IMODE(environment.st_mode) in (0o600, 0o640))
    for relative in STANDARD_PATHS.values():
        no_links(APP / relative)
    vhost = Path('/usr/local/lsws/conf/vhosts') / HOST / 'vhost.conf'
    validate_vhost(vhost)
    return account, trusted_executable(PHP)


def temp_identity(path, uid):
    path = Path(path)
    need(path.parent == TEMP_PARENT and re.fullmatch(PREFIX + r'[a-z0-9_]{6,20}', path.name) is not None)
    no_links(path)
    info = path.stat()
    need(stat.S_ISDIR(info.st_mode) and info.st_uid == uid and stat.S_IMODE(info.st_mode) == 0o700)
    return (info.st_dev, info.st_ino, info.st_uid, stat.S_IMODE(info.st_mode))


def create_owner_temp(account):
    no_links(TEMP_PARENT)
    info = TEMP_PARENT.stat()
    need(info.st_uid == 0 and stat.S_ISDIR(info.st_mode) and stat.S_IMODE(info.st_mode) == 0o1777)
    reader, writer = os.pipe()
    child = os.fork()
    if child == 0:
        os.close(reader)
        try:
            os.initgroups(account.pw_name, account.pw_gid)
            os.setgid(account.pw_gid)
            os.setuid(account.pw_uid)
            os.umask(0o077)
            value = tempfile.mkdtemp(prefix=PREFIX, dir=str(TEMP_PARENT))
            os.write(writer, value.encode('ascii'))
            os._exit(0)
        except BaseException:
            os._exit(2)
    os.close(writer)
    data = os.read(reader, 1024)
    os.close(reader)
    _, status = os.waitpid(child, 0)
    need(os.waitstatus_to_exitcode(status) == 0)
    path = Path(data.decode('ascii'))
    return path, temp_identity(path, account.pw_uid)


def cleanup_temp(path, uid, identity):
    need(temp_identity(path, uid) == identity and getattr(shutil.rmtree, 'avoids_symlink_attacks', False))
    shutil.rmtree(path)
    need(not path.exists())


def private_environment(root):
    values = {'PATH': SYSTEM_PATH, 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8'}
    for key, directory in {'HOME': 'home', 'TMPDIR': 'tmp', 'TMP': 'tmp', 'TEMP': 'tmp',
                           'XDG_CONFIG_HOME': 'xdg-config', 'XDG_CACHE_HOME': 'xdg-cache',
                           'XDG_RUNTIME_DIR': 'xdg-runtime'}.items():
        values[key] = str(root / directory)
    return values


def restrict_child():
    resource.setrlimit(resource.RLIMIT_CORE, (0, 0))
    resource.setrlimit(resource.RLIMIT_FSIZE, (16777216, 16777216))


def bounded_run(arguments, root, *, content=None, timeout=15, cwd=None):
    output = root / ('capture-' + os.urandom(8).hex())
    error = root / ('capture-' + os.urandom(8).hex())
    with output.open('xb') as stdout, error.open('xb') as stderr:
        result = subprocess.Popen(arguments, stdin=subprocess.PIPE if content is not None else subprocess.DEVNULL,
                                  cwd=cwd or root, env=private_environment(root), stdout=stdout, stderr=stderr,
                                  preexec_fn=restrict_child)
        if content is not None:
            result.stdin.write(content)
            result.stdin.close()
        deadline = time.monotonic() + timeout
        while result.poll() is None:
            need(output.stat().st_size <= MAX_CAPTURE and error.stat().st_size <= MAX_CAPTURE)
            directory_size(root)
            if time.monotonic() >= deadline:
                raise subprocess.TimeoutExpired(arguments[0], timeout)
            time.sleep(0.1)
    need(output.stat().st_size <= MAX_CAPTURE and error.stat().st_size <= MAX_CAPTURE)
    data = output.read_bytes()
    output.unlink()
    error.unlink()
    return result.returncode, data


def safe_arguments(arguments, *, control=False):
    need(isinstance(arguments, list) and len(arguments) <= 40)
    result = []
    for argument in arguments:
        need(isinstance(argument, str) and len(argument) <= 100)
        key = argument.lstrip('-').split('=', 1)[0].lower()
        if key in DISABLING_ARGS or 'sandbox' in key:
            if not control:
                continue
        allowed = SAFE_ARGS | (DISABLING_ARGS if control else frozenset())
        need(key in allowed and argument.lstrip('-') == key)
        result.append('--' + key)
    return list(dict.fromkeys(result))




def unchanged_files():
    result = {}
    for relative in ('.env', 'bootstrap/cache/config.php', 'config/pdf.php',
                     'app/Support/PdfBrowsershotConfigurator.php', 'composer.lock', 'vendor/autoload.php'):
        path = APP / relative
        no_links(path)
        if not path.exists():
            result[relative] = None
            continue
        info = regular(path)
        result[relative] = (info.st_dev, info.st_ino, info.st_uid, info.st_gid, info.st_mode,
                            info.st_mtime_ns, hashlib.sha256(path.read_bytes()).hexdigest())
    return result


PHP_CONFIGURATION = r'''
ob_start();
try {
 require 'vendor/autoload.php';
 final class PhosPdfConfiguration extends Illuminate\Foundation\Application {
  protected function registerBaseServiceProviders() {}
  protected function registerLaravelCloudServices() {}
 }
 final class PhosPdfEnvironment extends Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables {
  protected function writeErrorAndDie($exception) {throw $exception;}
 }
 $base='/home/uat-admin.primehealers.com/public_html';
 $paths=['APP_CONFIG_CACHE'=>$base.'/bootstrap/cache/config.php','APP_SERVICES_CACHE'=>$base.'/bootstrap/cache/services.php',
 'APP_PACKAGES_CACHE'=>$base.'/bootstrap/cache/packages.php','APP_ROUTES_CACHE'=>$base.'/bootstrap/cache/routes-v7.php',
 'APP_EVENTS_CACHE'=>$base.'/bootstrap/cache/events.php','LARAVEL_STORAGE_PATH'=>$base.'/storage'];
 $raw=file_get_contents('.env');if(!is_string($raw)||strlen($raw)>1048576||str_contains($raw,"\0"))throw new RuntimeException();
 $entries=(new Dotenv\Parser\Parser)->parse($raw);$names=[];
 foreach($entries as $entry){$name=$entry->getName();if(isset($names[$name]))throw new RuntimeException();$names[$name]=true;
  $value=$entry->getValue()->map(fn($item)=>$item->getChars())->getOrElse('');
  if(str_contains($value,"\n")||str_contains($value,"\r")||(isset($paths[$name])&&$value!==$paths[$name]))throw new RuntimeException();}
 $app=new PhosPdfConfiguration($base);(new PhosPdfEnvironment)->bootstrap($app);
 if($app->getCachedConfigPath()!==$paths['APP_CONFIG_CACHE']||$app->getCachedServicesPath()!==$paths['APP_SERVICES_CACHE']
 ||$app->getCachedPackagesPath()!==$paths['APP_PACKAGES_CACHE']||$app->getCachedRoutesPath()!==$paths['APP_ROUTES_CACHE']
 ||$app->getCachedEventsPath()!==$paths['APP_EVENTS_CACHE']||$app->storagePath()!==$paths['LARAVEL_STORAGE_PATH'])throw new RuntimeException();
 (new Illuminate\Foundation\Bootstrap\LoadConfiguration)->bootstrap($app);$cfg=$app['config'];
 $url=parse_url((string)$cfg->get('app.url',''));$db=$cfg->get('database.connections.'.$cfg->get('database.default'));
 if(!is_array($db))throw new RuntimeException();$db=(new Illuminate\Support\ConfigurationUrlParser)->parseConfiguration($db);
 if($app->getLoadedProviders()!==[]||$cfg->get('app.env')!=='uat'||!is_array($url)
 ||($url['scheme']??'')!=='https'||($url['host']??'')!=='uat-admin.primehealers.com'
 ||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])
 ||!in_array($url['path']??'',['','/'],true)||($url['port']??443)!==443
 ||PHP_MAJOR_VERSION!==8||PHP_MINOR_VERSION!==2||($db['driver']??'')!=='mysql'||($db['database']??'')!=='uata_primehealersos'
 ||!in_array($db['host']??'', ['localhost','127.0.0.1','::1'],true)||!empty($db['read'])||!empty($db['write'])
 ||(int)($db['port']??3306)!==3306)throw new RuntimeException();
 $configured=trim((string)$cfg->get('pdf.browser_path',''));$selected=null;$selection='unavailable';
 if($configured!==''){$selected=$configured;$selection='configured';}
 else foreach((array)$cfg->get('pdf.fallback_browser_paths.linux',[]) as $candidate)
  if(is_string($candidate)&&(is_file($candidate)||is_executable($candidate))){$selected=$candidate;$selection='fallback';break;}
 $environment=array_intersect_key((array)$cfg->get('pdf.environment.linux',[]),array_flip(['HOME','TMPDIR','TMP','TEMP','XDG_CONFIG_HOME','XDG_CACHE_HOME','XDG_RUNTIME_DIR']));
 ob_end_clean();echo json_encode(['identity_verified'=>true,'providers_loaded'=>0,'configuration_cached'=>$app->configurationIsCached(),
 'standard_paths'=>$paths,'node'=>(string)$cfg->get('pdf.node_binary','node'),'modules'=>(string)$cfg->get('pdf.node_module_path',''),
 'browser'=>$selected,'browser_selection'=>$selection,'disable_sandbox'=>(bool)$cfg->get('pdf.disable_sandbox',true),
 'arguments'=>array_merge((array)$cfg->get('pdf.chromium_arguments.common',[]),(array)$cfg->get('pdf.chromium_arguments.linux',[])),
 'pdf_paths'=>['temp'=>(string)$cfg->get('pdf.temp_path',''),'profile'=>(string)$cfg->get('pdf.user_data_dir',''),'environment'=>$environment]],JSON_THROW_ON_ERROR);
}catch(Throwable $exception){while(ob_get_level())ob_end_clean();echo '{"error_class":"ConfigurationInspectionFailed"}';exit(2);}
'''




def safe_json(data):
    need(len(data) <= MAX_CAPTURE)
    value = json.loads(data)
    need(isinstance(value, dict))
    return value




def version(arguments, root, pattern, timeout=8):
    code, data = bounded_run(arguments, root, timeout=timeout)
    text = data.decode('ascii', errors='ignore').strip()
    match = re.search(pattern, text)
    return match.group(0)[:100] if code == 0 and match else None


def empty_namespace(expected_parent):
    need(os.stat('/proc/self/ns/net').st_ino != expected_parent)
    interfaces = {line.split(':', 1)[0].strip() for line in Path('/proc/net/dev').read_text().splitlines()[2:]}
    with socket.socket(socket.AF_INET, socket.SOCK_DGRAM) as interface:
        flags = fcntl.ioctl(interface.fileno(), 0x8913, struct.pack('16sH', b'lo', 0))
    need(interfaces == {'lo'} and struct.unpack_from('H', flags, 16)[0] & 1 == 0)
    need(not Path('/proc/net/route').read_text().splitlines()[1:])
    try:
        ipv6 = Path('/proc/net/if_inet6').read_text()
    except FileNotFoundError:
        ipv6 = ''
    need(not ipv6.strip())


def directory_size(root):
    total = entries = 0
    for parent, directories, files in os.walk(root, followlinks=False):
        for name in directories + files:
            try:
                info = (Path(parent) / name).lstat()
            except FileNotFoundError:
                continue  # Chromium may remove an entry after os.walk lists it.
            total += info.st_size
            entries += 1
            need(total <= MAX_TREE and entries <= 10000)
    return total





def configured_path_details(candidate):
    result = {'value_type': type(candidate).__name__, 'linked_component': False, 'metadata_inspected': False}
    malformed = []
    if not isinstance(candidate, str):
        malformed.append('PathNotString')
    else:
        if any(ord(char) < 32 or ord(char) == 127 for char in candidate):
            malformed.append('ControlCharacters')
        if not candidate.startswith('/'):
            malformed.append('NotAbsolutePosixPath')
        if not 2 <= len(candidate) <= 401:
            malformed.append('PathLengthLimit')
        if not malformed and re.fullmatch(r'/[A-Za-z0-9_./-]{1,400}', candidate) is None:
            malformed.append('UnapprovedPathCharacters')
    if malformed:
        return {**result, 'configured_value_redacted': True, 'published_gate_compatible': False, 'reasons': malformed}
    result['configured_path'] = candidate
    lexical = PurePosixPath(candidate)
    reasons = []
    if '..' in lexical.parts:
        reasons.append('ParentTraversal')
    if not lexical.is_relative_to(PurePosixPath(APP.as_posix()) / 'storage'):
        reasons.append('OutsideApplicationStorage')
    result['within_application_storage'] = 'OutsideApplicationStorage' not in reasons
    if '..' in lexical.parts or lexical.anchor != '/':
        return {**result, 'published_gate_compatible': False, 'reasons': reasons + (['AmbiguousPathRoot'] if lexical.anchor != '/' else [])}
    # Inspect ancestors from the root outward, stopping before any symlink.
    # No configured PDF file is opened, read, resolved through a link, or created.
    current = Path('/')
    for component in lexical.parts[1:]:
        current = current / component
        try:
            info = current.lstat()
        except FileNotFoundError:
            result.update({'exists': False, 'first_missing_component': current.as_posix()})
            break
        except OSError:
            reasons.append('MetadataUnavailable')
            result['metadata_error_component'] = current.as_posix()
            break
        if stat.S_ISLNK(info.st_mode):
            reasons.append('LinkedComponent')
            result.update({'linked_component': True, 'first_linked_component': current.as_posix()})
            break
        result['metadata_inspected'] = True
        if current == Path(candidate):
            result.update({'exists': True, 'kind': 'directory' if stat.S_ISDIR(info.st_mode) else 'file' if stat.S_ISREG(info.st_mode) else 'other',
                           'uid': info.st_uid, 'gid': info.st_gid, 'mode': oct(stat.S_IMODE(info.st_mode))})
    result['published_gate_compatible'] = not reasons
    result['reasons'] = reasons
    return result


def audit_pdf_paths(value):
    report = {'paths': {}, 'schema_issues': [], 'configured_paths_used_for_execution': False}
    if not isinstance(value, dict):
        return {**report, 'schema_issues': ['PdfPathsNotObject'], 'published_gate_compatible': False}
    if set(value) != {'temp', 'profile', 'environment'}:
        report['schema_issues'].append('UnexpectedPdfPathKeys')
    for key in ('temp', 'profile'):
        report['paths'][key] = configured_path_details(value.get(key))
    environment = value.get('environment')
    report['environment_serialized_type'] = type(environment).__name__
    if environment == []:
        report['schema_issues'].append('EmptyEnvironmentSerializedAsArray')
        environment = {}
    elif not isinstance(environment, dict):
        report['schema_issues'].append('EnvironmentNotObject')
        environment = {}
    allowed = {'HOME', 'TMPDIR', 'TMP', 'TEMP', 'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'XDG_RUNTIME_DIR'}
    if set(environment) - allowed:
        report['schema_issues'].append('UnexpectedEnvironmentKeys')
        report['unrecognized_environment_key_count'] = len(set(environment) - allowed)
    for key in sorted(set(environment) & allowed):
        report['paths']['environment.' + key] = configured_path_details(environment[key])
    report['published_gate_compatible'] = not report['schema_issues'] and all(item['published_gate_compatible'] for item in report['paths'].values())
    return report


def runtime_fingerprint(path):
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    try:
        before = os.fstat(descriptor)
        need(stat.S_ISREG(before.st_mode) and before.st_uid == 0 and stat.S_IMODE(before.st_mode) & 0o022 == 0
             and before.st_size <= 536870912)
        digest = hashlib.sha256()
        total = 0
        header = b''
        while True:
            block = os.read(descriptor, 65536)
            if not block:
                break
            total += len(block)
            need(total <= 536870912)
            if len(header) < 1024:
                header += block[:1024 - len(header)]
            digest.update(block)
        after = os.fstat(descriptor)
        identity = lambda info: (info.st_dev, info.st_ino, info.st_uid, info.st_gid, info.st_mode,
                                 info.st_size, info.st_mtime_ns, info.st_ctime_ns)
        need(total == before.st_size and identity(before) == identity(after))
        return {'device': before.st_dev, 'inode': before.st_ino, 'uid': before.st_uid, 'gid': before.st_gid,
                'mode': oct(stat.S_IMODE(before.st_mode)), 'size': before.st_size, 'sha256': digest.hexdigest(),
                'mtime_ns': before.st_mtime_ns, 'ctime_ns': before.st_ctime_ns}, header
    finally:
        os.close(descriptor)


def executable_inventory(candidate, root, pattern, *, executable=True, php=None):
    result = {'available': False, 'version_executed': False}
    valid = isinstance(candidate, str) and (candidate in ('node', 'nodejs') or (
        re.fullmatch(r'/[A-Za-z0-9_+./-]{1,255}', candidate) is not None and '..' not in PurePosixPath(candidate).parts))
    if not valid:
        return {**result, 'configured_value_redacted': True, 'reason': 'InvalidExecutableSelection'}
    result['requested'] = candidate
    try:
        located = shutil.which(candidate, path=SYSTEM_PATH) if executable else candidate
        if not located:
            return {**result, 'reason': 'ExecutableUnavailable'}
        resolved = trusted_executable(located, executable=executable)
        result.update({'resolved': resolved, 'trusted': True})
        metadata, header = runtime_fingerprint(resolved)
        result['file'] = metadata
        result['wrapper_script'] = header.startswith(b'#!')
        if php is not None and b'<?php' not in header:
            return {**result, 'reason': 'UnsupportedComposerWrapper'}
        command = [php, resolved, '--no-plugins', '--no-scripts', '--version', '--no-ansi'] if php is not None else [resolved, '--version']
        result['version_executed'] = True
        value = version(command, root, pattern)
        after = Path(resolved).lstat()
        observed = {'device': after.st_dev, 'inode': after.st_ino, 'uid': after.st_uid, 'gid': after.st_gid,
                    'mode': oct(stat.S_IMODE(after.st_mode)), 'size': after.st_size,
                    'mtime_ns': after.st_mtime_ns, 'ctime_ns': after.st_ctime_ns}
        if not stat.S_ISREG(after.st_mode) or observed != {key: item for key, item in metadata.items() if key != 'sha256'}:
            return {**result, 'reason': 'RuntimeChangedDuringVersion'}
        result.update({'version_executed': True, 'version': value, 'available': value is not None})
        if value is None:
            result['reason'] = 'VersionUnavailable'
    except Exception as exception:
        result.update({'reason': 'UntrustedOrUnavailableRuntime', 'error_class': safe_error(exception)})
    return result


def module_inventory(candidate):
    result = {'metadata_only': True, 'import_attempted': False, 'packages': {}}
    if not isinstance(candidate, str) or re.fullmatch(r'/[A-Za-z0-9_./-]{1,400}', candidate) is None or '..' in PurePosixPath(candidate).parts:
        return {**result, 'configured_value_redacted': True, 'reason': 'MalformedModuleRoot'}
    result['configured_root'] = candidate
    modules = Path(candidate)
    if modules != APP / 'node_modules':
        return {**result, 'reason': 'UnapprovedModuleRoot'}
    try:
        no_links(modules)
        need(modules.is_dir())
    except Exception as exception:
        return {**result, 'reason': 'UnavailableOrLinkedModuleRoot', 'error_class': safe_error(exception)}
    result['approved_root'] = True
    for package in ('puppeteer', 'puppeteer-core'):
        try:
            path = modules / package / 'package.json'
            regular(path, 65536)
            raw = path.read_bytes()
            value = json.loads(raw)
            need(value.get('name') == package and isinstance(value.get('version'), str)
                 and len(value['version']) <= 80
                 and re.fullmatch(r'\d+\.\d+\.\d+(?:[-+.][A-Za-z0-9.-]+)?', value['version']) is not None)
            item = {'available': True, 'version': value['version'], 'metadata_path': path.as_posix(),
                    'metadata_bytes': len(raw), 'metadata_sha256': hashlib.sha256(raw).hexdigest()}
            engine = value.get('engines', {}).get('node') if isinstance(value.get('engines', {}), dict) else None
            if isinstance(engine, str) and re.fullmatch(r'[<>=^~|.0-9*xv -]{1,100}', engine):
                item['node_engine'] = engine
            result['packages'][package] = item
        except Exception as exception:
            result['packages'][package] = {'available': False, 'reason': 'PackageMetadataUnavailableOrInvalid', 'error_class': safe_error(exception)}
    return result


def inventory_configuration(cfg, root, php):
    expected = {key: str(APP / value) for key, value in STANDARD_PATHS.items()}
    report = {'inventory_only': True, 'synthetic_pdf_attempted': False, 'browser_render_session_attempted': False,
              'configured_pdf_paths_metadata_only': True,
              'deployment_readiness_claimed': False, 'providers_loaded': 0, 'configuration_cached': cfg['configuration_cached'],
              'configured_disable_sandbox': cfg.get('disable_sandbox') if type(cfg.get('disable_sandbox')) is bool else None,
              'standard_paths_match': cfg.get('standard_paths') == expected,
              'pdf_paths': audit_pdf_paths(cfg.get('pdf_paths')), 'nodes': {}, 'composer_candidates': []}
    phase('node')
    for label, selected in [('system', 'node'), ('configured', cfg.get('node'))]:
        report['nodes'][label] = executable_inventory(selected, root, r'v\d+\.\d+\.\d+')
    phase('modules')
    report['modules'] = module_inventory(cfg.get('modules'))
    phase('browser')
    report['browser_selection'] = cfg.get('browser_selection') if cfg.get('browser_selection') in ('configured', 'fallback', 'unavailable') else 'unknown'
    report['browser'] = executable_inventory(cfg.get('browser'), root, r'(?:Google Chrome|Chromium|Chrome for Testing)[ A-Za-z]*\d+\.\d+\.\d+\.\d+')
    phase('composer')
    for selected in ('/usr/local/bin/composer', '/usr/bin/composer', '/usr/local/bin/composer.phar'):
        report['composer_candidates'].append(executable_inventory(selected, root, r'Composer version \d+\.\d+\.\d+(?:[-.][A-Za-z0-9.]+)?', executable=False, php=php))
    args = cfg.get('arguments')
    report['chromium_argument_inventory'] = {'input_type': type(args).__name__, 'configured_arguments_used': False, 'items': []}
    if isinstance(args, list):
        for index, argument in enumerate(args[:40]):
            key = argument.lstrip('-').split('=', 1)[0].lower() if isinstance(argument, str) else ''
            item = {'index': index, 'recognized': key in SAFE_ARGS | DISABLING_ARGS,
                    'sandbox_incompatible': key in DISABLING_ARGS or 'sandbox' in key}
            if key in SAFE_ARGS | DISABLING_ARGS:
                item['switch'] = key
            else:
                item['configured_value_redacted'] = True
            report['chromium_argument_inventory']['items'].append(item)
        report['chromium_argument_inventory']['extra_item_count'] = max(0, len(args) - 40)
    failures = [{'group': 'pdf_paths', 'field': 'schema', 'reason': issue} for issue in report['pdf_paths']['schema_issues']]
    for field, item in report['pdf_paths']['paths'].items():
        failures.extend({'group': 'pdf_paths', 'field': field, 'reason': reason} for reason in item['reasons'])
    if not report['standard_paths_match']:
        failures.append({'group': 'standard_paths', 'field': 'cache_and_storage', 'reason': 'StandardPathMismatch'})
    for field, item in report['nodes'].items():
        if not item['available']:
            failures.append({'group': 'node', 'field': field, 'reason': item['reason']})
    if 'reason' in report['modules']:
        failures.append({'group': 'modules', 'field': 'root', 'reason': report['modules']['reason']})
    for field, item in report['modules']['packages'].items():
        if not item['available']:
            failures.append({'group': 'modules', 'field': field, 'reason': item['reason']})
    if not report['browser']['available']:
        failures.append({'group': 'browser', 'field': 'selected', 'reason': report['browser']['reason']})
    for index, item in enumerate(report['composer_candidates']):
        if not item['available']:
            failures.append({'group': 'composer', 'field': str(index), 'reason': item['reason']})
    for item in report['chromium_argument_inventory']['items']:
        if not item['recognized']:
            failures.append({'group': 'chromium_arguments', 'field': str(item['index']), 'reason': 'UnreviewedChromiumArgument'})
    if not isinstance(args, list) or len(args) > 40:
        failures.append({'group': 'chromium_arguments', 'field': 'schema', 'reason': 'InvalidArgumentListOrCount'})
    report['input_check_failures'] = failures
    return report


def worker(payload):
    global NETWORK_ISOLATED, OWNER_DROPPED
    phase('namespace')
    need(os.geteuid() == 0)
    owner = validate_target_values(payload['target'])
    account = pwd.getpwnam(owner)
    need(account.pw_uid == payload['uid'] > 0 and account.pw_gid == payload['gid'])
    empty_namespace(payload['parent_net_inode'])
    NETWORK_ISOLATED = True
    root = Path(payload['temporary'])
    need(temp_identity(root, account.pw_uid) == tuple(payload['temporary_identity']))
    os.initgroups(owner, account.pw_gid)
    os.setgid(account.pw_gid)
    os.setuid(account.pw_uid)
    need(os.geteuid() == account.pw_uid and os.getegid() == account.pw_gid and os.geteuid() != 0)
    OWNER_DROPPED = True
    os.umask(0o077)
    for directory in set(private_environment(root).values()) - {SYSTEM_PATH, 'C.UTF-8'}:
        Path(directory).mkdir(mode=0o700, exist_ok=True)
    before = unchanged_files()
    phase('configuration')
    code, output = bounded_run([payload['php'], '-d', 'display_errors=0', '-d', 'log_errors=0',
                               '-d', 'opcache.enable_cli=0', '-r', PHP_CONFIGURATION], root, cwd=APP)
    cfg = safe_json(output)
    need(code == 0 and cfg.get('identity_verified') is True and cfg.get('providers_loaded') == 0)
    phase('paths')
    report = inventory_configuration(cfg, root, payload['php'])
    phase('unchanged')
    report['strict_network_isolation'] = True
    report['website_owner_execution'] = True
    report['application_files_unchanged'] = before == unchanged_files()
    need(report['application_files_unchanged'])
    return report


def stop_group(process):
    for sig in (signal.SIGTERM, signal.SIGKILL):
        try:
            os.killpg(process.pid, sig)
        except ProcessLookupError:
            return
        if sig == signal.SIGTERM:
            time.sleep(0.2)


def inspect():
    phase('target')
    account, php = load_target()
    phase('namespace')
    unshare = trusted_executable('/usr/bin/unshare')
    python = trusted_executable(sys.executable)
    temporary, identity = create_owner_temp(account)
    process = None
    report = {'diagnostic': 'PHOS_UAT_PDF_PATH_INVENTORY_PRIVATE', 'status': 'refused', 'application_configuration_mutation_requested': False,
              'strict_network_isolation': False, 'network_fallback_attempted': False, 'deployment_readiness_claimed': False,
              'business_records_read': False, 'emails_sent': False, 'production_accessed': False, 'cleanup_complete': False}
    try:
        payload = {'target': {'application': str(APP), 'host': HOST, 'database': DATABASE, 'php': PHP, 'owner': account.pw_name},
                   'uid': account.pw_uid, 'gid': account.pw_gid, 'php': php, 'parent_net_inode': os.stat('/proc/self/ns/net').st_ino,
                   'temporary': str(temporary), 'temporary_identity': identity}
        source = Path(__file__).read_text()
        process = subprocess.Popen([unshare, '--net', '--', python, '-I', '-B', '-c', source, '--worker'],
            stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
            env={'PATH': SYSTEM_PATH, 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8'}, start_new_session=True)
        output, _ = process.communicate(json.dumps(payload).encode(), timeout=150)
        if process.returncode != 0:
            if not output.strip():
                report.update({'error_class': 'NetworkNamespaceUnavailable', 'phase': 'namespace',
                               'network_isolation_status': 'unavailable'})
            else:
                report.update(worker_error(safe_json(output)))
                report['network_isolation_status'] = 'verified' if report['strict_network_isolation'] else 'not_established'
            return report
        phase('worker')
        result = safe_json(output)
        need(result.get('strict_network_isolation') is True and result.get('website_owner_execution') is True)
        report.update({'status': 'complete', 'strict_network_isolation': True, 'network_isolation_status': 'verified', 'inventory': result})
    except Exception as exception:
        report['error_class'] = safe_error(exception)
        report['phase'] = PHASE
    finally:
        if process is not None:
            stop_group(process)
            if process.poll() is None:
                process.wait(timeout=5)
        try:
            cleanup_temp(temporary, account.pw_uid, identity)
            report['cleanup_complete'] = True
        except Exception:
            report['status'] = 'refused'
            report['error_class'] = 'CleanupRefused'
            report['phase'] = 'cleanup'
            report['private_artifact_directory'] = str(temporary)
    return report


def main():
    try:
        if sys.argv[1:] == ['--worker']:
            payload = safe_json(sys.stdin.buffer.read(MAX_CAPTURE + 1))
            result = worker(payload)
        else:
            need(sys.argv[1:] == [])
            result = inspect()
        print(json.dumps(result, sort_keys=True, ensure_ascii=True, indent=2))
        return 0 if result.get('status', 'complete') == 'complete' else 2
    except Exception as exception:
        print(json.dumps({'diagnostic': 'PHOS_UAT_PDF_PATH_INVENTORY_PRIVATE', 'status': 'refused',
                          'strict_network_isolation': NETWORK_ISOLATED, 'website_owner_execution': OWNER_DROPPED,
                          'network_fallback_attempted': False, 'phase': PHASE,
                          'error_class': safe_error(exception)}, sort_keys=True))
        return 2


if __name__ == '__main__':
    sys.exit(main())
