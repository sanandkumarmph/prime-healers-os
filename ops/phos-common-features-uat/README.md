# PHOS common-features UAT operator package

Pins the merged PR #8 release and its reviewed tree, exact change allowlist and
three prebuilt frontend files. The private target configuration stays on the
server. This package does not change the production site or server services.

The operator runs the helper as root with the isolated bare Git directory,
`--assets <assets.json>` and `--target-config <private-target.json>`. Use
`--check-release` with the Git directory and assets for an offline source/package
check. The supplied bootstrap command separately verifies helper and asset hashes.

Checks the existing source, dependency locks, UAT application/database identity,
PHP runtime, authentication routes and exact pending migration set. Preserves
server environment, scheduler, storage location and old frontend asset files.
Creates a root-private database dump, storage/app archive and changed-file backups
before applying exactly four additive migrations. Compares legacy table counts
and hashes, allowing only the intended module additions to system role grants.
Installs checksum-verified assets and verifies new route and finance guards.

Stops on an unknown baseline, partial schema, collisions, unexpected edits or
failed checks. A failed activation attempts a code/assets rollback while retaining
additive schema; it never automatically restores or resets the database. If any new
business history exists, automatic code rollback is refused and operator review is
required. Read the private log and saved recovery command before taking further
action. New tables must never be dropped as a routine rollback after use.

The helper repairs only existing root/site-owned release parent directories when
required for the website account; original metadata is recorded privately. It
never recursively changes permissions. Actual site details and all backup paths
are private operator inputs/output and must not be committed.

Local validation: six guard checks, nine PHP syntax checks, exact release/asset
verification, and a disposable MariaDB 10.11.16 rehearsal preserving 54 legacy
tables plus a deliberately non-UTF-8 binary sentinel table and verifying seven new tables and all four migrations. This is not a claim
of actual deployment. Authentication, host runtime and manual device acceptance
are verified on the target only after the operator runs the prepared command.

Database row fingerprints serialize raw PDO values only as hash input; they are
never unserialized. Binary bytes and legacy non-UTF-8 text are preserved exactly.
This avoids JSON encoding failures without repairing, converting or ignoring data.
