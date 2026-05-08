<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('assignment_type')->default('delivery_team')->after('type');
            $table->foreignId('assigned_user_id')->nullable()->after('assignment_type')->constrained('users')->nullOnDelete();

            $table->string('third_party_name')->nullable();
            $table->string('third_party_contact')->nullable();
            $table->string('third_party_phone')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->dropColumn('assignment_type');
            $table->dropConstrainedForeignId('assigned_user_id');
            $table->dropColumn('third_party_name');
            $table->dropColumn('third_party_contact');
            $table->dropColumn('third_party_phone');
        });
    }
};