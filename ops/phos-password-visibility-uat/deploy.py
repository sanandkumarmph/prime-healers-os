#!/usr/bin/env python3
"""Checksum-pinned PHOS password-visibility UAT rollout. Never runs migrations."""

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import shutil
import stat
import subprocess
import sys
import tempfile
import zipfile

try:
    import fcntl
    import pwd
except ImportError:  # Package verification is also supported on Windows.
    fcntl = pwd = None


BASELINE = "7e81f3fb20eea99fe13f19f48208f911b8369b56"
HOST = "uat-admin.primehealers.com"
DATABASE = "uata_primehealersos"
APP = Path("/home/uat-admin.primehealers.com/public_html")
WEB = APP / "public"
CONFIG = Path("/root/phos-uat-pr8-backup-o46ztayl/target-config.json")
BACKUP_PREFIX = "phos-password-visibilitys-backup-"
SYSTEM_PATH = "/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin"
BASELINE_HASHES = {'resources/views/auth/login.blade.php': 'd696c10244cf598fe348d365e0515e125689ce604e8ad48b2987a878dd74efa6',
 'resources/views/auth/reset-password.blade.php': '8bc47fe128deb801490256b4475042c0794e03117e87e03312fed83458277d93',
 'resources/views/components/password-input.blade.php': None}
PRESERVED_SOURCE_HASHES = {'app/Http/Controllers/Auth/NewPasswordController.php': '1b42e7ff700e6dfbcba661bfbe433ec364006264b89d14245c7008ab4e597451',
 'app/Http/Controllers/Auth/PasswordResetLinkController.php': 'f869c86114a7af5b4e40fdc2706c4456de9d0bba6a1d1f5e6b31bdba9dd251ba',
 'app/Http/Controllers/KnowledgeController.php': 'ce5ca7bd9ab38d5b98011fe3ba716f43d12829b74fe23ba5188d4179634d2225',
 'app/Http/Controllers/RentalController.php': 'e9848e19bcc3c268b191db12d1c4d835b36d684798478e0f7e1777399c0e8788',
 'app/Http/Middleware/PasswordRecoveryHeaders.php': '55f2f1a6ff3aedf59576cee9036380942902ca6558a93de7eabb11aec39b429b',
 'app/Providers/AppServiceProvider.php': '05ef2af6f22b7d14318f2058acae4ace97bf873d90ad58bddfe918ae26fd3a2a',
 'app/Services/Auth/PasswordRecovery.php': '564f9c4e05e650fc7563dacd543ee969709703801675b9785c5e254140734069',
 'app/Services/Auth/RevokeAccountSessions.php': '824adb9b3e83374827a0bf5a4202db1097c988dd48cfb133ccdcb240d3020ec8',
 'config/mail.php': 'bf3eb69073f18c28f4d2bfb238c079714fcf7ab42863b5aa95ade9b403370e1d',
 'public/css/phos-executive-dashboard.css': '9f19e7fc59140e630985ef6892a6d313697c9e013d06b0da46d33f661ff64658',
 'public/css/phos-operations.css': 'b0db2b8ddf52b3e6192201800aad541190b2007ebd061890a71383957d1f0559',
 'public/js/phos-executive-dashboard.js': '3c21ac7e24d8fa77d86bf80d44d1c768440467c8ae0b80eca0f8b6d37775a202',
 'public/js/phos-operations.js': '1d1ab8fc6191307946c21dddebf2585b5612949fc69711f6ab87cd6adf428227',
 'resources/views/auth/forgot-password.blade.php': '99466f7894b628ae52cae9e377458788ad3829bbf9fdab0fe75a8b652c923125',
 'resources/views/business-partners/index.blade.php': '95b718a492f59061c40889385c1ba95e6bf9d62706e52e422051b8291835ba8c',
 'resources/views/communications/index.blade.php': 'aa011618d9e5a747c7d8a01690da39e2b05b46963bbdd9eb71f1fd502971b223',
 'resources/views/components/phos-action-icon.blade.php': 'd658a7e06908d6a2d060f3e903e4064657ea798e181f889d71cb411e67a3b212',
 'resources/views/components/phos-list-filter.blade.php': 'bd7eac9596e7b2fd861c054b0095634c7648855fcde7076bd5bb903a0c368e3e',
 'resources/views/customers/index.blade.php': 'c1086d0250488430989d1f4d0d5cc102ba4a2c2c9007b2a135133199ad362fbc',
 'resources/views/dashboard.blade.php': '1e13670a25ea3acc1d9031e5531b01e37478be823108057ff46b89bfbdfeefbe',
 'resources/views/dashboard/executive-navigation.blade.php': 'ebbb5569a125a3a3fe411d168fea5b063ad84da845018ae413f0d86cfdf5c452',
 'resources/views/dashboard/overview-summary.blade.php': '4793eccbf9adb8786f3aa027266c34ec7969ee6a264c922c96b60ef3cd9f6b8c',
 'resources/views/deliveries/index.blade.php': '5da8e962a22b581ed76478376d63a58b8f430f88fda3857ea816fc037f82b732',
 'resources/views/knowledge/index.blade.php': 'c34185e3fbfadabd424d84f3a85d6d197e41d62d254cb5b1d2ff70f2931dd022',
 'resources/views/layouts/app.blade.php': 'e696febb18b904b68a227fcd8908bb9bd2b33b5957e7f9fc411dd3da9c2773f2',
 'resources/views/pickups/index.blade.php': '50da4a589de8c734c84bf710da683e32862e790e1d040cb59a32008b3f8f5102',
 'resources/views/renewals/index.blade.php': '6bc20d1974b9bbe2b1fa59658b56ebe866e8c48a4e22123a417e1a5d01074ae2',
 'resources/views/rentals/index.blade.php': 'f30e8ea7454ef068e17004582d00a21188af43755e4bdae79bcab9d8562546bd',
 'resources/views/sales/index.blade.php': 'e45c5e7cbbe171448a802b544a9270307e08abca2ad599400630e44bf3a52eca',
 'routes/auth.php': '7e29faeadfc2d125b3ca8ddf18ec91056964d066de94fea0ab14055eff8599fa'}
