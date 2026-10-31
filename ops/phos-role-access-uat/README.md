# Role-access UAT batch

This separate batch activates only the 22 reviewed role-access source files from the pinned application release in `manifest.json`. It expects the completed password-recovery and password-visibility batches. It does not rewrite user/role records or install dependencies, assets, migrations or mail settings.

The coordinator derives from the tested password-recovery source/cache installer. Destination directories must already exist. It verifies the fixed UAT identity, database-session configuration, private runtime permissions, source hashes and presence of a valid active Super Admin before activation. It uses the same deployment/mail locks as earlier batches.

Backups retain original source, cache files and compiled-view identities. Recovery restores only known files and retains unknown changes for review. Existing environment values, dependency files, frontend assets and password-recovery/visibility source are protected by before/after snapshots.

The activated runtime checks use synthetic in-memory role models to verify assigned-role authority, dashboard Allow/Block, finance denial, disabled/foreign roles and delegation. Route middleware and existing reset/TLS guards are also checked. No real account is modified and no email is sent. HTTP smoke checks may create an ordinary anonymous framework session.

Run the private operator command prepared for the exact helper commit. Keep the printed backup and recovery command. Production activation and merging are excluded. Complete UAT acceptance with representative accounts after the operator reports successful activation.
