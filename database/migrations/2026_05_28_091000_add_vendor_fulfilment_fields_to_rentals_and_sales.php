<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            if (!Schema::hasColumn('rentals', 'vendor_id')) {
                $table->unsignedBigInteger('vendor_id')->nullable()->after('dispatch_warehouse_id');
                $table->index('vendor_id');
            }
            if (!Schema::hasColumn('rentals', 'fulfilment_source')) {
                $table->string('fulfilment_source', 32)->default('in_house')->after('vendor_id');
            }
            if (!Schema::hasColumn('rentals', 'delivery_responsibility')) {
                $table->string('delivery_responsibility', 40)->nullable()->after('fulfilment_source');
            }
            if (!Schema::hasColumn('rentals', 'pickup_responsibility')) {
                $table->string('pickup_responsibility', 40)->nullable()->after('delivery_responsibility');
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'vendor_id')) {
                $table->unsignedBigInteger('vendor_id')->nullable()->after('warehouse_id');
                $table->index('vendor_id');
            }
            if (!Schema::hasColumn('sales', 'fulfilment_source')) {
                $table->string('fulfilment_source', 32)->default('in_house')->after('vendor_id');
            }
            if (!Schema::hasColumn('sales', 'delivery_responsibility')) {
                $table->string('delivery_responsibility', 40)->nullable()->after('fulfilment_source');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            foreach (['vendor_id', 'fulfilment_source', 'delivery_responsibility', 'pickup_responsibility'] as $column) {
                if (Schema::hasColumn('rentals', $column)) {
                    if ($column === 'vendor_id') {
                        $table->dropIndex(['vendor_id']);
                    }
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            foreach (['vendor_id', 'fulfilment_source', 'delivery_responsibility'] as $column) {
                if (Schema::hasColumn('sales', $column)) {
                    if ($column === 'vendor_id') {
                        $table->dropIndex(['vendor_id']);
                    }
                    $table->dropColumn($column);
                }
            }
        });
    }
};
