<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();

            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();

            $table->string('invoice_number')->unique();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();

            $table->string('purchase_order_number')->nullable();
            $table->date('purchase_order_date')->nullable();
            $table->string('reference_number')->nullable();

            $table->string('bill_to_name')->nullable();
            $table->string('bill_to_phone')->nullable();
            $table->string('bill_to_email')->nullable();
            $table->text('bill_to_address')->nullable();
            $table->string('bill_to_city')->nullable();
            $table->string('bill_to_state')->nullable();
            $table->string('bill_to_state_code')->nullable();
            $table->string('bill_to_pincode')->nullable();
            $table->string('bill_to_gstin')->nullable();

            $table->string('ship_to_name')->nullable();
            $table->string('ship_to_phone')->nullable();
            $table->text('ship_to_address')->nullable();
            $table->string('ship_to_city')->nullable();
            $table->string('ship_to_state')->nullable();
            $table->string('ship_to_state_code')->nullable();
            $table->string('ship_to_pincode')->nullable();

            $table->string('place_of_supply_state')->nullable();
            $table->string('place_of_supply_code')->nullable();

            $table->string('tax_type')->default('cgst_sgst');
            $table->string('status')->default('draft');
            $table->string('payment_status')->default('unpaid');

            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('taxable_amount', 12, 2)->default(0);

            $table->decimal('cgst_amount', 12, 2)->default(0);
            $table->decimal('sgst_amount', 12, 2)->default(0);
            $table->decimal('igst_amount', 12, 2)->default(0);
            $table->decimal('total_tax_amount', 12, 2)->default(0);

            $table->decimal('total_amount', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('balance_amount', 12, 2)->default(0);

            $table->text('notes')->nullable();
            $table->text('terms_conditions')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
    }
};