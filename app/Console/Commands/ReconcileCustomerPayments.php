<?php

namespace App\Console\Commands;

use App\Models\CustomerPaymentLink;
use App\Services\CustomerPayments\CustomerPaymentLinks;
use App\Services\CustomerPayments\UatPaymentPosting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class ReconcileCustomerPayments extends Command
{
    protected $signature = 'customer-payments:reconcile {--limit=25 : Maximum gateway links checked per run}';

    protected $description = 'Recover delayed payment webhooks using authenticated gateway checks.';

    public function handle(CustomerPaymentLinks $service, UatPaymentPosting $uat): int
    {
        if (! Schema::hasTable('customer_payment_links')) {
            return self::SUCCESS;
        }
        $postTest = $uat->enabled();
        $links = CustomerPaymentLink::query()->with('account')
            ->where(function ($query) use ($postTest) {
                $query->open();
                if ($postTest) {
                    $query->orWhere(fn ($q) => $q->where('status', 'test_paid')
                        ->whereHas('receipts', fn ($r) => $r->where('status', 'test')->whereNull('payment_id')));
                }
            })
            // Never turn a financial review into an automatic retry.
            ->whereDoesntHave('receipts', fn ($q) => $q->where('status', 'needs_review'))
            ->where('created_at', '<=', now()->subSeconds(30))
            ->where(fn ($q) => $q->whereNull('checked_at')->orWhere('checked_at', '<=', now()->subMinute()))
            ->orderBy('checked_at')->orderBy('id')->limit(max(1, min(100, (int) $this->option('limit'))))->get();
        $checked = 0;
        $failed = 0;
        foreach ($links as $link) {
            try {
                $service->refresh($link);
                $checked++;
            } catch (ValidationException $e) {
                $failed++;
                // Gateway/validation messages and credentials are deliberately excluded.
                Log::warning('Customer payment reconciliation deferred.', ['link_id' => $link->id]);
            } finally {
                // Fair retry order even for network errors or unknown creation responses.
                CustomerPaymentLink::whereKey($link->id)->update(['checked_at' => now()]);
            }
        }
        $this->info("Payment links checked: {$checked}; deferred: {$failed}.");

        return self::SUCCESS;
    }
}
