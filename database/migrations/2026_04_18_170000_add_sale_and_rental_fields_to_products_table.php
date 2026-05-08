<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'product_code')) {
                $table->string('product_code')->nullable()->after('name');
            }

            if (!Schema::hasColumn('products', 'sku')) {
                $table->string('sku')->nullable()->after('product_code');
            }

            if (!Schema::hasColumn('products', 'is_sellable')) {
                $table->boolean('is_sellable')->default(true)->after('organization_id');
            }

            if (!Schema::hasColumn('products', 'is_rentable')) {
                $table->boolean('is_rentable')->default(true)->after('is_sellable');
            }

            if (!Schema::hasColumn('products', 'sale_price')) {
                $table->decimal('sale_price', 10, 2)->nullable()->after('price_per_day');
            }

            if (!Schema::hasColumn('products', 'rental_price')) {
                $table->decimal('rental_price', 10, 2)->nullable()->after('sale_price');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'rental_price')) {
                $table->dropColumn('rental_price');
            }

            if (Schema::hasColumn('products', 'sale_price')) {
                $table->dropColumn('sale_price');
            }

            if (Schema::hasColumn('products', 'is_rentable')) {
                $table->dropColumn('is_rentable');
            }

            if (Schema::hasColumn('products', 'is_sellable')) {
                $table->dropColumn('is_sellable');
            }

            if (Schema::hasColumn('products', 'sku')) {
                $table->dropColumn('sku');
            }

            if (Schema::hasColumn('products', 'product_code')) {
                $table->dropColumn('product_code');
            }
        });
    }
};
