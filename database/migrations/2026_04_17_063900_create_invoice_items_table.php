<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();

            $table->string('source_type')->nullable(); // rental / sale / manual
            $table->unsignedBigInteger('source_id')->nullable();

            $table->string('description');
            $table->string('hsn_sac_code')->nullable();

            $table->decimal('quantity', 12, 2)->default(1);
            $table->string('unit')->nullable();
            $table->decimal('days', 12, 2)->nullable();

            $table->decimal('rate', 12, 2)->default(0);
            $table->decimal('discount_amount', 12, 2)->default(0);
            $table->decimal('taxable_amount', 12, 2)->default(0);

            $table->decimal('tax_percentage', 5, 2)->default(0);
            $table->string('tax_type')->default('cgst_sgst');

            $table->decimal('cgst_rate', 5, 2)->default(0);
            $table->decimal('sgst_rate', 5, 2)->default(0);
            $table->decimal('igst_rate', 5, 2)->default(0);

            $table->decimal('cgst_amount', 12, 2)->default(0);
            $table->decimal('sgst_amount', 12, 2)->default(0);
            $table->decimal('igst_amount', 12, 2)->default(0);

            $table->decimal('line_total', 12, 2)->default(0);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_items');
    }
};