MAX_FILE = 3 * 1024 * 1024
MAX_ARCHIVE = 12 * 1024 * 1024
LOG = None
OWNER = PHP = None
UID = GID = None


class Refused(RuntimeError):
    pass


def need(condition, message):
    if not condition:
        raise Refused(message)


def digest(data):
    return hashlib.sha256(data).hexdigest()


def valid_hash(value, length=64):
    return isinstance(value, str) and re.fullmatch("[0-9a-f]{%d}" % length, value) is not None


def unique_object(pairs):
    value = {}
    for key, item in pairs:
        need(key not in value, "Duplicate JSON object key refused.")
        value[key] = item
    return value


def read_json(data):
    return json.loads(data, object_pairs_hook=unique_object)


def regular(path):
    need(path.is_file() and not path.is_symlink(), "Expected a regular, unlinked file: " + str(path))


def no_links(path):
    for item in (path, *path.parents):
        need(not item.is_symlink(), "Linked destination or ancestor refused: " + str(item))


def safe_relative(name):
    need(isinstance(name, str) and name in BASELINE_HASHES, "Path outside reviewed password-visibility allowlist.")
    item = PurePosixPath(name)
    need(not item.is_absolute() and ".." not in item.parts and "\\" not in name,
         "Unsafe package path.")
    return name


def load_package(manifest_path, archive_path, manifest_sha, archive_sha, release):
    need(valid_hash(release, 40) and release != BASELINE, "An exact new release commit is required.")
    need(valid_hash(manifest_sha) and valid_hash(archive_sha), "Exact package SHA256 values are required.")
    regular(manifest_path)
    regular(archive_path)
    need(manifest_path.stat().st_size <= 65536 and archive_path.stat().st_size <= MAX_ARCHIVE,
         "Package exceeds size limits.")
    raw = manifest_path.read_bytes()
    archive = archive_path.read_bytes()
    need(digest(raw) == manifest_sha and digest(archive) == archive_sha, "Package checksum mismatch.")
    manifest = read_json(raw)
    need(isinstance(manifest, dict) and set(manifest) == {
        "format", "environment", "baseline", "release", "archive_sha256", "files"
    }, "Unexpected manifest schema.")
    need(manifest["format"] == 1 and manifest["environment"] == "PHOS_UAT"
         and manifest["baseline"] == BASELINE and manifest["release"] == release
         and manifest["archive_sha256"] == archive_sha, "Manifest release or target identity mismatch.")
    need(isinstance(manifest["files"], list) and len(manifest["files"]) == len(BASELINE_HASHES),
         "Manifest must include exactly the reviewed password-visibility paths.")
    records = {}
    for entry in manifest["files"]:
        need(isinstance(entry, dict) and set(entry) == {"path", "sha256", "baseline_sha256", "size"},
             "Unexpected file manifest schema.")
        name = safe_relative(entry["path"])
        need(name not in records and valid_hash(entry["sha256"]), "Duplicate path or invalid file hash.")
        need(entry["baseline_sha256"] == BASELINE_HASHES[name], "Baseline hash differs from embedded reviewed source.")
        need(type(entry["size"]) is int and 0 < entry["size"] <= MAX_FILE, "Invalid release file size.")
        records[name] = entry
    need(set(records) == set(BASELINE_HASHES), "Missing reviewed release path.")
    payload = {}
    with zipfile.ZipFile(archive_path) as package:
        entries = package.infolist()
        need(len(entries) == len(records), "Unexpected archive entry count.")
        for entry in entries:
            name = safe_relative(entry.filename)
            kind = stat.S_IFMT(entry.external_attr >> 16)
            need(name not in payload and not entry.is_dir() and kind in (0, stat.S_IFREG)
                 and not (entry.flag_bits & 1), "Duplicate, linked, encrypted or non-file archive entry.")
            need(entry.file_size == records[name]["size"] and entry.file_size <= MAX_FILE,
                 "Archive file size mismatch.")
            data = package.read(entry)
            need(len(data) == entry.file_size and digest(data) == records[name]["sha256"],
                 "Release file checksum mismatch: " + name)
            payload[name] = data
    need(set(payload) == set(records), "Archive and manifest path sets differ.")
    return manifest, payload


