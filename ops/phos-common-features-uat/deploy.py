#!/usr/bin/env python3
"""Pinned PHOS common-features UAT release; additive migrations and verified prebuilt assets."""
from pathlib import Path, PurePosixPath
import argparse, datetime, gzip, hashlib, json, os, re, secrets, shutil
import signal, stat, subprocess, sys, tempfile
try:
    import pwd, fcntl
except ImportError:
    pwd = fcntl = None

APP = WEB = STORAGE = None
HOST = DATABASE = OWNER = PHP = LOCK_BLOB = None
TARGET_CONFIG_BYTES = None
BASE = "d1dd30ddcd47e2b530fab8d3129006fb254d72de"
REVIEWED = "361c2150c5d3c2af76c1cc4e80d91be3666b1390"
REVIEWED_TREE = "e5c3c09a980e3345ace3bacf63140884381fbf72"
RELEASE = "f9e6de32620ca8ecaf7293e0ee15149cd8c78f22"
MIGRATION = "2026_10_27_000000_create_payment_submissions_table"
URL = "https://github.com/sanandkumarmph/prime-healers-os.git"
RUNTIME_CHANGED = {"resources/views/invoices/index.blade.php"}
REVIEWED_FILES = set(RUNTIME_CHANGED)
# Optional verified fixture identities stay in the private server configuration.
VERIFIED_CORRECTION_FIXTURE = None
CORRECTION_MIGRATION = "2026_10_28_120000_add_payment_reversal_link"
PATH = "/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin"
ENV = {"PATH": PATH, "LANG": "C.UTF-8", "GIT_TERMINAL_PROMPT": "0"}
BACKUP = LOG = None
PHASE = "preflight"

class Stop(Exception):
    pass

def need(condition, message):
    if not condition:
        raise Stop(message)

def note(message):
    print(message, flush=True)
    if LOG:
        with LOG.open("a") as output:
            output.write(message + "\n")

def phase(name):
    global PHASE
    PHASE = name
    note("PHASE=" + name)

def sha(data):
    return hashlib.sha256(data).hexdigest()

def blob(data):
    return hashlib.sha1(b"blob " + str(len(data)).encode() + b"\0" + data).hexdigest()

def regular(path):
    need(path.is_file() and not path.is_symlink(), "Missing or linked file: " + str(path))

def no_links(path):
    need(not path.is_symlink() and not any(p.is_symlink() for p in path.parents),
         "Linked destination refused: " + str(path))

def target_values(config):
    keys = {"host", "application", "database", "owner", "php", "package_lock_blob"}
    need(set(config) == keys and all(isinstance(config[k], str) for k in keys),
         "Unexpected private target configuration.")
    need(re.fullmatch(r"uat-[a-z0-9.-]+", config["host"]), "Target hostname must identify UAT.")
    need(config["application"] == "/home/" + config["host"] + "/public_html",
         "Application path does not match the private UAT target.")
    need(re.fullmatch(r"uat[a-zA-Z0-9_]+", config["database"]), "Target database must identify UAT.")
    need(re.fullmatch(r"[a-z_][a-z0-9_-]*", config["owner"]) and config["owner"] != "root",
         "Unexpected site owner.")
    need(config["php"].startswith("/usr/local/lsws/") and config["php"].endswith("/bin/php"),
         "Unexpected PHP runtime path.")
    need(re.fullmatch(r"[0-9a-f]{40}", config["package_lock_blob"]), "Invalid preserved lockfile fingerprint.")
    return config

def load_target(path):
    global APP, WEB, STORAGE, HOST, DATABASE, OWNER, PHP, LOCK_BLOB, TARGET_CONFIG_BYTES
    file = Path(path)
    regular(file)
    no_links(file)
    st, parent = file.stat(), file.parent.stat()
    need(st.st_uid == 0 and stat.S_IMODE(st.st_mode) & 0o077 == 0
         and parent.st_uid == 0 and stat.S_IMODE(parent.st_mode) & 0o077 == 0,
         "Private target configuration and its directory must be root-owned and private.")
    TARGET_CONFIG_BYTES = file.read_bytes()
    config = target_values(json.loads(TARGET_CONFIG_BYTES))
    APP = Path(config["application"])
    WEB, STORAGE = APP / "public", APP / "storage"
    HOST, DATABASE, OWNER, PHP, LOCK_BLOB = (config[k] for k in
        ("host", "database", "owner", "php", "package_lock_blob"))

def validate_verified_fixture(value):
    need(isinstance(value, dict) and set(value) == {"invoice_id","invoice_number","receipt_ids","amount"},
         "Unexpected private correction fixture fields.")
    need(type(value["invoice_id"]) is int and value["invoice_id"] > 0
         and isinstance(value["invoice_number"], str) and re.fullmatch(r"INV-[0-9]+", value["invoice_number"])
         and type(value["amount"]) is int and value["amount"] > 0
         and isinstance(value["receipt_ids"], list) and len(value["receipt_ids"]) == 3
         and all(type(item) is int and item > 0 for item in value["receipt_ids"])
         and len(set(value["receipt_ids"])) == 3, "Invalid private correction fixture identity.")
    return value

def load_verified_fixture(path):
    file = Path(path)
    regular(file)
    no_links(file)
    st, parent = file.stat(), file.parent.stat()
    need(st.st_uid == 0 and stat.S_IMODE(st.st_mode) & 0o077 == 0
         and parent.st_uid == 0 and stat.S_IMODE(parent.st_mode) & 0o077 == 0,
         "Correction fixture configuration and directory must be root-owned and private.")
    return validate_verified_fixture(json.loads(file.read_bytes()))

def permissions(path, uid, gid, mode):
    if os.name != "nt":
        os.chown(str(path), uid, gid)
    os.chmod(str(path), mode)

def atomic(path, data, uid, gid, mode):
    no_links(path)
    fd, temporary = tempfile.mkstemp(prefix=".phos-pr8-", dir=str(path.parent))
    try:
        with os.fdopen(fd, "wb") as output:
            output.write(data)
            output.flush()
            os.fsync(output.fileno())
        permissions(Path(temporary), uid, gid, mode)
        os.replace(temporary, str(path))
    finally:
        if os.path.exists(temporary):
            os.unlink(temporary)

def save_file(path, directory, number, after):
    no_links(path)
    record = {"path": str(path), "existed": path.exists(), "after": after}
    if record["existed"]:
        regular(path)
        data, st = path.read_bytes(), path.stat()
        name = "file-" + str(number)
        (directory / name).write_bytes(data)
        record.update(saved=name, before=sha(data), uid=st.st_uid, gid=st.st_gid,
                      mode=stat.S_IMODE(st.st_mode))
    return record

def validate_backups(records, directory):
    for record in records:
        if record["existed"]:
            file = directory / record["saved"]
            regular(file)
            need(sha(file.read_bytes()) == record["before"], "Backup checksum mismatch.")

def restore_files(records, directory):
    validate_backups(records, directory)
    for record in records:
        file = Path(record["path"])
        no_links(file)
        if file.exists():
            regular(file)
            need(sha(file.read_bytes()) in {record.get("before"), record["after"]},
                 "File changed outside this release; rollback stopped: " + str(file))
    for record in records:
        file = Path(record["path"])
        if record["existed"]:
            atomic(file, (directory / record["saved"]).read_bytes(),
                   record["uid"], record["gid"], record["mode"])
        elif file.exists():
            file.unlink()

def execute(args, cwd=None, capture=False, timeout=180, input_data=None):
    process = subprocess.Popen(args, cwd=str(cwd) if cwd else None, env=ENV,
                               stdin=subprocess.PIPE if input_data is not None else subprocess.DEVNULL,
                               stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)
    try:
        stdout, stderr = process.communicate(input=input_data, timeout=timeout)
    except BaseException:
        if process.poll() is None:
            try:
                os.killpg(process.pid, signal.SIGTERM)
                process.communicate(timeout=10)
            except subprocess.TimeoutExpired:
                os.killpg(process.pid, signal.SIGKILL)
                process.communicate()
            except ProcessLookupError:
                pass
        raise
    if LOG:
        with LOG.open("ab") as output:
            if not capture or process.returncode != 0:
                output.write(stdout)
            output.write(stderr)
    need(process.returncode == 0, "Command failed in " + PHASE + "; exit=" + str(process.returncode)
         + "; inspect the private log.")
    return stdout

def as_owner(args):
    account = pwd.getpwnam(OWNER)
    return ["runuser", "-u", OWNER, "--", "env", "-i", "PATH=" + PATH,
            "LANG=C.UTF-8", "HOME=" + account.pw_dir, "GIT_TERMINAL_PROMPT=0"] + args

def git(repo, *args):
    options = ["git", "-c", "core.hooksPath=/dev/null", "-c", "core.autocrlf=false",
               "-c", "credential.helper=", "-C", str(repo)] + list(args)
    return execute(as_owner(options) if repo == APP else options, capture=True)

def artisan(*args):
    execute(as_owner([PHP, "-d", "opcache.enable_cli=0", "-d", "opcache.jit=0",
                      "-d", "memory_limit=512M", "artisan"] + list(args) + ["--no-interaction"]),
            cwd=APP, timeout=240)

