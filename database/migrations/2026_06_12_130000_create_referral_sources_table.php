<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('referral_sources')) {
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

        Schema::table('rentals', function (Blueprint $table) {
            if (!Schema::hasColumn('rentals', 'referral_source_id')) {
                $table->foreignId('referral_source_id')->nullable()->after('referral_source_type')->constrained('referral_sources')->nullOnDelete();
            }
        });

        Schema::table('sales', function (Blueprint $table) {
            if (!Schema::hasColumn('sales', 'referral_source_id')) {
                $table->foreignId('referral_source_id')->nullable()->after('referral_source_type')->constrained('referral_sources')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('sales', function (Blueprint $table) {
            if (Schema::hasColumn('sales', 'referral_source_id')) {
                $table->dropConstrainedForeignId('referral_source_id');
            }
        });

        Schema::table('rentals', function (Blueprint $table) {
            if (Schema::hasColumn('rentals', 'referral_source_id')) {
                $table->dropConstrainedForeignId('referral_source_id');
            }
        });

        Schema::dropIfExists('referral_sources');
    }
};
