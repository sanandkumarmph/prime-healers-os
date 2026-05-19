<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'gst_registered')) {
                $table->boolean('gst_registered')->default(false)->after('email');
            }

            if (! Schema::hasColumn('customers', 'legal_name')) {
                $table->string('legal_name')->nullable()->after('gst_number');
            }

            if (! Schema::hasColumn('customers', 'billing_address')) {
                $table->text('billing_address')->nullable()->after('address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            foreach (['billing_address', 'legal_name', 'gst_registered'] as $column) {
                if (Schema::hasColumn('customers', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
