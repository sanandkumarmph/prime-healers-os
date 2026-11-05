<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('order_incentive_attributions', 'excluded_from_incentives')) {
            Schema::table('order_incentive_attributions', function (Blueprint $table) {
                $table->boolean('excluded_from_incentives')->default(false);
            });
        }
    }

    public function down(): void
    {
        if (DB::table('order_incentive_attributions')->where('excluded_from_incentives', true)->exists()) {
            throw new LogicException('Admin order ownership history must be preserved.');
        }

        Schema::table('order_incentive_attributions', fn (Blueprint $table) => $table->dropColumn('excluded_from_incentives'));
    }
};
