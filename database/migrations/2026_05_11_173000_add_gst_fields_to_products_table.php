<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'gst_tax_type')) {
                $table->string('gst_tax_type', 20)->nullable()->after('rental_price');
            }

            if (!Schema::hasColumn('products', 'gst_calculation_mode')) {
                $table->string('gst_calculation_mode', 20)->default('exclusive')->after('gst_tax_type');
            }

            if (!Schema::hasColumn('products', 'cgst_rate')) {
                $table->decimal('cgst_rate', 5, 2)->default(0)->after('gst_calculation_mode');
            }

            if (!Schema::hasColumn('products', 'sgst_rate')) {
                $table->decimal('sgst_rate', 5, 2)->default(0)->after('cgst_rate');
            }

            if (!Schema::hasColumn('products', 'igst_rate')) {
                $table->decimal('igst_rate', 5, 2)->default(0)->after('sgst_rate');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            foreach (['igst_rate', 'sgst_rate', 'cgst_rate', 'gst_calculation_mode', 'gst_tax_type'] as $column) {
                if (Schema::hasColumn('products', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
