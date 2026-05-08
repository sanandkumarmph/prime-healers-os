<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\Organization;
use App\Models\User;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained('organizations')->nullOnDelete();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained('organizations')->nullOnDelete();
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained('organizations')->nullOnDelete();
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained('organizations')->nullOnDelete();
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->foreignId('organization_id')->nullable()->after('id')->constrained('organizations')->nullOnDelete();
        });

        // Backfill current records to the first internal organization
        $firstInternalOrg = Organization::where('is_internal', true)->first();

        if ($firstInternalOrg) {
            \DB::table('customers')->update(['organization_id' => $firstInternalOrg->id]);
            \DB::table('products')->update(['organization_id' => $firstInternalOrg->id]);
            \DB::table('rentals')->update(['organization_id' => $firstInternalOrg->id]);
            \DB::table('sales')->update(['organization_id' => $firstInternalOrg->id]);
            \DB::table('deliveries')->update(['organization_id' => $firstInternalOrg->id]);
        }
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });

        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('organization_id');
        });
    }
};