def note(message):
    print(message, flush=True)
    if LOG:
        with LOG.open("a", encoding="utf-8") as output:
            output.write(message + "\n")


def execute(args, *, owner=False, cwd=None, input_data=None, timeout=90, allowed=(0,)):
    if owner:
        account = pwd.getpwnam(OWNER)
        args = ["runuser", "-u", OWNER, "--", "env", "-i", "PATH=" + SYSTEM_PATH,
                "HOME=" + account.pw_dir, "LANG=C.UTF-8"] + list(args)
    process = subprocess.run(args, cwd=str(cwd) if cwd else None,
                             env={"PATH": SYSTEM_PATH, "LANG": "C.UTF-8"},
                             input=input_data, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                             timeout=timeout, check=False)
    if LOG:
        with LOG.open("ab") as output:
            output.write(process.stdout)
            output.write(process.stderr)
    need(process.returncode in allowed, "A runtime command failed; inspect the private deployment log.")
    return process.stdout


def php(code):
    return execute([PHP, "-d", "opcache.enable_cli=0", "-d", "opcache.jit=0", "-r", code],
                   owner=True, cwd=APP, timeout=120)


def artisan(command, *args):
    need(command in {"down", "up", "view:clear", "view:cache"}, "Unreviewed application command refused.")
    execute([PHP, "-d", "opcache.enable_cli=0", "-d", "opcache.jit=0", "-d", "memory_limit=512M",
             "artisan", command, *args, "--no-interaction"], owner=True, cwd=APP, timeout=240)


def assert_target_values(value):
    need(isinstance(value, dict) and value.get("host") == HOST
         and value.get("application") == str(APP) and value.get("database") == DATABASE,
         "Private configuration does not identify this exact PHOS UAT target.")
    need(re.fullmatch(r"[a-z_][a-z0-9_-]*", value.get("owner", ""))
         and value["owner"] != "root", "Unexpected UAT website owner.")
    executable = value.get("php", "")
    need(isinstance(executable, str) and executable.startswith("/usr/local/lsws/")
         and executable.endswith("/bin/php"), "Unexpected UAT PHP path.")
    return value["owner"], executable


def load_target():
    global OWNER, PHP, UID, GID
    need(sys.platform.startswith("linux") and pwd is not None and fcntl is not None and os.geteuid() == 0,
         "Deployment and recovery require the Linux root operator terminal.")
    need(APP == Path("/home/uat-admin.primehealers.com/public_html")
         and HOST == "uat-admin.primehealers.com" and DATABASE == "uata_primehealersos",
         "Only the fixed PHOS UAT target is allowed.")
    no_links(APP)
    need(APP.is_dir() and WEB.is_dir(), "PHOS UAT application layout is missing.")
    if CONFIG.exists() or CONFIG.is_symlink():
        no_links(CONFIG)
        regular(CONFIG)
        for item in (CONFIG, CONFIG.parent):
            info = item.stat()
            need(info.st_uid == 0 and stat.S_IMODE(info.st_mode) & 0o077 == 0,
                 "Existing target configuration must remain root-private.")
        OWNER, PHP = assert_target_values(read_json(CONFIG.read_bytes()))
    else:
        OWNER = pwd.getpwuid(APP.stat().st_uid).pw_name
        PHP = "/usr/local/lsws/lsphp82/bin/php"
        assert_target_values({"host": HOST, "application": str(APP), "database": DATABASE,
                              "owner": OWNER, "php": PHP})
    account = pwd.getpwnam(OWNER)
    UID, GID = account.pw_uid, APP.stat().st_gid
    need(UID != 0 and APP.stat().st_uid == UID, "UAT application owner differs from private target.")
    need(Path(PHP).is_file() and str(Path(PHP).resolve()).startswith("/usr/local/lsws/"),
         "Verified LiteSpeed PHP executable is unavailable.")
    for directory in (WEB, APP / "storage", APP / "bootstrap/cache"):
        no_links(directory)
        need(directory.is_dir() and directory.stat().st_uid == UID, "Unexpected UAT directory ownership.")
    for path in (APP / ".env", APP / "vendor/autoload.php", WEB / "index.php"):
        no_links(path)
        regular(path)
    vhost = Path("/usr/local/lsws/conf/vhosts") / HOST / "vhost.conf"
    regular(vhost)
    need(re.search(r"(?im)^\s*docRoot\s+\$VH_ROOT/public_html/public\s*$", vhost.read_text()),
         "UAT document root differs from the inspected layout.")
    for tool in ("runuser", "curl"):
        need(shutil.which(tool, path=SYSTEM_PATH), "A required server utility is missing.")
    identity = read_json(php(r'''
require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
$connection=$app['db']->connection();$db=$connection->getConfig();
echo json_encode(['environment'=>$app->environment(),'host'=>parse_url(config('app.url'),PHP_URL_HOST),
 'driver'=>$db['driver']??null,'database'=>$connection->getPdo()->query('SELECT DATABASE()')->fetchColumn(),
 'dbhost'=>$db['host']??null,'split_connections'=>!empty($db['read'])||!empty($db['write']),
 'php_ok'=>PHP_MAJOR_VERSION===8&&PHP_MINOR_VERSION===2,'php'=>PHP_VERSION,
 'maintenance'=>config('app.maintenance.driver','file')],JSON_THROW_ON_ERROR);
'''))
    need(identity.get("environment") == "uat" and identity.get("host") == HOST
         and identity.get("driver") == "mysql" and identity.get("database") == DATABASE
         and identity.get("dbhost") in ("localhost", "127.0.0.1", "::1")
         and identity.get("split_connections") is False
         and identity.get("php_ok") is True and identity.get("maintenance") == "file",
         "Application runtime does not match the exact PHOS UAT identity/PHP/file-maintenance configuration.")
    note("PHOS_UAT_IDENTITY_VERIFIED PHP=" + identity["php"])


