<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('inventory_conversions')) {
            return;
        }

        Schema::create('inventory_conversions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->string('conversion_type')->default('sale_to_rental');
            $table->unsignedInteger('quantity_converted');
            $table->unsignedInteger('sale_stock_before')->default(0);
            $table->unsignedInteger('sale_stock_after')->default(0);
            $table->text('remarks')->nullable();
            $table->foreignId('converted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(
    ['organization_id', 'product_id', 'warehouse_id'],
    'inv_conv_org_prod_wh_idx'
);

$table->index(
    ['organization_id', 'conversion_type'],
    'inv_conv_org_conv_type_idx'
);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_conversions');
    }
};
