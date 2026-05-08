<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $isSqlite = DB::getDriverName() === 'sqlite';

        if (! Schema::hasColumn('deliveries', 'sale_id')) {
            Schema::table('deliveries', function (Blueprint $table) {
                $table->foreignId('sale_id')
                    ->nullable()
                    ->after('rental_id')
                    ->constrained('sales')
                    ->nullOnDelete();
            });
        }

        if (! $isSqlite && Schema::hasColumn('deliveries', 'rental_id')) {
            try {
                DB::statement('ALTER TABLE deliveries DROP FOREIGN KEY deliveries_rental_id_foreign');
            } catch (\Throwable $exception) {
                // Some local databases may already have this key removed or renamed.
            }

            DB::statement('ALTER TABLE deliveries MODIFY rental_id BIGINT UNSIGNED NULL');

            try {
                DB::statement('ALTER TABLE deliveries ADD CONSTRAINT deliveries_rental_id_foreign FOREIGN KEY (rental_id) REFERENCES rentals(id) ON DELETE CASCADE');
            } catch (\Throwable $exception) {
                // Keep the migration safe across older local schemas with custom constraints.
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('deliveries', 'sale_id')) {
            Schema::table('deliveries', function (Blueprint $table) {
                $table->dropConstrainedForeignId('sale_id');
            });
        }
    }
};
