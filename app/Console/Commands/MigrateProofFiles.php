<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\WhitespacePathNormalizer;

class MigrateProofFiles extends Command
{
    private const CLEANUP_MANIFEST = '.proof-migration/pending-cleanup.json';

    private array $cleanupManifest = [];

    protected $signature = 'rentnexis:migrate-proof-files
        {--organization= : Limit migration to one organization id}
        {--summary : Read-only audit with counts only; never prints customer names or file paths}
        {--apply : Move legacy public proof files and update customer records}';

    protected $description = 'Dry-run and optionally migrate legacy customer proof files from the public disk to private local storage.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $organizationId = $this->option('organization');

        if ($this->option('summary') && $apply) {
            $this->error('Summary audit is read-only. Omit --apply.');

            return self::FAILURE;
        }
        if ($organizationId !== null && (! ctype_digit((string) $organizationId) || (int) $organizationId < 1)) {
            $this->error('Choose a positive organization id.');

            return self::FAILURE;
        }

        $lock = null;
        if ($apply) {
            try {
                $lock = $this->acquireMigrationLock();
            } catch (\Throwable) {
                $this->error('Private proof migration lock is unavailable. No proof metadata changed.');

                return self::FAILURE;
            }
        }

        try {
            try {
                $this->cleanupManifest = $this->readCleanupManifest();
            } catch (\Throwable) {
                $this->line('PROOF_AUDIT_COMPLETE=false');
                $this->line('PROOF_AUDIT_PENDING_CLEANUP=unknown');
                $this->error('Private cleanup audit could not be verified. No proof metadata changed.');

                return self::FAILURE;
            }
            $entries = $this->scanEntries($organizationId);

            if ($this->option('summary')) {
                $this->printAuditSummary($entries, $organizationId);

                return self::SUCCESS;
            }

            $this->info('Rentnexis customer proof migration');
            $this->line('Mode: '.($apply ? 'APPLY' : 'DRY RUN'));
            $this->line('Scope: '.($organizationId ? 'organization #'.$organizationId : 'all organizations'));
            $this->newLine();

            $this->components->twoColumnDetail('Customers scanned', count($entries));

            if ($entries === [] && $this->scopedPendingGroups($organizationId) === []) {
                $this->line('No customer proof records found.');

                return self::SUCCESS;
            }

            $this->table(
                ['Customer ID', 'Organization', 'Current Path', 'Target Path', 'Action', 'Notes'],
                collect($entries)->map(fn (array $entry) => [
                    $entry['customer_id'],
                    $entry['organization_id'],
                    $entry['current_path'],
                    $entry['target_path'],
                    $entry['action'],
                    $entry['notes'],
                ])->all()
            );

            logger()->info('Rentnexis proof migration inspected state.', [
                'mode' => $apply ? 'apply' : 'dry-run',
                'organization_id' => $organizationId ? (int) $organizationId : null,
                'records' => count($entries),
            ]);

            if (! $apply) {
                $this->newLine();
                $this->comment('Dry run only. Re-run with --apply to migrate public proof files.');

                return self::SUCCESS;
            }

            $migrated = 0;
            $cleaned = 0;
            $skipped = 0;
            $incomplete = 0;

            foreach ($entries as $entry) {
                if ($entry['action'] === 'already_private') {
                    $skipped++;

                    continue;
                }
                if (! in_array($entry['action'], ['migrate_public_to_private', 'remove_stale_public_copy'], true)) {
                    $incomplete++;

                    continue;
                }
                try {
                    // A filesystem operation cannot be rolled back with a database transaction.
                    // Commit the verified private location first; only then remove its public duplicate.
                    $this->preparePrivateProof($entry);
                    if ($entry['action'] === 'migrate_public_to_private') {
                        $migrated++;
                    }
                } catch (\Throwable $exception) {
                    $incomplete++;
                    $this->warn('Customer #'.$entry['customer_id'].': incomplete; no unverified source was deleted.');
                    logger()->warning('Customer proof migration entry incomplete.', ['exception_type' => $exception::class]);
                }
            }

            foreach ($this->scopedPendingGroups($organizationId) as $key => $group) {
                try {
                    $copies = $group['copies'];
                    foreach ($copies as $copy) {
                        if ($copy['hash'] !== $copies[0]['hash'] || $this->proofHash('local', $copy['private_path']) !== $copy['hash']) {
                            throw new \RuntimeException('Shared proof private copies could not all be verified.');
                        }
                    }
                    if ($this->deleteVerifiedPublicCopy(['public_path' => $group['public_path']] + $copies[0])) {
                        $cleaned++;
                    }
                    $manifest = $this->cleanupManifest;
                    unset($manifest[$key]);
                    $this->writeCleanupManifest($manifest);
                } catch (\Throwable $exception) {
                    $incomplete++;
                    $this->warn('Public proof cleanup incomplete; retain the source and review the private audit.');
                    logger()->warning('Customer proof public cleanup incomplete.', ['exception_type' => $exception::class]);
                }
            }

            logger()->info('Rentnexis proof migration applied.', [
                'organization_id' => $organizationId ? (int) $organizationId : null,
                'migrated' => $migrated,
                'cleaned_public_copies' => $cleaned,
                'skipped' => $skipped,
                'incomplete' => $incomplete,
            ]);

            $this->newLine();
            $this->info($incomplete > 0 ? 'Proof migration INCOMPLETE. Retain the private audit and review remaining public copies.' : 'Proof migration completed.');
            $this->line('Verified private proofs activated: '.$migrated);
            $this->line('Removed verified public copies: '.$cleaned);
            $this->line('Skipped: '.$skipped);
            $this->line('Incomplete: '.$incomplete);
            $this->line('Pending verified cleanup records: '.count($this->scopedPendingGroups($organizationId)));

            return $incomplete > 0 ? self::FAILURE : self::SUCCESS;
        } finally {
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    /**
     * @return array<int, array<string, int|string>>
     */
    private function scanEntries(mixed $organizationId): array
    {
        if (! Customer::hasIdProofFilePathColumn()) {
            return [];
        }

        return $this->applyOrganization(
            Customer::query()->whereNotNull('id_proof_file_path')->where('id_proof_file_path', '!=', ''),
            $organizationId
        )
            ->orderBy('id')
            ->get()
            ->map(function (Customer $customer) {
                $currentPath = (string) $customer->id_proof_file_path;
                $targetPath = $this->targetPathFor($customer, $currentPath);
                $entry = [
                    'customer_id' => $customer->id,
                    'organization_id' => (int) $customer->organization_id,
                    'current_path' => $currentPath,
                    'target_path' => $targetPath,
                    'action' => 'missing_source',
                    'notes' => 'No proof file found on either disk; manual review required.',
                    'public_exists' => false,
                    'private_exists' => false,
                ];
                try {
                    $entry['private_exists'] = Storage::disk('local')->exists($currentPath);
                    $entry['public_exists'] = Storage::disk('public')->exists($currentPath);
                    if ($entry['private_exists']) {
                        $privateHash = $this->proofHash('local', $currentPath);
                        $entry['target_path'] = $currentPath;
                        if (! $entry['public_exists']) {
                            return array_replace($entry, ['action' => 'already_private', 'notes' => 'Verified private proof already exists.']);
                        }

                        return $privateHash === $this->proofHash('public', $currentPath)
                            ? array_replace($entry, ['action' => 'remove_stale_public_copy', 'notes' => 'Matching private copy verified; public duplicate can be removed.'])
                            : array_replace($entry, ['action' => 'conflicting_copies', 'notes' => 'Public and private copies differ; neither will be deleted.']);
                    }
                    if ($entry['public_exists']) {
                        $sourceHash = $this->proofHash('public', $currentPath);
                        if (Storage::disk('local')->exists($targetPath) && $this->proofHash('local', $targetPath) !== $sourceHash) {
                            return array_replace($entry, ['action' => 'private_target_conflict', 'notes' => 'Private destination differs; no file will be overwritten.']);
                        }

                        return array_replace($entry, ['action' => 'migrate_public_to_private', 'notes' => 'Verify private bytes, activate metadata, then remove the public source.']);
                    }
                } catch (\Throwable) {
                    return array_replace($entry, ['action' => 'unreadable_source', 'notes' => 'Source verification failed; no files will be changed.']);
                }

                return $entry;
            })
            ->all();
    }

    private function printAuditSummary(array $entries, mixed $organizationId): void
    {
        $rows = collect($entries);
        $this->line('PROOF_AUDIT_READ_ONLY=true');
        $this->line('PROOF_AUDIT_TRACKED_PATHS_ONLY=true');
        $this->line('PROOF_AUDIT_RECORDS='.$rows->count());
        $this->line('PROOF_AUDIT_PUBLIC_REFERENCED='.$rows->where('public_exists', true)->count());
        $this->line('PROOF_AUDIT_PRIVATE_REFERENCED='.$rows->where('private_exists', true)->count());
        $pending = count($this->scopedPendingGroups($organizationId));
        $this->line('PROOF_AUDIT_PENDING_CLEANUP='.$pending);
        $complete = $pending === 0 && $rows->every(fn ($row) => $row['action'] === 'already_private');
        $this->line('PROOF_AUDIT_COMPLETE='.($complete ? 'true' : 'false'));
        foreach (['migrate_public_to_private', 'remove_stale_public_copy', 'already_private', 'missing_source', 'conflicting_copies', 'private_target_conflict', 'unreadable_source'] as $action) {
            $this->line('PROOF_AUDIT_'.strtoupper($action).'='.$rows->where('action', $action)->count());
        }
    }

    private function proofContents(string $disk, string $path): string
    {
        $contents = Storage::disk($disk)->get($path);
        if (! is_string($contents)) {
            throw new \RuntimeException('Proof bytes could not be read.');
        }

        return $contents;
    }

    private function acquireMigrationLock(): mixed
    {
        $private = Storage::disk('local');
        $directory = $private->path('.proof-migration');
        if (! is_dir($directory) && ! $private->makeDirectory('.proof-migration')) {
            throw new \RuntimeException('Private audit directory unavailable.');
        }
        if (PHP_OS_FAMILY !== 'Windows' && ! chmod($directory, 0700)) {
            throw new \RuntimeException('Private audit directory permissions unavailable.');
        }
        $handle = @fopen($private->path('.proof-migration/migration.lock'), 'c');
        if (! $handle) {
            throw new \RuntimeException('Private migration lock unavailable.');
        }
        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            throw new \RuntimeException('Another proof migration is running.');
        }

        return $handle;
    }

