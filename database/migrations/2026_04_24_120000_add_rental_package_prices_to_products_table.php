<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'rental_price_15_days')) {
                $table->decimal('rental_price_15_days', 10, 2)->nullable()->after('price_per_day');
            }

            if (!Schema::hasColumn('products', 'rental_price_30_days')) {
                $table->decimal('rental_price_30_days', 10, 2)->nullable()->after('rental_price_15_days');
            }

            if (!Schema::hasColumn('products', 'rental_price_3_months')) {
                $table->decimal('rental_price_3_months', 10, 2)->nullable()->after('rental_price_30_days');
            }
        });

        if (Schema::hasColumn('products', 'rental_price') && Schema::hasColumn('products', 'rental_price_30_days')) {
            DB::table('products')
                ->whereNull('rental_price_30_days')
                ->update([
                    'rental_price_30_days' => DB::raw('rental_price'),
                ]);
        }
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'rental_price_3_months')) {
                $table->dropColumn('rental_price_3_months');
            }

            if (Schema::hasColumn('products', 'rental_price_30_days')) {
                $table->dropColumn('rental_price_30_days');
            }

            if (Schema::hasColumn('products', 'rental_price_15_days')) {
                $table->dropColumn('rental_price_15_days');
            }
        });
    }
};
