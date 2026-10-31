#!/usr/bin/env python3
"""Operator-only, mail-only PHOS UAT setup. SMTP authentication never sends mail."""

import argparse
import base64
import getpass
import hashlib
import http.client
import json
import os
from pathlib import Path
import re
import secrets
import signal
import socket
import ssl
import stat
import subprocess
import sys
import tempfile
import time
import warnings

try:
    import fcntl
    import pwd
except ImportError:
    fcntl = pwd = None

APP = Path('/home/uat-admin.primehealers.com/public_html')
HOST = 'uat-admin.primehealers.com'
DATABASE = 'uata_primehealersos'
MAILBOX = 'support@primehealers.com'
PRIVATE_CONFIG = Path('/root/phos-uat-pr8-backup-o46ztayl/target-config.json')
PHP_DEFAULT = '/usr/local/lsws/lsphp82/bin/php'
SYSTEM_PATH = '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'
BACKUP_PREFIX = 'phos-zoho-mail-backup-'
# Explicitly documented by Zoho Mail Help / the Zoho Mail Team IN notice.
# Do not generate regional names or infer a datacenter from physical location.
ZOHO_HOSTS = frozenset({'smtp.zoho.com', 'smtppro.zoho.com', 'smtp.zoho.in', 'smtppro.zoho.in'})
MAIL_KEYS = ('MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_SCHEME', 'MAIL_USERNAME',
             'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME')
OWNER = PHP = None
UID = GID = None


class Refused(RuntimeError):
    pass


def need(condition):
    if not condition:
        raise Refused()


def digest(value):
    return hashlib.sha256(value).hexdigest()


def no_links(path):
    for item in (path, *path.parents):
        need(not item.is_symlink())


def regular(path):
    no_links(path)
    need(path.is_file() and path.stat().st_nlink == 1)


def validate_input(value, *, limit=256):
    need(isinstance(value, str) and 0 < len(value) <= limit
         and not any(ord(char) < 32 or ord(char) == 127 for char in value))
    return value


def settings(host, port, password, from_name):
    validate_input(host, limit=80)
    need(host in ZOHO_HOSTS and type(port) is int and port in (465, 587))
    validate_input(password)
    need(password == password.strip() and password.isascii())
    validate_input(from_name, limit=120)
    return {'MAIL_MAILER': 'smtp', 'MAIL_HOST': host, 'MAIL_PORT': str(port),
            'MAIL_SCHEME': 'smtps' if port == 465 else 'smtp', 'MAIL_USERNAME': MAILBOX,
            'MAIL_PASSWORD': password, 'MAIL_FROM_ADDRESS': MAILBOX, 'MAIL_FROM_NAME': from_name}


def dotenv_value(value):
    validate_input(value)
    return '"' + value.replace('\\', '\\\\').replace('"', '\\"').replace('$', '\\$') + '"'


def patch_environment(original, values):
    need(isinstance(original, bytes) and len(original) <= 1048576 and b'\0' not in original)
    need(set(values) == set(MAIL_KEYS))
    original.decode('utf-8')
    lines = original.splitlines(keepends=True)
    newline = b'\r\n' if b'\r\n' in original else b'\n'
    seen = set()
    patched = []
    assignment = re.compile(rb'^[ \t]*(?:export[ \t]+)?([A-Za-z_][A-Za-z0-9_]*)[ \t]*=')
    for line in lines:
        match = assignment.match(line)
        if match:
            key = match.group(1).decode('ascii')
            need(key not in seen)
            seen.add(key)
            if key in values:
                ending = b'\r\n' if line.endswith(b'\r\n') else (b'\n' if line.endswith(b'\n') else b'')
                patched.append((key + '=' + dotenv_value(values[key])).encode('utf-8') + ending)
                continue
        patched.append(line)
    result = b''.join(patched)
    missing = [key for key in MAIL_KEYS if key not in seen]
    if missing:
        if result and not result.endswith(b'\n'):
            result += newline
        result += b''.join((key + '=' + dotenv_value(values[key])).encode('utf-8') + newline for key in missing)
    return result