def runtime(name):
    return name.startswith(("app/", "bootstrap/", "config/", "database/migrations/", "resources/", "routes/", "public/")) or name in {
        "artisan", "composer.json", "composer.lock", "package.json", "package-lock.json"
    }

def source_map(repo, commit):
    found = {}
    for record in git(repo, "ls-tree", "-rz", commit).split(b"\0"):
        if not record:
            continue
        meta, raw = record.split(b"\t", 1)
        mode, kind, checksum = meta.split()
        name = raw.decode()
        if runtime(name):
            need(mode in (b"100644", b"100755") and kind == b"blob", "Unsupported runtime entry.")
            found[name] = checksum.decode()
    return found

def verify_source(repo, commit, label):
    expected = source_map(repo, commit)
    differences = []
    for name, checksum in expected.items():
        file = APP / name
        if name == "package-lock.json":
            checksum = LOCK_BLOB
        if not file.is_file() or file.is_symlink() or blob(file.read_bytes()) != checksum:
            differences.append(name)
        elif any(p.is_symlink() for p in file.parents):
            differences.append(name + " (linked parent)")
    for name in differences[:12]:
        note("SOURCE_DIFFERENCE=" + name)
    need(not differences, label + " source differs from the reviewed release.")
    note(label + "_SOURCE_VERIFIED=" + str(len(expected)))

def settings():
    file = APP / "bootstrap/cache/config.php"
    regular(file)
    code = r'''
$c = require $argv[1]; $driver = $c["database"]["default"] ?? "";
echo json_encode([
  "host" => parse_url($c["app"]["url"] ?? "", PHP_URL_HOST),
  "env" => $c["app"]["env"] ?? null, "driver" => $driver,
  "db" => $c["database"]["connections"][$driver] ?? [],
  "compiled" => $c["view"]["compiled"] ?? null,
  "views" => $c["view"]["paths"] ?? [],
  "maintenance" => $c["app"]["maintenance"]["driver"] ?? "file"
], JSON_THROW_ON_ERROR);
'''
    result = json.loads(execute([PHP, "-d", "opcache.enable_cli=0", "-d", "opcache.jit=0",
                                 "-r", code, str(file)], capture=True))
    need(result["host"] == HOST and result["env"] == "uat" and result["driver"] == "mysql",
         "Cached application identity is not PHOS UAT.")
    db = result["db"]
    need(db.get("database") == DATABASE and db.get("host") in ("localhost", "127.0.0.1", "::1")
         and not db.get("url") and not db.get("read") and not db.get("write"),
         "PHOS UAT database identity/connection overrides changed.")
    need(result["maintenance"] == "file", "Unexpected maintenance driver.")
    need(Path(result["compiled"]).resolve() == STORAGE / "framework/views",
         "Unexpected compiled-view directory.")
    need(str(APP / "resources/views") in result["views"], "Unexpected cached view path.")
    return result

def db_state():
    code = r'''
$c = require $argv[1]; $d = $c["database"]["connections"][$c["database"]["default"]];
$dsn = !empty($d["unix_socket"]) ? "mysql:unix_socket=".$d["unix_socket"] :
  "mysql:host=".$d["host"].";port=".($d["port"] ?? 3306);
$p = new PDO($dsn.";dbname=".$d["database"], $d["username"], $d["password"],
  [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 8]);
$out = ["database" => $p->query("SELECT DATABASE()")->fetchColumn(),
        "version" => $p->query("SELECT VERSION()")->fetchColumn()];
$q = $p->prepare("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
$q->execute(["payment_submissions"]); $out["engine"] = $q->fetchColumn();
$q = $p->prepare("SELECT COUNT(*) FROM migrations WHERE migration=?");
$q->execute([$argv[2]]); $out["migration"] = (int) $q->fetchColumn();
$out["primary"] = [];
if ($out["engine"]) {
  $q=$p->prepare("SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION");
  $q->execute(["payment_submissions"]); $out["primary"]=$q->fetchAll(PDO::FETCH_COLUMN);
}

$cfix=["column"=>null,"unique"=>false,"foreign"=>[],"migration"=>0,"entries"=>0];
$q=$p->query("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments'");
$cfix["engine"]=$q->fetchColumn();
$q=$p->query("SELECT DATA_TYPE,IS_NULLABLE,COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND COLUMN_NAME='reversal_of_payment_id'");
$cfix["column"]=$q->fetch(PDO::FETCH_ASSOC) ?: null;
$q=$p->prepare("SELECT COUNT(*) FROM migrations WHERE migration=?");
$q->execute([$argv[3]]);$cfix["migration"]=(int)$q->fetchColumn();
$q=$p->query("SELECT NON_UNIQUE,GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS columns_list FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' GROUP BY INDEX_NAME,NON_UNIQUE");
foreach($q->fetchAll(PDO::FETCH_ASSOC) as $idx) {
 if ((int)$idx["NON_UNIQUE"]===0 && $idx["columns_list"]==="reversal_of_payment_id") $cfix["unique"]=true;
}
$q=$p->query("SELECT k.REFERENCED_TABLE_NAME AS target_table,k.REFERENCED_COLUMN_NAME AS target_column,r.DELETE_RULE AS delete_rule FROM information_schema.KEY_COLUMN_USAGE k JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA=k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME=k.CONSTRAINT_NAME AND r.TABLE_NAME=k.TABLE_NAME WHERE k.TABLE_SCHEMA=DATABASE() AND k.TABLE_NAME='payments' AND k.COLUMN_NAME='reversal_of_payment_id'");
$cfix["foreign"]=$q->fetchAll(PDO::FETCH_ASSOC);
if ($cfix["column"]) $cfix["entries"]=(int)$p->query("SELECT COUNT(*) FROM payments WHERE reversal_of_payment_id IS NOT NULL")->fetchColumn();
$out["correction"]=$cfix;

echo json_encode($out, JSON_THROW_ON_ERROR);
'''
    result = json.loads(execute([PHP, "-d", "opcache.enable_cli=0", "-d", "opcache.jit=0",
                                 "-r", code, str(APP / "bootstrap/cache/config.php"), MIGRATION, CORRECTION_MIGRATION], capture=True))
    need(result["database"] == DATABASE, "Connected database is not PHOS UAT.")
    return result

def schema_ok(result):
    need(result["engine"] == "InnoDB" and result["primary"] == ["organization_id", "token"]
         and result["migration"] == 1, "Payment submission schema/migration is not the reviewed structure.")


def correction_ok(result, allow_missing=False):
    state = result["correction"]
    need(state["engine"] == "InnoDB", "Payments must use InnoDB for atomic corrections.")
    if state["column"] is None and allow_missing:
        need(state["migration"] == 0 and not state["unique"] and not state["foreign"],
             "Correction migration history or partial schema needs review.")
        return False
    column = state["column"] or {}
    foreign = state["foreign"]
    need(column.get("DATA_TYPE") == "bigint" and column.get("IS_NULLABLE") == "YES"
         and "unsigned" in column.get("COLUMN_TYPE", "")
         and state["migration"] == 1 and state["unique"] is True
         and len(foreign) == 1 and foreign[0].get("target_table") == "payments"
         and foreign[0].get("target_column") == "id"
         and foreign[0].get("delete_rule") in ("RESTRICT", "NO ACTION"),
         "Payment correction schema must have the reviewed unique restrictive reversal link.")
    return True


def correction_runtime_ok():
    code = r'''
require "vendor/autoload.php"; $app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
$router=app("router");
foreach (["payments.correction"=>"GET","payments.reverse"=>"POST"] as $name=>$method) {
 $route=$router->getRoutes()->getByName($name);
 if (!$route || !in_array($method,$route->methods(),true)
  || !in_array("module:payments,delete",$route->gatherMiddleware(),true)
  || !in_array(App\Http\Middleware\SerializeFinancialRequests::class,$router->gatherRouteMiddleware($route),true)) {
  throw new RuntimeException("CORRECTION_ROUTE_GUARD_MISSING");
 }
}
foreach (["invoices.update","invoices.destroy","invoices.bulk.delete","invoices.void","sales.update","rentals.update"] as $name) {
 $route=$router->getRoutes()->getByName($name);
 if (!$route || !in_array(App\Http\Middleware\SerializeFinancialRequests::class,$router->gatherRouteMiddleware($route),true)) {
  throw new RuntimeException("FINANCE_SERIALIZATION_MIDDLEWARE_MISSING");
 }
}
$payment=new App\Models\Payment(["amount"=>100,"reversal_of_payment_id"=>null]);
if(strlen(app(App\Services\Finance\PaymentCorrectionService::class)->version($payment))!==64) {
 throw new RuntimeException("CORRECTION_VERSION_HELPER_UNAVAILABLE");
}
echo "PHOS_PAYMENT_CORRECTIONS_RUNTIME_OK=READ_ONLY\n";
'''
    note(execute(as_owner([PHP, "-d", "opcache.enable_cli=0", "-d", "opcache.jit=0", "-r", code]),
                 cwd=APP, capture=True).decode().strip())


