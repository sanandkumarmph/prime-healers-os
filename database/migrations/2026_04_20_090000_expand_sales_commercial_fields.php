<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'asset_id')) {
                $table->foreignId('asset_id')->nullable()->after('product_id')->constrained('assets')->nullOnDelete();
            }

            if (!Schema::hasColumn('sales', 'rental_id')) {
                $table->foreignId('rental_id')->nullable()->after('asset_id')->constrained('rentals')->nullOnDelete();
            }

            if (!Schema::hasColumn('sales', 'unit_price')) {
                $table->decimal('unit_price', 10, 2)->default(0)->after('quantity');
            }

            if (!Schema::hasColumn('sales', 'discount_amount')) {
                $table->decimal('discount_amount', 10, 2)->default(0)->after('unit_price');
            }

            if (!Schema::hasColumn('sales', 'shipping_charges')) {
                $table->decimal('shipping_charges', 10, 2)->default(0)->after('discount_amount');
            }

            if (!Schema::hasColumn('sales', 'tax_percentage')) {
                $table->decimal('tax_percentage', 5, 2)->default(0)->after('shipping_charges');
            }

            if (!Schema::hasColumn('sales', 'tax_calculation_mode')) {
                $table->string('tax_calculation_mode', 20)->default('exclusive')->after('tax_percentage');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'tax_calculation_mode')) {
                $table->dropColumn('tax_calculation_mode');
            }

            if (Schema::hasColumn('sales', 'tax_percentage')) {
                $table->dropColumn('tax_percentage');
            }

            if (Schema::hasColumn('sales', 'shipping_charges')) {
                $table->dropColumn('shipping_charges');
            }

            if (Schema::hasColumn('sales', 'discount_amount')) {
                $table->dropColumn('discount_amount');
            }

            if (Schema::hasColumn('sales', 'unit_price')) {
                $table->dropColumn('unit_price');
            }

            if (Schema::hasColumn('sales', 'rental_id')) {
                $table->dropConstrainedForeignId('rental_id');
            }

            if (Schema::hasColumn('sales', 'asset_id')) {
                $table->dropConstrainedForeignId('asset_id');
            }
        });
    }
};
