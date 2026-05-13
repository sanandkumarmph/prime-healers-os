<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rental_items')) {
            return;
        }

        Schema::table('rental_items', function (Blueprint $table) {
            if (!Schema::hasColumn('rental_items', 'ordered_quantity')) {
                $table->unsignedInteger('ordered_quantity')->nullable()->after('quantity');
            }

            if (!Schema::hasColumn('rental_items', 'delivered_quantity')) {
                $table->unsignedInteger('delivered_quantity')->default(0)->after('ordered_quantity');
            }

            if (!Schema::hasColumn('rental_items', 'returned_quantity')) {
                $table->unsignedInteger('returned_quantity')->default(0)->after('delivered_quantity');
            }
        });

        if (Schema::hasColumn('rental_items', 'ordered_quantity') && Schema::hasColumn('rental_items', 'quantity')) {
            DB::table('rental_items')
                ->whereNull('ordered_quantity')
                ->update([
                    'ordered_quantity' => DB::raw('quantity'),
                ]);
        }

        if (Schema::hasColumn('rental_items', 'delivered_quantity')) {
            DB::table('rental_items')
                ->whereNull('delivered_quantity')
                ->update([
                    'delivered_quantity' => 0,
                ]);
        }

        if (Schema::hasColumn('rental_items', 'returned_quantity')) {
            DB::table('rental_items')
                ->whereNull('returned_quantity')
                ->update([
                    'returned_quantity' => 0,
                ]);
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('rental_items')) {
            return;
        }

        Schema::table('rental_items', function (Blueprint $table) {
            if (Schema::hasColumn('rental_items', 'ordered_quantity')) {
                $table->dropColumn('ordered_quantity');
            }
        });
    }
};
