<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->string('asset_stage')->default('rental_stock')->after('barcode_value');
            $table->index(['organization_id', 'asset_stage']);
        });

        DB::table('assets')
            ->whereNull('asset_stage')
            ->update(['asset_stage' => 'rental_stock']);
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropIndex(['organization_id', 'asset_stage']);
            $table->dropColumn('asset_stage');
        });
    }
};
