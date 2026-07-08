<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('referral_sources')) {
            Schema::create('referral_sources', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('source_type', 80)->nullable();
                $table->string('name', 180);
                $table->string('contact', 180)->nullable();
                $table->string('city', 120)->nullable();
                $table->text('notes')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['organization_id', 'name', 'contact']);
                $table->index(['organization_id', 'source_type']);
                $table->index(['organization_id', 'is_active']);
            });
        }

        $this->addReferralSourceColumnsIfMissing('rentals');
        $this->addReferralSourceColumnsIfMissing('sales');
    }

    public function down(): void
    {
        $this->dropReferralSourceColumnsIfExists('sales');
        $this->dropReferralSourceColumnsIfExists('rentals');

        Schema::dropIfExists('referral_sources');
    }

    private function addReferralSourceColumnsIfMissing(string $tableName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (! Schema::hasColumn($tableName, 'referral_source_type')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->string('referral_source_type', 80)->nullable();
            });
        }

        if (! Schema::hasColumn($tableName, 'referral_source_id')) {
            Schema::table($tableName, function (Blueprint $table) {
                $column = $table->foreignId('referral_source_id')->nullable();

                if (Schema::hasTable('referral_sources')) {
                    $column->constrained('referral_sources')->nullOnDelete();
                }
            });
        }
    }

    private function dropReferralSourceColumnsIfExists(string $tableName): void
    {
        if (! Schema::hasTable($tableName)) {
            return;
        }

        if (Schema::hasColumn($tableName, 'referral_source_id')) {
            Schema::table($tableName, function (Blueprint $table) use ($tableName) {
                if ($this->foreignKeyExists($tableName, 'referral_source_id')) {
                    $table->dropForeign(['referral_source_id']);
                }

                $table->dropColumn('referral_source_id');
            });
        }

        if (Schema::hasColumn($tableName, 'referral_source_type')) {
            Schema::table($tableName, function (Blueprint $table) {
                $table->dropColumn('referral_source_type');
            });
        }
    }

    private function foreignKeyExists(string $tableName, string $columnName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            return DB::table('information_schema.KEY_COLUMN_USAGE')
                ->where('TABLE_SCHEMA', DB::raw('DATABASE()'))
                ->where('TABLE_NAME', $tableName)
                ->where('COLUMN_NAME', $columnName)
                ->whereNotNull('REFERENCED_TABLE_NAME')
                ->exists();
        }

        return false;
    }
};
