# PHOS UAT: private Zoho SMTP setup

The separate [synthetic PDF/runtime diagnostic](pdf-diagnostic.md) checks existing UAT runtime isolation before installation. It does not configure mail or deploy code.

The [bounded permission repair](permission-repair.md) addresses the observed UAT shared-group permissions before that diagnostic. It verifies the actual web PHP account before any permission change and includes guarded recovery; it changes no configuration values or business records.

This is a separate operator utility. It does not deploy application source, merge code, migrate, restore a database, send a message or access production. Old rollout helpers remain unchanged. No live server/SMTP run has been performed during preparation.

The authorized mailbox and sender are fixed as `support@primehealers.com`. The application, host and database are fixed to the existing PHOS UAT installation. The helper requires the root operator terminal, validates the website's non-root owner and exact private target identity, and runs PHP/configuration commands and the SMTP authentication child as that owner. It has no credential, host or target command-line arguments.

## Before the operator runs it

1. Deploy and verify the separately reviewed application update first. Its SMTP configuration must contain fixed `require_tls => true`. The setup utility refuses incompatible/uncached-effective configurations that lack that protection. It also refuses any nonempty `MAIL_URL`, because that URL can override individual SMTP settings. It never edits that extra key automatically.
2. In the mailbox's Zoho **Server Configuration Details**, copy its exact outgoing server and port. Do not infer the datacenter from the user's location. Zoho documents SMTPS on 465 and TLS on 587, with account-specific server details taking precedence. [Zoho SMTP configuration](https://www.zoho.com/mail/help/zoho-smtp.html)
3. Generate a dedicated UAT app-specific password in Zoho Accounts → Security → Application Specific Passwords. Use a recognizable UAT name and keep the value private. Zoho recommends app-specific passwords and allows them to be revoked independently; use this instead of the main account password. [Zoho app-specific passwords](https://help.zoho.com/portal/en/kb/accounts/manage-your-zoho-account/articles/mfa-application-specific-passwords)
4. Use the separately checksum-verified copy of `setup-zoho-mail.py` supplied for the operator terminal, then run it with `python3 -B`. Do not paste the password into chat, a command, an environment variable, a screenshot, shell history or a log. The helper opens `/dev/tty` and uses a hidden `getpass` prompt; it refuses an unsafe echoed-password fallback.

The initial exact host allowlist is `smtp.zoho.com`, `smtppro.zoho.com`, `smtp.zoho.in`, and `smtppro.zoho.in`. The `.com` hosts appear in Zoho's SMTP documentation; the `.in` pair is explicitly published by the Zoho Mail Team. [Zoho IN datacenter notice](https://help.zoho.com/portal/en-gb/community/topic/important-notice-to-zoho-mail-indian-datacentre-in-dc-deployment-users) If the account shows a different datacenter hostname, stop and request an allowlist review. The helper does not construct or guess regional names.

## What happens

Before prompting or changing anything, the helper checks the fixed UAT path/vhost, LiteSpeed PHP 8.2, owner, unlinked regular `.env` file, single hard-link count and private `.env` mode (0600 or 0640). It loads only environment/configuration with provider registration disabled, checks UAT HTTPS URL/environment and local MySQL identity using only `SELECT DATABASE()`, and refuses duplicate environment keys, multiline values or a nonempty mail URL. No user, token, password hash, customer or business row is read.

Effective config/services/packages/routes/events cache paths must be the standard paths inside this UAT application's `bootstrap/cache`, and storage must be this application's `storage` directory. Nonstandard path overrides are refused before configuration loading or Artisan work. Parent links are checked even when a target file does not yet exist. The helper never follows a cache/storage override into another application.

The operator supplies the exact account SMTP host, 465/587, a display name (default **Prime Healers**) and the app-specific password. Inputs reject control characters, including CR/LF/NUL. New mail values are quoted with dotenv interpolation/quote escaping and parsed in memory by the installed dotenv parser before activation. Every unrelated environment line remains byte-for-byte intact. Missing mail keys are appended with the original newline style.

The helper then validates a local HTTPS-only cache probe and attempts exactly one SMTP `AUTH PLAIN` inside verified TLS. Port 465 uses implicit TLS; port 587 requires STARTTLS before authentication. Certificate and hostname verification remain enabled with the system CA store and TLS 1.2 or newer. There is no authentication fallback/retry and no SMTP message command. Successful authentication alone does not mean application setup succeeded.

After authentication, the helper rechecks file drift and creates a root-private 0700 backup directory containing 0600 copies of the original `.env`, original config cache when present, original file metadata, recovery state and its own recovery script. These server-private files necessarily contain the original secrets; they are never uploaded or committed. Only their private directory/recovery command is printed. No credential or captured PHP/SMTP output is logged or printed. Failures print exception class names only.

A temporary file-based UAT maintenance marker protects activation. Only these eight environment keys change:

```
MAIL_MAILER
MAIL_HOST
MAIL_PORT
MAIL_SCHEME
MAIL_USERNAME
MAIL_PASSWORD
MAIL_FROM_ADDRESS
MAIL_FROM_NAME
```

`APP_KEY`, database credentials and other settings remain unchanged. Additional runtime fingerprints verify that the effective application key/URL/environment/timezone, database and session configurations did not change indirectly. The website owner runs only `config:clear` and `config:cache`, with a private umask. The helper verifies the effective mail settings without printing them, then invalidates only this UAT application's `bootstrap/cache/config.php` in web OPcache. The short-lived PHP probe requires both a loopback `REMOTE_ADDR` and a random header token; its HTTPS certificate/hostname is verified, its token is never placed in a URL, and the file is deleted in `finally`. It never resets global OPcache.

Only verified activation removes the helper's own maintenance marker and prints `ZOHO_MAIL_CONFIGURATION_COMPLETE=true`. It also prints `MANUAL_FORGOT_PASSWORD_VERIFICATION_PENDING=true`. It does not claim that an email was delivered.

## Failure and recovery

On an activation failure, safe file undo happens **before** cache probes or runtime verification. A persistent probe failure cannot block restoration of files whose identity is known. Hash, owner, group, mode and modification-time signatures are rechecked after maintenance creation and immediately before activation; the cache is rechecked again before framework cache mutation. Recovery tracks whether the helper started cache mutation, so a failure before that point cannot overwrite a different administrator's cache.

Once cache mutation starts, recovery accepts an absent cache, the original known cache or a recorded verified new cache signature. Any other present cache is preserved, including partial output from a failed/interrupted `config:cache` command whose signature was never verified. The environment file is restored where safe, but the helper reports `ORIGINAL_FILES_RESTORED=false`, an uncertain rollback and retained maintenance when an unknown cache remains. It does not guess whether those bytes came from the failed command or an administrator. This conservative case requires private operator review; the backup is retained and the recovery command may continue to refuse unknown bytes. Detected concurrent edits are preserved. Do not run another editor, deployment or cache command concurrently with this brief operation. The helper never reports success from SMTP authentication alone.

The printed recovery command uses the root-private copied helper and `--rollback` backup path. Recovery verifies the fixed target, private backup ownership/modes, copied-helper checksum, backup checksums and original metadata before restoring only the two reviewed files. It performs no database restore and makes no SMTP connection. Do not delete a maintenance marker manually after an uncertain result; review the reported class and private recovery state first. Do not copy backup contents or credentials into chat.

The file-based maintenance marker and short-lived cache probe are bounded operational artifacts, approved for configuration activation/recovery. No other application source, `.env` key, global OS configuration, service, DNS record or production target is changed. Framework configuration commands may perform their normal local cache work. The utility itself sends no email and does not submit a password-reset request.

## Final operator verification

After the helper reports complete, use the PHOS **UAT** Forgot Password screen manually with an existing UAT account whose inbox you control. This intentional operator action sends the actual verification email; the helper does not do so. Verify the expected sender, delivery to that inbox and an HTTPS reset link on the UAT host. Do not paste the reset token/link or password into chat. Complete a controlled password reset and login only if that is the intended account test. Report only success/failure and the UAT host, with no token or credential. If delivery fails, inspect Zoho/account policy privately; do not rerun authentication repeatedly or switch hosts by guesswork.

## Offline validation

Run `python -B -m unittest -v test_setup_zoho_mail.py` from this directory. All SMTP/HTTP calls are mocked; the real local PHP check uses installed PHP extensions and runs only the dotenv candidate parser with synthetic inputs. Its early return precedes application construction, providers and PDO, regardless of which database extensions are installed. This keeps Linux's dynamically loaded ctype extension available. Windows filesystem tests stub Linux ownership/mode calls and adapt unsupported `utime(..., follow_symlinks=False)`; production Linux guards remain unchanged.

Coverage includes exact hostname/port restrictions, injection/duplicate/MAIL_URL refusal, secret quoting and unchanged environment bytes, verified SMTPS/STARTTLS, one AUTH attempt and no message command, missing STARTTLS/auth failure without fallback, known-file restoration despite persistent probe failure, config-cache command failure with unknown present bytes preserved, absent-cache restoration, injected environment/cache/metadata drift, same-byte reruns, exact cache/storage path refusal, absent-target linked-parent refusal and successful recovery before maintenance removal. Local Windows validation runs 21 tests with the directory-symlink case skipped when Windows denies symlink creation; Linux CI runs that case and discovers PHP through the system path.
