<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rental_items')) {
            return;
        }

        Schema::table('rental_items', function (Blueprint $table) {
            if (!Schema::hasColumn('rental_items', 'start_date')) {
                $table->date('start_date')->nullable()->after('quantity');
            }

            if (!Schema::hasColumn('rental_items', 'end_date')) {
                $table->date('end_date')->nullable()->after('start_date');
            }

            if (!Schema::hasColumn('rental_items', 'duration_days')) {
                $table->unsignedInteger('duration_days')->nullable()->after('end_date');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('rental_items')) {
            return;
        }

        Schema::table('rental_items', function (Blueprint $table) {
            foreach (['duration_days', 'end_date', 'start_date'] as $column) {
                if (Schema::hasColumn('rental_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
