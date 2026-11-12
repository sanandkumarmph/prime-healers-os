<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('quotations', 'conversion_source')) {
            Schema::table('quotations', fn (Blueprint $table) => $table->string('conversion_source', 80)->nullable());
        }
        if (!Schema::hasColumn('quotations', 'converted_by_user_id')) {
            Schema::table('quotations', fn (Blueprint $table) => $table->unsignedBigInteger('converted_by_user_id')->nullable());
        }
    }

    public function down(): void
    {
        Schema::table('quotations', fn (Blueprint $table) => $table->dropColumn(['conversion_source', 'converted_by_user_id']));
    }
};