def verify_baseline(root):
    for name, expected in BASELINE_HASHES.items():
        path = root / name
        verify_parents(path, root)
        if expected is None:
            need(not path.exists(), "New password-visibility destination already exists: " + name)
        else:
            regular(path)
            need(digest(path.read_bytes()) == expected, "Reviewed password-form baseline source differs: " + name)
    verify_preserved_source(root)


def release_installed(root, payload):
    for name, data in payload.items():
        path = root / name
        no_links(path)
        if not path.is_file() or digest(path.read_bytes()) != digest(data):
            return False
    return True



def verify_preserved_source(root):
    for name, expected in PRESERVED_SOURCE_HASHES.items():
        path = root / name
        no_links(path)
        regular(path)
        need(digest(path.read_bytes()) == expected, "Existing c0f dashboard/Knowledge source changed: " + name)


def preserved_assets():
    for name in ("public/css/phos-executive-dashboard.css", "public/js/phos-executive-dashboard.js"):
        for local in (True, False):
            status, data, _ = http("/" + name.removeprefix("public/"), local)
            need(status == "200" and digest(data) == PRESERVED_SOURCE_HASHES[name],
                 "Preserved dashboard asset response differs from c0f.")
    note("PRESERVED_DASHBOARD_ASSETS_VERIFIED=2_LOCAL_AND_PUBLIC")


def preserved_snapshot():
    verify_preserved_source(APP)
    files = [APP / ".env", APP / "bootstrap/cache/config.php", APP / "composer.lock",
             APP / "package-lock.json", APP / "routes/console.php", WEB / "index.php"]
    files += [APP / name for name in PRESERVED_SOURCE_HASHES]
    files += sorted((APP / "bootstrap/cache").glob("routes*.php"))
    files += sorted(path for path in (WEB / "build").rglob("*") if path.is_file())
    result = {}
    for path in files:
        no_links(path)
        regular(path)
        info = path.stat()
        result[str(path.relative_to(APP))] = [digest(path.read_bytes()), info.st_uid, info.st_gid,
                                             stat.S_IMODE(info.st_mode)]
    return result


def preserved(expected):
    need(preserved_snapshot() == expected, "Preserved environment/dependencies/routes/build assets changed.")


def http(path, local=True, token=None):
    need(path.startswith("/") and not path.startswith("//"), "Invalid UAT HTTP check path.")
    with tempfile.TemporaryDirectory(prefix="http-", dir=str(LOG.parent)) as folder:
        body = Path(folder) / "body"
        headers = Path(folder) / "headers"
        args = ["curl", "--silent", "--show-error", "--connect-timeout", "8", "--max-time", "25",
                "--max-redirs", "0", "--proto", "=https", "--output", str(body), "--dump-header",
                str(headers), "--write-out", "%{http_code}"]
        if local:
            args += ["--resolve", HOST + ":443:127.0.0.1"]
        if token:
            args += ["--header", "@-"]
        args += ["https://" + HOST + path]
        status = execute(args, input_data=("X-PHOS-Release: " + token + "\n").encode() if token else None,
                         timeout=35).decode().strip()
        return status, body.read_bytes(), headers.read_text(errors="replace")


