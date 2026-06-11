<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            if (!Schema::hasColumn('rentals', 'referral_source_type')) {
                $table->string('referral_source_type', 80)->nullable()->after('pickup_staff_id');
            }

            if (!Schema::hasColumn('rentals', 'referred_by')) {
                $table->string('referred_by', 180)->nullable()->after('referral_source_type');
            }

            if (!Schema::hasColumn('rentals', 'referral_incentive_amount')) {
                $table->decimal('referral_incentive_amount', 12, 2)->nullable()->after('referred_by');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            foreach (['referral_incentive_amount', 'referred_by', 'referral_source_type'] as $column) {
                if (Schema::hasColumn('rentals', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