def atomic(path, data, uid, gid, mode, *, times=None):
    no_links(path.parent)
    temporary = path.parent / ('.phos-mail-' + secrets.token_hex(16))
    descriptor = None
    try:
        descriptor = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, 'wb') as output:
            descriptor = None
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
            os.fchown(output.fileno(), uid, gid)
            os.fchmod(output.fileno(), mode)
        if times is not None:
            os.utime(temporary, ns=times, follow_symlinks=False)
        os.replace(temporary, path)
    finally:
        if descriptor is not None:
            os.close(descriptor)
        if temporary.exists():
            temporary.unlink()


def snapshot(path):
    no_links(path)
    if not path.exists() and not path.is_symlink():
        return None
    regular(path)
    info = path.stat()
    need(info.st_size <= 4194304)
    data = path.read_bytes()
    return {'data': data, 'sha256': digest(data), 'uid': info.st_uid, 'gid': info.st_gid,
            'mode': stat.S_IMODE(info.st_mode), 'atime_ns': info.st_atime_ns, 'mtime_ns': info.st_mtime_ns}


def file_signature(entry):
    if entry is None:
        return None
    return {key: entry[key] for key in ('sha256', 'uid', 'gid', 'mode', 'mtime_ns')}


def require_unchanged(path, expected):
    need(file_signature(snapshot(path)) == file_signature(expected))


def as_owner():
    os.initgroups(OWNER, GID)
    os.setgid(GID)
    os.setuid(UID)
    os.umask(0o077)


def execute(arguments, *, content=None, timeout=90):
    process = subprocess.run(arguments, cwd=APP,
                             env={'PATH': SYSTEM_PATH, 'LANG': 'C.UTF-8', 'LC_ALL': 'C.UTF-8'},
                             input=content, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                             timeout=timeout, check=False, preexec_fn=as_owner)
    need(process.returncode == 0 and len(process.stdout) <= 65536)
    return process.stdout