def health():
    for local in (True, False):
        for path in ("/login", "/up"):
            status, _, _ = http(path, local)
            need(status == "200", "UAT HTTP health check failed.")
            note("CHECK=" + path + " HTTP=200 CONNECTION=" + ("local" if local else "public"))
    protected = ("/rentals", "/sales", "/customers", "/business-partners", "/renewal-center", "/pickup-center", "/communication-center", "/deliveries")
    for path in protected:
        status, _, headers = http(path)
        locations = re.findall(r"(?im)^location:\s*(.*?)\s*$", headers)
        need(status == "302" and locations, "A protected UAT route did not redirect to login.")
        location = locations[-1].strip()
        from urllib.parse import urlsplit
        parsed = urlsplit(location)
        need(parsed.path.rstrip("/") == "/login" and parsed.hostname in (None, HOST),
             "A protected route returned an unexpected redirect.")
    note("PROTECTED_ROUTES_VERIFIED=" + str(len(protected)))


def atomic(path, data, uid, gid, mode):
    no_links(path)
    need(path.parent.is_dir(), "Destination parent is missing.")
    descriptor, temporary = tempfile.mkstemp(prefix=".phos-dashboard-", dir=str(path.parent))
    try:
        with os.fdopen(descriptor, "wb") as output:
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
        os.chown(temporary, uid, gid)
        os.chmod(temporary, mode)
        os.replace(temporary, path)
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)


def web_cache(invalidate):
    token = secrets.token_hex(32)
    path = WEB / ("phos-password-visibilitys-" + secrets.token_hex(16) + ".php")
    code = r'''<?php
if (!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)
 || !hash_equals('__TOKEN__',$_SERVER['HTTP_X_PHOS_RELEASE']??'')) {http_response_code(404);exit;}
header('Cache-Control: no-store');header('Content-Type: application/json');
$count=0;$enabled=filter_var(ini_get('opcache.enable'),FILTER_VALIDATE_BOOLEAN);
if ($enabled) {
 if (!function_exists('opcache_get_status')||!function_exists('opcache_invalidate')) {
  http_response_code(500);echo '{"ok":false}';exit;
 }
 $status=@opcache_get_status(true);
 if (!is_array($status)) {http_response_code(500);echo '{"ok":false}';exit;}
 if (__INVALIDATE__) foreach(array_keys($status['scripts']??[]) as $file) {
  if(str_starts_with($file,'__APP__/')&&is_file($file)) {
   if(!@opcache_invalidate($file,true)){http_response_code(500);echo '{"ok":false}';exit;}$count++;
  }
 }
}
echo json_encode(['ok'=>PHP_MAJOR_VERSION===8&&PHP_MINOR_VERSION===2,'php'=>PHP_VERSION,'invalidated'=>$count]);
'''
    code = code.replace("__TOKEN__", token).replace("__APP__", str(APP))
    code = code.replace("__INVALIDATE__", "true" if invalidate else "false")
    try:
        need(not path.exists(), "Probe filename collision.")
        atomic(path, code.encode(), UID, WEB.stat().st_gid, 0o644)
        status, data, _ = http("/" + path.name, token=token)
        result = read_json(data)
        need(status == "200" and result.get("ok") is True, "UAT web PHP/cache probe failed.")
        note("WEB_PHP=" + result["php"] + " UAT_OPCACHE_INVALIDATED=" + str(result["invalidated"]))
    finally:
        if path.exists() and not path.is_symlink():
            path.unlink()


def runtime_guards():
    code = r'''
require 'vendor/autoload.php'; $app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();$router=app('router');
foreach(['rentals.index','sales.index','customers.index','business-partners.index','renewal-center.index','pickup-center.index','communication-center.index','deliveries.index','dashboard','dashboard.compact','knowledge.index','enquiries.index','availability.index','cash-handovers.index'] as $name){
 $r=$router->getRoutes()->getByName($name);
 if(!$r||!in_array('auth',$r->gatherMiddleware(),true)){fwrite(STDERR,'AUTH_GUARD_MISSING');exit(9);}
}
foreach(['payments.store','invoices.payments.store','invoices.markPaid','rentals.markPaid',
 'rentals.recordPayment','sales.markPaid','sales.recordPayment','invoices.bulk.action'] as $name){
 $r=$router->getRoutes()->getByName($name);
 if(!$r||!in_array(App\Http\Middleware\ProtectPaymentSubmission::class,$router->gatherRouteMiddleware($r),true)){
  fwrite(STDERR,'PAYMENT_GUARD_MISSING');exit(9);
 }
}
foreach(['delivery-collections.store','delivery-collections.review','delivery-collections.reverse',
 'cash-handovers.confirm','deliveries.complete'] as $name){
 $r=$router->getRoutes()->getByName($name);
 if(!$r||!in_array(App\Http\Middleware\SerializeFinancialRequests::class,$router->gatherRouteMiddleware($r),true)){
  fwrite(STDERR,'TRANSACTION_GUARD_MISSING');exit(9);
 }
}

foreach(['password.request','password.email','password.reset','password.store'] as $name){
 $r=$router->getRoutes()->getByName($name);
 if(!$r||!in_array(App\Http\Middleware\PasswordRecoveryHeaders::class,$router->gatherRouteMiddleware($r),true)){
  fwrite(STDERR,'RESET_GUARD_MISSING');exit(9);
 }
}
echo 'PASSWORD_VISIBILITY_AND_LEGACY_RUNTIME_GUARDS_OK';
'''
    need(php(code).decode().strip() == "PASSWORD_VISIBILITY_AND_LEGACY_RUNTIME_GUARDS_OK", "Runtime guard verification failed.")
    note("PASSWORD_VISIBILITY_AND_LEGACY_RUNTIME_GUARDS_OK")


