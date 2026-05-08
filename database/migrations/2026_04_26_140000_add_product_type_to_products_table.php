<?php

use App\Models\Asset;
use App\Models\Product;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'product_type')) {
                $table->string('product_type', 20)->default(Product::TYPE_SELLABLE)->after('sku');
            }
        });

        DB::table('products')
            ->select('id', 'is_sellable', 'is_rentable', 'product_type')
            ->orderBy('id')
            ->chunk(100, function ($products) {
                foreach ($products as $product) {
                    $saleStock = (int) DB::table('sale_inventories')
                        ->where('product_id', $product->id)
                        ->sum('quantity_in_stock');

                    $rentalAssets = (int) DB::table('assets')
                        ->where('product_id', $product->id)
                        ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
                        ->count();

                    $productType = match (true) {
                        $rentalAssets > 0 && $saleStock === 0 => Product::TYPE_RENTABLE,
                        $saleStock > 0 && $rentalAssets === 0 => Product::TYPE_SELLABLE,
                        $rentalAssets > 0 && $saleStock > 0 => Product::TYPE_RENTABLE,
                        (bool) $product->is_rentable && !(bool) $product->is_sellable => Product::TYPE_RENTABLE,
                        default => Product::TYPE_SELLABLE,
                    };

                    DB::table('products')
                        ->where('id', $product->id)
                        ->update([
                            'product_type' => $productType,
                            'is_sellable' => $productType === Product::TYPE_SELLABLE,
                            'is_rentable' => $productType === Product::TYPE_RENTABLE,
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (Schema::hasColumn('products', 'product_type')) {
                $table->dropColumn('product_type');
            }
        });
    }
};
