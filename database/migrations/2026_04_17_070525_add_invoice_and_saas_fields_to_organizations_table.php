<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (!Schema::hasColumn('organizations', 'logo')) {
                $table->string('logo')->nullable()->after('name');
            }

            if (!Schema::hasColumn('organizations', 'address')) {
                $table->text('address')->nullable()->after('logo');
            }

            if (!Schema::hasColumn('organizations', 'city')) {
                $table->string('city')->nullable()->after('address');
            }

            if (!Schema::hasColumn('organizations', 'state')) {
                $table->string('state')->nullable()->after('city');
            }

            if (!Schema::hasColumn('organizations', 'state_code')) {
                $table->string('state_code')->nullable()->after('state');
            }

            if (!Schema::hasColumn('organizations', 'pincode')) {
                $table->string('pincode')->nullable()->after('state_code');
            }

            if (!Schema::hasColumn('organizations', 'country')) {
                $table->string('country')->default('India')->after('pincode');
            }

            if (!Schema::hasColumn('organizations', 'gst_number')) {
                $table->string('gst_number')->nullable()->after('country');
            }

            if (!Schema::hasColumn('organizations', 'phone')) {
                $table->string('phone')->nullable()->after('gst_number');
            }

            if (!Schema::hasColumn('organizations', 'email')) {
                $table->string('email')->nullable()->after('phone');
            }

            if (!Schema::hasColumn('organizations', 'default_terms')) {
                $table->text('default_terms')->nullable()->after('email');
            }

            if (!Schema::hasColumn('organizations', 'plan')) {
                $table->string('plan')->default('internal')->after('default_terms');
            }

            if (!Schema::hasColumn('organizations', 'is_internal')) {
                $table->boolean('is_internal')->default(true)->after('plan');
            }

            if (!Schema::hasColumn('organizations', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('is_internal');
            }
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $columns = [
                'logo',
                'address',
                'city',
                'state',
                'state_code',
                'pincode',
                'country',
                'gst_number',
                'phone',
                'email',
                'default_terms',
                'plan',
                'is_internal',
                'is_active',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('organizations', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};