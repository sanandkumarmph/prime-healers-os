<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sale_items')) {
            return;
        }

        Schema::table('sale_items', function (Blueprint $table) {
            if (!Schema::hasColumn('sale_items', 'tax_type')) {
                $table->string('tax_type', 20)->nullable()->after('tax_calculation_mode');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('sale_items') || !Schema::hasColumn('sale_items', 'tax_type')) {
            return;
        }

        Schema::table('sale_items', function (Blueprint $table) {
            $table->dropColumn('tax_type');
        });
    }
};