PHP_INSPECT = r'''
ob_start();
try {
 require 'vendor/autoload.php';
 final class PhosMailConfiguration extends Illuminate\Foundation\Application {
  protected function registerBaseServiceProviders() {}
  protected function registerLaravelCloudServices() {}
 }
 final class PhosMailEnvironment extends Illuminate\Foundation\Bootstrap\LoadEnvironmentVariables {
  protected function writeErrorAndDie($exception) {throw $exception;}
 }
 $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
 $raw=isset($input['candidate_b64'])?base64_decode($input['candidate_b64'],true):file_get_contents('.env');
 if(!is_string($raw)||strlen($raw)>1048576||str_contains($raw,"\0"))throw new RuntimeException();
 $entries=(new Dotenv\Parser\Parser)->parse($raw);$names=[];
 $base='/home/uat-admin.primehealers.com/public_html';
 $paths=['APP_CONFIG_CACHE'=>$base.'/bootstrap/cache/config.php','APP_SERVICES_CACHE'=>$base.'/bootstrap/cache/services.php',
  'APP_PACKAGES_CACHE'=>$base.'/bootstrap/cache/packages.php','APP_ROUTES_CACHE'=>$base.'/bootstrap/cache/routes-v7.php',
  'APP_EVENTS_CACHE'=>$base.'/bootstrap/cache/events.php','LARAVEL_STORAGE_PATH'=>$base.'/storage'];
 foreach($entries as $entry){
  $name=$entry->getName();if(isset($names[$name]))throw new RuntimeException();$names[$name]=true;
  $value=$entry->getValue()->map(fn($item)=>$item->getChars())->getOrElse('');
  if(str_contains($value,"\n")||str_contains($value,"\r"))throw new RuntimeException();
  if($name==='MAIL_URL'&&!in_array(strtolower(trim($value)),['','null','(null)'],true))throw new RuntimeException();
  if(array_key_exists($name,$paths)&&$value!==$paths[$name])throw new RuntimeException();
 }
 if(isset($input['candidate_b64'])){
  foreach(($input['expected']??[]) as $key=>$value){
   $found=array_values(array_filter($entries,fn($entry)=>$entry->getName()===$key));
   if(count($found)!==1||$found[0]->getValue()->map(fn($item)=>$item->getChars())->getOrElse(null)!==$value)throw new RuntimeException();
  }
  ob_end_clean();echo '{"candidate_valid":true}';exit;
 }
 $app=new PhosMailConfiguration('/home/uat-admin.primehealers.com/public_html');
 (new PhosMailEnvironment)->bootstrap($app);
 if($app->getCachedConfigPath()!==$paths['APP_CONFIG_CACHE']||$app->getCachedServicesPath()!==$paths['APP_SERVICES_CACHE']
  ||$app->getCachedPackagesPath()!==$paths['APP_PACKAGES_CACHE']||$app->getCachedRoutesPath()!==$paths['APP_ROUTES_CACHE']
  ||$app->getCachedEventsPath()!==$paths['APP_EVENTS_CACHE']||$app->storagePath()!==$paths['LARAVEL_STORAGE_PATH'])throw new RuntimeException();
 (new Illuminate\Foundation\Bootstrap\LoadConfiguration)->bootstrap($app);
 $cfg=$app['config'];$url=parse_url((string)$cfg->get('app.url',''));
 if($app->getLoadedProviders()!==[]||$cfg->get('app.env')!=='uat'||!is_array($url)
  ||($url['scheme']??'')!=='https'||($url['host']??'')!=='uat-admin.primehealers.com'
  ||isset($url['user'])||isset($url['pass'])||isset($url['query'])||isset($url['fragment'])
  ||!in_array($url['path']??'',['','/'],true)||($url['port']??443)!==443
  ||PHP_MAJOR_VERSION!==8||PHP_MINOR_VERSION!==2||$cfg->get('app.maintenance.driver','file')!=='file')throw new RuntimeException();
 $mail=$cfg->get('mail.mailers.smtp');
 if(!is_array($mail)||($mail['transport']??'')!=='smtp'||($mail['require_tls']??null)!==true
  ||!empty($mail['url'])||($mail['auto_tls']??true)===false||($mail['verify_peer']??true)===false
  ||($mail['stream']['ssl']['verify_peer']??true)===false||($mail['stream']['ssl']['verify_peer_name']??true)===false)throw new RuntimeException();
 $connection=$cfg->get('database.default');$db=$cfg->get('database.connections.'.$connection);
 if(!is_array($db))throw new RuntimeException();
 $db=(new Illuminate\Support\ConfigurationUrlParser)->parseConfiguration($db);
 if(($db['driver']??'')!=='mysql'||($db['database']??'')!=='uata_primehealersos'
  ||!in_array($db['host']??'', ['localhost','127.0.0.1','::1'],true)||!empty($db['read'])||!empty($db['write'])
  ||(int)($db['port']??3306)!==3306)throw new RuntimeException();
 $socket=(string)($db['unix_socket']??'');
 if($socket!==''&&(!preg_match('#^/[A-Za-z0-9_./-]+$#D',$socket)||str_contains($socket,'..')))throw new RuntimeException();
 $dsn=$socket!==''?'mysql:unix_socket='.$socket.';dbname=uata_primehealersos;charset=utf8mb4'
  :'mysql:host='.$db['host'].';port=3306;dbname=uata_primehealersos;charset=utf8mb4';
 $pdo=new PDO($dsn,(string)($db['username']??''),(string)($db['password']??''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_TIMEOUT=>5]);
 if($pdo->query('SELECT DATABASE()')->fetchColumn()!=='uata_primehealersos')throw new RuntimeException();
 if(isset($input['expected'])){
  $want=$input['expected'];
  if($cfg->get('mail.default')!=='smtp'||($mail['host']??null)!==$want['MAIL_HOST']
   ||(int)($mail['port']??0)!==(int)$want['MAIL_PORT']||($mail['scheme']??null)!==$want['MAIL_SCHEME']
   ||($mail['username']??null)!==$want['MAIL_USERNAME']||($mail['password']??null)!==$want['MAIL_PASSWORD']
   ||$cfg->get('mail.from.address')!==$want['MAIL_FROM_ADDRESS']||$cfg->get('mail.from.name')!==$want['MAIL_FROM_NAME'])throw new RuntimeException();
 }
 $fingerprint=hash('sha256',json_encode($cfg->get('mail'),JSON_THROW_ON_ERROR));
 $protected=hash('sha256',json_encode(['application'=>array_intersect_key($cfg->get('app'),array_flip(['key','url','env','debug','timezone'])),
  'database'=>$cfg->get('database'),'session'=>$cfg->get('session')],JSON_THROW_ON_ERROR));
 ob_end_clean();echo json_encode(['identity_verified'=>true,'configuration_cached'=>$app->configurationIsCached(),
  'mail_fingerprint'=>$fingerprint,'protected_configuration_fingerprint'=>$protected,'providers_loaded'=>0],JSON_THROW_ON_ERROR);
}catch(Throwable $exception){while(ob_get_level())ob_end_clean();echo json_encode(['error_class'=>get_class($exception)]);exit(2);}
'''


