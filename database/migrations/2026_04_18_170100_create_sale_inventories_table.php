<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_inventories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity_in_stock')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('reorder_level')->nullable();
            $table->decimal('purchase_cost', 10, 2)->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'product_id', 'warehouse_id'], 'sale_inventories_unique_scope');
            $table->index(['organization_id', 'warehouse_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_inventories');
    }
};
