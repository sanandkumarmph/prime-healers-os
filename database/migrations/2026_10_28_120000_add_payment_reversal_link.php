<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('reversal_of_payment_id')->nullable()->unique()
                ->constrained('payments')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        // Never discard correction links after any reversal has been recorded.
        if (\Illuminate\Support\Facades\DB::table('payments')->whereNotNull('reversal_of_payment_id')->exists()) {
            throw new \RuntimeException('Payment corrections exist. Preserve their links; restore application code without dropping this migration.');
        }
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['reversal_of_payment_id']);
            $table->dropUnique(['reversal_of_payment_id']);
            $table->dropColumn('reversal_of_payment_id');
        });
    }
};
