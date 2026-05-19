<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            if (! Schema::hasColumn('deliveries', 'cancellation_reason')) {
                $table->string('cancellation_reason')->nullable()->after('notes');
            }

            if (! Schema::hasColumn('deliveries', 'cancellation_notes')) {
                $table->text('cancellation_notes')->nullable()->after('cancellation_reason');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $drops = [];

            if (Schema::hasColumn('deliveries', 'cancellation_notes')) {
                $drops[] = 'cancellation_notes';
            }

            if (Schema::hasColumn('deliveries', 'cancellation_reason')) {
                $drops[] = 'cancellation_reason';
            }

            if ($drops !== []) {
                $table->dropColumn($drops);
            }
        });
    }
};
