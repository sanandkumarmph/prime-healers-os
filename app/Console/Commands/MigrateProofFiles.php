<?php

namespace App\Console\Commands;

use App\Models\Customer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class MigrateProofFiles extends Command
{
    protected $signature = 'rentnexis:migrate-proof-files
        {--organization= : Limit migration to one organization id}
        {--apply : Move legacy public proof files and update customer records}';

    protected $description = 'Dry-run and optionally migrate legacy customer proof files from the public disk to private local storage.';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $organizationId = $this->option('organization');

        $entries = $this->scanEntries($organizationId);

        $this->info('Rentnexis customer proof migration');
        $this->line('Mode: '.($apply ? 'APPLY' : 'DRY RUN'));
        $this->line('Scope: '.($organizationId ? 'organization #'.$organizationId : 'all organizations'));
        $this->newLine();

        $this->components->twoColumnDetail('Customers scanned', count($entries));

        if ($entries === []) {
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
            'entries' => $entries,
        ]);

        if (! $apply) {
            $this->newLine();
            $this->comment('Dry run only. Re-run with --apply to migrate public proof files.');

            return self::SUCCESS;
        }

        $migrated = 0;
        $cleaned = 0;
        $skipped = 0;

        foreach ($entries as $entry) {
            if ($entry['action'] === 'migrate_public_to_private') {
                DB::transaction(function () use ($entry, &$migrated): void {
                    /** @var Customer $customer */
                    $customer = Customer::query()->findOrFail($entry['customer_id']);
                    $currentPath = (string) $customer->id_proof_file_path;
                    $targetPath = $entry['target_path'];

                    $contents = Storage::disk('public')->get($currentPath);
                    Storage::disk('local')->put($targetPath, $contents);
                    Storage::disk('public')->delete($currentPath);

                    $customer->forceFill([
                        'id_proof_file_path' => $targetPath,
                    ])->save();

                    $migrated++;
                });

                continue;
            }

            if ($entry['action'] === 'remove_stale_public_copy') {
                Storage::disk('public')->delete($entry['current_path']);
                $cleaned++;

                continue;
            }

            $skipped++;
        }

        logger()->info('Rentnexis proof migration applied.', [
            'organization_id' => $organizationId ? (int) $organizationId : null,
            'migrated' => $migrated,
            'cleaned_public_copies' => $cleaned,
            'skipped' => $skipped,
        ]);

        $this->newLine();
        $this->info('Proof migration completed.');
        $this->line('Migrated public proof files: '.$migrated);
        $this->line('Removed stale public copies: '.$cleaned);
        $this->line('Skipped: '.$skipped);

        return self::SUCCESS;
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
                $localExists = Storage::disk('local')->exists($currentPath);
                $publicExists = Storage::disk('public')->exists($currentPath);
                $targetPath = $this->targetPathFor($customer, $currentPath);

                if ($publicExists && ! $localExists) {
                    return [
                        'customer_id' => $customer->id,
                        'organization_id' => (int) $customer->organization_id,
                        'current_path' => $currentPath,
                        'target_path' => $targetPath,
                        'action' => 'migrate_public_to_private',
                        'notes' => 'Copy from public to local, update database path, remove public source.',
                    ];
                }

                if ($publicExists && $localExists) {
                    return [
                        'customer_id' => $customer->id,
                        'organization_id' => (int) $customer->organization_id,
                        'current_path' => $currentPath,
                        'target_path' => $currentPath,
                        'action' => 'remove_stale_public_copy',
                        'notes' => 'Private copy already exists; remove the stale public duplicate.',
                    ];
                }

                if ($localExists) {
                    return [
                        'customer_id' => $customer->id,
                        'organization_id' => (int) $customer->organization_id,
                        'current_path' => $currentPath,
                        'target_path' => $currentPath,
                        'action' => 'already_private',
                        'notes' => 'Proof already exists on the private local disk.',
                    ];
                }

                return [
                    'customer_id' => $customer->id,
                    'organization_id' => (int) $customer->organization_id,
                    'current_path' => $currentPath,
                    'target_path' => $targetPath,
                    'action' => 'missing_source',
                    'notes' => 'No proof file found on either disk; manual review required.',
                ];
            })
            ->all();
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
