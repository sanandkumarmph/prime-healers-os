<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ResetAppData extends Command
{
    protected $signature = 'app:reset-data';

    protected $description = 'Clear local transactional and master test data without dropping schema.';

    /**
     * @var array<int, string>
     */
    private array $tables = [
        'payments',
        'invoice_items',
        'sale_assets',
        'invoices',
        'sale_items',
        'sales',
        'rental_items',
        'rental_assets',
        'deliveries',
        'asset_movements',
        'inventory_conversions',
        'rentals',
        'assets',
        'sale_inventories',
        'customers',
        'products',
    ];

    public function handle(): int
    {
        if (! App::environment('local')) {
            $this->error('app:reset-data is allowed only in the local environment.');

            return self::FAILURE;
        }

        $connection = DB::connection();
        $driver = $connection->getDriverName();
        $existingTables = array_values(array_filter(
            $this->tables,
            fn (string $table) => Schema::hasTable($table)
        ));

        if ($existingTables === []) {
            $this->warn('No configured reset tables were found.');

            return self::SUCCESS;
        }

        $removedCounts = [];

        foreach ($existingTables as $table) {
            $removedCounts[$table] = (int) DB::table($table)->count();
        }

        $disableForeignKeys = function () use ($driver): void {
            match ($driver) {
                'mysql' => DB::statement('SET FOREIGN_KEY_CHECKS=0'),
                'sqlite' => DB::statement('PRAGMA foreign_keys = OFF'),
                'pgsql' => DB::statement('SET session_replication_role = replica'),
                default => Schema::disableForeignKeyConstraints(),
            };
        };

        $enableForeignKeys = function () use ($driver): void {
            match ($driver) {
                'mysql' => DB::statement('SET FOREIGN_KEY_CHECKS=1'),
                'sqlite' => DB::statement('PRAGMA foreign_keys = ON'),
                'pgsql' => DB::statement('SET session_replication_role = DEFAULT'),
                default => Schema::enableForeignKeyConstraints(),
            };
        };

        try {
            $disableForeignKeys();

            foreach ($existingTables as $table) {
                DB::table($table)->truncate();
            }
        } finally {
            $enableForeignKeys();
        }

        $this->info('Rentnexis local data reset completed.');
        $this->newLine();
        $this->table(
            ['Table', 'Rows Removed'],
            collect($removedCounts)
                ->map(fn (int $count, string $table) => [$table, $count])
                ->values()
                ->all()
        );

        $this->line('Total rows removed: '.array_sum($removedCounts));

        $this->newLine();
        $this->comment('Running optimize:clear ...');
        Artisan::call('optimize:clear');
        $this->line(trim(Artisan::output()));

        return self::SUCCESS;
    }
}
