<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rental_sale_items') || Schema::hasColumn('rental_sale_items', 'asset_id')) {
            return;
        }

        Schema::table('rental_sale_items', function (Blueprint $table) {
            $table->unsignedBigInteger('asset_id')->nullable()->after('product_id');
            $table->index(['organization_id', 'asset_id']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('rental_sale_items') || !Schema::hasColumn('rental_sale_items', 'asset_id')) {
            return;
        }

        Schema::table('rental_sale_items', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'asset_id']);
            $table->dropColumn('asset_id');
        });
    }
};
