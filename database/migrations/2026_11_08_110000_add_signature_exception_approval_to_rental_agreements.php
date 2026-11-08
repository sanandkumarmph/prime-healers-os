<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('rental_agreements', function (Blueprint $table) {
            $table->text('exception_approval')->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Signature exception approvals are audit history. Preserve this additive column during code rollback.');
    }
};
