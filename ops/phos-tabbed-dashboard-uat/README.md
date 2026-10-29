# PHOS tabbed dashboard: guarded UAT operator package

This is a **UAT-only, file-only** rollout helper. It is separate from the PR 8
migration helper and must not be substituted for that helper or used on production.
No deployment occurs when these files are committed, downloaded or tested.

The fixed target is `https://uat-admin.primehealers.com/`, application
`/home/uat-admin.primehealers.com/public_html`, document root `public/`, database
`uata_primehealersos`, and PHP 8.2 under the existing LiteSpeed installation.
The helper validates the resolved application configuration and the connected
database's `SELECT DATABASE()` result **before** HTTP smoke checks.

It loads the existing root-private PR 8 target configuration when available.
An existing configuration with unexpected permissions, links or identity stops
the operation. If the configuration is absent, the helper derives the non-root
website account from the fixed application's ownership and requires the normal
LiteSpeed PHP 8.2 executable. There is no command-line host/application/database
override. No server credentials or private configuration belong in this package.

## Release scope and baseline

The exact PR 8 source baseline is:

`09c6c23112dfb405c10b28cdde9d5717a16872e9`

The helper embeds SHA256 hashes of the three existing reviewed files and requires
the four new paths to be absent. It checks **file contents**, not the server's Git
HEAD, and does not change server Git refs or its index. This handles the previously
deployed file state without assuming that a historical server Git ref was advanced.
Unknown edits, partially installed releases, new-path collisions, symlinks and
unexpected archive contents stop activation.

Exactly these seven paths may be installed:

- `resources/views/dashboard.blade.php`
- `resources/views/layouts/app.blade.php`
- `app/Http/Controllers/RentalController.php`
- `resources/views/dashboard/executive-navigation.blade.php`
- `resources/views/dashboard/overview-summary.blade.php`
- `public/css/phos-executive-dashboard.css`
- `public/js/phos-executive-dashboard.js`

Controller changes in the reviewed release are limited to the compact-dashboard
alias redirect. The review/package producer must verify that restriction; the
operator helper verifies the pinned file bytes, not the semantics of arbitrary PHP.

The helper never invokes migrations, seeders, imports, dependency installation,
database restoration, business-write probes or schema changes. It never requires
PR 8 workflow tables to be empty: legitimate existing history remains in place.
Ordinary HTTP/browser acceptance checks may create **transient framework session
or cache records**. No byte-identical database claim is made.

## Package contract

Produce the package only after the final application commit has been reviewed and
tests pass. The custom ZIP contains the seven allowlisted relative files, with no
directory entries, links, extra files or enclosing folder. Read each file from the
exact Git commit, not from an uncommitted working tree.

The separate UTF-8 JSON manifest has this exact schema:

```json
{
  "format": 1,
  "environment": "PHOS_UAT",
  "baseline": "09c6c23112dfb405c10b28cdde9d5717a16872e9",
  "release": "EXACT_40_CHARACTER_REVIEWED_COMMIT",
  "archive_sha256": "EXACT_64_CHARACTER_ARCHIVE_SHA256",
  "files": [
    {
      "path": "resources/views/dashboard.blade.php",
      "sha256": "EXACT_64_CHARACTER_NEW_FILE_SHA256",
      "baseline_sha256": "58c83b6521bf50ca5493e294c61b5c081e922cb37a56f45950625c7d39206b29",
      "size": 12345
    }
  ]
}
```

Include one entry per allowlisted path. For the four new files,
`baseline_sha256` is JSON `null`. Existing-file baseline hashes must match
`BASELINE_HASHES` in `deploy.py`. File size is the byte count. The ZIP SHA256 is
recorded in the manifest; the manifest's own SHA256 is pinned in the separately
prepared operator command. No checksum is downloaded from an unpinned mutable URL
and then treated as its own authority.

The operator bootstrap must pin all of:

- Reviewed application release commit.
- Helper commit and helper file SHA256.
- Custom release ZIP SHA256.
- Manifest SHA256.

Fetch public source from the exact commit over verified HTTPS, then verify the
above checksums before executing the helper. Do not use a mutable branch URL,
unverified remote script pipe, `curl -k`, or disabled host-key verification.
The final copyable operator command is generated only after those real values
exist; this README's placeholders are not a deployment command.

## Offline checks

`--check-package` validates the pinned hashes, manifest identity, embedded source
baseline, ZIP paths/file types/sizes and every payload hash. It works without Linux,
the server, application dependencies or a database:

