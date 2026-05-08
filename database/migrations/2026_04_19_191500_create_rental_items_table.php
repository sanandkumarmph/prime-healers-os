<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_items')) {
            return;
        }

        Schema::create('rental_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('rental_id');
            $table->unsignedBigInteger('product_id');
            $table->unsignedInteger('quantity')->default(1);
            $table->decimal('unit_rental_amount', 12, 2)->default(0);
            $table->decimal('line_total', 12, 2)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'rental_id']);
            $table->index(['organization_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_items');
    }
};
