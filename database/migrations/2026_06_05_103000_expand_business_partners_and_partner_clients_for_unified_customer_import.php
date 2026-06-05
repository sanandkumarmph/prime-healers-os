<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_partners', function (Blueprint $table) {
            if (!Schema::hasColumn('business_partners', 'partner_code')) {
                $table->string('partner_code', 120)->nullable()->after('business_name');
            }

            if (!Schema::hasColumn('business_partners', 'credit_terms')) {
                $table->string('credit_terms', 120)->nullable()->after('pincode');
            }

            if (!Schema::hasColumn('business_partners', 'referral_percentage')) {
                $table->decimal('referral_percentage', 5, 2)->nullable()->after('credit_terms');
            }

            if (!Schema::hasColumn('business_partners', 'account_manager')) {
                $table->string('account_manager', 160)->nullable()->after('referral_percentage');
            }

            if (!Schema::hasColumn('business_partners', 'notes')) {
                $table->text('notes')->nullable()->after('account_manager');
            }
        });

        Schema::table('partner_clients', function (Blueprint $table) {
            if (!Schema::hasColumn('partner_clients', 'account_manager')) {
                $table->string('account_manager', 160)->nullable()->after('delivery_notes');
            }

            if (!Schema::hasColumn('partner_clients', 'notes')) {
                $table->text('notes')->nullable()->after('account_manager');
            }
        });
    }

    public function down(): void
    {
        Schema::table('business_partners', function (Blueprint $table) {
            foreach (['partner_code', 'credit_terms', 'referral_percentage', 'account_manager', 'notes'] as $column) {
                if (Schema::hasColumn('business_partners', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('partner_clients', function (Blueprint $table) {
            foreach (['account_manager', 'notes'] as $column) {
                if (Schema::hasColumn('partner_clients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
