<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('movement_type', 64);
            $table->integer('quantity')->default(0);
            $table->string('from_status', 64)->nullable();
            $table->string('to_status', 64)->nullable();
            $table->foreignId('from_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('to_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            $table->foreignId('rental_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('delivery_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('movement_at');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'movement_at']);
            $table->index(['organization_id', 'movement_type']);
            $table->index(['organization_id', 'product_id']);
            $table->index(['organization_id', 'asset_id']);
            $table->index(['organization_id', 'from_warehouse_id']);
            $table->index(['organization_id', 'to_warehouse_id']);
            $table->index(['organization_id', 'rental_id']);
            $table->index(['organization_id', 'sale_id']);
            $table->index(['organization_id', 'delivery_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
