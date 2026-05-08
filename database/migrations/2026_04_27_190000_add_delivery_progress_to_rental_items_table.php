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
            if (!Schema::hasColumn('rental_items', 'delivered_quantity')) {
                $table->unsignedInteger('delivered_quantity')->default(0)->after('quantity');
            }

            if (!Schema::hasColumn('rental_items', 'returned_quantity')) {
                $table->unsignedInteger('returned_quantity')->default(0)->after('delivered_quantity');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('rental_items')) {
            return;
        }

        Schema::table('rental_items', function (Blueprint $table) {
            if (Schema::hasColumn('rental_items', 'returned_quantity')) {
                $table->dropColumn('returned_quantity');
            }

            if (Schema::hasColumn('rental_items', 'delivered_quantity')) {
                $table->dropColumn('delivered_quantity');
            }
        });
    }
};
