<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            if (!Schema::hasColumn('organizations', 'bank_account_name')) {
                $table->string('bank_account_name')->nullable()->after('gst_number');
            }

            if (!Schema::hasColumn('organizations', 'bank_account_number')) {
                $table->string('bank_account_number')->nullable()->after('bank_account_name');
            }

            if (!Schema::hasColumn('organizations', 'bank_ifsc')) {
                $table->string('bank_ifsc')->nullable()->after('bank_account_number');
            }

            if (!Schema::hasColumn('organizations', 'bank_name')) {
                $table->string('bank_name')->nullable()->after('bank_ifsc');
            }

            if (!Schema::hasColumn('organizations', 'bank_branch')) {
                $table->string('bank_branch')->nullable()->after('bank_name');
            }

            if (!Schema::hasColumn('organizations', 'upi_id')) {
                $table->string('upi_id')->nullable()->after('bank_branch');
            }

            if (!Schema::hasColumn('organizations', 'payment_qr_code')) {
                $table->string('payment_qr_code')->nullable()->after('upi_id');
            }

            if (!Schema::hasColumn('organizations', 'digital_signature')) {
                $table->string('digital_signature')->nullable()->after('payment_qr_code');
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive.
    }
};
