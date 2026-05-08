<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->unsignedBigInteger('delivery_staff_id')->nullable()->after('product_id');
            $table->unsignedBigInteger('pickup_staff_id')->nullable()->after('delivery_staff_id');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn(['delivery_staff_id', 'pickup_staff_id']);
        });
    }
};