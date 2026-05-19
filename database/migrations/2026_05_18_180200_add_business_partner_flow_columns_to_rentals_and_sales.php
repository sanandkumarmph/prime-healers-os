<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->string('customer_type')->default('direct_customer')->after('customer_id');
            $table->foreignId('business_partner_id')->nullable()->after('customer_type')->constrained('business_partners')->nullOnDelete();
            $table->foreignId('partner_client_id')->nullable()->after('business_partner_id')->constrained('partner_clients')->nullOnDelete();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('customer_id')->nullable()->change();
            $table->string('customer_type')->default('direct_customer')->after('customer_id');
            $table->foreignId('business_partner_id')->nullable()->after('customer_type')->constrained('business_partners')->nullOnDelete();
            $table->foreignId('partner_client_id')->nullable()->after('business_partner_id')->constrained('partner_clients')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_client_id');
            $table->dropConstrainedForeignId('business_partner_id');
            $table->dropColumn('customer_type');
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('partner_client_id');
            $table->dropConstrainedForeignId('business_partner_id');
            $table->dropColumn('customer_type');
        });
    }
};
