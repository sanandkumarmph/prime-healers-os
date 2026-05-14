<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_items')) {
            Schema::table('rental_items', function (Blueprint $table) {
                if (!Schema::hasColumn('rental_items', 'gst_rate')) {
                    $table->decimal('gst_rate', 5, 2)->default(0)->after('unit_rental_amount');
                }

                if (!Schema::hasColumn('rental_items', 'gst_mode')) {
                    $table->string('gst_mode', 20)->nullable()->after('gst_rate');
                }

                if (!Schema::hasColumn('rental_items', 'tax_type')) {
                    $table->string('tax_type', 20)->nullable()->after('gst_mode');
                }

                if (!Schema::hasColumn('rental_items', 'taxable_amount')) {
                    $table->decimal('taxable_amount', 12, 2)->default(0)->after('tax_type');
                }

                if (!Schema::hasColumn('rental_items', 'cgst_amount')) {
                    $table->decimal('cgst_amount', 12, 2)->default(0)->after('taxable_amount');
                }

                if (!Schema::hasColumn('rental_items', 'sgst_amount')) {
                    $table->decimal('sgst_amount', 12, 2)->default(0)->after('cgst_amount');
                }

                if (!Schema::hasColumn('rental_items', 'igst_amount')) {
                    $table->decimal('igst_amount', 12, 2)->default(0)->after('sgst_amount');
                }
            });
        }

        if (Schema::hasTable('rental_sale_items')) {
            Schema::table('rental_sale_items', function (Blueprint $table) {
                if (!Schema::hasColumn('rental_sale_items', 'gst_rate')) {
                    $table->decimal('gst_rate', 5, 2)->default(0)->after('unit_price');
                }

                if (!Schema::hasColumn('rental_sale_items', 'gst_mode')) {
                    $table->string('gst_mode', 20)->nullable()->after('gst_rate');
                }

                if (!Schema::hasColumn('rental_sale_items', 'tax_type')) {
                    $table->string('tax_type', 20)->nullable()->after('gst_mode');
                }

                if (!Schema::hasColumn('rental_sale_items', 'taxable_amount')) {
                    $table->decimal('taxable_amount', 12, 2)->default(0)->after('tax_type');
                }

                if (!Schema::hasColumn('rental_sale_items', 'cgst_amount')) {
                    $table->decimal('cgst_amount', 12, 2)->default(0)->after('taxable_amount');
                }

                if (!Schema::hasColumn('rental_sale_items', 'sgst_amount')) {
                    $table->decimal('sgst_amount', 12, 2)->default(0)->after('cgst_amount');
                }

                if (!Schema::hasColumn('rental_sale_items', 'igst_amount')) {
                    $table->decimal('igst_amount', 12, 2)->default(0)->after('sgst_amount');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('rental_sale_items')) {
            Schema::table('rental_sale_items', function (Blueprint $table) {
                foreach ([
                    'igst_amount',
                    'sgst_amount',
                    'cgst_amount',
                    'taxable_amount',
                    'tax_type',
                    'gst_mode',
                    'gst_rate',
                ] as $column) {
                    if (Schema::hasColumn('rental_sale_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if (Schema::hasTable('rental_items')) {
            Schema::table('rental_items', function (Blueprint $table) {
                foreach ([
                    'igst_amount',
                    'sgst_amount',
                    'cgst_amount',
                    'taxable_amount',
                    'tax_type',
                    'gst_mode',
                    'gst_rate',
                ] as $column) {
                    if (Schema::hasColumn('rental_items', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }
    }
};
