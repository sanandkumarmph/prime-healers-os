<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $columns = Schema::getColumnListing('organizations');

        Schema::table('organizations', function (Blueprint $table) use ($columns) {
            if (!in_array('logo', $columns)) {
                $table->string('logo')->nullable()->after('name');
            }

            if (!in_array('address', $columns)) {
                $table->text('address')->nullable()->after('logo');
            }

            if (!in_array('city', $columns)) {
                $table->string('city')->nullable()->after('address');
            }

            if (!in_array('state', $columns)) {
                $table->string('state')->nullable()->after('city');
            }

            if (!in_array('state_code', $columns)) {
                $table->string('state_code')->nullable()->after('state');
            }

            if (!in_array('pincode', $columns)) {
                $table->string('pincode')->nullable()->after('state_code');
            }

            if (!in_array('country', $columns)) {
                $table->string('country')->default('India')->after('pincode');
            }

            if (!in_array('gst_number', $columns)) {
                $table->string('gst_number')->nullable()->after('country');
            }

            if (!in_array('phone', $columns)) {
                $table->string('phone')->nullable()->after('gst_number');
            }

            if (!in_array('email', $columns)) {
                $table->string('email')->nullable()->after('phone');
            }

            if (!in_array('default_terms', $columns)) {
                $table->text('default_terms')->nullable()->after('email');
            }

            if (!in_array('plan', $columns)) {
                $table->string('plan')->default('internal')->after('default_terms');
            }

            if (!in_array('is_internal', $columns)) {
                $table->boolean('is_internal')->default(true)->after('plan');
            }

            if (!in_array('is_active', $columns)) {
                $table->boolean('is_active')->default(true)->after('is_internal');
            }
        });
    }

    public function down(): void
    {
        // Keep empty to avoid accidentally dropping existing production columns
    }
};