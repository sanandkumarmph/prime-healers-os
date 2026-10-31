# Private customer proof audit

Run the audit as the website owner in the intended application environment:

```sh
php artisan rentnexis:migrate-proof-files --summary
```

This mode reads customer proof references and the private cleanup journal. It does not change files, database rows or application logs, and prints only counts/flags. `--organization=<id>` optionally limits the customer records and relevant pending cleanup groups. Combining `--summary` with `--apply` is rejected.

`PROOF_AUDIT_PUBLIC_REFERENCED` counts customer records whose current proof path exists on the public disk. Counts are records, so shared physical files may count more than once. `PROOF_AUDIT_PENDING_CLEANUP` counts exact old public paths retained in the private retry journal, including paths no longer present in current customer metadata. An unreadable/invalid journal produces `pending_cleanup=unknown`, `complete=false` and a failure exit code.

`PROOF_AUDIT_COMPLETE=true` applies only to these tracked paths. It does not discover arbitrary public files or old unrecorded orphans. A separate read-only web-server/storage audit is still required to establish whether unknown legacy paths exist. Laravel authorization tests do not prove that Apache/LiteSpeed/nginx refuses an existing static file under a public storage link. An unauthenticated HEAD check can verify server denial without retrieving proof contents; keep the exact URL private and report only its status.

The summary is an observation, not a transaction snapshot. If an apply process runs concurrently, database references and the atomically replaced journal may be observed at different phases. Repeat the read-only audit after maintenance finishes for a final result.

Apply remains a separate maintenance operation, not an automatic deployment step. Its guarantees are:

- Private writes and SHA256 contents are verified before customer proof metadata changes.
- A private, verified, atomically replaced retry journal is persisted before the metadata update. It stores only exact old/new paths, SHA256 and customer/organization IDs under the local private disk's `.proof-migration/` directory.
- Customer timestamps, unrelated customer fields, financial rows and unrelated files are preserved. Shared public sources remain until all relevant private copies are verified and no customer still needs that public path, including storage-normalized aliases.
- Public cleanup happens after metadata commits, rechecks private and public hashes, and checks deletion results. A cleanup failure retains the private proof and public duplicate, keeps its journal entry, reports `INCOMPLETE` and returns failure.
- Rerunning apply retries only exact verified journaled duplicates and current customer references. It does not enumerate/delete arbitrary orphan files. Conflicting/missing/unreadable sources are retained for review.
- An exclusive local lock prevents concurrent apply processes. Back up the private proof directory and its retry journal together before maintenance. Keep detailed default dry-run/apply output private because it includes paths; use `--summary` for shareable diagnostics.

No UAT or production migration is performed by adding this command or documentation.
