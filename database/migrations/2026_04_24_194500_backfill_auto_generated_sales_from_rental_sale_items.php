<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (
            !Schema::hasTable('rental_sale_items') ||
            !Schema::hasTable('sales') ||
            !Schema::hasTable('rentals') ||
            !Schema::hasColumn('sales', 'auto_generated_from_rental')
        ) {
            return;
        }

        $hasWarehouseColumn = Schema::hasColumn('sales', 'warehouse_id');
        $hasStockAppliedColumn = Schema::hasColumn('sales', 'stock_applied');
        $hasCreatedByColumn = Schema::hasColumn('sales', 'created_by');
        $hasCreatedByUserColumn = Schema::hasColumn('sales', 'created_by_user_id');

        $rows = DB::table('rental_sale_items')
            ->join('rentals', 'rentals.id', '=', 'rental_sale_items.rental_id')
            ->select(
                'rental_sale_items.organization_id',
                'rental_sale_items.rental_id',
                'rental_sale_items.product_id',
                'rental_sale_items.warehouse_id',
                'rental_sale_items.quantity',
                'rental_sale_items.unit_price',
                'rental_sale_items.line_total',
                'rental_sale_items.notes',
                'rentals.customer_id',
                'rentals.created_by_user_id',
                'rentals.created_at as rental_created_at'
            )
            ->orderBy('rental_sale_items.id')
            ->get();

        foreach ($rows as $row) {
            $payload = [
                'customer_id' => $row->customer_id,
                'product_id' => $row->product_id,
                'asset_id' => null,
                'rental_id' => $row->rental_id,
                'auto_generated_from_rental' => true,
                'quantity' => (int) $row->quantity,
                'unit_price' => (float) ($row->unit_price ?? 0),
                'discount_amount' => 0,
                'shipping_charges' => 0,
                'tax_percentage' => 0,
                'tax_calculation_mode' => 'exclusive',
                'sale_date' => $row->rental_created_at
                    ? \Illuminate\Support\Carbon::parse($row->rental_created_at)->toDateString()
                    : now()->toDateString(),
                'sale_amount' => (float) ($row->line_total ?? 0),
                'payment_status' => 'unpaid',
                'notes' => trim(collect([
                    'Auto-generated from rental #' . $row->rental_id,
                    $row->notes,
                ])->filter()->implode(' | ')),
                'organization_id' => $row->organization_id,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            if ($hasWarehouseColumn) {
                $payload['warehouse_id'] = $row->warehouse_id;
            }

            if ($hasStockAppliedColumn) {
                $payload['stock_applied'] = false;
            }

            if ($hasCreatedByColumn && !empty($row->created_by_user_id)) {
                $payload['created_by'] = $row->created_by_user_id;
            }

            if ($hasCreatedByUserColumn && !empty($row->created_by_user_id)) {
                $payload['created_by_user_id'] = $row->created_by_user_id;
            }

            DB::table('sales')->insert($payload);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('sales') || !Schema::hasColumn('sales', 'auto_generated_from_rental')) {
            return;
        }

        DB::table('sales')
            ->where('auto_generated_from_rental', true)
            ->delete();
    }
};
