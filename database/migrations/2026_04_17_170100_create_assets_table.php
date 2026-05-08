<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained()->restrictOnDelete();
            $table->string('asset_name')->nullable();
            $table->string('serial_number');
            $table->string('barcode_value')->nullable();
            $table->date('purchase_date')->nullable();
            $table->decimal('purchase_cost', 12, 2)->nullable();
            $table->string('condition_status')->default('good');
            $table->string('asset_status')->default('available');
            $table->text('notes')->nullable();
            $table->date('last_service_date')->nullable();
            $table->date('next_service_date')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'serial_number']);
            $table->unique(['organization_id', 'barcode_value']);
            $table->index(['organization_id', 'product_id']);
            $table->index(['organization_id', 'warehouse_id']);
            $table->index(['organization_id', 'asset_status']);
            $table->index(['organization_id', 'condition_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};
