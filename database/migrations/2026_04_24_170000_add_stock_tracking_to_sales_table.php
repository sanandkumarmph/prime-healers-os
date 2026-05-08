<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'warehouse_id')) {
                $table->foreignId('warehouse_id')->nullable()->after('rental_id')->constrained('warehouses')->nullOnDelete();
            }

            if (!Schema::hasColumn('sales', 'stock_applied')) {
                $table->boolean('stock_applied')->default(false)->after('organization_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'stock_applied')) {
                $table->dropColumn('stock_applied');
            }

            if (Schema::hasColumn('sales', 'warehouse_id')) {
                $table->dropConstrainedForeignId('warehouse_id');
            }
        });
    }
};
