<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sale_items', function (Blueprint $table) {
            $table->string('hsn_code')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve saved sale HSN codes during code rollback.');
    }
};