def inspect_configuration(content=None):
    data = execute([PHP, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'opcache.enable_cli=0', '-r', PHP_INSPECT],
                   content=json.dumps(content or {}).encode(), timeout=90)
    result = json.loads(data)
    need(isinstance(result, dict) and 'error_class' not in result)
    return result


SMTP_AUTH = r'''
import json, smtplib, ssl, sys
client=None
try:
 value=json.load(sys.stdin)
 context=ssl.create_default_context();context.minimum_version=ssl.TLSVersion.TLSv1_2
 if value['port']==465:
  client=smtplib.SMTP_SSL(value['host'],465,timeout=20,context=context)
  client.ehlo()
 else:
  client=smtplib.SMTP(value['host'],587,timeout=20)
  client.ehlo()
  if not client.has_extn('starttls'):raise RuntimeError()
  client.starttls(context=context);client.ehlo()
 if 'PLAIN' not in client.esmtp_features.get('auth','').upper().split():raise RuntimeError()
 # Exactly one AUTH attempt, no fallback/retry, and no SMTP message commands.
 client.auth('PLAIN',lambda challenge=None:'\0support@primehealers.com\0'+value['password'],initial_response_ok=True)
 print(json.dumps({'authenticated':True,'tls_version':client.sock.version()}))
except Exception as exception:
 print(json.dumps({'error_class':type(exception).__name__}));sys.exit(2)
finally:
 if client is not None:client.close()
'''


def authenticate_once(values):
    result = json.loads(execute([sys.executable, '-B', '-c', SMTP_AUTH], content=json.dumps({
        'host': values['MAIL_HOST'], 'port': int(values['MAIL_PORT']), 'password': values['MAIL_PASSWORD']}).encode(), timeout=60))
    need(result.get('authenticated') is True and result.get('tls_version') in ('TLSv1.2', 'TLSv1.3'))


def artisan(command):
    need(command in ('config:clear', 'config:cache'))
    execute([PHP, '-d', 'display_errors=0', '-d', 'log_errors=0', '-d', 'opcache.enable_cli=0',
             '-d', 'memory_limit=512M', 'artisan', command, '--no-interaction'], timeout=180)


def load_target():
    global OWNER, PHP, UID, GID
    need(sys.platform.startswith('linux') and pwd is not None and fcntl is not None and os.geteuid() == 0)
    need(str(APP) == '/home/uat-admin.primehealers.com/public_html' and HOST == 'uat-admin.primehealers.com'
         and DATABASE == 'uata_primehealersos' and MAILBOX == 'support@primehealers.com')
    no_links(APP)
    need(APP.is_dir())
    OWNER = pwd.getpwuid(APP.stat().st_uid).pw_name
    PHP = PHP_DEFAULT
    if PRIVATE_CONFIG.exists() or PRIVATE_CONFIG.is_symlink():
        regular(PRIVATE_CONFIG)
        for path in (PRIVATE_CONFIG, PRIVATE_CONFIG.parent):
            info = path.stat()
            need(info.st_uid == 0 and stat.S_IMODE(info.st_mode) & 0o077 == 0)
        need(PRIVATE_CONFIG.stat().st_size < 65536)
        value = json.loads(PRIVATE_CONFIG.read_bytes())
        need(value.get('host') == HOST and value.get('application') == str(APP) and value.get('database') == DATABASE)
        OWNER, PHP = value.get('owner'), value.get('php')
    need(isinstance(OWNER, str) and re.fullmatch(r'[a-z_][a-z0-9_-]*', OWNER) and OWNER != 'root'
         and PHP == '/usr/local/lsws/lsphp82/bin/php')
    account = pwd.getpwnam(OWNER)
    UID, GID = account.pw_uid, account.pw_gid
    need(UID != 0 and APP.stat().st_uid == UID)
    executable = Path(PHP).resolve()
    need(executable.is_file() and str(executable).startswith('/usr/local/lsws/lsphp82/bin/')
         and executable.stat().st_uid == 0 and stat.S_IMODE(executable.stat().st_mode) & 0o022 == 0)
    for path in (APP / '.env', APP / 'vendor/autoload.php', APP / 'public/index.php', APP / 'config/mail.php'):
        regular(path)
    for path in (APP / 'public', APP / 'storage/framework', APP / 'bootstrap/cache'):
        no_links(path)
        need(path.is_dir() and path.stat().st_uid == UID)
    environment = (APP / '.env').stat()
    need(environment.st_uid == UID and environment.st_gid == GID
         and stat.S_IMODE(environment.st_mode) in (0o600, 0o640) and environment.st_size <= 1048576)
    cache = APP / 'bootstrap/cache/config.php'
    if cache.exists() or cache.is_symlink():
        regular(cache)
        need(cache.stat().st_uid == UID and stat.S_IMODE(cache.stat().st_mode) in (0o600, 0o640, 0o644))
    vhost = Path('/usr/local/lsws/conf/vhosts') / HOST / 'vhost.conf'
    regular(vhost)
    need(vhost.stat().st_size < 1048576
         and re.search(r'(?im)^\s*docRoot\s+\$VH_ROOT/public_html/public\s*$', vhost.read_text()))
    need(not (APP / 'storage/framework/maintenance.php').exists())


