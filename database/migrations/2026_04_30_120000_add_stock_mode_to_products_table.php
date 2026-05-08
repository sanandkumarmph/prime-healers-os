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
            if (!Schema::hasColumn('products', 'stock_mode')) {
                $table->string('stock_mode', 20)->default('untracked')->after('product_type');
            }
        });

        $saleCounts = DB::table('assets')
            ->selectRaw('product_id, COUNT(*) as aggregate_count')
            ->where('asset_stage', 'new_stock')
            ->groupBy('product_id')
            ->pluck('aggregate_count', 'product_id');

        $rentalCounts = DB::table('assets')
            ->selectRaw('product_id, COUNT(*) as aggregate_count')
            ->where('asset_stage', 'rental_stock')
            ->groupBy('product_id')
            ->pluck('aggregate_count', 'product_id');

        DB::table('products')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($products) use ($saleCounts, $rentalCounts) {
                foreach ($products as $product) {
                    $saleCount = (int) ($saleCounts[$product->id] ?? 0);
                    $rentalCount = (int) ($rentalCounts[$product->id] ?? 0);

                    $resolvedStockMode = match (true) {
                        $saleCount > 0 && $rentalCount > 0 => 'tracked_both',
                        $saleCount > 0 => 'tracked_sale',
                        $rentalCount > 0 => 'tracked_rental',
                        default => 'untracked',
                    };

                    DB::table('products')
                        ->where('id', $product->id)
                        ->update(['stock_mode' => $resolvedStockMode]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'stock_mode')) {
                $table->dropColumn('stock_mode');
            }
        });
    }
};
