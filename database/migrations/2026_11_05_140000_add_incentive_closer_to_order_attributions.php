<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('order_incentive_attributions', 'closer_user_id')) {
            Schema::table('order_incentive_attributions', function (Blueprint $table) {
                $table->foreignId('closer_user_id')->nullable()->constrained('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (DB::table('order_incentive_attributions')->whereNotNull('closer_user_id')->exists()) {
            throw new LogicException('Incentive handoff history must be preserved.');
        }

        Schema::table('order_incentive_attributions', fn (Blueprint $table) => $table->dropConstrainedForeignId('closer_user_id'));
    }
};
