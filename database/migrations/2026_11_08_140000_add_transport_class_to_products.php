<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('transport_class', 12)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve the additive product transport classification during code rollback.');
    }
};
