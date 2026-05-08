<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('rental_renewals')) {
            return;
        }

        Schema::table('rental_renewals', function (Blueprint $table) {
            if (!Schema::hasColumn('rental_renewals', 'invoice_id')) {
                $table->unsignedBigInteger('invoice_id')->nullable()->after('payment_id');
                $table->index('invoice_id');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('rental_renewals')) {
            return;
        }

        Schema::table('rental_renewals', function (Blueprint $table) {
            if (Schema::hasColumn('rental_renewals', 'invoice_id')) {
                $table->dropIndex(['invoice_id']);
                $table->dropColumn('invoice_id');
            }
        });
    }
};
