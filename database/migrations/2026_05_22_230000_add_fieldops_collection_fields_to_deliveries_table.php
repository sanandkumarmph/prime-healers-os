<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            if (!Schema::hasColumn('deliveries', 'collection_required')) {
                $table->boolean('collection_required')->default(false)->after('notes');
            }

            if (!Schema::hasColumn('deliveries', 'collection_amount_to_collect')) {
                $table->decimal('collection_amount_to_collect', 10, 2)->nullable()->after('collection_required');
            }

            if (!Schema::hasColumn('deliveries', 'collection_amount_collected')) {
                $table->decimal('collection_amount_collected', 10, 2)->nullable()->after('collection_amount_to_collect');
            }

            if (!Schema::hasColumn('deliveries', 'collection_payment_mode')) {
                $table->string('collection_payment_mode', 32)->nullable()->after('collection_amount_collected');
            }

            if (!Schema::hasColumn('deliveries', 'collection_transaction_reference')) {
                $table->string('collection_transaction_reference')->nullable()->after('collection_payment_mode');
            }

            if (!Schema::hasColumn('deliveries', 'collection_note')) {
                $table->text('collection_note')->nullable()->after('collection_transaction_reference');
            }

            if (!Schema::hasColumn('deliveries', 'collection_not_collected_reason')) {
                $table->string('collection_not_collected_reason', 64)->nullable()->after('collection_note');
            }
        });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $columns = array_filter([
                Schema::hasColumn('deliveries', 'collection_not_collected_reason') ? 'collection_not_collected_reason' : null,
                Schema::hasColumn('deliveries', 'collection_note') ? 'collection_note' : null,
                Schema::hasColumn('deliveries', 'collection_transaction_reference') ? 'collection_transaction_reference' : null,
                Schema::hasColumn('deliveries', 'collection_payment_mode') ? 'collection_payment_mode' : null,
                Schema::hasColumn('deliveries', 'collection_amount_collected') ? 'collection_amount_collected' : null,
                Schema::hasColumn('deliveries', 'collection_amount_to_collect') ? 'collection_amount_to_collect' : null,
                Schema::hasColumn('deliveries', 'collection_required') ? 'collection_required' : null,
            ]);

            if (!empty($columns)) {
                $table->dropColumn($columns);
            }
        });
    }
};
