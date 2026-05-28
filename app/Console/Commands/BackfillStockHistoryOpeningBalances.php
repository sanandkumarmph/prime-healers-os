<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Product;
use App\Models\StockMovement;
use App\Services\Inventory\StockMovementRecorder;
use Illuminate\Console\Command;

class BackfillStockHistoryOpeningBalances extends Command
{
    protected $signature = 'phos:backfill-stock-history-opening-balances
        {--organization= : Limit to one organization id}';

    protected $description = 'Backfill current stock and asset positions into the stock movement ledger as opening balances.';

    public function handle(StockMovementRecorder $recorder): int
    {
        $organizationId = $this->option('organization');
        $productCount = 0;
        $assetCount = 0;

        Product::query()
            ->when($organizationId, fn ($query) => $query->where('organization_id', (int) $organizationId))
            ->where('stock_mode', Product::STOCK_MODE_UNTRACKED)
            ->where('available_quantity', '>', 0)
            ->chunkById(100, function ($products) use ($recorder, &$productCount) {
                foreach ($products as $product) {
                    $exists = StockMovement::query()
                        ->where('organization_id', $product->organization_id)
                        ->where('product_id', $product->id)
                        ->whereNull('asset_id')
                        ->where('movement_type', StockMovement::TYPE_OPENING)
                        ->where('notes', 'Backfilled current untracked stock as opening balance.')
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $recorder->recordForProduct($product, StockMovement::TYPE_OPENING, (int) $product->available_quantity, [
                        'to_status' => 'available',
                        'movement_at' => $product->created_at ?? now(),
                        'notes' => 'Backfilled current untracked stock as opening balance.',
                    ]);

                    $productCount++;
                }
            });

        Asset::query()
            ->when($organizationId, fn ($query) => $query->where('organization_id', (int) $organizationId))
            ->chunkById(100, function ($assets) use ($recorder, &$assetCount) {
                foreach ($assets as $asset) {
                    $exists = StockMovement::query()
                        ->where('organization_id', $asset->organization_id)
                        ->where('asset_id', $asset->id)
                        ->where('movement_type', StockMovement::TYPE_OPENING)
                        ->where('notes', 'Backfilled current asset position as opening balance.')
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    $recorder->recordForAsset($asset, StockMovement::TYPE_OPENING, 1, [
                        'to_status' => $asset->asset_status,
                        'to_warehouse_id' => $asset->warehouse_id,
                        'movement_at' => $asset->created_at ?? now(),
                        'notes' => 'Backfilled current asset position as opening balance.',
                    ]);

                    $assetCount++;
                }
            });

        $this->info(sprintf(
            'Opening balance backfill completed. Products: %d, assets: %d.',
            $productCount,
            $assetCount
        ));

        return self::SUCCESS;
    }
}
