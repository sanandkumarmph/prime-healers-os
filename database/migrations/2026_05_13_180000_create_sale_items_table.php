<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('sale_items')) {
            Schema::create('sale_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('organization_id');
                $table->unsignedBigInteger('sale_id');
                $table->unsignedBigInteger('product_id')->nullable();
                $table->unsignedBigInteger('asset_id')->nullable();
                $table->unsignedBigInteger('warehouse_id')->nullable();
                $table->unsignedInteger('quantity')->default(1);
                $table->decimal('unit_price', 10, 2)->default(0);
                $table->decimal('discount_amount', 10, 2)->default(0);
                $table->decimal('shipping_charges', 10, 2)->default(0);
                $table->decimal('tax_percentage', 5, 2)->default(0);
                $table->string('tax_calculation_mode', 20)->default('exclusive');
                $table->decimal('taxable_amount', 10, 2)->default(0);
                $table->decimal('total_tax_amount', 10, 2)->default(0);
                $table->decimal('line_total', 10, 2)->default(0);
                $table->unsignedInteger('sort_order')->default(0);
                $table->json('asset_ids')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'sale_id']);
                $table->index(['organization_id', 'product_id']);
            });
        }

        $hasWarehouseColumn = Schema::hasColumn('sales', 'warehouse_id');
        $sales = DB::table('sales')
            ->select([
                'id',
                'organization_id',
                'product_id',
                'asset_id',
                'quantity',
                'unit_price',
                'discount_amount',
                'shipping_charges',
                'tax_percentage',
                'tax_calculation_mode',
                'sale_amount',
                'notes',
                DB::raw($hasWarehouseColumn ? 'warehouse_id' : 'NULL as warehouse_id'),
            ])
            ->orderBy('id')
            ->get();

        foreach ($sales as $sale) {
            $alreadyExists = DB::table('sale_items')
                ->where('sale_id', $sale->id)
                ->exists();

            if ($alreadyExists) {
                continue;
            }

            $quantity = max((int) ($sale->quantity ?? 0), 1);
            $unitPrice = round((float) ($sale->unit_price ?? 0), 2);
            $discountAmount = round((float) ($sale->discount_amount ?? 0), 2);
            $shippingCharges = round((float) ($sale->shipping_charges ?? 0), 2);
            $taxPercentage = round((float) ($sale->tax_percentage ?? 0), 2);
            $taxMode = ($sale->tax_calculation_mode ?? 'exclusive') === 'inclusive' ? 'inclusive' : 'exclusive';
            $subtotal = round($quantity * $unitPrice, 2);
            $taxableBase = max($subtotal - $discountAmount, 0);

            if ($taxMode === 'inclusive' && $taxPercentage > 0) {
                $taxableAmount = round($taxableBase / (1 + ($taxPercentage / 100)), 2);
                $taxAmount = round($taxableBase - $taxableAmount, 2);
            } else {
                $taxableAmount = round($taxableBase, 2);
                $taxAmount = round(($taxableAmount * $taxPercentage) / 100, 2);
            }

            $lineTotal = round((float) ($sale->sale_amount ?? ($taxableBase + ($taxMode === 'exclusive' ? $taxAmount : 0) + $shippingCharges)), 2);

            DB::table('sale_items')->insert([
                'organization_id' => $sale->organization_id,
                'sale_id' => $sale->id,
                'product_id' => $sale->product_id,
                'asset_id' => $sale->asset_id,
                'warehouse_id' => $sale->warehouse_id,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'discount_amount' => $discountAmount,
                'shipping_charges' => $shippingCharges,
                'tax_percentage' => $taxPercentage,
                'tax_calculation_mode' => $taxMode,
                'taxable_amount' => $taxableAmount,
                'total_tax_amount' => $taxAmount,
                'line_total' => $lineTotal,
                'sort_order' => 0,
                'asset_ids' => $sale->asset_id ? json_encode([(int) $sale->asset_id]) : null,
                'notes' => $sale->notes,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_items');
    }
};