```text
python deploy.py --check-package --manifest manifest.json --archive release.zip \
  --manifest-sha256 MANIFEST_SHA256 --archive-sha256 ARCHIVE_SHA256 \
  --expected-release REVIEWED_COMMIT
```

Run the offline guard tests with:

```text
python ops/phos-tabbed-dashboard-uat/test_deploy.py
```

The tests use temporary package/source/recovery fixtures, not UAT. A fixture root
is passed to pure verification functions; no production guard or command-line
target override is introduced for testing. Windows may skip the symlink-creation
test if that process lacks the required OS privilege.
The Git checkout must contain the baseline commit above because source/recovery
fixtures are read from that exact commit; the tests do not fetch it automatically.
Local validation covered 27 tests: 26 passed and the Windows symlink-creation test
was skipped for that OS privilege. All three embedded PHP probes compiled under
PHP 8.2. The partial-activation rehearsal used real temporary file replacement and
removal with external runtime/ownership calls mocked; it was not a Linux server
deployment rehearsal or a live-UAT acceptance test.

## Operator execution and verification

The operator runs the checksum-verified helper in the Linux root terminal using
the same pinned arguments as offline validation, replacing `--check-package` with
`--deploy`. The helper takes an exclusive private lock, creates a root-private
backup directory, and checks the exact UAT identity, baseline source, PHP and
document-root layout before activation.

Before changing application files it saves each existing destination's bytes and
ownership/mode, records absent new files, preserves environment/dependency/route
cache/build asset fingerprints, and writes a self-contained recovery helper and
state. Its printed recovery command identifies that exact release and backup.
Keep the earlier PR 8 database/storage backup independently; this dashboard helper
does not overwrite it or take/restore a business database snapshot.

Activation uses a brief UAT file-maintenance window and atomic per-file writes.
New directories/files belong to the website account; existing file metadata is
retained. There is no recursive permission change. Only compiled views are rebuilt;
route caches, dependencies, existing Vite assets, environment and scheduler remain
untouched. A random, token-protected, loopback-only PHP probe verifies the web PHP
runtime and invalidates OPcache entries under the exact UAT application path.
The probe is removed in a `finally` block; it never resets a shared global cache.

Verification includes:

- Actual HTTPS `/login` and `/up` responses through local vhost resolution and
  public DNS, without bypassing TLS verification.
- Unauthenticated dashboard, compact alias, invoices, rentals and sales routes
  redirecting to this UAT application's login.
- HTTP-kernel route introspection preserving dashboard authentication, existing
  payment-submission guards and PR 8 financial transaction guards.
- Local/public CSS and JavaScript responses matching the pinned release bytes.
- Exact installed source hashes and preserved configuration/dependency/route-cache/
  historical-build-asset fingerprints.

Successful output names the release, private backup and recovery command, with
`MIGRATIONS_RUN=0`, `BUSINESS_DATA_WRITES=false` and `PRODUCTION_CHANGED=false`.
The business-write statement refers to helper actions; normal framework session
or cache records can be created by the authorized HTTP smoke checks.
These checks do not substitute for authenticated role/device acceptance of tab
selection, filters, expanded details, calculations or keyboard navigation.

## Recovery

A failed activation attempts to restore only the seven reviewed destinations from
the new dashboard backup, rebuild baseline compiled views, invalidate only UAT
OPcache entries and bring UAT back online after checks pass. Recovery does not
consult PR 8 business history, restore a database, drop tables, rerun migrations,
or roll back the earlier PR 8 features. It returns dashboard source to PR 8.

Before activation, the helper saves the exact file-maintenance payload generated
by baseline Laravel. Recovery can re-establish maintenance from that verified
private copy without booting newly activated code. Source files are restored
before any web-cache probe or view rebuild. A persistent cache/probe failure
therefore leaves baseline files restored and UAT in maintenance for review; it
cannot prevent source undo. Regression rehearsals cover persistent web-cache
failure and an activated-code `artisan down` failure.

Before restoring anything, recovery checks every saved original hash and confirms
each current destination still contains the expected baseline or deployed bytes.
Unexpected later edits stop recovery for review instead of being overwritten.
New directories are removed only when empty; no recursive deletion occurs.

Manual recovery uses the exact `RECOVERY_COMMAND` printed before activation.
If automatic recovery cannot complete, retain the private backup/log and inspect
the stated failure before resuming. A killed process or server restart is not an
automatically handled failure; use the saved recovery command. Do not use the old
PR 8 migration rollback helper for this dashboard release.
