<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('business_partners', function (Blueprint $table) {
            $table->boolean('gst_registered')->default(false)->after('email');
            $table->string('gstin', 20)->nullable()->after('gst_registered');
            $table->string('legal_name')->nullable()->after('gstin');
            $table->string('billing_state')->nullable()->after('legal_name');
            $table->text('billing_address')->nullable()->after('billing_state');
            $table->string('billing_city')->nullable()->after('billing_address');
            $table->string('billing_pincode', 20)->nullable()->after('billing_city');
        });
    }

    public function down(): void
    {
        Schema::table('business_partners', function (Blueprint $table) {
            $table->dropColumn([
                'gst_registered',
                'gstin',
                'legal_name',
                'billing_state',
                'billing_address',
                'billing_city',
                'billing_pincode',
            ]);
        });
    }
};
