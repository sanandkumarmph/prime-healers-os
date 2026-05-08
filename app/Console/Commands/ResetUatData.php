<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class ResetUatData extends Command
{
    protected $signature = 'rentnexis:reset-uat-data {--apply : Actually delete UAT transactional data after reviewing the dry run}';

    protected $description = 'Dry-run and optionally clear Rentnexis transactional/UAT data without touching users, permissions, settings, or knowledge data.';

    /**
     * @var array<int, string>
     */
    private array $protectedTables = [
        'users',
        'roles',
        'permissions',
        'model_has_roles',
        'model_has_permissions',
        'organizations',
        'settings',
        'warehouses',
        'knowledge_categories',
        'knowledge_articles',
    ];

    /**
     * @var array<int, string>
     */
    private array $deleteOrder = [
        'payments',
        'invoice_items',
        'invoices',
        'deliveries',
        'rental_assets',
        'rental_items',
        'rental_sale_items',
        'rental_renewals',
        'sale_assets',
        'sales',
        'asset_movements',
        'inventory_conversions',
        'sale_inventories',
        'rentals',
        'assets',
        'products',
        'customers',
    ];

    private int $chunkSize = 500;

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $existingTables = array_values(array_filter($this->deleteOrder, fn (string $table) => Schema::hasTable($table)));
        $missingTables = array_values(array_diff($this->deleteOrder, $existingTables));
        $tableCounts = collect($existingTables)
            ->mapWithKeys(fn (string $table) => [$table => (int) DB::table($table)->count()])
            ->all();
        $snapshotFiles = $this->importSnapshotFiles();
        $blockedDependencies = $this->blockedDependencies($existingTables);

        $this->info('Rentnexis UAT data reset');
        $this->line('Mode: '.($apply ? 'APPLY' : 'DRY RUN'));
        $this->line('Environment: '.app()->environment());
        $this->line('Database connection: '.DB::connection()->getName().' ('.DB::connection()->getDriverName().')');
        $this->newLine();
        $this->line('Protected data kept intact: '.implode(', ', $this->protectedTables));
        $this->newLine();

        $this->components->twoColumnDetail('Database tables targeted', count($existingTables));
        $this->table(
            ['Table', 'Rows to Delete'],
            collect($tableCounts)
                ->map(fn (int $count, string $table) => [$table, $count])
                ->values()
                ->all()
        );

        if ($missingTables !== []) {
            $this->newLine();
            $this->components->twoColumnDetail('Skipped missing tables', count($missingTables));
            $this->line(implode(', ', $missingTables));
        }

        $this->newLine();
        $this->components->twoColumnDetail('Import snapshot files to delete', count($snapshotFiles));
        if ($snapshotFiles !== []) {
            $previewFiles = array_slice($snapshotFiles, 0, 20);
            $this->table(
                ['Snapshot Path'],
                collect($previewFiles)->map(fn (string $path) => [$path])->all()
            );

            if (count($snapshotFiles) > count($previewFiles)) {
                $this->line('... and '.(count($snapshotFiles) - count($previewFiles)).' more snapshot file(s).');
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('Blocked dependencies', count($blockedDependencies));
        if ($blockedDependencies === []) {
            $this->line('None detected.');
        } else {
            $this->table(
                ['Blocking Table', 'Blocking Column', 'References'],
                collect($blockedDependencies)
                    ->map(fn (array $dependency) => [
                        $dependency['table'],
                        $dependency['column'],
                        $dependency['references'],
                    ])
                    ->all()
            );
        }

        logger()->info('Rentnexis UAT data reset command inspected state.', [
            'mode' => $apply ? 'apply' : 'dry-run',
            'environment' => app()->environment(),
            'table_counts' => $tableCounts,
            'snapshot_files' => $snapshotFiles,
            'blocked_dependencies' => $blockedDependencies,
        ]);

        if (! $apply) {
            $this->newLine();
            $this->comment('Dry run only. No data was deleted. Re-run with --apply after reviewing the counts above.');

            return self::SUCCESS;
        }

        if ($blockedDependencies !== []) {
            $this->newLine();
            $this->error('Apply mode aborted because blocked dependencies were detected outside the approved reset scope.');

            return self::FAILURE;
        }

        $deletedCounts = [];

        DB::transaction(function () use ($existingTables, &$deletedCounts): void {
            foreach ($existingTables as $table) {
                $deletedCounts[$table] = $this->deleteTableRows($table);
            }
        });

        if ($snapshotFiles !== []) {
            Storage::disk('local')->delete($snapshotFiles);
        }

        logger()->info('Rentnexis UAT data reset applied.', [
            'environment' => app()->environment(),
            'deleted_counts' => $deletedCounts,
            'deleted_snapshot_files' => count($snapshotFiles),
        ]);

        $this->newLine();
        $this->info('UAT transactional data reset applied successfully.');
        $this->table(
            ['Table', 'Rows Deleted'],
            collect($deletedCounts)
                ->map(fn (int $count, string $table) => [$table, $count])
                ->values()
                ->all()
        );
        $this->line('Import snapshot files deleted: '.count($snapshotFiles));

        $this->newLine();
        $this->comment('Running post-reset audit: php artisan rentnexis:audit-data');
        $auditExitCode = Artisan::call('rentnexis:audit-data');
        $this->line(rtrim(Artisan::output()));

        return $auditExitCode === self::SUCCESS ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<int, string>
     */
    private function importSnapshotFiles(): array
    {
        if (! Storage::disk('local')->exists('imports')) {
            return [];
        }

        return collect(Storage::disk('local')->allFiles('imports'))
            ->filter(fn (string $path) => str_ends_with(strtolower($path), '.json'))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<int, array{table: string, column: string, references: string}>
     */
    private function blockedDependencies(array $tables): array
    {
        if ($tables === []) {
            return [];
        }

        return match (DB::connection()->getDriverName()) {
            'mysql' => $this->mysqlBlockedDependencies($tables),
            'sqlite' => $this->sqliteBlockedDependencies($tables),
            'pgsql' => $this->pgsqlBlockedDependencies($tables),
            default => [],
        };
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<int, array{table: string, column: string, references: string}>
     */
    private function mysqlBlockedDependencies(array $tables): array
    {
        $rows = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->select('TABLE_NAME', 'COLUMN_NAME', 'REFERENCED_TABLE_NAME', 'REFERENCED_COLUMN_NAME')
            ->whereRaw('TABLE_SCHEMA = DATABASE()')
            ->whereNotNull('REFERENCED_TABLE_NAME')
            ->get();

        return collect($rows)
            ->filter(function ($row) use ($tables) {
                return in_array((string) $row->REFERENCED_TABLE_NAME, $tables, true)
                    && !in_array((string) $row->TABLE_NAME, $tables, true);
            })
            ->map(fn ($row) => [
                'table' => (string) $row->TABLE_NAME,
                'column' => (string) $row->COLUMN_NAME,
                'references' => (string) $row->REFERENCED_TABLE_NAME.'.'.$row->REFERENCED_COLUMN_NAME,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<int, array{table: string, column: string, references: string}>
     */
    private function sqliteBlockedDependencies(array $tables): array
    {
        $tableNames = collect(DB::select("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'"))
            ->map(fn ($row) => (string) $row->name)
            ->all();

        $dependencies = [];

        foreach ($tableNames as $tableName) {
            $escapedTableName = str_replace("'", "''", $tableName);
            $foreignKeys = DB::select("PRAGMA foreign_key_list('{$escapedTableName}')");

            foreach ($foreignKeys as $foreignKey) {
                $referencedTable = (string) ($foreignKey->table ?? '');

                if (in_array($referencedTable, $tables, true) && !in_array($tableName, $tables, true)) {
                    $dependencies[] = [
                        'table' => $tableName,
                        'column' => (string) ($foreignKey->from ?? ''),
                        'references' => $referencedTable.'.'.($foreignKey->to ?? 'id'),
                    ];
                }
            }
        }

        return $dependencies;
    }

    /**
     * @param  array<int, string>  $tables
     * @return array<int, array{table: string, column: string, references: string}>
     */
    private function pgsqlBlockedDependencies(array $tables): array
    {
        $rows = DB::select("
            SELECT
                tc.table_name,
                kcu.column_name,
                ccu.table_name AS referenced_table_name,
                ccu.column_name AS referenced_column_name
            FROM information_schema.table_constraints tc
            JOIN information_schema.key_column_usage kcu
              ON tc.constraint_name = kcu.constraint_name
             AND tc.table_schema = kcu.table_schema
            JOIN information_schema.constraint_column_usage ccu
              ON ccu.constraint_name = tc.constraint_name
             AND ccu.table_schema = tc.table_schema
            WHERE tc.constraint_type = 'FOREIGN KEY'
              AND tc.table_schema = current_schema()
        ");

        return collect($rows)
            ->filter(function ($row) use ($tables) {
                return in_array((string) $row->referenced_table_name, $tables, true)
                    && !in_array((string) $row->table_name, $tables, true);
            })
            ->map(fn ($row) => [
                'table' => (string) $row->table_name,
                'column' => (string) $row->column_name,
                'references' => (string) $row->referenced_table_name.'.'.$row->referenced_column_name,
            ])
            ->values()
            ->all();
    }

    private function deleteTableRows(string $table): int
    {
        $initialCount = (int) DB::table($table)->count();

        if ($initialCount === 0) {
            return 0;
        }

        $columns = Schema::getColumnListing($table);

        if (in_array('id', $columns, true)) {
            do {
                $ids = DB::table($table)
                    ->orderBy('id')
                    ->limit($this->chunkSize)
                    ->pluck('id')
                    ->all();

                if ($ids === []) {
                    break;
                }

                DB::table($table)->whereIn('id', $ids)->delete();
            } while (true);
        } else {
            DB::table($table)->delete();
        }

        return $initialCount;
    }
}
