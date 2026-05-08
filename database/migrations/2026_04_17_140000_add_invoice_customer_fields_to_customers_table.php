<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'customer_type')) {
                $table->string('customer_type')->nullable()->after('name');
            }

            if (!Schema::hasColumn('customers', 'first_name')) {
                $table->string('first_name')->nullable()->after('customer_type');
            }

            if (!Schema::hasColumn('customers', 'last_name')) {
                $table->string('last_name')->nullable()->after('first_name');
            }

            if (!Schema::hasColumn('customers', 'company_name')) {
                $table->string('company_name')->nullable()->after('last_name');
            }

            if (!Schema::hasColumn('customers', 'email')) {
                $table->string('email')->nullable()->after('phone');
            }

            if (!Schema::hasColumn('customers', 'gst_treatment')) {
                $table->string('gst_treatment')->nullable()->after('email');
            }

            if (!Schema::hasColumn('customers', 'place_of_supply')) {
                $table->string('place_of_supply')->nullable()->after('gst_treatment');
            }

            if (!Schema::hasColumn('customers', 'gst_number')) {
                $table->string('gst_number')->nullable()->after('place_of_supply');
            }

            if (!Schema::hasColumn('customers', 'state')) {
                $table->string('state')->nullable()->after('city');
            }

            if (!Schema::hasColumn('customers', 'pincode')) {
                $table->string('pincode')->nullable()->after('state');
            }

            if (!Schema::hasColumn('customers', 'patient_name')) {
                $table->string('patient_name')->nullable()->after('pincode');
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive.
    }
};