    private function readCleanupManifest(): array
    {
        if (! Storage::disk('local')->exists(self::CLEANUP_MANIFEST)) {
            return [];
        }
        $document = json_decode($this->proofContents('local', self::CLEANUP_MANIFEST), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($document) || ($document['version'] ?? null) !== 1 || ! is_array($document['entries'] ?? null)) {
            throw new \RuntimeException('Private cleanup audit format invalid.');
        }
        foreach ($document['entries'] as $key => $group) {
            if (! is_array($group) || ! $this->safeRelativePath($group['public_path'] ?? null) || $key !== hash('sha256', $group['public_path']) || ! is_array($group['copies'] ?? null) || $group['copies'] === []) {
                throw new \RuntimeException('Private cleanup audit entry invalid.');
            }
            foreach ($group['copies'] as $copy) {
                if (! is_array($copy) || ! is_int($copy['customer_id'] ?? null) || $copy['customer_id'] < 1 || ! is_int($copy['organization_id'] ?? null) || $copy['organization_id'] < 0 || ! $this->safeRelativePath($copy['private_path'] ?? null) || ! is_string($copy['hash'] ?? null) || ! preg_match('/\A[a-f0-9]{64}\z/', $copy['hash'])) {
                    throw new \RuntimeException('Private cleanup audit copy invalid.');
                }
            }
        }

        return $document['entries'];
    }

