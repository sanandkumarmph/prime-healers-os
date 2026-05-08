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
            if (!Schema::hasColumn('staff', 'assignment_role')) {
                $table->string('assignment_role')->nullable()->after('role');
            }

            if (!Schema::hasColumn('staff', 'is_assignment_enabled')) {
                $table->boolean('is_assignment_enabled')->default(true)->after('assignment_role');
            }

            if (!Schema::hasColumn('staff', 'city')) {
                $table->string('city')->nullable()->after('address');
            }

            if (!Schema::hasColumn('staff', 'notes')) {
                $table->text('notes')->nullable()->after('city');
            }
        });

        DB::table('staff')->select(['id', 'role', 'status'])->orderBy('id')->chunkById(100, function ($staffMembers) {
            foreach ($staffMembers as $staff) {
                $role = strtolower(trim((string) ($staff->role ?? '')));

                $assignmentRole = match ($role) {
                    'delivery', 'pickup', 'vendor', 'technician', 'office', 'admin' => $role,
                    'third_party', 'third party' => 'third_party',
                    default => 'office',
                };

                $payload = [];

                if (Schema::hasColumn('staff', 'assignment_role')) {
                    $payload['assignment_role'] = $assignmentRole;
                }

                if (Schema::hasColumn('staff', 'is_assignment_enabled')) {
                    $payload['is_assignment_enabled'] = ($staff->status ?? 'active') === 'active'
                        && in_array($assignmentRole, ['delivery', 'pickup', 'vendor', 'third_party'], true);
                }

                if (!empty($payload)) {
                    DB::table('staff')->where('id', $staff->id)->update($payload);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            if (Schema::hasColumn('staff', 'notes')) {
                $table->dropColumn('notes');
            }

            if (Schema::hasColumn('staff', 'city')) {
                $table->dropColumn('city');
            }
        });
    }
};