def web_cache(invalidate):
    token = secrets.token_hex(32)
    path = APP / 'public' / ('phos-mail-cache-' + secrets.token_hex(16) + '.php')
    code = r'''<?php
if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)
 ||!hash_equals('__TOKEN__',$_SERVER['HTTP_X_PHOS_MAIL']??'')){http_response_code(404);exit;}
header('Content-Type: application/json');header('Cache-Control: no-store');
$ok=PHP_MAJOR_VERSION===8&&PHP_MINOR_VERSION===2;
if(__INVALIDATE__&&filter_var(ini_get('opcache.enable'),FILTER_VALIDATE_BOOLEAN)){
 if(!function_exists('opcache_get_status')||!function_exists('opcache_invalidate'))$ok=false;
 else{$status=@opcache_get_status(true);if(!is_array($status))$ok=false;
 elseif(isset($status['scripts']['/home/uat-admin.primehealers.com/public_html/bootstrap/cache/config.php']))
 $ok=$ok&&@opcache_invalidate('/home/uat-admin.primehealers.com/public_html/bootstrap/cache/config.php',true);}
}
http_response_code($ok?200:500);echo json_encode(['ok'=>$ok]);
'''.replace('__TOKEN__', token).replace('__INVALIDATE__', 'true' if invalidate else 'false')
    connection = None
    data = code.encode()
    try:
        need(not path.exists() and not path.is_symlink())
        atomic(path, data, UID, (APP / 'public').stat().st_gid, 0o644)
        context = ssl.create_default_context()
        connection = http.client.HTTPSConnection(HOST, 443, timeout=15, context=context)
        connection.sock = context.wrap_socket(socket.create_connection(('127.0.0.1', 443), timeout=15), server_hostname=HOST)
        connection.request('GET', '/' + path.name, headers={'Host': HOST, 'X-PHOS-Mail': token})
        response = connection.getresponse()
        result = json.loads(response.read(4096))
        need(response.status == 200 and result.get('ok') is True)
    finally:
        if connection is not None:
            connection.close()
        if path.exists() or path.is_symlink():
            regular(path)
            need(digest(path.read_bytes()) == digest(data))
            path.unlink()


def save_state(backup, state):
    atomic(backup / 'state.json', json.dumps(state, sort_keys=True).encode(), 0, 0, 0o600)


