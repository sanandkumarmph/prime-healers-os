<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('enquiries', function (Blueprint $table) {
            $table->string('intent', 12)->nullable();
        });
        Schema::table('quotations', function (Blueprint $table) {
            // Finalising an existing quote did not prove that a message was sent.
            // Leave historical delivery records unknown; do not invent a backfill.
            $table->timestamp('shared_at')->nullable();
            $table->string('shared_channel', 20)->nullable();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('enquiries')->whereNotNull('intent')->exists()
            || \Illuminate\Support\Facades\DB::table('quotations')->whereNotNull('shared_at')->exists()) {
            throw new RuntimeException('Guided sales history exists. Preserve its recorded intent and sharing history.');
        }
        Schema::table('quotations', fn (Blueprint $table) => $table->dropColumn(['shared_at', 'shared_channel']));
        Schema::table('enquiries', fn (Blueprint $table) => $table->dropColumn('intent'));
    }
};
