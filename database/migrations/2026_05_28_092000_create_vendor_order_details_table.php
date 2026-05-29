<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_order_details', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('vendor_id')->nullable();
            $table->unsignedBigInteger('rental_id')->nullable();
            $table->unsignedBigInteger('sale_id')->nullable();
            $table->string('order_type', 20);
            $table->string('fulfilment_source', 32)->default('in_house');
            $table->string('delivery_responsibility', 40)->nullable();
            $table->string('pickup_responsibility', 40)->nullable();
            $table->string('vendor_order_status', 32)->default('draft');
            $table->decimal('procurement_cost', 12, 2)->default(0);
            $table->decimal('vendor_delivery_cost', 12, 2)->default(0);
            $table->decimal('vendor_pickup_cost', 12, 2)->default(0);
            $table->decimal('other_vendor_cost', 12, 2)->default(0);
            $table->string('vendor_invoice_number')->nullable();
            $table->string('vendor_payment_status', 32)->default('pending');
            $table->timestamp('vendor_paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'vendor_id']);
            $table->index(['organization_id', 'order_type']);
            $table->index(['organization_id', 'fulfilment_source']);
            $table->unique(['rental_id']);
            $table->unique(['sale_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_order_details');
    }
};
