<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            if (!Schema::hasColumn('activity_logs', 'business_partner_id')) {
                $table->unsignedBigInteger('business_partner_id')->nullable()->after('delivery_id')->index();
            }

            if (!Schema::hasColumn('activity_logs', 'partner_client_id')) {
                $table->unsignedBigInteger('partner_client_id')->nullable()->after('business_partner_id')->index();
            }
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            if (Schema::hasColumn('activity_logs', 'partner_client_id')) {
                $table->dropIndex(['partner_client_id']);
                $table->dropColumn('partner_client_id');
            }

            if (Schema::hasColumn('activity_logs', 'business_partner_id')) {
                $table->dropIndex(['business_partner_id']);
                $table->dropColumn('business_partner_id');
            }
        });
    }
};
