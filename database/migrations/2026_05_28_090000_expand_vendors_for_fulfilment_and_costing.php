<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            if (!Schema::hasColumn('vendors', 'whatsapp')) {
                $table->string('whatsapp')->nullable()->after('phone');
            }
            if (!Schema::hasColumn('vendors', 'vendor_type')) {
                $table->string('vendor_type', 80)->nullable()->after('email');
            }
            if (!Schema::hasColumn('vendors', 'gst_number')) {
                $table->string('gst_number', 32)->nullable()->after('vendor_type');
            }
            if (!Schema::hasColumn('vendors', 'gst_registration_type')) {
                $table->string('gst_registration_type', 80)->nullable()->after('gst_number');
            }
            if (!Schema::hasColumn('vendors', 'state')) {
                $table->string('state', 120)->nullable()->after('city');
            }
            if (!Schema::hasColumn('vendors', 'pincode')) {
                $table->string('pincode', 20)->nullable()->after('state');
            }
            if (!Schema::hasColumn('vendors', 'payment_terms')) {
                $table->string('payment_terms', 120)->nullable()->after('pincode');
            }
            if (!Schema::hasColumn('vendors', 'deactivated_at')) {
                $table->timestamp('deactivated_at')->nullable()->after('is_active');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vendors', function (Blueprint $table) {
            $drop = [];
            foreach (['whatsapp', 'vendor_type', 'gst_number', 'gst_registration_type', 'state', 'pincode', 'payment_terms', 'deactivated_at'] as $column) {
                if (Schema::hasColumn('vendors', $column)) {
                    $drop[] = $column;
                }
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
