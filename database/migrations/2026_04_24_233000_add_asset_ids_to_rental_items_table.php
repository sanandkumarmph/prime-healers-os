<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rental_items') || Schema::hasColumn('rental_items', 'asset_ids')) {
            return;
        }

        Schema::table('rental_items', function (Blueprint $table) {
            $table->json('asset_ids')->nullable()->after('product_id');
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('rental_items') || !Schema::hasColumn('rental_items', 'asset_ids')) {
            return;
        }

        Schema::table('rental_items', function (Blueprint $table) {
            $table->dropColumn('asset_ids');
        });
    }
};
