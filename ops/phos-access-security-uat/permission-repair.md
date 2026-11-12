Prepared PHOS UAT permission repair - private review artifact

Files: phos_uat_permission_repair.py and test_uat_permission_repair.py.
No operator command, remote execution or permission change has been performed.

The helper accepts only the fixed UAT installation and existing root-private
target configuration. It requires website UID/primary GID5006/5006, observed
APP5006:65534 0750 and public5006:65534 0755, and the observed vhost65534:65534
0750 with the exact UAT document-root directive. It never modifies the vhost.
Unlinked root/site-owned, non-group/world-writable application ancestors are
read-only guards and are rechecked before target mutations and final verification.

Before changing any existing target metadata, a new random PHP probe is created
exclusively in the fixed public directory, mode0600 and website-owned. It loads
no application/config/database. Only a request with the random private header,
fixed host and loopback peer can retrieve its safe UID/GID/access booleans. The
client connects only127.0.0.1:443, verifies TLS using the real UAT hostname/SNI,
does not follow redirects or proxy settings, and never falls back to HTTP.
The PHP identity uses posix_geteuid/getegid or effective fields in /proc/self/status;
script/file ownership is never accepted as process identity. Effective UID must
be5006. Effective GID may legitimately differ from the account's primary group.

Preflight collects all blocked fixed entry labels with safe metadata/reason codes.
Unexpected cache PHP or linked entries are reported by count, without arbitrary
filenames. Symlink/hardlink/identity drift, wrong owner/type and unsafe boundaries
stop before target mutations. The target list is bounded to eight directories,
one environment file and at most32 known generated cache PHP files. No recursive
walk, mkdir of application paths, source/config edit, framework command, runtime
installation, service action or process account configuration is included.

Changes, only when the existing target is valid:
- .env: preserve UID5006; primary GID5006; mode0600.
- storage, storage/app, bootstrap: site-owned parents become0750 while retaining
  their current group for public/static traversal. Already non-writable root-owned
  traversal parents remain unchanged. These parents tighten before child barriers.
- Existing bootstrap/cache, storage/framework, storage/logs, storage/app/private,
  storage/app/pdf-runtime: must already be site-owned; primary GID5006, mode0700.
- Existing known config/packages/services/events/routes-vN cache PHP: UID/GID5006,
  mode0600. A justified root-owned cache file is chowned first so it does not become
  unreadable to the verified web UID during the change; site-owned files tighten
  mode first. Public storage/images and unrelated paths are untouched.

After initial web identity proof, a root-private0700 random /root backup stores
only device/inode/type/link/hash/metadata records and a mode0600 copy of this
reviewed recovery helper. No .env/config byte content is copied into the backup.
The UID proof is repeated immediately before mutation. FD-based chmod/chown and
durable per-step journals preserve exact before and observed after states. File
bytes/hashes/mtime remain unchanged. A hard interruption before a verified after
state is journalled is explicitly uncertain; recovery refuses to guess ownership.

Failure handling restores approved attributes before any later health attempt.
Recovery only acts on exact reviewed paths whose inode/content/expected metadata
still match, and continues restoring unaffected entries if one has drifted.
Foreign/replaced/changed entries are never overwritten and require private review.
Restoring the original attributes also restores the original0644 environment-file
and0775 parent exposure; refused or rolled_back never means the security repair
completed.
The metadata backup is retained. Cleanup removes only the probe with matching
bytes/inode/metadata; partial creation is also checked and cleaned. Any uncertain
cleanup or recovery yields refused/manual-review, never a false complete result.

Completion requires unchanged hashes, verified web UID5006, readable environment,
all generated cache files and existing private directories accessible as the web
owner, and HTTP200 for /login and /up over verified local HTTPS. Health response
bodies are not read. The requested /login GET may create a routine anonymous
database session through normal application middleware; the helper itself issues
no direct database command and reads/writes no business record.

Offline validation currently passes19 tests and the embedded PHP syntax check.
Tests cover target scope, all-entry preflight, root/cache ordering, TLS/SNI scope,
wrong web identity, partial mutation, ambiguous interruption, foreign drift,
recovery refusal, probe cleanup and preservation of static-serving groups.
