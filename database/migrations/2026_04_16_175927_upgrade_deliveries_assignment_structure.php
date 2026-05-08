<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            if (!Schema::hasColumn('deliveries', 'assignment_type')) {
                $table->string('assignment_type')->default('delivery_team')->after('type');
            }

            if (!Schema::hasColumn('deliveries', 'assigned_user_id')) {
                $table->foreignId('assigned_user_id')
                    ->nullable()
                    ->after('assignment_type')
                    ->constrained('users')
                    ->nullOnDelete();
            }

            if (!Schema::hasColumn('deliveries', 'third_party_name')) {
                $table->string('third_party_name')->nullable()->after('assigned_user_id');
            }

            if (!Schema::hasColumn('deliveries', 'third_party_contact')) {
                $table->string('third_party_contact')->nullable()->after('third_party_name');
            }

            if (!Schema::hasColumn('deliveries', 'third_party_phone')) {
                $table->string('third_party_phone')->nullable()->after('third_party_contact');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            if (Schema::hasColumn('deliveries', 'assigned_user_id')) {
                $table->dropConstrainedForeignId('assigned_user_id');
            }

            $columnsToDrop = [];

            if (Schema::hasColumn('deliveries', 'assignment_type')) {
                $columnsToDrop[] = 'assignment_type';
            }

            if (Schema::hasColumn('deliveries', 'third_party_name')) {
                $columnsToDrop[] = 'third_party_name';
            }

            if (Schema::hasColumn('deliveries', 'third_party_contact')) {
                $columnsToDrop[] = 'third_party_contact';
            }

            if (Schema::hasColumn('deliveries', 'third_party_phone')) {
                $columnsToDrop[] = 'third_party_phone';
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};