def make_backup(original, cached, changed, before):
    backup = Path(tempfile.mkdtemp(prefix=BACKUP_PREFIX, dir='/root'))
    os.chmod(backup, 0o700)
    state = {'version': 1, 'host': HOST, 'application': str(APP), 'database': DATABASE,
             'phase': 'backed_up', 'files': {}, 'new_environment_sha256': digest(changed),
             'original_mail_fingerprint': before['mail_fingerprint'],
             'protected_configuration_fingerprint': before['protected_configuration_fingerprint'],
             'cache_mutation_started': False}
    for name, entry in [('environment', original), ('config-cache', cached)]:
        state['files'][name] = {key: value for key, value in entry.items() if key != 'data'} if entry else None
        if entry:
            atomic(backup / (name + '.before'), entry['data'], 0, 0, 0o600)
    maintenance = json.dumps({'time': int(time.time()), 'retry': 60, 'status': 503, 'template': None,
                              'phos_mail_setup': secrets.token_hex(16)}).encode()
    atomic(backup / 'maintenance', maintenance, 0, 0, 0o600)
    state['maintenance_sha256'] = digest(maintenance)
    helper = Path(__file__).resolve().read_bytes()
    atomic(backup / 'rollback-helper.py', helper, 0, 0, 0o600)
    state['helper_sha256'] = digest(helper)
    save_state(backup, state)
    return backup, state


def ensure_maintenance(backup, state):
    path = APP / 'storage/framework/down'
    data = (backup / 'maintenance').read_bytes()
    need(digest(data) == state['maintenance_sha256'])
    if path.exists() or path.is_symlink():
        regular(path)
        need(digest(path.read_bytes()) == digest(data))
    else:
        atomic(path, data, UID, GID, 0o600)


def leave_maintenance(state):
    path = APP / 'storage/framework/down'
    regular(path)
    need(digest(path.read_bytes()) == state['maintenance_sha256'])
    path.unlink()


def restore_files(backup, state):
    failures = []
    # Restore source/cache first. No cache command, HTTP probe or maintenance
    # operation is allowed to block the actual file undo.
    for name, path in [('environment', APP / '.env'), ('config-cache', APP / 'bootstrap/cache/config.php')]:
        try:
            entry = state['files'][name]
            if name == 'environment':
                regular(path)
                current = file_signature(snapshot(path))
                allowed = [file_signature(entry)]
                if state.get('new_environment_signature'):
                    allowed.append(state['new_environment_signature'])
                    need(current in allowed)
                else:
                    # A signal may arrive after atomic replacement but before the
                    # new mtime can be recorded. Its reviewed bytes/owner/mode are
                    # still known; an unrelated original-file metadata edit is not.
                    own_new = {**file_signature(entry), 'sha256': state['new_environment_sha256'], 'mtime_ns': current['mtime_ns']}
                    need(current in allowed or (state['new_environment_sha256'] != entry['sha256'] and current == own_new))
            elif not state['cache_mutation_started']:
                require_unchanged(path, entry)
                continue
            else:
                # A failed/interrupted framework command can leave partial output,
                # but it can also race an administrator's edit. Unknown present
                # bytes have no verified ownership and must never be overwritten.
                allowed_cache = [None, file_signature(entry)]
                if state.get('new_cache_signature'):
                    allowed_cache.append(state['new_cache_signature'])
                need(file_signature(snapshot(path)) in allowed_cache)
            if entry is None:
                if path.exists() or path.is_symlink():
                    regular(path)
                    path.unlink()
                continue
            data = (backup / (name + '.before')).read_bytes()
            need(digest(data) == entry['sha256'])
            atomic(path, data, entry['uid'], entry['gid'], entry['mode'], times=(entry['atime_ns'], entry['mtime_ns']))
        except BaseException as exception:
            failures.append(type(exception).__name__)
    need(not failures)


def recover(backup, state):
    restored = False
    try:
        restore_files(backup, state)
        restored = True
        ensure_maintenance(backup, state)
        web_cache(True)
        before = inspect_configuration()
        need(before.get('identity_verified') is True and before.get('mail_fingerprint') == state['original_mail_fingerprint']
             and before.get('protected_configuration_fingerprint') == state['protected_configuration_fingerprint'])
        state['phase'] = 'rollback_complete'
        save_state(backup, state)
        leave_maintenance(state)
        print('ROLLBACK_COMPLETE=true ORIGINAL_MAIL_CONFIGURATION_RESTORED=true')
        return True
    except BaseException as exception:
        try:
            ensure_maintenance(backup, state)
        except BaseException:
            pass
        state['phase'] = 'rollback_uncertain'
        save_state(backup, state)
        print('ROLLBACK_COMPLETE=false ORIGINAL_FILES_RESTORED=' + str(restored).lower()
              + ' UAT_MAINTENANCE_REQUIRED=true ERROR_CLASS=' + type(exception).__name__)
        return False


