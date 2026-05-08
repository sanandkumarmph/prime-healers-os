<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'deposit_amount')) {
                $table->decimal('deposit_amount', 12, 2)->default(0)->after('discount_amount');
            }

            if (!Schema::hasColumn('invoices', 'shipping_charges')) {
                $table->decimal('shipping_charges', 12, 2)->default(0)->after('deposit_amount');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'shipping_charges')) {
                $table->dropColumn('shipping_charges');
            }

            if (Schema::hasColumn('invoices', 'deposit_amount')) {
                $table->dropColumn('deposit_amount');
            }
        });
    }
};