def deployed_assets(payload):
    status, body, _ = http('/login')
    need(status == '200' and b'aria-label="Show password"' in body
         and b'aria-controls="password"' in body and b'type="password"' in body,
         'Served sign-in visibility control is missing.')
    preserved_assets()
    note('PASSWORD_VISIBILITY_LOGIN_HTML_VERIFIED=true')


def save_state(backup, state):
    temporary = backup / "rollback-state.tmp"
    temporary.write_text(json.dumps(state, indent=2) + "\n", encoding="utf-8")
    os.chmod(temporary, 0o600)
    os.replace(temporary, backup / "rollback-state.json")


def make_backup(manifest, payload, before, backup):
    records = []
    for number, name in enumerate(sorted(payload)):
        path = APP / name
        verify_parents(path)
        record = {"path": name, "new_sha256": digest(payload[name]), "existed": BASELINE_HASHES[name] is not None}
        if record["existed"]:
            regular(path)
            info, data = path.stat(), path.read_bytes()
            need(digest(data) == BASELINE_HASHES[name], "Baseline changed while taking the backup.")
            saved = "original-" + str(number)
            (backup / saved).write_bytes(data)
            os.chmod(backup / saved, 0o600)
            record.update({"saved": saved, "old_sha256": digest(data), "uid": info.st_uid,
                           "gid": info.st_gid, "mode": stat.S_IMODE(info.st_mode)})
        else:
            need(not path.exists(), "New-file collision while taking the backup.")
        records.append(record)
    state = {"format": 1, "application": str(APP), "baseline": BASELINE, "release": manifest["release"],
             "files": records, "created_directories": [], "preserved": before, "complete": False}
    shutil.copyfile(Path(__file__).resolve(), backup / "rollback-helper.py")
    os.chmod(backup / "rollback-helper.py", 0o600)
    save_state(backup, state)
    return backup, state


def verify_parents(path, root=None):
    root = APP if root is None else root
    no_links(path)
    parent = path.parent
    while parent != root:
        need(parent.is_relative_to(root), "Destination escaped the UAT application.")
        need(parent.is_dir(), "Existing destination parent is missing or not a directory.")
        parent = parent.parent


def validate_restore(root, backup, state):
    need(state.get("format") == 1 and state.get("application") == str(APP)
         and state.get("baseline") == BASELINE and valid_hash(state.get("release"), 40),
         "Recovery state has an unexpected identity.")
    records = state.get("files")
    need(isinstance(records, list) and len(records) == len(BASELINE_HASHES), "Unexpected recovery file count.")
    need({r.get("path") for r in records} == set(BASELINE_HASHES), "Unexpected recovery destinations.")
    for record in records:
        name = safe_relative(record["path"])
        path = root / name
        verify_parents(path, root)
        expected = BASELINE_HASHES[name]
        need(record.get("existed") is (expected is not None), "Recovery must distinguish two originals from one new component.")
        need(valid_hash(record.get("new_sha256")), "Invalid deployed recovery hash.")
        if expected is not None:
            saved = record.get("saved", "")
            need(re.fullmatch(r"original-[0-9]+", saved), "Unexpected backup filename.")
            saved_path = backup / saved
            no_links(saved_path)
            regular(saved_path)
            need(record.get("old_sha256") == expected and digest(saved_path.read_bytes()) == expected,
                 "Original backup hash mismatch.")
        else:
            need(set(record) == {"path", "new_sha256", "existed"}, "Unexpected metadata for a new release file.")
        if path.exists():
            regular(path)
            need(digest(path.read_bytes()) in {expected, record["new_sha256"]},
                 "A password-visibility destination changed after deployment; recovery needs review.")
        else:
            need(expected is None, "An original password-visibility file disappeared; recovery needs review.")
    need(state.get("created_directories") == [], "This rollout must not create or remove destination directories.")


