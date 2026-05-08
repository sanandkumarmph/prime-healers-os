<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->string('assignment_role')->nullable()->after('role');
            $table->boolean('is_assignment_enabled')->default(true)->after('assignment_role');
        });

        DB::table('staff')->get()->each(function ($staff) {
            $role = strtolower(trim((string) ($staff->role ?? '')));

            $assignmentRole = match ($role) {
                'delivery' => 'delivery',
                'vendor' => 'vendor',
                'third_party', 'third party' => 'third_party',
                'pickup' => 'pickup',
                'technician' => 'technician',
                'admin' => 'admin',
                default => 'office',
            };

            DB::table('staff')
                ->where('id', $staff->id)
                ->update([
                    'assignment_role' => $assignmentRole,
                    'is_assignment_enabled' => ($staff->status ?? 'active') === 'active',
                ]);
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->foreignId('assigned_staff_id')
                ->nullable()
                ->after('assigned_user_id')
                ->constrained('staff')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('assigned_staff_id');
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn(['assignment_role', 'is_assignment_enabled']);
        });
    }
};
