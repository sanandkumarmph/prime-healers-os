<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'tax_calculation_mode')) {
                $table->string('tax_calculation_mode')
                    ->default('exclusive')
                    ->after('tax_type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'tax_calculation_mode')) {
                $table->dropColumn('tax_calculation_mode');
            }
        });
    }
};