    private function safeRelativePath(mixed $path): bool
    {
        return is_string($path) && $path !== '' && ! str_contains($path, "\0")
            && ! preg_match('~^(?:[A-Za-z]:|[\\\\/])~', $path)
            && ! in_array('..', preg_split('~[\\\\/]~', $path), true);
    }

    private function writeCleanupManifest(array $entries): void
    {
        $private = Storage::disk('local');
        $json = json_encode(['version' => 1, 'entries' => $entries], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT);
        $temporary = '.proof-migration/manifest-'.bin2hex(random_bytes(12)).'.tmp';
        try {
            if (! $private->put($temporary, $json, ['visibility' => 'private']) || $this->proofContents('local', $temporary) !== $json) {
                throw new \RuntimeException('Private cleanup audit write failed.');
            }
            if (! $private->move($temporary, self::CLEANUP_MANIFEST) || $this->proofContents('local', self::CLEANUP_MANIFEST) !== $json) {
                throw new \RuntimeException('Private cleanup audit activation failed.');
            }
            $this->cleanupManifest = $entries;
        } catch (\Throwable $exception) {
            if ($private->exists($temporary) && ! $private->delete($temporary)) {
                throw new \RuntimeException('Private audit temporary cleanup failed.', previous: $exception);
            }
            throw $exception;
        }
    }

    private function rememberPendingCleanup(Customer $customer, string $publicPath, string $privatePath, string $hash): void
    {
        if (! $this->safeRelativePath($publicPath) || ! $this->safeRelativePath($privatePath)) {
            throw new \RuntimeException('Proof audit path invalid.');
        }
        $entries = $this->cleanupManifest;
        $key = hash('sha256', $publicPath);
        $group = $entries[$key] ?? ['public_path' => $publicPath, 'copies' => []];
        $copy = ['customer_id' => (int) $customer->id, 'organization_id' => (int) $customer->organization_id, 'private_path' => $privatePath, 'hash' => $hash];
        $existing = collect($group['copies'])->firstWhere('customer_id', $customer->id);
        if ($existing && $existing !== $copy) {
            throw new \RuntimeException('Existing private cleanup audit conflicts.');
        }
        if (! $existing) {
            $group['copies'][] = $copy;
        }
        $entries[$key] = $group;
        $this->writeCleanupManifest($entries);
    }

