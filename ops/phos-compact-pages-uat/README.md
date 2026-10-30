# PHOS compact list pages: guarded UAT operator package

This separate file-only helper upgrades **PHOS UAT** from the exact installed
`c0fef0f918ab2ea01be4bc3222f811d9f4a3d664` source. It does not alter the previous
dashboard helper or its private backups. Downloading, committing or checking the
package does not deploy it. Activation requires the operator's Linux root terminal.

The fixed target is `https://uat-admin.primehealers.com/`, application
`/home/uat-admin.primehealers.com/public_html`, document root `public/`, database
`uata_primehealersos`, and LiteSpeed PHP 8.2. Existing root-private PR 8 target
configuration supplies the website account and executable when available. An
invalid existing configuration stops the operation; an absent one permits only
the fixed UAT path, its non-root website owner and the normal LiteSpeed PHP 8.2
executable. The application URL/environment and connected `SELECT DATABASE()`
identity are checked before HTTP probes. There are no host/database/path overrides.

## Exact release scope

The package contains thirteen paths. These nine originals must match the embedded
c0f baseline SHA256 values and are backed up with their ownership and mode:

- `resources/views/rentals/index.blade.php`
- `resources/views/sales/index.blade.php`
- `resources/views/customers/index.blade.php`
- `resources/views/business-partners/index.blade.php`
- `resources/views/renewals/index.blade.php`
- `resources/views/pickups/index.blade.php`
- `resources/views/communications/index.blade.php`
- `resources/views/deliveries/index.blade.php`
- `resources/views/layouts/app.blade.php`

These four new destinations must be absent, with all parent directories already
present and unlinked:

- `resources/views/components/phos-list-filter.blade.php`
- `resources/views/components/phos-action-icon.blade.php`
- `public/css/phos-operations.css`
- `public/js/phos-operations.js`

Unknown baseline bytes, missing originals/parents, new-file collisions, symlinks,
unexpected package entries or edits outside this allowlist stop activation. The
helper checks file contents, never assumes the server Git HEAD, and does not alter
Git refs/index, dependencies, environment, scheduler or route caches. It creates
or removes no destination directories and makes no recursive permission changes.

Eight additional c0f dashboard/Knowledge/controller/asset files are independently
pinned and preserved. The previous executive-dashboard CSS and JavaScript are also
verified over local and public HTTPS. Historical Vite files and their manifest,
configuration and dependency locks retain their fingerprints and metadata.

The helper never runs migrations, seeders, imports, business/financial write
fixtures, database restoration or production operations. Legitimate existing
workflow history is not inspected for emptiness or removed. Ordinary authorized
HTTP smoke tests may create transient framework session/cache records, so there
is no byte-identical database claim.

## Immutable package contract

Only generate the package after the final reviewed application commit is ready.
Read its thirteen blobs and helper from that exact commit. The ZIP contains only
the thirteen relative files: no enclosing folder, directory entries or links.
Use deterministic `ZIP_STORED` metadata, sorted paths, Unix regular-file mode 0644,
fixed timestamp 2026-01-01 and empty extra/comment fields.

The separate UTF-8 manifest uses this exact schema:

```json
{
  "format": 1,
  "environment": "PHOS_UAT",
  "baseline": "c0fef0f918ab2ea01be4bc3222f811d9f4a3d664",
  "release": "EXACT_40_CHARACTER_REVIEWED_COMMIT",
  "archive_sha256": "EXACT_64_CHARACTER_ARCHIVE_SHA256",
  "files": [
    {
      "path": "resources/views/rentals/index.blade.php",
      "sha256": "EXACT_64_CHARACTER_NEW_FILE_SHA256",
      "baseline_sha256": "5b8d24184e792beeae095132151c5234bd27d897e2a6533cc4fcb04f7a78c3cf",
      "size": 12345
    }
  ]
}
```

Include every allowlisted path once. Each original has its embedded baseline hash;
each of the four new files has JSON `null` as `baseline_sha256`. File size is bytes.
The operator command separately pins the application/helper commit, helper SHA256,
manifest SHA256, ZIP SHA256 and bootstrap commit/SHA256. Fetch only immutable HTTPS
URLs and check every hash before executing code. No mutable branch URL, remote
pipe-to-shell, disabled TLS verification or changed SSH host-key policy is needed.

The bootstrap downloads the thirteen source files and helper from application
commit A, embeds the exact manifest, and reconstructs the checksum-pinned ZIP.
Its public manifest/bootstrap can live in a later operations-only commit B without
self-referential commit hashes. The outer command fetches B's bootstrap into a
private local file, verifies its independently pinned SHA256, then executes it.
The final command is prepared only after real A/B commits and checksums exist.

## Offline checks and validation

```text
python deploy.py --check-package --manifest release-manifest.json --archive release.zip \
  --manifest-sha256 MANIFEST_SHA256 --archive-sha256 ARCHIVE_SHA256 \
  --expected-release REVIEWED_COMMIT
python ops/phos-compact-pages-uat/test_deploy.py
```

Package verification works on Windows without application boot or a database.
Source/recovery tests use the exact baseline Git blobs and temporary local fixtures;
the checkout must contain the baseline commit. Tests introduce no deployment-target
override. Local validation: 39 tests, 38 passed and one Windows symlink-creation
privilege skip; all three embedded PHP probes compile. Rehearsals exercise real
temporary file backup/replacement/removal, with external runtime/ownership calls
mocked. They are not a Linux server rollout or live authenticated acceptance.

## Activation, verification and recovery

`--deploy` uses the same pinned arguments as `--check-package`, takes the shared
PHOS rollout lock, validates source/identity and creates a new root-private
`phos-compact-pages-backup-*` folder. It saves nine original files, four absent-file
intents, preservation fingerprints, recovery helper/state and a recovery command
before application changes. Earlier dashboard/database/storage backups stay intact.

Activation uses a brief file-maintenance window and atomic writes. Existing file
metadata is retained; new files use the website account and mode 0644. Only compiled
views are cleared/rebuilt. A random token-protected loopback PHP probe verifies
the web PHP runtime and invalidates only OPcache entries beneath the UAT path;
it is removed in a `finally` block and never resets a shared global cache.

Checks cover local/public `/login` and `/up`, eight anonymous list-route redirects,
the eight list routes' authentication plus existing dashboard/Knowledge/payment/
financial guards, both new CSS/JS responses, both preserved executive assets, final
source hashes and preserved environment/dependency/route-cache/build metadata.
No authenticated business action or export is submitted by this helper.

Recovery first validates all saved originals and every current destination against
baseline/release bytes. A later unknown edit stops recovery before undo. It restores
the nine originals and removes only the four exact new files whose hashes still
match this release. It never removes a directory or changes the database. The old
dashboard and Knowledge source/assets remain pinned throughout.

Baseline Laravel's exact file-maintenance payload is saved before activation, so
recovery can enter maintenance without booting newly activated code. Source undo
precedes view/cache probes. Persistent cache failure leaves baseline files restored
and UAT in maintenance; a failed post-recovery health check re-establishes that
maintenance state too. Follow the exact printed recovery command if interrupted;
do not substitute a previous dashboard or migration helper. Retain the private
log/backup if recovery is incomplete. Runtime success is separate from subsequent
authenticated role/device acceptance of the new list controls and workflows.
