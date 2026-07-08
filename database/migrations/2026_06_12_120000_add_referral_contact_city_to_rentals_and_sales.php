<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            if (!Schema::hasColumn('rentals', 'referral_contact')) {
                $table->string('referral_contact', 180)->nullable()->after('referred_by');
            }

            if (!Schema::hasColumn('rentals', 'referral_city')) {
                $table->string('referral_city', 120)->nullable()->after('referral_contact');
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'referral_city')) {
                $table->string('referral_city', 120)->nullable()->after('referral_contact');
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'referral_city')) {
                $table->dropColumn('referral_city');
            }
        });

        Schema::table('rentals', function (Blueprint $table) {
            foreach (['referral_city', 'referral_contact'] as $column) {
                if (Schema::hasColumn('rentals', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
