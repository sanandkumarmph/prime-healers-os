<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->timestamp('returned_at')->nullable()->after('end_date');
            $table->string('return_condition')->nullable()->after('returned_at');
            $table->text('return_notes')->nullable()->after('return_condition');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropColumn(['returned_at', 'return_condition', 'return_notes']);
        });
    }
};