def scheduler():
    found = {}
    for user in ("root", OWNER):
        process = subprocess.run(["crontab", "-u", user, "-l"], env=ENV,
                                 stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
        found[user] = sha(process.stdout)
    return found

def snapshot():
    files = [APP / ".env", APP / "bootstrap/cache/config.php", APP / "composer.lock",
             APP / "package-lock.json", APP / "routes/console.php", WEB / "index.php"]
    files += sorted(file for file in (WEB / "build").rglob("*") if file.is_file())
    return {"files": {str(file): sha(file.read_bytes()) for file in files},
            "storage": str(STORAGE.resolve()), "cron": scheduler()}

def preserved(before):
    need(snapshot() == before, "Environment, lockfiles, assets, storage or scheduler changed.")

def http(path, local=True, token=None):
    output = BACKUP / ("http-" + secrets.token_hex(6))
    headers = output.with_suffix(".headers")
    command = ["curl", "--disable", "--silent", "--show-error", "--noproxy", "*", "--compressed",
               "--connect-timeout", "10", "--max-time", "40", "--output", str(output),
               "--dump-header", str(headers), "--write-out", "%{http_code}"]
    if local:
        command += ["--resolve", HOST + ":443:127.0.0.1"]
    data = None
    if token:
        command += ["--header", "@-"]
        data = ("X-PHOS-Release: " + token + "\n").encode()
    command += ["https://" + HOST + path]
    status = execute(command, capture=True, timeout=50, input_data=data).decode().strip()
    body, header = output.read_bytes(), headers.read_text(errors="replace")
    output.unlink()
    headers.unlink()
    return status, body, header

def health():
    for local in (True, False):
        for path in ("/login", "/up"):
            status, _, _ = http(path, local)
            need(status == "200", "PHOS health check failed: " + path + " HTTP=" + status)
            note("CHECK=" + path + " HTTP=200 CONNECTION=" + ("local" if local else "public"))
    for path in ("/invoices", "/rentals", "/sales"):
        status, _, headers = http(path)
        locations = re.findall(r"(?im)^location:\s*(.*?)\s*$", headers)
        need(status == "302" and locations and locations[-1].split("?")[0].rstrip("/").endswith("/login"),
             "Protected PHOS route did not redirect to login: " + path)
    note("PROTECTED_ROUTES_VERIFIED=3")

def web_cache(invalidate):
    token = secrets.token_hex(32)
    file = WEB / ("phos-pr8-" + secrets.token_hex(16) + ".php")
    code = r'''<?php
if (!in_array($_SERVER["REMOTE_ADDR"] ?? "", ["127.0.0.1","::1"], true)
 || !hash_equals("__TOKEN__", $_SERVER["HTTP_X_PHOS_RELEASE"] ?? "")) {
 http_response_code(404); exit;
}
header("Cache-Control: no-store"); header("Content-Type: application/json");
$count=0; $enabled=filter_var(ini_get("opcache.enable"), FILTER_VALIDATE_BOOLEAN);
if ($enabled) {
 if (!function_exists("opcache_get_status") || !function_exists("opcache_invalidate")) {
  http_response_code(500); echo '{"ok":false,"reason":"OPCACHE_API_UNAVAILABLE"}'; exit;
 }
 $s=@opcache_get_status(true);
 if (!is_array($s)) {
  http_response_code(500); echo '{"ok":false,"reason":"OPCACHE_STATUS_UNAVAILABLE"}'; exit;
 }
 if (__INVALIDATE__) {
  foreach (array_keys($s["scripts"] ?? []) as $path) {
   if (str_starts_with($path, "__APP__/") && is_file($path)) {
    if (!@opcache_invalidate($path,true)) {
     http_response_code(500); echo '{"ok":false,"reason":"OPCACHE_INVALIDATION_FAILED"}'; exit;
    }
    $count++;
   }
  }
 }
}
echo json_encode(["ok"=>PHP_VERSION_ID>=80200,"php"=>PHP_VERSION,"invalidated"=>$count]);
'''
    code = code.replace("__TOKEN__", token).replace("__APP__", str(APP))
    code = code.replace("__INVALIDATE__", "true" if invalidate else "false")
    try:
        # Match the site's scripts: strict ownership and PHP suEXEC reject root-owned probes.
        atomic(file, code.encode(), pwd.getpwnam(OWNER).pw_uid, WEB.stat().st_gid, 0o644)
        status, data, _ = http("/" + file.name, token=token)
        note("PHOS_PHP_PROBE_HTTP=" + status)
        try:
            result = json.loads(data)
        except (ValueError, UnicodeError):
            result = {}
        reason = result.get("reason", "") if isinstance(result, dict) else ""
        details = " HTTP=" + status
        if reason in {"OPCACHE_API_UNAVAILABLE", "OPCACHE_STATUS_UNAVAILABLE", "OPCACHE_INVALIDATION_FAILED"}:
            details += " REASON=" + reason
        need(status == "200", "PHOS loopback PHP/OPcache probe failed;" + details)
        need(isinstance(result, dict) and result.get("ok") is True,
             "PHOS web PHP runtime check failed;" + details)
        note("WEB_PHP=" + result["php"] + " PHOS_OPCACHE_INVALIDATED=" + str(result["invalidated"]))
    finally:
        if file.exists():
            file.unlink()

def assets():
    manifest = json.loads((WEB / "build/manifest.json").read_bytes())
    entries = [value["file"] for value in manifest.values() if value.get("isEntry")]
    need(entries, "No frontend manifest entries.")
    for name in entries:
        path = PurePosixPath(name)
        need(not path.is_absolute() and ".." not in path.parts and name.startswith("assets/"),
             "Unsafe frontend asset path.")
        regular(WEB / "build" / name)
        for local in (True, False):
            status, data, _ = http("/build/" + name, local)
            need(status == "200" and sha(data) == sha((WEB / "build" / name).read_bytes()),
                 "Served PHOS frontend differs from the preserved build.")
    note("PRESERVED_FRONTEND_ASSETS_VERIFIED=" + str(len(entries)))

def routes_ok():
    code = r'''
require "vendor/autoload.php"; $app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
$router=app("router"); $expected=App\Http\Middleware\ProtectPaymentSubmission::class;
if (($router->getMiddleware()["payment_submission"] ?? "") !== $expected) {
 fwrite(STDERR,"PHOS_PAYMENT_MIDDLEWARE_ALIAS_MISSING\n"); exit(8);
}
if (!class_exists($expected)) {
 fwrite(STDERR,"PHOS_PAYMENT_MIDDLEWARE_CLASS_UNAVAILABLE\n"); exit(8);
}
$names=["payments.store","invoices.payments.store","invoices.markPaid","rentals.markPaid",
"rentals.recordPayment","sales.markPaid","sales.recordPayment","invoices.bulk.action"];
foreach ($names as $name) {
 $r=$router->getRoutes()->getByName($name);
 if (!$r) {fwrite(STDERR,"PHOS_PAYMENT_ROUTE_MISSING=".$name."\n");exit(7);}
 if (!in_array("payment_submission",$r->gatherMiddleware(),true) ||
     !in_array($expected,$router->gatherRouteMiddleware($r),true)) {
  fwrite(STDERR,"PHOS_PAYMENT_ROUTE_GUARD_MISSING=".$name."\n");exit(7);
 }
}
echo "PAYMENT_SUBMISSION_RUNTIME_OK=8_ROUTES\n";
'''
    note(execute(as_owner([PHP, "-d", "opcache.enable_cli=0", "-d", "opcache.jit=0", "-r", code]),
                 cwd=APP, capture=True).decode().strip())

def country_codes_ok():
    # Pure payload normalization; no customer lookup, save or outgoing message.
    code = r'''
require "vendor/autoload.php"; $app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
$input=["customer_type"=>"Individual","name"=>"Synthetic runtime probe",
 "phone_country_code"=>"+1","phone"=>"2025550100",
 "whatsapp_number_country_code"=>"+44","whatsapp_number"=>"7700900101"];
$out=App\Support\CustomerProfileSupport::payload($input);
if ($out["phone"]!=="+12025550100" || $out["whatsapp_number"]!=="+447700900101") {
 fwrite(STDERR,"CUSTOMER_COUNTRY_CODE_PERSISTENCE_PROBE_FAILED\n");exit(15);
}
$input["phone_country_code"]="+91";$input["phone"]="9876543210";
$input["whatsapp_number"]="";
$out=App\Support\CustomerProfileSupport::payload($input);
if ($out["phone"]!=="+919876543210" || $out["whatsapp_number"]!==null) {
 fwrite(STDERR,"CUSTOMER_DEFAULT_OR_OPTIONAL_CONTACT_PROBE_FAILED\n");exit(16);
}
echo "CUSTOMER_COUNTRY_CODES_RUNTIME_OK=PHONE_AND_WHATSAPP\n";
'''
    note(execute(as_owner([PHP, "-d", "opcache.enable_cli=0", "-d", "opcache.jit=0", "-r", code]),
                 cwd=APP, capture=True).decode().strip())


def acceptance_probe(invoice_id, verify_metrics=False):
    code = r'''
require "vendor/autoload.php"; $app=require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Http\Kernel::class)->bootstrap();
$id=(int)$argv[1];
Illuminate\Support\Facades\DB::connection()->beforeExecuting(function ($sql) {
 if (!preg_match('/^\s*select\b/i',$sql)) throw new RuntimeException("FINANCIAL_PRESERVATION_PROBE_MUST_NOT_WRITE");
});
$invoice=Illuminate\Support\Facades\DB::table("invoices")->where("id",$id)->first([
 "id","invoice_number","organization_id","customer_id","rental_id","reference_number","total_amount","paid_amount","balance_amount","status","payment_status"
]);
$labelled=$invoice && (str_starts_with((string)$invoice->reference_number,"UAT-PAY-SAFETY-")
 || str_starts_with((string)$invoice->reference_number,"UAT-CORRECTION-"));
$fixtureRental=null;
if ($invoice && $invoice->rental_id) {
 $candidate=Illuminate\Support\Facades\DB::table("rentals")
  ->join("customers","customers.id","=","rentals.customer_id")
  ->join("products","products.id","=","rentals.product_id")
  ->where("rentals.id",$invoice->rental_id)
  ->where("rentals.organization_id",$invoice->organization_id)
  ->where("rentals.customer_id",$invoice->customer_id)
  ->whereColumn("customers.organization_id","rentals.organization_id")
  ->whereColumn("products.organization_id","rentals.organization_id")
  ->first(["rentals.id","rentals.organization_id","rentals.customer_id","rentals.product_id",
   "rentals.start_date","rentals.end_date","rentals.rental_amount","rentals.deposit_amount",
   "rentals.transport_amount","rentals.other_amount","rentals.status",
   "customers.name as fixture_customer_label","products.name as fixture_product_label"]);
 if ($candidate && str_starts_with((string)$candidate->fixture_customer_label,"UAT PAYMENT SAFETY")
     && str_starts_with((string)$candidate->fixture_product_label,"UAT PAYMENT SAFETY")) {
  $labelled=true;$fixtureRental=$candidate;
 }
}
$receipts=Illuminate\Support\Facades\DB::table("payments")->where("invoice_id",$id)
 ->orderBy("id")->get(["id","organization_id","customer_id","rental_id","invoice_id","payment_date","amount","payment_method","notes","reversal_of_payment_id"]);
$verifiedFixture=__VERIFIED_CORRECTION_FIXTURE__;
if (!$labelled && $verifiedFixture && $invoice && $id===$verifiedFixture["invoice_id"]
 && $invoice->invoice_number===$verifiedFixture["invoice_number"] && !$invoice->rental_id
 && (float)$invoice->total_amount===$verifiedFixture["amount"]*1.0
 && (float)$invoice->paid_amount===$verifiedFixture["amount"]*1.0
 && (float)$invoice->balance_amount===0.0 && $invoice->status==="paid" && $invoice->payment_status==="paid"
 && $receipts->count()===3) {
 $customer=Illuminate\Support\Facades\DB::table("customers")
  ->where("id",$invoice->customer_id)->where("organization_id",$invoice->organization_id)->value("name");
 $expectedAmounts=[$verifiedFixture["amount"],-$verifiedFixture["amount"],$verifiedFixture["amount"]];
 $matches=str_starts_with((string)$customer,"UAT PAYMENT SAFETY");
 foreach ($receipts as $n=>$receipt) {
  $expectedLink=$n===1?$verifiedFixture["receipt_ids"][0]:null;
  $matches=$matches && (int)$receipt->id===$verifiedFixture["receipt_ids"][$n]
   && (float)$receipt->amount===$expectedAmounts[$n]*1.0
   && (int)$receipt->organization_id===(int)$invoice->organization_id
   && (int)$receipt->customer_id===(int)$invoice->customer_id
   && (int)$receipt->invoice_id===$id && !$receipt->rental_id
   && ($receipt->reversal_of_payment_id===null?null:(int)$receipt->reversal_of_payment_id)===$expectedLink;
 }
 $labelled=$matches;
}
if (!$labelled) {
 fwrite(STDERR,"ACCEPTANCE_INVOICE_IS_NOT_THE_LABELLED_UAT_FIXTURE\n");exit(11);
}

$audit=Illuminate\Support\Facades\DB::table("activity_logs")->where("organization_id",$invoice->organization_id)
 ->where("invoice_id",$id)->orderBy("id")->get();
$payments=App\Models\Payment::query()->where("invoice_id",$id)->where("organization_id",$invoice->organization_id);
$today=Carbon\Carbon::today();
$out=["state"=>["invoice"=>$invoice,"receipts"=>$receipts,"rental"=>$fixtureRental,"audit"=>$audit],"expected"=>[
 "collectedToday"=>round((float)(clone $payments)->whereDate("payment_date",$today)->sum("amount"),2),
 "collectedThisMonth"=>round((float)(clone $payments)->whereYear("payment_date",$today->year)->whereMonth("payment_date",$today->month)->sum("amount"),2)
]];
if (__VERIFY_METRICS__) {
 $metrics=app(App\Services\Metrics\InvoiceMetricsService::class)->summary(
  App\Models\Invoice::query()->forOrganization((int)$invoice->organization_id)->whereKey($id)
 );
 $out["metrics"]=["collectedToday"=>$metrics["collectedToday"] ?? null,
                   "collectedThisMonth"=>$metrics["collectedThisMonth"] ?? null];
}

if (__VERIFY_METRICS__ && ($invoice->status==="cancelled" || $invoice->payment_status==="cancelled")) {
 $db=Illuminate\Support\Facades\DB::connection();
 $db->beginTransaction();
 try {
  Illuminate\Support\Facades\DB::listen(function ($query) {
   if (!preg_match('/^\s*select\b/i',$query->sql)) {
    throw new RuntimeException("CANCELLED_BALANCE_PROBE_MUST_NOT_WRITE");
   }
  });
  $model=App\Models\Invoice::query()->whereKey($id)->firstOrFail();
  $original=$model->getRawOriginal();
  $model->syncFinancialStatus(false);
  if ($model->status!=="cancelled" || $model->payment_status!=="cancelled"
   || (float)$model->balance_amount!==0.0
   || (float)$model->total_amount!==(float)$invoice->total_amount
   || (float)$model->paid_amount!==(float)$invoice->paid_amount
   || $model->fresh()->getRawOriginal()!==$original) {
   throw new RuntimeException("CANCELLED_BALANCE_PREVIEW_OR_PRESERVATION_FAILED");
  }
  $out["cancelled_balance"]=["balance_amount"=>(float)$model->balance_amount,
   "paid_amount"=>(float)$model->paid_amount,"stored_unchanged"=>true];
 } finally {
  $db->rollBack();
 }
}
echo json_encode($out,JSON_THROW_ON_ERROR);
'''
    code = code.replace("__VERIFIED_CORRECTION_FIXTURE__",
                        "json_decode(" + json.dumps(json.dumps(VERIFIED_CORRECTION_FIXTURE)) + ",true)")
    code = code.replace("__VERIFY_METRICS__", "true" if verify_metrics else "false")
    result = json.loads(execute(as_owner([PHP, "-d", "opcache.enable_cli=0", "-d", "opcache.jit=0",
                                          "-r", code, str(invoice_id)]), cwd=APP, capture=True))
    if verify_metrics:
        for key in ("collectedToday", "collectedThisMonth"):
            need(result.get("metrics", {}).get(key) is not None
                 and result["metrics"][key] == result["expected"][key],
                 "Deployed invoice collection figure does not match its receipts: " + key)
        note("UAT_COLLECTION_TOTALS_VERIFIED=true")
        invoice = result["state"]["invoice"]
        if "cancelled" in (invoice["status"], invoice["payment_status"]):
            preview = result.get("cancelled_balance", {})
            need(preview.get("balance_amount") == 0
                 and preview.get("paid_amount") == float(invoice["paid_amount"])
                 and preview.get("stored_unchanged") is True,
                 "Deployed cancelled-invoice preview or preservation check failed.")
            note("CANCELLED_INVOICE_BALANCE_RUNTIME_OK=" + str(invoice_id) + " DUE=0.00 READ_ONLY=true")
    receipts = result["state"]["receipts"]
    total = sum(float(record["amount"]) for record in receipts)
    note("UAT_TEST_INVOICE=" + str(invoice_id) + " RECEIPTS=" + str(len(receipts))
         + " RECEIPT_TOTAL=" + format(total, ".2f"))
    return result["state"]

def quote_mysql(value):
    value = "" if value is None else str(value)
    need("\0" not in value, "Unsupported database credential encoding.")
    for before, after in (("\\", "\\\\"), ('"', '\\"'), ("\n", "\\n"), ("\r", "\\r"), ("\t", "\\t")):
        value = value.replace(before, after)
    return '"' + value + '"'

def database_backup(config):
    phase("database_backup")
    client = shutil.which("mariadb-dump") or shutil.which("mysqldump")
    need(client, "No MariaDB backup client.")
    db = config["db"]
    defaults = BACKUP / "temporary-db-client.cnf"
    options = {"host": db["host"], "port": db.get("port") or 3306,
               "user": db["username"], "password": db.get("password", "")}
    if db.get("unix_socket"):
        options["socket"] = db["unix_socket"]
    defaults.write_text("[client]\n" + "".join(k + "=" + quote_mysql(v) + "\n" for k, v in options.items()))
    os.chmod(str(defaults), 0o600)
    destination = BACKUP / (DATABASE + ".sql.gz")
    try:
        with (BACKUP / "database-backup.log").open("wb") as err:
            process = subprocess.Popen(
                ["timeout", "--kill-after=10s", "600s", client, "--defaults-file=" + str(defaults),
                 "--single-transaction", "--quick", "--hex-blob", "--skip-lock-tables",
                 "--no-tablespaces", "--databases", DATABASE],
                env=ENV, stdin=subprocess.DEVNULL, stdout=subprocess.PIPE, stderr=err,
                start_new_session=True)
            try:
                with gzip.open(destination, "wb") as output:
                    shutil.copyfileobj(process.stdout, output)
                process.stdout.close()
                need(process.wait() == 0, "PHOS database backup failed; inspect database-backup.log.")
            finally:
                if process.poll() is None:
                    os.killpg(process.pid, signal.SIGTERM)
                    try:
                        process.wait(timeout=10)
                    except subprocess.TimeoutExpired:
                        os.killpg(process.pid, signal.SIGKILL)
                        process.wait()
        found = False
        with gzip.open(destination, "rb") as source:
            previous = b""
            while True:
                chunk = source.read(1024 * 1024)
                if not chunk:
                    break
                found = found or b"CREATE TABLE" in previous + chunk
                previous = chunk[-20:]
        need(found and destination.stat().st_size > 100, "Database backup schema verification failed.")
        note("DATABASE_BACKUP_VERIFIED=" + DATABASE)
    finally:
        if defaults.exists():
            defaults.unlink()

def changed_paths(repo):
    return git(repo, "diff", "--name-only", BASE, RELEASE).decode().splitlines()

def validate_change_names(changes, report=True):
    actual = {name for name in changes if runtime(name)}
    for name in sorted(actual - RUNTIME_CHANGED):
        if report:
            note("UNEXPECTED_RUNTIME_FILE=" + name)
    for name in sorted(RUNTIME_CHANGED - actual):
        if report:
            note("MISSING_EXPECTED_RUNTIME_FILE=" + name)
    need(actual == RUNTIME_CHANGED, "Unexpected runtime changes or dependencies in release.")
    need(set(changes) == REVIEWED_FILES, "Release contains an unexpected reviewed file set.")
    for name in changes:
        pure = PurePosixPath(name)
        need(not pure.is_absolute() and ".." not in pure.parts, "Unsafe release filename.")


def verify_copy_delta(before, after):
    old = b"Delete this invoice permanently? Payments will remain as payment records but will be unlinked from this invoice."
    new = b"Delete this invoice permanently? Invoices with payment, renewal, or audit history cannot be deleted."
    need(before.count(old) == 2 and after == before.replace(old, new),
         "Release must contain exactly the two reviewed confirmation text replacements.")


def verify_release(repo):
    need(git(repo, "rev-parse", REVIEWED + "^{tree}").decode().strip() == REVIEWED_TREE,
         "Reviewed PR tree differs from the tested source.")
    need(git(repo, "rev-parse", RELEASE + "^{tree}").decode().strip() == REVIEWED_TREE,
         "Merged tree differs from the validated PR.")
    changes = changed_paths(repo)
    validate_change_names(changes)
    name = "resources/views/invoices/index.blade.php"
    verify_copy_delta(git(repo, "show", BASE + ":" + name), git(repo, "show", RELEASE + ":" + name))
    note("PHOS_REVIEWED_RELEASE_CHECK_PASSED=" + str(len(RUNTIME_CHANGED)) + "_RUNTIME_FILES")
    return changes

def write_access_probe(directories, files):
    code = r'''
import json, pathlib, sys, tempfile
request = json.load(sys.stdin)
failures = []
for name in request["directories"]:
    try:
        with tempfile.TemporaryDirectory(prefix=".phos-write-check-", dir=name) as folder:
            child = pathlib.Path(folder) / "child"
            child.mkdir()
            (child / "check").write_bytes(b"PHOS deployment permission check\n")
    except OSError as error:
        failures.append({"path": name, "operation": "write", "errno": error.errno})
for name in request["files"]:
    try:
        with open(name, "rb") as source:
            source.read(1)
    except OSError as error:
        failures.append({"path": name, "operation": "read", "errno": error.errno})
print(json.dumps(failures))
'''
    request = {"directories": [str(p) for p in sorted(directories)],
               "files": [str(p) for p in sorted(files)]}
    return json.loads(execute(as_owner(["python3", "-c", code]), capture=True,
                              input_data=json.dumps(request).encode()))

def prepare_destinations(repo, changes, repair=False):
    base_files = {name.decode() for name in git(repo, "ls-tree", "-rz", "--name-only", BASE).split(b"\0") if name}
    parents, directories, files = set(), set(), set()
    for name in changes:
        file = APP / name
        no_links(file)
        if name not in base_files:
            need(not file.exists(), "New release destination already exists: " + name)
        if file.exists():
            regular(file)
            files.add(file)
        parent = file.parent
        while not parent.exists():
            parent = parent.parent
        need(parent == APP or APP in parent.parents, "Release destination escaped the application.")
        need(parent.is_dir(), "Release destination parent is not a directory: " + str(parent))
        directories.add(parent)
        while parent != APP:
            parents.add(parent)
            parent = parent.parent
    failures = write_access_probe(directories, files)
    if failures and repair:
        uid = pwd.getpwnam(OWNER).pw_uid
        records = []
        for parent in sorted(parents, key=lambda p: (len(p.parts), str(p))):
            st = parent.stat()
            mode = stat.S_IMODE(st.st_mode)
            if st.st_uid in (0, uid) and (st.st_uid != uid or mode & 0o700 != 0o700):
                records.append({"path": str(parent), "uid": st.st_uid, "gid": st.st_gid,
                                "mode": mode, "after_uid": uid, "after_mode": mode | 0o700})
        need(records, "Release destinations are blocked; no eligible directory permission repair found.")
        backup = BACKUP / "directory-permissions.before.json"
        with backup.open("x") as output:
            json.dump(records, output, indent=2)
            output.write("\n")
        note("DIRECTORY_PERMISSION_BACKUP=" + str(backup))
        for record in records:
            parent = Path(record["path"])
            no_links(parent)
            permissions(parent, record["after_uid"], record["gid"], record["after_mode"])
            note("RELEASE_DIRECTORY_REPAIRED=" + str(parent.relative_to(APP)))
        failures = write_access_probe(directories, files)
    for failure in failures:
        note("RELEASE_ACCESS_FAILURE=" + str(failure["operation"]) + ":" + str(failure["path"])
             + " ERRNO=" + str(failure["errno"]))
    need(not failures, "Release destinations are not accessible to the website account; maintenance was not started.")
    note("RELEASE_DESTINATIONS_VERIFIED=" + str(len(changes)) + "_FILES_AS_WEBSITE_OWNER")

def check_layout():
    need(APP.is_dir() and not APP.is_symlink() and WEB.is_dir() and not WEB.is_symlink(),
         "Unexpected PHOS application/web directory.")
    account = pwd.getpwnam(OWNER)
    for path in (APP, STORAGE, APP / "bootstrap/cache", WEB, WEB / "build"):
        need(path.is_dir() and not path.is_symlink() and path.stat().st_uid == account.pw_uid,
             "PHOS directory owner/layout changed: " + str(path))
    for path in (APP / ".env", WEB / "index.php", WEB / "build/manifest.json",
                 APP / "vendor/autoload.php"):
        regular(path)
    config = Path("/usr/local/lsws/conf/vhosts/" + HOST + "/vhost.conf")
    text = config.read_text()
    need(re.search(r"(?im)^\s*docRoot\s+\$VH_ROOT/public_html/public\s*$", text),
         "PHOS vhost document root differs from the inspected layout.")
    autoload = APP / "vendor/composer/autoload_real.php"
    need(not re.search(r"setClassMapAuthoritative\s*\(\s*true\s*\)", autoload.read_text()),
         "Authoritative Composer autoloading needs a separately rehearsed update.")
    need(git(APP, "symbolic-ref", "--short", "HEAD").decode().strip() == "uat",
         "PHOS checkout is not on the uat branch.")
    return settings()

def route_cache_present():
    files = [file for file in (APP / "bootstrap/cache").glob("routes*.php") if file.is_file()]
    need(len(files) <= 1, "Unexpected multiple route caches.")
    return bool(files)

def rebuild(cached):
    artisan("route:clear")
    if cached:
        artisan("route:cache")
    artisan("view:clear")
    artisan("view:cache")



# Exact reviewed release and asset allowlists.
BASE = 'f9e6de32620ca8ecaf7293e0ee15149cd8c78f22'
REVIEWED = 'daec56af76aa5df0d4b0d2f81ff78630de7c0888'
RELEASE = '09c6c23112dfb405c10b28cdde9d5717a16872e9'
REVIEWED_TREE = 'a3ef49779227e4696cdae67ba4b438639b48f0bf'
RUNTIME_CHANGED = {'resources/views/sales/create.blade.php', 'app/Http/Middleware/SerializeFinancialRequests.php', 'app/Http/Middleware/SecureHeaders.php', 'app/Http/Controllers/RentalAvailabilityController.php', 'routes/common-features.php', 'resources/views/rentals/_form.blade.php', 'app/Http/Controllers/CustomerController.php', 'app/Http/Controllers/DeliveryController.php', 'app/Http/Controllers/RentalController.php', 'app/Http/Controllers/SalesWorkspaceController.php', 'resources/views/deliveries/cash.blade.php', 'resources/views/invoices/partials/invoice-document-styles.blade.php', 'resources/views/sales-workspace/conversion-banner.blade.php', 'app/Services/WorkflowGuides.php', 'app/Http/Controllers/ProductController.php', 'app/Services/Sales/QuotationConversion.php', 'app/Models/Product.php', 'app/Models/Enquiry.php', 'app/Casts/UtcDateTime.php', 'app/Models/DeliveryCollection.php', 'app/Services/Deliveries/DeliverySubmissionService.php', 'app/Models/EnquiryFollowUp.php', 'app/Services/Sales/SalesWorkspace.php', 'resources/views/dashboard/compact.blade.php', 'resources/views/rentals/create.blade.php', 'resources/views/sales-workspace/quotation-script.blade.php', 'resources/views/sales-workspace/show.blade.php', 'routes/sales-workspace.php', 'database/migrations/2026_10_28_190000_create_phos_collection_and_cash_history.php', 'resources/views/sales-workspace/quote-line.blade.php', 'resources/views/sales-workspace/enquiry-form.blade.php', 'app/Services/Deliveries/DeliveryCashHandoverService.php', 'resources/views/sales-workspace/quote-show.blade.php', 'app/Http/Controllers/InvoiceController.php', 'resources/views/sales-workspace/style.blade.php', 'app/Http/Controllers/QuotationController.php', 'resources/views/invoices/show.blade.php', 'database/migrations/2026_10_28_190100_create_delivery_workflow_submissions.php', 'resources/views/sales-workspace/alerts.blade.php', 'resources/views/deliveries/collection-review.blade.php', 'resources/views/sales-workspace/quote-form.blade.php', 'app/Models/User.php', 'resources/views/sales-workspace/quote-public.blade.php', 'app/Http/Middleware/VerifyQuotationLink.php', 'resources/views/sales-workspace/customer-picker.blade.php', 'resources/js/app.js', 'database/migrations/2026_10_25_120000_add_guided_sales_fields.php', 'resources/views/availability/index.blade.php', 'resources/views/layouts/app.blade.php', 'resources/views/sales-workspace/index.blade.php', 'app/Services/Deliveries/DeliveryProofService.php', 'app/Http/Controllers/WorkflowGuideController.php', 'resources/views/sales-workspace/follow-up-form.blade.php', 'resources/views/sales-workspace/quote-pdf.blade.php', 'app/Services/Deliveries/DeliveryCollectionService.php', 'resources/views/deliveries/show.blade.php', 'database/migrations/2026_10_24_120000_create_sales_workspace.php', 'resources/views/guides/index.blade.php', 'resources/js/rental-availability.js', 'app/Models/Role.php', 'app/Services/Deliveries/SignatureCapture.php', 'resources/views/deliveries/collections.blade.php', 'resources/views/sales-workspace/quote-document.blade.php', 'resources/views/sales-workspace/quote-unavailable.blade.php', 'app/Models/Delivery.php', 'app/Models/DeliveryCashHandover.php', 'resources/views/customers/partials/quick-create-modal.blade.php', 'app/Models/Quotation.php', 'app/Http/Controllers/DeliveryCollectionController.php', 'app/Http/Controllers/SaleController.php', 'app/Models/EnquiryActivity.php', 'app/Services/Availability/RentalAvailability.php', 'resources/views/sales-workspace/customer-script.blade.php', 'routes/web.php'}
REVIEWED_FILES = {'resources/views/sales/create.blade.php', 'docs/common-features-backport.md', 'app/Http/Middleware/SerializeFinancialRequests.php', 'resources/views/rentals/_form.blade.php', 'tests/Concurrency/CommonMigrationPreservation.php', 'app/Services/Sales/SalesWorkspace.php', 'resources/views/dashboard/compact.blade.php', 'resources/views/rentals/create.blade.php', 'tests/Feature/Regression/DeliveryProofWorkflowRegressionTest.php', 'resources/views/sales-workspace/quote-show.blade.php', 'resources/views/sales-workspace/alerts.blade.php', 'tests/Support/RunMariaDbTests.php', 'resources/views/sales-workspace/quote-form.blade.php', 'resources/js/app.js', 'resources/views/availability/index.blade.php', 'resources/views/sales-workspace/index.blade.php', 'tests/Browser/PhosCommonFeatures.cjs', 'resources/views/deliveries/show.blade.php', 'resources/views/guides/index.blade.php', 'tests/Feature/Regression/RentalDateAvailabilityTest.php', 'resources/views/sales-workspace/quote-document.blade.php', 'app/Services/Availability/RentalAvailability.php', 'app/Http/Controllers/DeliveryCollectionController.php', 'app/Http/Controllers/SaleController.php', 'app/Models/EnquiryActivity.php', 'resources/views/sales-workspace/quote-public.blade.php', 'app/Http/Middleware/SecureHeaders.php', 'tests/Feature/Regression/PhosCashWorkflowTest.php', 'app/Http/Controllers/CustomerController.php', 'app/Http/Controllers/RentalController.php', 'tests/Feature/Regression/DeliveryBillingPermissionsRegressionTest.php', 'app/Models/DeliveryCollection.php', 'app/Services/Deliveries/DeliverySubmissionService.php', 'app/Models/EnquiryFollowUp.php', 'routes/sales-workspace.php', 'resources/views/sales-workspace/show.blade.php', 'database/migrations/2026_10_28_190000_create_phos_collection_and_cash_history.php', 'app/Services/Deliveries/DeliveryCashHandoverService.php', 'docs/validation/common-features-2026-10-28.json', 'resources/views/deliveries/collection-review.blade.php', 'app/Models/User.php', 'resources/views/sales-workspace/customer-picker.blade.php', 'resources/views/layouts/app.blade.php', 'resources/views/sales-workspace/quote-pdf.blade.php', 'app/Services/Deliveries/SignatureCapture.php', 'resources/views/deliveries/collections.blade.php', 'resources/views/sales-workspace/quote-unavailable.blade.php', 'tests/Feature/Regression/PhosPresentationAndGuidesTest.php', 'docs/validation/sales-workspace-review-2026-10-29.json', 'app/Models/DeliveryCashHandover.php', 'routes/web.php', 'tests/Feature/Regression/InvoiceBulkOperationsRegressionTest.php', 'routes/common-features.php', 'app/Http/Controllers/SalesWorkspaceController.php', 'resources/views/deliveries/cash.blade.php', 'resources/views/invoices/partials/invoice-document-styles.blade.php', 'app/Services/WorkflowGuides.php', 'app/Http/Controllers/ProductController.php', 'tests/Feature/Regression/SalesWorkspaceTest.php', 'app/Models/Product.php', 'resources/views/sales-workspace/quote-line.blade.php', 'resources/views/sales-workspace/enquiry-form.blade.php', 'app/Http/Controllers/InvoiceController.php', 'app/Http/Controllers/QuotationController.php', 'resources/views/invoices/show.blade.php', 'docs/validation/signature-review-2026-10-29.json', 'tests/Concurrency/CommonWorkflowWorker.php', 'database/migrations/2026_10_25_120000_add_guided_sales_fields.php', 'app/Services/Deliveries/DeliveryProofService.php', 'app/Services/Deliveries/DeliveryCollectionService.php', 'tests/Feature/Regression/PhosDeliverySubmissionTest.php', 'resources/js/rental-availability.js', 'tests/Support/DisposableMariaDb.php', 'app/Http/Controllers/RentalAvailabilityController.php', 'app/Http/Controllers/DeliveryController.php', 'tests/Feature/Regression/GuidedSalesWorkspaceTest.php', 'resources/views/sales-workspace/conversion-banner.blade.php', 'app/Services/Sales/QuotationConversion.php', 'app/Models/Enquiry.php', 'app/Casts/UtcDateTime.php', 'resources/views/sales-workspace/quotation-script.blade.php', 'tests/Concurrency/CommonWorkflows.cjs', 'resources/views/sales-workspace/style.blade.php', 'database/migrations/2026_10_28_190100_create_delivery_workflow_submissions.php', 'app/Http/Middleware/VerifyQuotationLink.php', 'app/Http/Controllers/WorkflowGuideController.php', 'database/migrations/2026_10_24_120000_create_sales_workspace.php', 'app/Models/Role.php', 'app/Models/Delivery.php', 'resources/views/customers/partials/quick-create-modal.blade.php', 'app/Models/Quotation.php', 'resources/views/sales-workspace/customer-script.blade.php', 'resources/views/sales-workspace/follow-up-form.blade.php'}
NEW_MIGRATIONS = {'2026_10_24_120000_create_sales_workspace', '2026_10_25_120000_add_guided_sales_fields', '2026_10_28_190100_create_delivery_workflow_submissions', '2026_10_28_190000_create_phos_collection_and_cash_history'}
ASSET_NAMES = {'public/build/manifest.json', 'public/build/assets/app-VMZt-FW_.js', 'public/build/assets/app-CkQmqSZE.css'}
ASSET_PAYLOAD_SHA256 = '2b327550cfbf4b2fa639eca0ed7828a9c5ff4eef83dea2c266e264c7f7e663af'

# Appended to the previously validated PHOS UAT helper primitives.
import base64, tarfile, time

NEW_TABLES = {'enquiries','quotations','enquiry_follow_ups','enquiry_activities',
              'delivery_collections','delivery_cash_handovers','delivery_workflow_submissions'}

def snapshot():
    files = [APP/'.env', APP/'bootstrap/cache/config.php', APP/'composer.lock',
             APP/'package-lock.json', APP/'routes/console.php', WEB/'index.php']
    return {'files':{str(p):sha(p.read_bytes()) for p in files},
            'storage':str(STORAGE.resolve()),'cron':scheduler()}

def preserved(before):
    need(snapshot() == before, 'Environment, dependency locks, scheduler or storage location changed.')

def database_probe():
    # Raw PDO, SELECT only. Results stay in the private backup, never in chat.
    code = r'''
$c=require $argv[1];$d=$c['database']['connections'][$c['database']['default']];
$dsn=!empty($d['unix_socket'])?'mysql:unix_socket='.$d['unix_socket']:'mysql:host='.$d['host'].';port='.($d['port']??3306);
$p=new PDO($dsn.';dbname='.$d['database'],$d['username'],$d['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$tables=$p->query("SELECT TABLE_NAME AS name,ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE'")->fetchAll(PDO::FETCH_ASSOC);
$new=['enquiries','quotations','enquiry_follow_ups','enquiry_activities','delivery_collections','delivery_cash_handovers','delivery_workflow_submissions'];
$out=['database'=>$p->query('SELECT DATABASE()')->fetchColumn(),'legacy'=>[],'new'=>[],'migrations'=>$p->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN)];
foreach($tables as $t){$name=$t['name'];if(!preg_match('/^[a-zA-Z0-9_]+$/',$name))throw new RuntimeException('Unexpected SQL identifier');
 if(in_array($name,$new,true)){$out['new'][$name]=['engine'=>$t['engine'],'rows'=>(int)$p->query('SELECT COUNT(*) FROM `'.$name.'`')->fetchColumn()];continue;}
 if($name==='migrations')continue;
 $hashes=[];$q=$p->query('SELECT * FROM `'.$name.'`');
 while($row=$q->fetch(PDO::FETCH_ASSOC)){
  if($name==='roles' && $row['is_system'] && in_array($row['slug'],['super_admin','admin_operations','sales'],true)){
   $permissions=json_decode($row['permissions'] ?: '{}',true,512,JSON_THROW_ON_ERROR) ?: [];unset($permissions['enquiries'],$permissions['quotations']);$row['permissions']=json_encode($permissions,JSON_THROW_ON_ERROR);
  }
  ksort($row);$hashes[]=hash('sha256',json_encode($row,JSON_THROW_ON_ERROR));
 }
 sort($hashes);$out['legacy'][$name]=['rows'=>count($hashes),'sha256'=>hash('sha256',json_encode($hashes,JSON_THROW_ON_ERROR))];
}
ksort($out['legacy']);ksort($out['new']);echo json_encode($out,JSON_THROW_ON_ERROR);
'''
    result=json.loads(execute([PHP,'-d','opcache.enable_cli=0','-d','memory_limit=512M','-r',code,
                               str(APP/'bootstrap/cache/config.php')],capture=True))
    need(result['database']==DATABASE,'Wrong database identity.')
    return result

def asset_payload(path):
    raw=Path(path).read_bytes()
    need(sha(raw)==ASSET_PAYLOAD_SHA256,'Asset package checksum mismatch.')
    entries=json.loads(raw)
    need(set(entries)==ASSET_NAMES,'Unexpected asset package paths.')
    result={}
    for name,entry in entries.items():
        need(name=='public/build/manifest.json' or re.fullmatch(r'public/build/assets/[a-zA-Z0-9_.-]+\.(js|css)',name), 'Unsafe asset destination.')
        data=gzip.decompress(base64.b64decode(entry['gzip_base64'],validate=True))
        need(len(data)<8*1024*1024 and sha(data)==entry['sha256'],'Asset content checksum mismatch.')
        result[name]=data
    manifest=json.loads(result['public/build/manifest.json'])
    for value in manifest.values():
        if value.get('file'): need('public/build/'+value['file'] in result,'Manifest asset missing from package.')
        for css in value.get('css',[]): need('public/build/'+css in result,'Manifest CSS missing from package.')
    return result

def verify_release(repo):
    need(git(repo,'rev-parse',RELEASE+'^{tree}').decode().strip()==REVIEWED_TREE,'Release tree mismatch.')
    need(git(repo,'rev-parse',REVIEWED+'^{tree}').decode().strip()==REVIEWED_TREE,'Reviewed tree mismatch.')
    names=changed_paths(repo);validate_change_names(names)
    need(not git(repo,'diff','--name-only',BASE,RELEASE,'--','composer.json','composer.lock','package.json','package-lock.json').strip(),
         'Dependency changes need a different deployment.')
    migrations={Path(n).stem for n in names if n.startswith('database/migrations/')}
    need(migrations==NEW_MIGRATIONS,'Unexpected migration set.')
    for name in names:
        mode=git(repo,'ls-tree',RELEASE,'--',name).split()[0]
        need(mode in (b'100644',b'100755'),'Removed or linked release file refused.')
    note('PINNED_RELEASE_VERIFIED='+RELEASE)
    return names

def new_runtime_ok():
    code=r'''
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$router=app('router');
foreach(['enquiries.index','quotations.create','availability.index','dashboard.compact','workflow-guides.index','cash-handovers.index','delivery-collections.index','invoices.download'] as $name){
 $r=$router->getRoutes()->getByName($name);if(!$r || !in_array('auth',$r->gatherMiddleware(),true))throw new RuntimeException('New route authentication missing: '.$name);
}
foreach(['delivery-collections.store','delivery-collections.review','delivery-collections.reverse','cash-handovers.confirm','deliveries.complete'] as $name){
 $r=$router->getRoutes()->getByName($name);if(!$r || !in_array(App\Http\Middleware\SerializeFinancialRequests::class,$router->gatherRouteMiddleware($r),true))throw new RuntimeException('New transaction guard missing: '.$name);
}
foreach(['quotation-share.show','quotation-share.pdf','quotation-share.respond'] as $name){
 $r=$router->getRoutes()->getByName($name);if(!$r || !in_array(App\Http\Middleware\VerifyQuotationLink::class,$router->gatherRouteMiddleware($r),true))throw new RuntimeException('Quotation signature guard missing');
}
if(!class_exists(App\Casts\UtcDateTime::class) || !class_exists(App\Services\Deliveries\SignatureCapture::class))throw new RuntimeException('New classes unavailable');
echo 'NEW_FEATURE_ROUTE_AND_AUTOLOAD_GUARDS_OK';
'''
    note(execute(as_owner([PHP,'-d','opcache.enable_cli=0','-r',code]),cwd=APP,capture=True).decode())

def storage_backup():
    path=STORAGE/'app';no_links(path);need(path.is_dir(),'Storage app directory missing.')
    destination=BACKUP/'storage-app.tar.gz'
    with tarfile.open(destination,'w:gz',dereference=False) as archive:
        archive.add(path,arcname='storage-app',recursive=True)
    with tarfile.open(destination,'r:gz') as archive:
        need(bool(archive.getmembers()),'Storage backup is empty.')
        for member in archive:
            if member.isfile():
                stream=archive.extractfile(member)
                while stream.read(1024*1024): pass
    note('PRIVATE_STORAGE_BACKUP_VERIFIED=true')

def no_new_history():
    state=database_probe()
    need(all(entry['rows']==0 for entry in state['new'].values()),
         'New business history exists. Automatic code rollback is unsafe; retain maintenance and use coordinated recovery.')

def rollback(state):
    phase('rollback_code_only')
    need(state['app']==str(APP) and state['release']==RELEASE,'Wrong rollback identity.')
    if not (STORAGE/'framework/down').exists():
        artisan('down','--retry=60');time.sleep(10)
    no_new_history();preserved(state['preserved'])
    need({r['path'] for r in state['files']}=={str(APP/n) for n in REVIEWED_FILES|ASSET_NAMES},'Unexpected rollback destinations.')
    validate_backups(state['files']+[state['index']],BACKUP)
    head=git(APP,'rev-parse','HEAD').decode().strip();need(head in (BASE,RELEASE),'Server HEAD changed; recovery needs review.')
    need(sha(Path(state['index']['path']).read_bytes()) in {state['index']['before'],state.get('index_after')},'Server Git index changed.')
    if not (STORAGE/'framework/down').exists(): artisan('down','--retry=60')
    restore_files(state['files'],BACKUP)
    if head==RELEASE: git(APP,'update-ref','refs/heads/uat',BASE,RELEASE)
    record=state['index'];atomic(Path(record['path']),(BACKUP/record['saved']).read_bytes(),record['uid'],record['gid'],record['mode'])
    rebuild(state['route_cached']);web_cache(True);verify_source(APP,BASE,'RESTORED_BASELINE')
    need(database_probe()['legacy']==state['legacy'],'Legacy database records changed; inspect before resuming.')
    preserved(state['preserved']);artisan('up');health();assets()
    note('ROLLBACK_COMPLETE_CODE_ONLY=true ADDITIVE_SCHEMA_RETAINED=true DATABASE_RESTORED=false')

def main():
    global BACKUP,LOG
    parser=argparse.ArgumentParser();parser.add_argument('gitdir',nargs='?')
    parser.add_argument('--assets');parser.add_argument('--target-config');parser.add_argument('--check-release',action='store_true')
    parser.add_argument('--rollback');args=parser.parse_args()
    if args.check_release:
        if os.name=='nt': ENV['PATH']=os.environ.get('PATH',PATH)
        verify_release(Path(args.gitdir).resolve());asset_payload(args.assets);note('RELEASE_AND_ASSET_CHECK_PASSED');return
    need(pwd is not None and fcntl is not None and os.geteuid()==0,'Run in the root Linux server terminal.')
    os.umask(0o077);need(args.target_config,'Private target config required.');load_target(args.target_config)
    lock=open('/root/phos-uat-pr1-deploy.lock','a');fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    for name in ('git','runuser','curl','timeout','crontab'):need(shutil.which(name,path=PATH),'Missing tool: '+name)
    check_layout()
    if args.rollback:
        BACKUP=Path(args.rollback);no_links(BACKUP)
        need(BACKUP.parent==Path('/root') and BACKUP.name.startswith('phos-uat-pr8-backup-'),'Unexpected rollback path.')
        need(BACKUP.stat().st_uid==0 and stat.S_IMODE(BACKUP.stat().st_mode)&0o077==0,'Backup is not private.')
        LOG=BACKUP/'rollback.log';rollback(json.loads((BACKUP/'rollback-state.json').read_bytes()));return
    repo=Path(args.gitdir).resolve();changes=verify_release(repo);payload=asset_payload(args.assets)
    need(not (STORAGE/'framework/down').exists() and not (STORAGE/'framework/maintenance.php').exists(),'Site already in maintenance.')
    BACKUP=Path(tempfile.mkdtemp(prefix='phos-uat-pr8-backup-',dir='/root'));LOG=BACKUP/'deployment.log'
    (BACKUP/'target-config.json').write_bytes(TARGET_CONFIG_BYTES)
    current=git(APP,'rev-parse','HEAD').decode().strip()
    if current==RELEASE:
        verify_source(repo,RELEASE,'ALREADY_DEPLOYED')
        existing=database_probe()
        need(NEW_MIGRATIONS<=set(existing['migrations']) and set(existing['new'])==NEW_TABLES
             and all(x['engine']=='InnoDB' for x in existing['new'].values()),'Release HEAD exists but new schema is incomplete.')
        need(all((APP/n).is_file() and sha((APP/n).read_bytes())==sha(data) for n,data in payload.items()),'Release HEAD exists but frontend assets are incomplete.')
        schema_ok(db_state());correction_ok(db_state());new_runtime_ok();health();assets();note('DEPLOYMENT_ALREADY_CURRENT');return
    need(current==BASE,'Site is not at the verified pre-release baseline.')
    need(not git(APP,'diff','--cached','--name-only').strip(),'Staged server changes need review.')
    verify_source(repo,BASE,'BASELINE');config=settings();schema_ok(db_state());correction_ok(db_state())
    applied=database_probe()['migrations']
    migrations={Path(n.decode()).stem for n in git(repo,'ls-tree','-rz','--name-only',RELEASE,'--','database/migrations').split(b'\0') if n}
    need(migrations-set(applied)==NEW_MIGRATIONS and not set(applied)-migrations,'Pending migration set differs from exactly four reviewed migrations.')
    need(not database_probe()['new'],'New tables already exist; reconcile partial deployment before retrying.')
    need(shutil.disk_usage(BACKUP).free>1024*1024*1024,'At least 1 GiB free is required for backups.')
    prepare_destinations(repo,changes,True)
    for name,data in payload.items():
        path=APP/name;no_links(path);need(path.parent.is_dir(),'Asset directory missing.')
        if path.exists(): regular(path)
        if path.exists() and name!='public/build/manifest.json':need(sha(path.read_bytes())==sha(data),'Hashed asset collision.')
    before=snapshot();health();web_cache(False)
    git(APP,'fetch','--quiet','--no-tags',URL,RELEASE)
    need(git(APP,'rev-parse','FETCH_HEAD').decode().strip()==RELEASE,'Unexpected fetched commit.')
    phase('back_up_reviewed_paths')
    index_path=Path(git(APP,'rev-parse','--git-path','index').decode().strip())
    if not index_path.is_absolute():index_path=APP/index_path
    index=save_file(index_path,BACKUP,'git-index',None)
    records=[save_file(APP/n,BACKUP,i,sha(git(repo,'show',RELEASE+':'+n))) for i,n in enumerate(changes)]
    records += [save_file(APP/n,BACKUP,'asset-'+str(i),sha(data)) for i,(n,data) in enumerate(payload.items())]
    state={'app':str(APP),'release':RELEASE,'files':records,'index':index,'route_cached':route_cache_present(),'preserved':before}
    shutil.copyfile(Path(__file__).resolve(),BACKUP/'rollback-helper.py')
    (BACKUP/'rollback-state.json').write_text(json.dumps(state,indent=2)+'\n')
    note('BACKUP_DIRECTORY='+str(BACKUP))
    note('RECOVERY_COMMAND=python3 '+str(BACKUP/'rollback-helper.py')+' --target-config '+str(BACKUP/'target-config.json')+' --rollback '+str(BACKUP))
    maintenance=mutated=False
    try:
        maintenance=True;artisan('down','--retry=60');time.sleep(10)
        phase('database_and_private_storage_backups');database_backup(config);storage_backup()
        state['legacy']=database_probe()['legacy']
        state['legacy_assets']={str(p):sha(p.read_bytes()) for p in (WEB/'build').rglob('*') if p.is_file() and p.name!='manifest.json'}
        (BACKUP/'rollback-state.json').write_text(json.dumps(state,indent=2)+'\n')
        preserved(before);verify_source(repo,BASE,'PRE_ACTIVATION')
        phase('activate_pinned_release');mutated=True;git(APP,'merge','--ff-only','--no-edit',RELEASE)
        state['index_after']=sha(index_path.read_bytes());(BACKUP/'rollback-state.json').write_text(json.dumps(state,indent=2)+'\n')
        uid=pwd.getpwnam(OWNER).pw_uid;gid=STORAGE.stat().st_gid
        for record in records[:len(changes)]:
            p=Path(record['path']);regular(p);permissions(p,record.get('uid',uid),record.get('gid',gid),record.get('mode',0o644))
        verify_source(repo,RELEASE,'DEPLOYED_SOURCE')
        phase('apply_only_four_additive_migrations')
        artisan('migrate','--force',*['--path=database/migrations/'+name+'.php' for name in sorted(NEW_MIGRATIONS)])
        after=database_probe()
        need(set(after['new'])==NEW_TABLES and all(x['engine']=='InnoDB' and x['rows']==0 for x in after['new'].values()),'New schema is incomplete or contains unexpected history.')
        need(set(after['migrations'])-set(applied)==NEW_MIGRATIONS,'Unexpected migration history changes.')
        need(after['legacy']==state['legacy'],'Legacy business records changed during migration.')
        phase('install_verified_frontend_assets')
        for name,data in payload.items():atomic(APP/name,data,uid,gid,0o644)
        rebuild(state['route_cached']);web_cache(True);routes_ok();correction_runtime_ok();country_codes_ok();new_runtime_ok()
        schema_ok(db_state());correction_ok(db_state());preserved(before)
        need(database_probe()['legacy']==state['legacy'],'Runtime checks changed legacy records.')
        need(all(Path(p).is_file() and sha(Path(p).read_bytes())==h for p,h in state['legacy_assets'].items()),'Existing frontend assets changed.')
        need(all(sha((APP/n).read_bytes())==sha(data) for n,data in payload.items()),'Installed frontend differs from verified package.')
        # No application write probes: manual acceptance follows only after release verification.
        phase('bring_uat_online');artisan('up');maintenance=False;health();assets()
        note('DEPLOYMENT_COMPLETE');note('ENVIRONMENT=PHOS_UAT');note('COMMIT='+RELEASE)
        note('LEGACY_DATA_PRESERVED=true PRIVATE_STORAGE_BACKUP_VERIFIED=true MIGRATIONS_RUN=4 PRODUCTION_CHANGED=false')
        (BACKUP/'deployment-complete.json').write_text(json.dumps({'commit':RELEASE,'legacy_tables_verified':len(state['legacy']),'migrations':sorted(NEW_MIGRATIONS)},indent=2)+'\n')
    except BaseException:
        if mutated:
            try:rollback(state);maintenance=False
            except BaseException:note('AUTOMATIC_ROLLBACK_INCOMPLETE: retain private backup and inspect recovery log.');raise
        elif maintenance:artisan('up')
        raise

if __name__=='__main__':
    try:main()
    except BaseException as error:
        note('STOPPED: '+str(error));sys.exit(1)
