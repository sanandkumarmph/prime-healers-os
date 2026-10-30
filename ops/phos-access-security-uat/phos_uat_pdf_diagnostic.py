#!/usr/bin/env python3
"""Prepared diagnostic only: fixed PHOS UAT, private synthetic PDF, no activation."""
import hashlib
import json
import os
from pathlib import Path
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
    need(value['diagnostic'] == 'PHOS_UAT_PDF_RUNTIME_PRIVATE' and value['status'] == 'refused'
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
    vhost_info = regular(vhost, 1048576)
    need(vhost_info.st_uid == 0 and stat.S_IMODE(vhost_info.st_mode) & 0o022 == 0)
    need(re.search(r'(?im)^\s*docRoot\s+\$VH_ROOT/public_html/public\s*$', vhost.read_text()) is not None)
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


def safe_pdf_paths(value):
    need(isinstance(value, dict) and set(value) == {'temp', 'profile', 'environment'})
    need(isinstance(value['environment'], dict) and set(value['environment']).issubset(
        {'HOME', 'TMPDIR', 'TMP', 'TEMP', 'XDG_CONFIG_HOME', 'XDG_CACHE_HOME', 'XDG_RUNTIME_DIR'}))
    for candidate in [value['temp'], value['profile'], *value['environment'].values()]:
        need(isinstance(candidate, str) and re.fullmatch(r'/[A-Za-z0-9_./-]{1,400}', candidate) is not None)
        path = Path(candidate)
        need('..' not in path.parts and path.is_relative_to(APP / 'storage'))
        no_links(path)
    return value


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


NODE_PROBE = r'''
import fs from 'node:fs';import path from 'node:path';import {createRequire} from 'node:module';import {pathToFileURL} from 'node:url';import {spawn} from 'node:child_process';
const input=JSON.parse(fs.readFileSync(0,'utf8'));let browser,child,launchTimer;let phase='module';
const result={launch:false,pdf:false,sandbox_status:'unconfirmed',namespace_sandbox:null,seccomp_sandbox:null,external_requests:0};
const safeError=e=>['Error','TimeoutError','ProtocolError','TargetCloseError','SyntaxError','TypeError','RangeError'].includes(e?.name)?e.name:'ProbeError';
const forbidden=arg=>{const key=arg.replace(/^-+/,'').split('=')[0].toLowerCase();return key.includes('sandbox')||['single-process','no-zygote'].includes(key);};
try{
 const root=fs.realpathSync(input.modules);const require=createRequire(path.join(root,'__phos_probe__.js'));const entry=fs.realpathSync(require.resolve('puppeteer',{paths:[root]}));
 if(!entry.startsWith(root+path.sep))throw new Error();result.module_entry=entry;
 const imported=await import(pathToFileURL(entry).href);const puppeteer=imported.default||imported;
 phase='launch';let args=await puppeteer.defaultArgs({headless:true,userDataDir:path.join(input.directory,'profile')});
 args=args.filter(a=>!a.startsWith('--remote-debugging-')&&(!input.sandbox||!forbidden(a)));
 args.push(...input.arguments,'--remote-debugging-pipe','--disable-background-networking','--disable-component-update','--disable-default-apps','--disable-sync','--no-first-run','--no-default-browser-check','--disable-domain-reliability','--safebrowsing-disable-auto-update','--disable-breakpad','--disable-crash-reporter','--disable-extensions');
 result.sandbox_disabling_flags=args.some(forbidden);if(input.sandbox&&result.sandbox_disabling_flags)throw new Error();
 // Spawn explicitly without detaching: every Chrome child stays in the dedicated
 // namespace worker process group, including during a supervisor timeout.
 child=spawn(input.browser,args,{cwd:input.directory,env:process.env,detached:false,stdio:['ignore','ignore','pipe','pipe','pipe']});
 let incoming='';const transport={onmessage:null,onclose:null,send(message){child.stdio[3].write(message+'\0');},close(){child.stdio[3].end();}};
 child.stdio[4].on('data',chunk=>{incoming+=chunk.toString();let end;while((end=incoming.indexOf('\0'))>=0){const message=incoming.slice(0,end);incoming=incoming.slice(end+1);transport.onmessage?.(message);}});
 const closeTransport=()=>transport.onclose?.();child.once('exit',closeTransport);child.once('error',closeTransport);child.stdio[3].on('error',closeTransport);child.stdio[4].on('error',closeTransport);child.stderr.resume();
 browser=await Promise.race([puppeteer.connect({transport,protocolTimeout:12000}),new Promise((_,reject)=>{launchTimer=setTimeout(()=>reject(new Error()),20000);})]);clearTimeout(launchTimer);
 result.launch=true;result.browser_version=(await browser.version()).replace(/[^A-Za-z0-9./ -]/g,'').slice(0,80);
 const prepare=async(page,internal=false)=>{await page.setJavaScriptEnabled(internal);await page.setRequestInterception(true);page.on('request',request=>{if(/^(about:|data:)/.test(request.url())||(internal&&/^chrome:\/\/(?:sandbox|version|resources|theme)\//.test(request.url())))request.continue();else{result.external_requests++;request.abort();}});};
 const page=await browser.newPage();await prepare(page);
 if(input.sandbox){let statusPage;try{statusPage=await browser.newPage();await prepare(statusPage,true);await statusPage.goto('chrome://sandbox/',{waitUntil:'domcontentloaded',timeout:8000});await statusPage.waitForFunction(()=>document.querySelectorAll('tr').length>0,{timeout:2500});const rows=await statusPage.evaluate(()=>[...document.querySelectorAll('tr')].map(row=>[...row.querySelectorAll('td,th')].map(cell=>cell.textContent.trim())));
 const status=key=>{const row=rows.find(row=>key.test(row[0]||''));return row?(/^yes$/i.test(row[1]||'')?true:/^no$/i.test(row[1]||'')?false:null):null;};
 result.namespace_sandbox=status(/^(PID |User |Network )?namespaces?$|namespace sandbox/i);result.seccomp_sandbox=status(/^seccomp-bpf sandbox$/i);
 await statusPage.goto('chrome://version/',{waitUntil:'domcontentloaded',timeout:8000});await statusPage.waitForFunction(()=>Boolean(document.querySelector('#command_line')?.textContent),{timeout:2500});const actual=await statusPage.evaluate(()=>document.querySelector('#command_line')?.textContent||'');
 result.actual_sandbox_flags_absent=actual!==''&&!actual.split(/\s+/).some(forbidden);
 if(result.namespace_sandbox===true&&result.seccomp_sandbox===true&&result.actual_sandbox_flags_absent===true)result.sandbox_status='verified';}catch{result.sandbox_status='unconfirmed';}finally{if(statusPage)await statusPage.close().catch(()=>{});}}
 phase='render';await page.setContent('<!doctype html><html><head><meta charset="utf-8"><style>body{font:14px sans-serif;color:#123}h1{font-size:20px}</style></head><body><h1>PHOS synthetic PDF runtime probe</h1><p>Fixed non-business diagnostic content.</p></body></html>',{waitUntil:'domcontentloaded',timeout:8000});
 const pdf=await page.pdf({format:'A4',printBackground:true,timeout:12000});const bytes=Buffer.from(pdf);result.pdf=bytes.subarray(0,5).toString()==='%PDF-'&&bytes.length>=500&&bytes.length<=2097152;result.pdf_bytes=bytes.length;
 const matches=bytes.toString('latin1').match(/\/Type\s*\/Page\b/g);result.page_object_count=matches?.length??null;
 fs.writeFileSync(path.join(input.directory,'synthetic.pdf'),bytes,{mode:0o600,flag:'wx'});
}catch(error){result.error_class=safeError(error);result.error_phase=phase;}
finally{clearTimeout(launchTimer);if(browser){try{await browser.close();}catch{}}if(child&&child.exitCode===null)child.kill('SIGTERM');}
process.stdout.write(JSON.stringify(result));process.exitCode=result.pdf?0:2;
'''


def safe_json(data):
    need(len(data) <= MAX_CAPTURE)
    value = json.loads(data)
    need(isinstance(value, dict))
    return value


def validate_probe_result(value, modules):
    allowed = {'launch', 'pdf', 'sandbox_status', 'namespace_sandbox', 'seccomp_sandbox', 'external_requests',
               'module_entry', 'sandbox_disabling_flags', 'actual_sandbox_flags_absent', 'browser_version',
               'pdf_bytes', 'page_object_count', 'error_class', 'error_phase'}
    need(isinstance(value, dict) and set(value).issubset(allowed))
    for key in ('launch', 'pdf', 'sandbox_disabling_flags', 'actual_sandbox_flags_absent'):
        if key in value:
            need(type(value[key]) is bool)
    for key in ('namespace_sandbox', 'seccomp_sandbox'):
        if key in value:
            need(value[key] is None or type(value[key]) is bool)
    need(value.get('sandbox_status') in ('verified', 'unconfirmed'))
    need(type(value.get('external_requests')) is int and 0 <= value['external_requests'] <= 10000)
    if 'pdf_bytes' in value:
        need(type(value['pdf_bytes']) is int and 0 <= value['pdf_bytes'] <= 2097152)
    if 'page_object_count' in value:
        need(value['page_object_count'] is None or (type(value['page_object_count']) is int and 0 <= value['page_object_count'] <= 10))
    if 'error_class' in value:
        need(value['error_class'] in ('Error', 'TimeoutError', 'ProtocolError', 'TargetCloseError', 'SyntaxError', 'TypeError', 'RangeError', 'ProbeError'))
    if 'error_phase' in value:
        need(value['error_phase'] in ('module', 'launch', 'render'))
    if 'browser_version' in value:
        need(isinstance(value['browser_version'], str) and re.fullmatch(r'[A-Za-z ]{1,40}/[0-9.]{1,35}', value['browser_version']) is not None)
    if 'module_entry' in value:
        entry = Path(value['module_entry'])
        need(entry.is_relative_to(modules))
        no_links(entry)
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
    need(cfg.get('standard_paths') == {key: str(APP / value) for key, value in STANDARD_PATHS.items()})
    report = {'strict_network_isolation': True, 'website_owner_execution': True, 'providers_loaded': 0,
              'configuration_cached': cfg['configuration_cached'], 'standard_paths': cfg['standard_paths'],
              'configured_pdf_paths': safe_pdf_paths(cfg['pdf_paths']), 'browser_selection': cfg['browser_selection']}
    report['nodes'] = {}
    phase('node')
    configured = cfg['node']
    need(configured in ('node', 'nodejs') or (isinstance(configured, str) and configured.startswith('/')))
    for label, candidate in [('system', 'node'), ('configured', configured)]:
        try:
            located = shutil.which(candidate, path=SYSTEM_PATH)
            need(located is not None)
            resolved = trusted_executable(located)
            value = version([resolved, '--version'], root, r'v\d+\.\d+\.\d+')
            report['nodes'][label] = {'requested': candidate, 'resolved': resolved, 'version': value, 'trusted': True}
        except Exception:
            report['nodes'][label] = {'available': False, 'error_class': 'UntrustedOrUnavailableExecutable'}
    need(report['nodes']['configured'].get('version') is not None)
    node = report['nodes']['configured']['resolved']
    current = report['nodes']['configured']['version']
    report['node_22_security_patch_target'] = 'v22.23.4'
    report['puppeteer_25_node_minimum_met'] = tuple(map(int, current[1:].split('.'))) >= (22, 12, 0)
    report['node_22_patch_needed'] = tuple(map(int, current[1:].split('.'))) < (22, 23, 4) if current.startswith('v22.') else None
    phase('modules')
    modules = Path(cfg['modules'])
    need(modules == APP / 'node_modules')
    no_links(modules)
    need(modules.is_dir())
    report['module_root'] = str(modules)
    report['packages'] = {}
    for package in ('puppeteer', 'puppeteer-core'):
        metadata = modules / package / 'package.json'
        regular(metadata, 65536)
        value = json.loads(metadata.read_bytes())
        need(value.get('name') == package and re.fullmatch(r'\d+\.\d+\.\d+(?:[-+.][A-Za-z0-9.-]+)?', value.get('version', '')))
        report['packages'][package] = value['version']
    browser_requested = cfg['browser']
    phase('browser')
    need(isinstance(browser_requested, str))
    browser = trusted_executable(browser_requested)
    with Path(browser).open('rb') as executable:
        wrapper = executable.read(2) == b'#!'
    report['browser'] = {'requested': browser_requested, 'resolved': browser, 'trusted': True, 'wrapper_script': wrapper,
                         'version': version([browser, '--version'], root, r'(?:Google Chrome|Chromium|Chrome for Testing)[ A-Za-z]*\d+\.\d+\.\d+\.\d+')}
    need(report['browser']['version'] is not None)
    report['composer'] = {'available': False}
    phase('composer')
    for candidate in ('/usr/local/bin/composer', '/usr/bin/composer', '/usr/local/bin/composer.phar'):
        if not Path(candidate).exists():
            continue
        try:
            composer = trusted_executable(candidate, executable=False)
            value = version([payload['php'], composer, '--no-plugins', '--no-scripts', '--version', '--no-ansi'], root,
                            r'Composer version \d+\.\d+\.\d+(?:[-.][A-Za-z0-9.]+)?')
            report['composer'] = {'available': value is not None, 'resolved': composer, 'php': payload['php'], 'version': value}
        except Exception:
            report['composer'] = {'available': False, 'error_class': 'UntrustedOrUnavailableExecutable'}
        break
    for variant in ('sandbox', 'current_configuration_control'):
        if variant != 'sandbox' and (report['sandbox'].get('pdf') is True or not cfg['disable_sandbox']):
            report[variant] = {'attempted': False}
            break
        phase('sandbox' if variant == 'sandbox' else 'control')
        directory = root / variant
        directory.mkdir(mode=0o700)
        # Stop Puppeteer's executable configuration search at the private CWD.
        with (directory / 'package.json').open('x') as package:
            package.write(json.dumps({'puppeteer': {'skipDownload': True,
                'cacheDirectory': str(root / 'puppeteer-cache'), 'temporaryDirectory': str(root / 'tmp')}}))
        args = safe_arguments(cfg['arguments'], control=variant != 'sandbox')
        if variant != 'sandbox' and cfg['disable_sandbox']:
            args = list(dict.fromkeys(args + ['--no-sandbox', '--disable-setuid-sandbox']))
        code, output = bounded_run([node, '--input-type=module', '-e', NODE_PROBE], root,
            cwd=directory, timeout=45, content=json.dumps({'modules': str(modules), 'browser': browser,
            'directory': str(directory), 'sandbox': variant == 'sandbox', 'arguments': args}).encode())
        result = validate_probe_result(safe_json(output), modules)
        need(code in (0, 2))
        report[variant] = {'attempted': True, **result}
        directory_size(root)
    phase('unchanged')
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
    report = {'diagnostic': 'PHOS_UAT_PDF_RUNTIME_PRIVATE', 'status': 'refused', 'application_configuration_mutation_requested': False,
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
        print(json.dumps({'diagnostic': 'PHOS_UAT_PDF_RUNTIME_PRIVATE', 'status': 'refused',
                          'strict_network_isolation': NETWORK_ISOLATED, 'website_owner_execution': OWNER_DROPPED,
                          'network_fallback_attempted': False, 'phase': PHASE,
                          'error_class': safe_error(exception)}, sort_keys=True))
        return 2


if __name__ == '__main__':
    sys.exit(main())
