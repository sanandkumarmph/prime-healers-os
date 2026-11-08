<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->string('salutation', 12)->nullable();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Enquiry salutations may contain saved customer information. Preserve this additive column during code rollback.');
    }
};
