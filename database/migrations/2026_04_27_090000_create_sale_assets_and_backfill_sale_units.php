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
        if (Schema::hasTable('assets') && !Schema::hasColumn('assets', 'batch_number')) {
            Schema::table('assets', function (Blueprint $table) {
                $table->string('batch_number')->nullable()->after('barcode_value');
            });
        }

        if (!Schema::hasTable('sale_assets')) {
            Schema::create('sale_assets', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('sale_id')->constrained()->cascadeOnDelete();
                $table->foreignId('asset_id')->constrained()->restrictOnDelete();
                $table->timestamps();

                $table->unique(['sale_id', 'asset_id']);
                $table->index(['organization_id', 'asset_id']);
            });
        }

        if (Schema::hasTable('sales') && Schema::hasColumn('sales', 'asset_id')) {
            $sales = DB::table('sales')
                ->select('id', 'organization_id', 'asset_id')
                ->whereNotNull('asset_id')
                ->get();

            foreach ($sales as $sale) {
                $exists = DB::table('sale_assets')
                    ->where('sale_id', $sale->id)
                    ->where('asset_id', $sale->asset_id)
                    ->exists();

                if (!$exists) {
                    DB::table('sale_assets')->insert([
                        'organization_id' => $sale->organization_id,
                        'sale_id' => $sale->id,
                        'asset_id' => $sale->asset_id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        }

        if (
            Schema::hasTable('products') &&
            Schema::hasTable('sale_inventories') &&
            Schema::hasTable('assets')
        ) {
            Product::query()
                ->select(['id', 'organization_id', 'name', 'product_code', 'sku'])
                ->chunkById(100, function ($products) {
                    foreach ($products as $product) {
                        $prefix = $this->unitCodePrefix($product);
                        $sequence = $this->nextSequence($product->id, $prefix);

                        $inventories = DB::table('sale_inventories')
                            ->where('organization_id', $product->organization_id)
                            ->where('product_id', $product->id)
                            ->orderBy('id')
                            ->get();

                        foreach ($inventories as $inventory) {
                            $requiredAvailable = max((int) $inventory->quantity_in_stock, 0);
                            if ($requiredAvailable <= 0) {
                                continue;
                            }

                            $existingAvailable = DB::table('assets')
                                ->where('organization_id', $product->organization_id)
                                ->where('product_id', $product->id)
                                ->where('warehouse_id', $inventory->warehouse_id)
                                ->where('asset_stage', Asset::STAGE_NEW_STOCK)
                                ->where('asset_status', 'available_for_sale')
                                ->count();

                            $missing = max($requiredAvailable - $existingAvailable, 0);

                            for ($index = 0; $index < $missing; $index++) {
                                DB::table('assets')->insert([
                                    'organization_id' => $product->organization_id,
                                    'product_id' => $product->id,
                                    'warehouse_id' => $inventory->warehouse_id,
                                    'asset_name' => $product->name,
                                    'serial_number' => $prefix . '-' . str_pad((string) $sequence, 3, '0', STR_PAD_LEFT),
                                    'barcode_value' => null,
                                    'batch_number' => null,
                                    'asset_stage' => Asset::STAGE_NEW_STOCK,
                                    'purchase_date' => null,
                                    'purchase_cost' => $inventory->purchase_cost,
                                    'condition_status' => 'good',
                                    'asset_status' => 'available_for_sale',
                                    'notes' => 'Auto-created from legacy sellable stock quantity migration.',
                                    'last_service_date' => null,
                                    'next_service_date' => null,
                                    'created_at' => now(),
                                    'updated_at' => now(),
                                ]);

                                $sequence++;
                            }
                        }
                    }
                });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('sale_assets')) {
            Schema::dropIfExists('sale_assets');
        }

        if (Schema::hasTable('assets') && Schema::hasColumn('assets', 'batch_number')) {
            Schema::table('assets', function (Blueprint $table) {
                $table->dropColumn('batch_number');
            });
        }
    }

    private function unitCodePrefix(Product $product): string
    {
        $prefix = $product->sku ?: $product->product_code ?: strtoupper(substr(preg_replace('/[^A-Z0-9]+/i', '-', $product->name), 0, 18));
        $prefix = trim($prefix ?: ('PRD-' . $product->id), '-');

        return strtoupper($prefix);
    }

    private function nextSequence(int $productId, string $prefix): int
    {
        $maxSequence = (int) DB::table('assets')
            ->where('product_id', $productId)
            ->where('asset_stage', Asset::STAGE_NEW_STOCK)
            ->where('serial_number', 'like', $prefix . '-%')
            ->selectRaw("MAX(CAST(SUBSTRING_INDEX(serial_number, '-', -1) AS UNSIGNED)) as seq")
            ->value('seq');

        return $maxSequence + 1;
    }
};