    private function scopedPendingGroups(mixed $organizationId): array
    {
        if ($organizationId === null || $organizationId === '') {
            return $this->cleanupManifest;
        }

        return array_filter($this->cleanupManifest, fn ($group) => collect($group['copies'])->contains(fn ($copy) => $copy['organization_id'] === (int) $organizationId));
    }

    private function proofHash(string $disk, string $path): string
    {
        return hash('sha256', $this->proofContents($disk, $path));
    }

    private function preparePrivateProof(array $entry): array
    {
        return DB::transaction(function () use ($entry): array {
            $customer = Customer::query()->lockForUpdate()->findOrFail($entry['customer_id']);
            if ((string) $customer->id_proof_file_path !== $entry['current_path']) {
                throw new \RuntimeException('Proof reference changed since audit.');
            }
            $contents = $this->proofContents('public', $entry['current_path']);
            $expectedHash = hash('sha256', $contents);
            $target = $entry['target_path'];
            $private = Storage::disk('local');
            $targetExisted = $private->exists($target);
            if (! $targetExisted) {
                try {
                    if (! $private->put($target, $contents) || ! $private->exists($target) || $this->proofHash('local', $target) !== $expectedHash) {
                        throw new \RuntimeException('Private proof copy could not be verified.');
                    }
                } catch (\Throwable $exception) {
                    // Only a newly created, unverified private target may be cleaned up here.
                    // Its public original and database reference have not been touched.
                    if ($private->exists($target) && (! $private->delete($target) || $private->exists($target))) {
                        throw new \RuntimeException('Unverified private target cleanup was incomplete.', previous: $exception);
                    }
                    throw $exception;
                }
            }
            if ($this->proofHash('local', $target) !== $expectedHash) {
                throw new \RuntimeException('Conflicting private proof bytes.');
            }
            // Persist retry information before committing the new database reference.
            // This private journal is the only source of unreferenced cleanup candidates.
            $this->rememberPendingCleanup($customer, $entry['current_path'], $target, $expectedHash);
            if ((string) $customer->id_proof_file_path !== $target) {
                $customer->timestamps = false;
                if (! $customer->forceFill(['id_proof_file_path' => $target])->save()) {
                    throw new \RuntimeException('Private proof metadata could not be saved.');
                }
            }

            return ['public_path' => $entry['current_path'], 'private_path' => $target, 'hash' => $expectedHash];
        });
    }

    private function deleteVerifiedPublicCopy(array $verified): bool
    {
        // Re-read both copies after the metadata transaction committed. A failed deletion
        // leaves a duplicate and a working private reference, never the only proof copy.
        if ($this->proofHash('local', $verified['private_path']) !== $verified['hash']) {
            throw new \RuntimeException('Private proof changed before public cleanup.');
        }
        $public = Storage::disk('public');
        if (! $public->exists($verified['public_path'])) {
            return false;
        }
        if ($verified['public_path'] !== $verified['private_path'] && $this->publicPathStillReferenced($verified['public_path'])) {
            throw new \RuntimeException('Another customer still references the public source.');
        }
        if ($this->proofHash('public', $verified['public_path']) !== $verified['hash']) {
            throw new \RuntimeException('Public proof changed before cleanup.');
        }
        if (! $public->delete($verified['public_path']) || $public->exists($verified['public_path'])) {
            throw new \RuntimeException('Public proof cleanup was not completed.');
        }

        return true;
    }

    private function publicPathStillReferenced(string $path): bool
    {
        $normalizer = new WhitespacePathNormalizer;
        $normalized = $normalizer->normalizePath($path);

        return Customer::query()->whereNotNull('id_proof_file_path')->cursor()->contains(function ($customer) use ($normalizer, $normalized) {
            try {
                return $normalizer->normalizePath((string) $customer->id_proof_file_path) === $normalized;
            } catch (\Throwable) {
                return false;
            }
        });
    }

    private function applyOrganization($query, mixed $organizationId)
    {
        if ($organizationId === null || $organizationId === '') {
            return $query;
        }

        return $query->where('organization_id', (int) $organizationId);
    }

    private function targetPathFor(Customer $customer, string $currentPath): string
    {
        $extension = pathinfo($currentPath, PATHINFO_EXTENSION);
        $suffix = $extension !== '' ? '.'.strtolower($extension) : '';

        return 'customer-id-proofs/customer-'.$customer->id.'-proof'.$suffix;
    }
}
