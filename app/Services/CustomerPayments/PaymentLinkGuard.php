<?php

namespace App\Services\CustomerPayments;

use App\Models\CustomerPaymentLink;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PaymentLinkGuard
{
    private array $receiving = [];

    public function receiving(int $invoiceId, callable $callback): mixed
    {
        $this->receiving[$invoiceId] = ($this->receiving[$invoiceId] ?? 0) + 1;
        try {
            return $callback();
        } finally {
            if (--$this->receiving[$invoiceId] === 0) {
                unset($this->receiving[$invoiceId]);
            }
        }
    }

    public function assertEditable(int $organizationId, ?int $invoiceId, ?int $rentalId = null): void
    {
        if ($invoiceId && isset($this->receiving[$invoiceId])) {
            return;
        }
        if ((! $invoiceId && ! $rentalId) || ! Schema::hasTable('customer_payment_links')) {
            return;
        }
        $query = CustomerPaymentLink::where('organization_id', $organizationId)->open();
        $query->where(function ($q) use ($invoiceId, $rentalId) {
            $q->where('invoice_id', $invoiceId ?: -1);
            if ($rentalId) {
                $q->orWhereHas('invoice', fn ($i) => $i->where('rental_id', $rentalId));
            }
        });
        // Current read is required after waiting for an invoice lock under InnoDB.
        if ($query->orderBy('id')->lockForUpdate()->first(['customer_payment_links.id'])) {
            throw ValidationException::withMessages(['payment_link' => 'Cancel or reconcile the active payment link before editing financial details or recording another payment. Open the invoice → Payment link.']);
        }
    }
}
