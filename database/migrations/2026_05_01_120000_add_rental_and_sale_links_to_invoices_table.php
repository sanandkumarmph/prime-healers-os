<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (!Schema::hasColumn('invoices', 'rental_id')) {
                $table->foreignId('rental_id')->nullable()->after('customer_id')->constrained()->nullOnDelete();
            }

            if (!Schema::hasColumn('invoices', 'sale_id')) {
                $table->foreignId('sale_id')->nullable()->after('rental_id')->constrained()->nullOnDelete();
            }
        });

        $this->backfillLinks();
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (Schema::hasColumn('invoices', 'sale_id')) {
                $table->dropConstrainedForeignId('sale_id');
            }

            if (Schema::hasColumn('invoices', 'rental_id')) {
                $table->dropConstrainedForeignId('rental_id');
            }
        });
    }

    private function backfillLinks(): void
    {
        if (!Schema::hasTable('invoice_items') || !Schema::hasTable('invoices')) {
            return;
        }

        $renewalInvoiceIds = Schema::hasTable('rental_renewals')
            ? DB::table('rental_renewals')->whereNotNull('invoice_id')->pluck('invoice_id')->map(fn ($id) => (int) $id)->all()
            : [];

        $invoiceRows = DB::table('invoice_items')
            ->selectRaw('invoice_id')
            ->selectRaw("COUNT(DISTINCT CASE WHEN source_type = 'rental' AND source_id IS NOT NULL THEN source_id END) as rental_match_count")
            ->selectRaw("MIN(CASE WHEN source_type = 'rental' AND source_id IS NOT NULL THEN source_id END) as matched_rental_id")
            ->selectRaw("COUNT(DISTINCT CASE WHEN source_type = 'sale' AND source_id IS NOT NULL THEN source_id END) as sale_match_count")
            ->selectRaw("MIN(CASE WHEN source_type = 'sale' AND source_id IS NOT NULL THEN source_id END) as matched_sale_id")
            ->groupBy('invoice_id')
            ->get();

        foreach ($invoiceRows as $row) {
            $payload = [];
            $invoiceId = (int) $row->invoice_id;

            if ((int) $row->rental_match_count === 1 && !in_array($invoiceId, $renewalInvoiceIds, true)) {
                $payload['rental_id'] = (int) $row->matched_rental_id;
            }

            if ((int) $row->sale_match_count === 1) {
                $payload['sale_id'] = (int) $row->matched_sale_id;
            }

            if ($payload === []) {
                continue;
            }

            DB::table('invoices')
                ->where('id', $invoiceId)
                ->where(function ($query) use ($payload) {
                    if (array_key_exists('rental_id', $payload)) {
                        $query->whereNull('rental_id');
                    }

                    if (array_key_exists('sale_id', $payload)) {
                        $method = array_key_exists('rental_id', $payload) ? 'orWhereNull' : 'whereNull';
                        $query->{$method}('sale_id');
                    }
                })
                ->update($payload);
        }
    }
};
