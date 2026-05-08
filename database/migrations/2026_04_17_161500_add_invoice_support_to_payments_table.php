<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (!Schema::hasColumn('payments', 'organization_id')) {
                $table->foreignId('organization_id')->nullable()->after('id')->constrained()->nullOnDelete();
            }

            if (!Schema::hasColumn('payments', 'customer_id')) {
                $table->foreignId('customer_id')->nullable()->after('organization_id')->constrained()->nullOnDelete();
            }

            if (!Schema::hasColumn('payments', 'invoice_id')) {
                $table->foreignId('invoice_id')->nullable()->after('rental_id')->constrained()->nullOnDelete();
            }
        });

        DB::table('payments')
            ->select('payments.id', 'rentals.organization_id', 'rentals.customer_id')
            ->leftJoin('rentals', 'rentals.id', '=', 'payments.rental_id')
            ->orderBy('payments.id')
            ->get()
            ->each(function ($payment) {
                $payload = [];

                if (!empty($payment->organization_id)) {
                    $payload['organization_id'] = $payment->organization_id;
                }

                if (!empty($payment->customer_id)) {
                    $payload['customer_id'] = $payment->customer_id;
                }

                if ($payload !== []) {
                    DB::table('payments')
                        ->where('id', $payment->id)
                        ->update($payload);
                }
            });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            if (Schema::hasColumn('payments', 'invoice_id')) {
                $table->dropConstrainedForeignId('invoice_id');
            }

            if (Schema::hasColumn('payments', 'customer_id')) {
                $table->dropConstrainedForeignId('customer_id');
            }

            if (Schema::hasColumn('payments', 'organization_id')) {
                $table->dropConstrainedForeignId('organization_id');
            }
        });
    }
};
