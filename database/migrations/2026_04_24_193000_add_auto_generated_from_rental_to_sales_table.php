<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'auto_generated_from_rental')) {
                $table->boolean('auto_generated_from_rental')
                    ->default(false)
                    ->after('rental_id');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'auto_generated_from_rental')) {
                $table->dropColumn('auto_generated_from_rental');
            }
        });
    }
};