def ensure_recovery_maintenance(backup, state):
    down = APP / "storage/framework/down"
    no_links(down)
    if down.exists():
        regular(down)
    else:
        # Never boot newly activated PHP merely to enter recovery maintenance.
        # Reuse the exact payload generated by baseline Laravel before activation.
        saved = backup / "maintenance-down"
        regular(saved)
        data = saved.read_bytes()
        need(valid_hash(state.get("maintenance_sha256")) and digest(data) == state["maintenance_sha256"],
             "Baseline-generated maintenance backup is unavailable or corrupted.")
        atomic(down, data, UID, GID, 0o600)


def restore(backup, state):
    note("PHASE=rollback_password_visibility_code_only")
    validate_restore(APP, backup, state)
    preserved(state["preserved"])
    ensure_recovery_maintenance(backup, state)
    for record in state["files"]:
        path = APP / record["path"]
        if record["existed"]:
            atomic(path, (backup / record["saved"]).read_bytes(), record["uid"], record["gid"], record["mode"])
        elif path.exists():
            regular(path)
            need(digest(path.read_bytes()) == record["new_sha256"], "New-file recovery collision; refusing removal.")
            path.unlink()
    artisan("view:clear")
    artisan("view:cache")
    web_cache(True)
    verify_baseline(APP)
    preserved(state["preserved"])
    runtime_guards()
    try:
        artisan("up")
        health()
        preserved_assets()
    except BaseException:
        ensure_recovery_maintenance(backup, state)
        raise
    state["rolled_back"] = True
    save_state(backup, state)
    note("ROLLBACK_COMPLETE_CODE_ONLY=true DATABASE_RESTORED=false MIGRATIONS_RUN=0")


def completed_backup(manifest, payload):
    """An exact file set alone is not proof that a prior activation completed."""
    for directory in sorted(Path("/root").glob(BACKUP_PREFIX + "*"), reverse=True):
        no_links(directory)
        if not directory.is_dir():
            continue
        info = directory.stat()
        need(info.st_uid == 0 and stat.S_IMODE(info.st_mode) & 0o077 == 0,
             "An existing dashboard backup is not root-private.")
        path = directory / "rollback-state.json"
        if not path.exists():
            continue
        regular(path)
        state = read_json(path.read_bytes())
        if state.get("release") != manifest["release"] or state.get("complete") is not True or state.get("rolled_back"):
            continue
        validate_restore(APP, directory, state)
        need(all(record["new_sha256"] == digest(payload[record["path"]]) for record in state["files"]),
             "Completed deployment state differs from pinned package.")
        preserved(state["preserved"])
        for record in state["files"]:
            current = (APP / record["path"]).stat()
            need((current.st_uid, current.st_gid, stat.S_IMODE(current.st_mode)) ==
                 (record.get("uid", UID), record.get("gid", GID), record.get("mode", 0o644)),
                 "Installed dashboard metadata differs from successful deployment.")
        return directory
    return None


def deploy(manifest, payload):
    global LOG
    backup = Path(tempfile.mkdtemp(prefix=BACKUP_PREFIX, dir="/root"))
    os.chmod(backup, 0o700)
    LOG = backup / "deployment.log"
    note("BACKUP_DIRECTORY=" + str(backup))
    load_target()  # UAT application/database identity is verified before any HTTP probe.
    need(not (APP / "storage/framework/down").exists()
         and not (APP / "storage/framework/maintenance.php").exists(), "UAT is already in maintenance mode.")
    if release_installed(APP, payload):
        prior = completed_backup(manifest, payload)
        need(prior is not None,
             "Release files already exist without a verified successful activation; inspect/recover before retrying.")
        health()
        runtime_guards()
        deployed_assets(payload)
        note("ORIGINAL_BACKUP_DIRECTORY=" + str(prior))
        note("RECOVERY_COMMAND=python3 " + str(prior / "rollback-helper.py") + " --rollback " + str(prior)
             + " --expected-release " + manifest["release"])
        note("PASSWORD_VISIBILITY_RELEASE_ALREADY_CURRENT=" + manifest["release"])
        return
    verify_baseline(APP)
    before = preserved_snapshot()
    backup, state = make_backup(manifest, payload, before, backup)
    note("RECOVERY_COMMAND=python3 " + str(backup / "rollback-helper.py") + " --rollback " + str(backup)
         + " --expected-release " + manifest["release"])
    health()
    runtime_guards()
    web_cache(False)
    need(shutil.disk_usage(APP).free > 64 * 1024 * 1024, "Insufficient free space for dashboard activation.")
    mutated = maintenance = False
    try:
        note("PHASE=activate_password_visibility_files")
        maintenance = True
        artisan("down", "--retry=60")
        down = APP / "storage/framework/down"
        no_links(down)
        regular(down)
        data = down.read_bytes()
        (backup / "maintenance-down").write_bytes(data)
        os.chmod(backup / "maintenance-down", 0o600)
        state["maintenance_sha256"] = digest(data)
        save_state(backup, state)
        verify_baseline(APP)
        preserved(before)
        web_cache(True)
        mutated = True
        records = {record["path"]: record for record in state["files"]}
        for name, data in payload.items():
            path = APP / name
            verify_parents(path)
            record = records[name]
            atomic(path, data, record.get("uid", UID), record.get("gid", GID), record.get("mode", 0o644))
        need(release_installed(APP, payload), "Installed dashboard files differ from the pinned release.")
        note("PHASE=compile_password_visibility_views")
        artisan("view:clear")
        artisan("view:cache")
        web_cache(True)
        runtime_guards()
        preserved(before)
        note("PHASE=bring_uat_online")
        artisan("up")
        maintenance = False
        health()
        deployed_assets(payload)
        need(release_installed(APP, payload), "Dashboard source changed during verification.")
        preserved(before)
        state["complete"] = True
        save_state(backup, state)
        note("DEPLOYMENT_COMPLETE ENVIRONMENT=PHOS_UAT COMMIT=" + manifest["release"])
        note("MIGRATIONS_RUN=0 BUSINESS_DATA_WRITES=false DATABASE_RESTORED=false PRODUCTION_CHANGED=false")
        note("HTTP_SMOKE_TESTS_MAY_CREATE_TRANSIENT_FRAMEWORK_SESSIONS=true")
    except BaseException:
        if mutated:
            try:
                restore(backup, state)
                maintenance = False
            except BaseException:
                note("AUTOMATIC_ROLLBACK_INCOMPLETE: retain the private backup and inspect the private log.")
        elif maintenance:
            artisan("up")
        raise


