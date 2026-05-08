<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->decimal('rental_amount', 10, 2)->default(0)->after('status');
            $table->decimal('deposit_amount', 10, 2)->default(0)->after('rental_amount');
            $table->decimal('transport_amount', 10, 2)->default(0)->after('deposit_amount');
            $table->decimal('other_amount', 10, 2)->default(0)->after('transport_amount');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn([
                'rental_amount',
                'deposit_amount',
                'transport_amount',
                'other_amount',
            ]);
        });
    }
};