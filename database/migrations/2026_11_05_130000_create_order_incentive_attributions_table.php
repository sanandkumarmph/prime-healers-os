<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_incentive_attributions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->string('order_type', 12);
            $table->unsignedBigInteger('order_id');
            $table->string('source', 80);
            $table->foreignId('sales_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['organization_id', 'order_type', 'order_id'], 'incentive_attribution_order');
        });
    }

    public function down(): void
    {
        if (Illuminate\Support\Facades\DB::table('order_incentive_attributions')->exists()) {
            throw new LogicException('Order incentive attribution history must be preserved.');
        }

        Schema::dropIfExists('order_incentive_attributions');
    }
};