def main():
    global LOG
    parser = argparse.ArgumentParser(description=__doc__)
    mode = parser.add_mutually_exclusive_group(required=True)
    mode.add_argument("--check-package", action="store_true")
    mode.add_argument("--deploy", action="store_true")
    mode.add_argument("--rollback", type=Path)
    parser.add_argument("--manifest", type=Path)
    parser.add_argument("--archive", type=Path)
    parser.add_argument("--manifest-sha256")
    parser.add_argument("--archive-sha256")
    parser.add_argument("--expected-release", required=True)
    args = parser.parse_args()
    if args.rollback:
        need(sys.platform.startswith("linux") and fcntl is not None and os.geteuid() == 0,
             "Recovery requires the Linux server root terminal.")
        os.umask(0o077)
        path = args.rollback
        no_links(path)
        need(path.parent == Path("/root") and path.name.startswith(BACKUP_PREFIX)
             and path.is_dir() and path.stat().st_uid == 0 and stat.S_IMODE(path.stat().st_mode) & 0o077 == 0,
             "Recovery requires the original root-private dashboard backup.")
        LOG = path / "rollback.log"
        load_target()
        regular(path / "rollback-state.json")
        state = read_json((path / "rollback-state.json").read_bytes())
        need(state.get("release") == args.expected_release and valid_hash(args.expected_release, 40),
             "Pinned recovery release does not match backup.")
        with deployment_lock():
            restore(path, state)
        return
    need(args.manifest is not None and args.archive is not None, "Both manifest and archive are required.")
    manifest, payload = load_package(args.manifest, args.archive, args.manifest_sha256,
                                     args.archive_sha256, args.expected_release)
    note("PINNED_PASSWORD_VISIBILITY_PACKAGE_VERIFIED=" + manifest["release"] + " FILES=" + str(len(payload)))
    if args.check_package:
        return
    need(sys.platform.startswith("linux") and fcntl is not None and os.geteuid() == 0,
         "Use the Linux server root terminal; package verification is the only local mode.")
    os.umask(0o077)
    with deployment_lock():
        deploy(manifest, payload)


def deployment_lock():
    need(fcntl is not None and os.geteuid() == 0, "Root Linux deployment lock required.")
    # Shared with the previous dashboard helper; prevents overlapping PHOS rollouts.
    path = Path("/root/phos-tabbed-dashboard-deploy.lock")
    no_links(path)
    descriptor = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    lock = os.fdopen(descriptor, "a")
    info = os.fstat(lock.fileno())
    need(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and stat.S_IMODE(info.st_mode) & 0o077 == 0,
         "Deployment lock is not root-private.")
    fcntl.flock(lock.fileno(), fcntl.LOCK_EX | fcntl.LOCK_NB)
    return lock


if __name__ == "__main__":
    try:
        main()
    except (Refused, OSError, ValueError, KeyError, zipfile.BadZipFile, subprocess.SubprocessError) as error:
        # Raw command output remains in the private log; do not print configuration or credentials.
        print("STOPPED: " + (str(error) if isinstance(error, Refused) else type(error).__name__)
              + (" PRIVATE_LOG=" + str(LOG) if LOG else ""), file=sys.stderr)
        sys.exit(1)