def configure(values):
    need(not (APP / 'storage/framework/down').exists())
    before = inspect_configuration()
    need(before.get('identity_verified') is True and before.get('providers_loaded') == 0)
    original = snapshot(APP / '.env')
    cached = snapshot(APP / 'bootstrap/cache/config.php')
    changed = patch_environment(original['data'], values)
    valid = inspect_configuration({'candidate_b64': base64.b64encode(changed).decode(), 'expected': values})
    need(valid.get('candidate_valid') is True)
    web_cache(False)
    authenticate_once(values)
    print('SMTP_AUTHENTICATED=true EMAIL_SENT=false')
    require_unchanged(APP / '.env', original)
    require_unchanged(APP / 'bootstrap/cache/config.php', cached)
    backup, state = make_backup(original, cached, changed, before)
    print('BACKUP_DIRECTORY=' + str(backup))
    print('RECOVERY_COMMAND=python3 -B ' + str(backup / 'rollback-helper.py') + ' --rollback ' + str(backup))
    try:
        ensure_maintenance(backup, state)
        # Backup/maintenance creation can take time. Refuse a concurrent admin edit
        # at the actual activation boundary, including permission/mtime drift.
        require_unchanged(APP / '.env', original)
        require_unchanged(APP / 'bootstrap/cache/config.php', cached)
        atomic(APP / '.env', changed, original['uid'], original['gid'], original['mode'])
        state['new_environment_signature'] = file_signature(snapshot(APP / '.env'))
        state['phase'] = 'mail_environment_changed'
        save_state(backup, state)
        require_unchanged(APP / 'bootstrap/cache/config.php', cached)
        state['cache_mutation_started'] = True
        save_state(backup, state)
        artisan('config:clear')
        artisan('config:cache')
        after = inspect_configuration({'expected': values})
        need(after.get('configuration_cached') is True and after.get('identity_verified') is True
             and after.get('protected_configuration_fingerprint') == before['protected_configuration_fingerprint'])
        cache = snapshot(APP / 'bootstrap/cache/config.php')
        need(cache is not None and cache['uid'] == UID)
        state['new_cache_sha256'] = cache['sha256']
        state['new_cache_signature'] = file_signature(cache)
        save_state(backup, state)
        web_cache(True)
        need(digest((APP / '.env').read_bytes()) == digest(changed))
        state['phase'] = 'complete'
        save_state(backup, state)
        leave_maintenance(state)
        print('ZOHO_MAIL_CONFIGURATION_COMPLETE=true ENVIRONMENT=PHOS_UAT EMAIL_SENT=false PRODUCTION_CHANGED=false')
        print('MANUAL_FORGOT_PASSWORD_VERIFICATION_PENDING=true')
        return 0
    except BaseException as exception:
        print('CONFIGURATION_COMPLETE=false ERROR_CLASS=' + type(exception).__name__)
        recover(backup, state)
        return 2


def prompt_values():
    warnings.simplefilter('error', getpass.GetPassWarning)
    # A terminal cannot seek. Separate streams avoid BufferedRandom's seek
    # requirement for r+ while keeping all input on the controlling terminal.
    with open('/dev/tty', 'r', encoding='utf-8') as terminal_input, \
            open('/dev/tty', 'w', encoding='utf-8', buffering=1) as terminal_output:
        need(terminal_input.isatty() and terminal_output.isatty())
        terminal_output.write('Copy the exact SMTP hostname from this Zoho account Server Configuration Details: ')
        terminal_output.flush()
        host = terminal_input.readline().rstrip('\n')
        terminal_output.write('Port (465 for SMTPS or 587 for STARTTLS): ')
        terminal_output.flush()
        port = terminal_input.readline().rstrip('\n')
        need(port in ('465', '587'))
        terminal_output.write('Sender display name [Prime Healers]: ')
        terminal_output.flush()
        name = terminal_input.readline().rstrip('\n') or 'Prime Healers'
        password = getpass.getpass('Zoho app-specific password (hidden; never paste into chat): ', stream=terminal_output)
    return settings(host, int(port), password, name)


def load_recovery(backup):
    need(backup.is_absolute() and backup.parent == Path('/root') and backup.name.startswith(BACKUP_PREFIX))
    no_links(backup)
    need(backup.is_dir() and backup.stat().st_uid == 0 and stat.S_IMODE(backup.stat().st_mode) == 0o700)
    for path in (backup / 'state.json', backup / 'rollback-helper.py', backup / 'maintenance'):
        regular(path)
        need(path.stat().st_uid == 0 and stat.S_IMODE(path.stat().st_mode) == 0o600 and path.stat().st_size <= 1048576)
    state = json.loads((backup / 'state.json').read_bytes())
    need(state.get('version') == 1 and state.get('host') == HOST and state.get('application') == str(APP)
         and state.get('database') == DATABASE and digest(Path(__file__).resolve().read_bytes()) == state.get('helper_sha256'))
    for key in ('new_environment_sha256', 'original_mail_fingerprint', 'protected_configuration_fingerprint', 'maintenance_sha256'):
        need(isinstance(state.get(key), str) and re.fullmatch(r'[0-9a-f]{64}', state[key]))
    if 'new_cache_sha256' in state:
        need(isinstance(state['new_cache_sha256'], str) and re.fullmatch(r'[0-9a-f]{64}', state['new_cache_sha256']))
    need(isinstance(state.get('files'), dict) and set(state['files']) == {'environment', 'config-cache'})
    need(type(state.get('cache_mutation_started')) is bool)
    for key, hash_key in [('new_environment_signature', 'new_environment_sha256'), ('new_cache_signature', 'new_cache_sha256')]:
        if key not in state:
            continue
        signature = state[key]
        need(isinstance(signature, dict) and set(signature) == {'sha256','uid','gid','mode','mtime_ns'}
             and signature['sha256'] == state.get(hash_key) and signature['uid'] == UID
             and type(signature['gid']) is int and signature['gid'] >= 0
             and type(signature['mtime_ns']) is int and signature['mode'] in (0o600, 0o640, 0o644))
        if key == 'new_environment_signature':
            need(signature['gid'] == GID and signature['mode'] in (0o600, 0o640))
    for name, entry in state['files'].items():
        if entry is None:
            need(name == 'config-cache')
            continue
        need(isinstance(entry, dict) and set(entry) == {'sha256','uid','gid','mode','atime_ns','mtime_ns'}
             and isinstance(entry['sha256'], str) and re.fullmatch(r'[0-9a-f]{64}', entry['sha256'])
             and entry['uid'] == UID and type(entry['gid']) is int and entry['gid'] >= 0
             and type(entry['atime_ns']) is int and type(entry['mtime_ns']) is int)
        if name == 'environment':
            need(entry['gid'] == GID and entry['mode'] in (0o600, 0o640))
        else:
            need(entry['mode'] in (0o600, 0o640, 0o644))
        path = backup / (name + '.before')
        regular(path)
        need(path.stat().st_uid == 0 and stat.S_IMODE(path.stat().st_mode) == 0o600
             and path.stat().st_size <= 4194304 and digest(path.read_bytes()) == entry['sha256'])
    return state


def interrupted(signum, frame):
    raise InterruptedError()


def main():
    try:
        parser = argparse.ArgumentParser(description='Configure only the authorized PHOS UAT Zoho mailbox; sends no mail.')
        parser.add_argument('--rollback', type=Path)
        args = parser.parse_args()
        load_target()
        lock_path = Path('/root/phos-zoho-mail-uat.lock')
        no_links(lock_path)
        descriptor = os.open(lock_path, os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
        with os.fdopen(descriptor, 'wb') as lock:
            need(os.fstat(lock.fileno()).st_uid == 0 and stat.S_IMODE(os.fstat(lock.fileno()).st_mode) == 0o600)
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            signal.signal(signal.SIGTERM, interrupted)
            signal.signal(signal.SIGHUP, interrupted)
            if args.rollback:
                return 0 if recover(args.rollback, load_recovery(args.rollback)) else 2
            inspect_configuration()
            return configure(prompt_values())
    except BaseException as exception:
        if isinstance(exception, SystemExit):
            return exception.code
        print('ZOHO_MAIL_CONFIGURATION_COMPLETE=false ERROR_CLASS=' + type(exception).__name__)
        return 2


if __name__ == '__main__':
    sys.exit(main())
