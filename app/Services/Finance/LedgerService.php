<?php

namespace App\Services\Finance;

use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class LedgerService
{
    public const PERIODS = [
        'today' => 'Today',
        'this_week' => 'This Week',
        'last_7_days' => 'Last 7 Days',
        'this_fortnight' => 'This Fortnight',
        'last_15_days' => 'Last 15 Days',
        'this_month' => 'This Month',
        'last_30_days' => 'Last 30 Days',
        'financial_year' => 'Financial Year',
        'custom' => 'Custom Range',
    ];

    public function filtersFromRequest(Request $request): array
    {
        $period = $request->query('period', 'this_month');
        $period = array_key_exists($period, self::PERIODS) ? $period : 'this_month';
        [$fromDate, $toDate] = $this->resolveDateRange($period, $request->query('from_date'), $request->query('to_date'));

        return [
            'period' => $period,
            'from_date' => $fromDate,
            'to_date' => $toDate,
            'entity_type' => $request->query('entity_type', ''),
            'customer_id' => $this->nullableInt($request->query('customer_id')),
            'business_partner_id' => $this->nullableInt($request->query('business_partner_id')),
            'rental_id' => $this->nullableInt($request->query('rental_id')),
            'sale_id' => $this->nullableInt($request->query('sale_id')),
            'invoice_id' => $this->nullableInt($request->query('invoice_id')),
            'payment_status' => trim((string) $request->query('payment_status', '')),
            'outstanding_only' => $request->boolean('outstanding_only'),
            'city' => trim((string) $request->query('city', '')),
            'search' => trim((string) $request->query('search', '')),
        ];
    }

    public function statement(int $organizationId, array $filters): array
    {
        $entries = $this->entries($organizationId, $filters);
        $fromDate = $filters['from_date'];

        $openingBalance = $entries
            ->filter(fn (array $entry) => $entry['entry_date']->lt($fromDate))
            ->sum(fn (array $entry) => $entry['debit'] - $entry['credit']);

        $periodEntries = $entries
            ->filter(fn (array $entry) => $entry['entry_date']->betweenIncluded($fromDate, $filters['to_date']))
            ->values();

        $runningBalance = $openingBalance;
        $periodEntries = $periodEntries->map(function (array $entry) use (&$runningBalance): array {
            $runningBalance += $entry['debit'] - $entry['credit'];
            $entry['running_balance'] = $runningBalance;

            return $entry;
        });

        $totalDebit = (float) $periodEntries->sum('debit');
        $totalCredit = (float) $periodEntries->sum('credit');
        $closingBalance = (float) ($openingBalance + $totalDebit - $totalCredit);
        $context = $this->context($organizationId, $filters);
        $entryCount = $periodEntries->count();

        $summary = [
            'opening_balance' => (float) $openingBalance,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'closing_balance' => $closingBalance,
            'outstanding' => max($closingBalance, 0),
            'period_activity' => $totalDebit + $totalCredit,
            'entry_count' => $entryCount,
            'overdue_amount' => (float) $periodEntries
                ->filter(fn (array $entry) => $entry['type'] === 'invoice' && ($entry['is_overdue'] ?? false))
                ->sum(fn (array $entry) => (float) ($entry['balance_amount'] ?? 0)),
        ];

        return [
            'filters' => $filters,
            'entries' => $periodEntries,
            'summary' => $summary,
            'opening_balance' => (float) $openingBalance,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'closing_balance' => $closingBalance,
            'date_label' => $fromDate->format('d M Y') . ' - ' . $filters['to_date']->format('d M Y'),
            'context_label' => $context['label'],
            'context' => $context,
            'recipient' => $this->recipient($organizationId, $filters),
            'entry_count_label' => $entryCount === 1 ? '1 entry' : $entryCount . ' entries',
            'related_records' => $this->relatedRecords($organizationId, $filters),
        ];
    }

    public function filterOptions(int $organizationId): array
    {
        return [
            'customers' => Customer::query()
                ->where('organization_id', $organizationId)
                ->orderBy('name')
                ->get(['id', 'name', 'phone', 'city']),
            'businessPartners' => BusinessPartner::query()
                ->where('organization_id', $organizationId)
                ->orderBy('business_name')
                ->get(['id', 'business_name', 'phone', 'city']),
            'rentals' => Rental::query()
                ->where('organization_id', $organizationId)
                ->latest('id')
                ->limit(100)
                ->get(['id', 'customer_id', 'business_partner_id', 'start_date', 'end_date']),
            'sales' => Sale::query()
                ->where('organization_id', $organizationId)
                ->latest('id')
                ->limit(100)
                ->get(['id', 'customer_id', 'business_partner_id', 'sale_date']),
            'invoices' => Invoice::query()
                ->where('organization_id', $organizationId)
                ->latest('invoice_date')
                ->latest('id')
                ->limit(150)
                ->get(['id', 'invoice_number', 'customer_id', 'invoice_date', 'total_amount']),
            'cities' => Customer::query()
                ->where('organization_id', $organizationId)
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->distinct()
                ->orderBy('city')
                ->pluck('city'),
        ];
    }

    private function entries(int $organizationId, array $filters): Collection
    {
        $invoiceEntries = $this->invoiceQuery($organizationId, $filters)
            ->get()
            ->map(fn (Invoice $invoice) => $this->invoiceEntry($invoice));

        $paymentEntries = $this->paymentQuery($organizationId, $filters)
            ->get()
            ->map(fn (Payment $payment) => $this->paymentEntry($payment));

        return $invoiceEntries
            ->merge($paymentEntries)
            ->sortBy([
                ['entry_date', 'asc'],
                ['created_at', 'asc'],
                ['sort_id', 'asc'],
            ])
            ->values();
    }

    private function invoiceQuery(int $organizationId, array $filters): Builder
    {
        $query = Invoice::query()
            ->with(['customer', 'rental.businessPartner', 'sale.businessPartner'])
            ->where('organization_id', $organizationId);

        $query->whereDate('invoice_date', '<=', $filters['to_date']->toDateString());

        $this->applyCommonInvoiceFilters($query, $filters);

        return $query;
    }

    private function paymentQuery(int $organizationId, array $filters): Builder
    {
        $query = Payment::query()
            ->with(['customer', 'invoice.customer', 'invoice.rental.businessPartner', 'invoice.sale.businessPartner', 'rental.businessPartner'])
            ->where('organization_id', $organizationId);

        $query->whereDate('payment_date', '<=', $filters['to_date']->toDateString());

        if ($filters['customer_id']) {
            $query->where(function (Builder $paymentQuery) use ($filters) {
                $paymentQuery
                    ->where('customer_id', $filters['customer_id'])
                    ->orWhereHas('invoice', fn (Builder $invoiceQuery) => $invoiceQuery->where('customer_id', $filters['customer_id']));
            });
        }

        if ($filters['business_partner_id']) {
            $query->where(function (Builder $paymentQuery) use ($filters) {
                $paymentQuery
                    ->whereHas('invoice.rental', fn (Builder $rentalQuery) => $rentalQuery->where('business_partner_id', $filters['business_partner_id']))
                    ->orWhereHas('invoice.sale', fn (Builder $saleQuery) => $saleQuery->where('business_partner_id', $filters['business_partner_id']))
                    ->orWhereHas('rental', fn (Builder $rentalQuery) => $rentalQuery->where('business_partner_id', $filters['business_partner_id']));
            });
        }

        if ($filters['rental_id']) {
            $query->where(function (Builder $paymentQuery) use ($filters) {
                $paymentQuery
                    ->where('rental_id', $filters['rental_id'])
                    ->orWhereHas('invoice', fn (Builder $invoiceQuery) => $invoiceQuery->where('rental_id', $filters['rental_id']));
            });
        }

        if ($filters['sale_id']) {
            $query->whereHas('invoice', fn (Builder $invoiceQuery) => $invoiceQuery->where('sale_id', $filters['sale_id']));
        }

        if ($filters['invoice_id']) {
            $query->where('invoice_id', $filters['invoice_id']);
        }

        if ($filters['city'] !== '') {
            $query->where(function (Builder $paymentQuery) use ($filters) {
                $paymentQuery
                    ->whereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('city', 'like', '%' . $filters['city'] . '%'))
                    ->orWhereHas('invoice', fn (Builder $invoiceQuery) => $invoiceQuery->where('bill_to_city', 'like', '%' . $filters['city'] . '%'));
            });
        }

        if ($filters['search'] !== '') {
            $query->where(function (Builder $paymentQuery) use ($filters) {
                $search = $filters['search'];
                $paymentQuery
                    ->where('notes', 'like', '%' . $search . '%')
                    ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('name', 'like', '%' . $search . '%')->orWhere('phone', 'like', '%' . $search . '%'))
                    ->orWhereHas('invoice', fn (Builder $invoiceQuery) => $invoiceQuery->where('invoice_number', 'like', '%' . $search . '%')->orWhere('reference_number', 'like', '%' . $search . '%'));
            });
        }

        return $query;
    }

    private function applyCommonInvoiceFilters(Builder $query, array $filters): void
    {
        if ($filters['customer_id']) {
            $query->where('customer_id', $filters['customer_id']);
        }

        if ($filters['business_partner_id']) {
            $query->where(function (Builder $invoiceQuery) use ($filters) {
                $invoiceQuery
                    ->whereHas('rental', fn (Builder $rentalQuery) => $rentalQuery->where('business_partner_id', $filters['business_partner_id']))
                    ->orWhereHas('sale', fn (Builder $saleQuery) => $saleQuery->where('business_partner_id', $filters['business_partner_id']));
            });
        }

        if ($filters['rental_id']) {
            $query->where(function (Builder $invoiceQuery) use ($filters) {
                $invoiceQuery
                    ->where('rental_id', $filters['rental_id'])
                    ->orWhereHas('items', fn (Builder $itemQuery) => $itemQuery->where('source_type', 'rental')->where('source_id', $filters['rental_id']));
            });
        }

        if ($filters['sale_id']) {
            $query->where(function (Builder $invoiceQuery) use ($filters) {
                $invoiceQuery
                    ->where('sale_id', $filters['sale_id'])
                    ->orWhereHas('items', fn (Builder $itemQuery) => $itemQuery->where('source_type', 'sale')->where('source_id', $filters['sale_id']));
            });
        }

        if ($filters['invoice_id']) {
            $query->where('id', $filters['invoice_id']);
        }

        if ($filters['payment_status'] !== '') {
            $status = $filters['payment_status'];
            $query->where(fn (Builder $statusQuery) => $statusQuery->where('payment_status', $status)->orWhere('status', $status));
        }

        if ($filters['outstanding_only']) {
            $query->where('balance_amount', '>', 0);
        }

        if ($filters['city'] !== '') {
            $query->where(fn (Builder $cityQuery) => $cityQuery
                ->where('bill_to_city', 'like', '%' . $filters['city'] . '%')
                ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('city', 'like', '%' . $filters['city'] . '%')));
        }

        if ($filters['search'] !== '') {
            $search = $filters['search'];
            $query->where(function (Builder $invoiceQuery) use ($search) {
                $invoiceQuery
                    ->where('invoice_number', 'like', '%' . $search . '%')
                    ->orWhere('reference_number', 'like', '%' . $search . '%')
                    ->orWhere('bill_to_name', 'like', '%' . $search . '%')
                    ->orWhere('bill_to_phone', 'like', '%' . $search . '%')
                    ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('name', 'like', '%' . $search . '%')->orWhere('phone', 'like', '%' . $search . '%'));
            });
        }
    }

    private function invoiceEntry(Invoice $invoice): array
    {
        $status = $invoice->payment_status ?: $invoice->status;
        $balance = (float) ($invoice->balance_amount ?? 0);

        return [
            'sort_id' => $invoice->id,
            'entry_date' => $this->dateValue($invoice->invoice_date),
            'created_at' => $invoice->created_at ?? $invoice->invoice_date,
            'type' => 'invoice',
            'reference' => $invoice->invoice_number ?: 'Invoice #' . $invoice->id,
            'particulars' => 'Invoice generated' . ($invoice->bill_to_name ? ' - ' . $invoice->bill_to_name : ''),
            'debit' => (float) ($invoice->total_amount ?? 0),
            'credit' => 0.0,
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'status_tone' => $this->statusTone($status),
            'due_date' => $invoice->due_date ? $this->dateValue($invoice->due_date) : null,
            'balance_amount' => $balance,
            'is_overdue' => $balance > 0 && $invoice->due_date && $this->dateValue($invoice->due_date)->lt(now()->startOfDay()),
            'source_label' => 'Invoice',
            'source_url' => route('invoices.show', $invoice->id),
        ];
    }

    private function paymentEntry(Payment $payment): array
    {
        $invoice = $payment->invoice;

        return [
            'sort_id' => $payment->id,
            'entry_date' => $this->dateValue($payment->payment_date),
            'created_at' => $payment->created_at ?? $payment->payment_date,
            'type' => 'payment',
            'reference' => $invoice?->invoice_number ? 'Payment for ' . $invoice->invoice_number : 'Payment #' . $payment->id,
            'particulars' => trim('Payment received' . ($payment->customer?->name ? ' - ' . $payment->customer->name : '') . ($payment->payment_method ? ' via ' . $payment->paymentMethodLabel() : '')),
            'debit' => 0.0,
            'credit' => (float) ($payment->amount ?? 0),
            'status' => 'received',
            'status_label' => 'Received',
            'status_tone' => 'success',
            'source_label' => 'Payment',
            'source_url' => $invoice ? route('invoices.show', $invoice->id) . '#invoice-payment-history' : ($payment->rental_id ? route('rentals.show', $payment->rental_id) . '#rental-billing-actions' : route('invoices.index')),
        ];
    }

    private function context(int $organizationId, array $filters): array
    {
        if ($filters['invoice_id']) {
            $invoice = Invoice::query()
                ->with(['customer', 'rental.businessPartner', 'sale.businessPartner'])
                ->where('organization_id', $organizationId)
                ->find($filters['invoice_id']);

            return [
                'type' => 'Invoice',
                'label' => $invoice ? 'Invoice ' . ($invoice->invoice_number ?: '#' . $invoice->id) : 'Invoice Statement',
                'name' => $invoice?->bill_to_name ?: $invoice?->customer?->name,
                'meta' => array_filter([
                    $invoice?->bill_to_phone,
                    $invoice?->bill_to_city,
                    $invoice?->payment_status ? $this->statusLabel($invoice->payment_status) : null,
                ]),
                'url' => $invoice ? route('invoices.show', $invoice->id) : null,
            ];
        }

        if ($filters['rental_id']) {
            return [
                'type' => 'Rental',
                'label' => 'Rental #' . $filters['rental_id'],
                'name' => 'Rental #' . $filters['rental_id'],
                'meta' => [],
                'url' => route('rentals.show', $filters['rental_id']),
            ];
        }

        if ($filters['sale_id']) {
            return [
                'type' => 'Sale',
                'label' => 'Sale #' . $filters['sale_id'],
                'name' => 'Sale #' . $filters['sale_id'],
                'meta' => [],
                'url' => route('sales.show', $filters['sale_id']),
            ];
        }

        if ($filters['customer_id']) {
            $customer = Customer::query()->where('organization_id', $organizationId)->find($filters['customer_id']);

            return [
                'type' => 'Customer',
                'label' => $customer ? $customer->name : 'Customer Statement',
                'name' => $customer?->name,
                'meta' => array_filter([$customer?->phone, $customer?->city, $customer?->email]),
                'url' => $customer ? route('customers.show', $customer->id) : null,
            ];
        }

        if ($filters['business_partner_id']) {
            $partner = BusinessPartner::query()->where('organization_id', $organizationId)->find($filters['business_partner_id']);

            return [
                'type' => 'Business Partner',
                'label' => $partner ? $partner->displayName() : 'Business Partner Statement',
                'name' => $partner?->displayName(),
                'meta' => array_filter([$partner?->contact_person, $partner?->phone, $partner?->city]),
                'url' => $partner ? route('business-partners.show', $partner->id) : null,
            ];
        }

        return [
            'type' => 'Global',
            'label' => 'Global Ledger',
            'name' => 'All finance records',
            'meta' => ['Invoices and payments'],
            'url' => null,
        ];
    }

    private function recipient(int $organizationId, array $filters): array
    {
        if ($filters['invoice_id']) {
            $invoice = Invoice::query()
                ->with(['customer'])
                ->where('organization_id', $organizationId)
                ->find($filters['invoice_id']);

            return $this->recipientData(
                $invoice?->bill_to_name ?: $invoice?->customer?->displayName(),
                $invoice?->bill_to_phone ?: $invoice?->customer?->phone,
                $invoice?->bill_to_email ?: $invoice?->customer?->email,
                $invoice?->bill_to_address ?: $invoice?->customer?->address,
                $invoice?->bill_to_city ?: $invoice?->customer?->city,
                $invoice?->bill_to_state ?: $invoice?->customer?->state,
                $invoice?->bill_to_pincode ?: $invoice?->customer?->pincode,
                $invoice?->bill_to_gstin ?: $invoice?->customer?->gst_number
            );
        }

        if ($filters['rental_id']) {
            $rental = Rental::query()
                ->with(['customer', 'businessPartner', 'partnerClient'])
                ->where('organization_id', $organizationId)
                ->find($filters['rental_id']);

            return $this->recipientData(
                $rental?->billingContactName(),
                $rental?->billingContactPhone(),
                $rental?->billingContactEmail(),
                $rental?->billingContactAddress(),
                $rental?->billingContactCity(),
                $rental?->billingContactState(),
                $rental?->billingContactPincode(),
                $rental?->usesBusinessPartnerFlow() ? $rental?->businessPartner?->gstin : $rental?->customer?->gst_number
            );
        }

        if ($filters['sale_id']) {
            $sale = Sale::query()
                ->with(['customer', 'businessPartner', 'partnerClient'])
                ->where('organization_id', $organizationId)
                ->find($filters['sale_id']);

            return $this->recipientData(
                $sale?->billingContactName(),
                $sale?->billingContactPhone(),
                $sale?->billingContactEmail(),
                $sale?->billingContactAddress(),
                $sale?->billingContactCity(),
                $sale?->billingContactState(),
                $sale?->billingContactPincode(),
                $sale?->usesBusinessPartnerFlow() ? $sale?->businessPartner?->gstin : $sale?->customer?->gst_number
            );
        }

        if ($filters['customer_id']) {
            $customer = Customer::query()
                ->where('organization_id', $organizationId)
                ->find($filters['customer_id']);

            return $this->recipientData(
                $customer?->displayName(),
                $customer?->phone,
                $customer?->email,
                $customer?->billing_address ?: $customer?->address,
                $customer?->city,
                $customer?->state,
                $customer?->pincode,
                $customer?->gst_number
            );
        }

        if ($filters['business_partner_id']) {
            $partner = BusinessPartner::query()
                ->where('organization_id', $organizationId)
                ->find($filters['business_partner_id']);

            return $this->recipientData(
                $partner?->billingDisplayName(),
                $partner?->phone,
                $partner?->email,
                $partner?->billingAddressLine(),
                $partner?->billingCityValue(),
                $partner?->billingStateValue(),
                $partner?->billingPincodeValue(),
                $partner?->gstin
            );
        }

        return $this->recipientData('All finance records', null, null, 'Global ledger statement', null, null, null, null);
    }

    private function recipientData(?string $name, ?string $phone, ?string $email, ?string $address, ?string $city, ?string $state, ?string $pincode, ?string $gstin): array
    {
        $location = collect([$city, $state, $pincode])->filter()->join(', ');

        return [
            'name' => $name ?: 'Customer',
            'phone' => $phone,
            'email' => $email,
            'address' => $address,
            'location' => $location,
            'gstin' => $gstin,
            'lines' => array_values(array_filter([$address, $location])),
        ];
    }
    private function relatedRecords(int $organizationId, array $filters): array
    {
        $records = [];

        if ($filters['customer_id']) {
            $records[] = [
                'label' => 'Invoices',
                'count' => Invoice::query()->where('organization_id', $organizationId)->where('customer_id', $filters['customer_id'])->count(),
                'url' => route('invoices.index'),
            ];
            $records[] = [
                'label' => 'Rentals',
                'count' => Rental::query()->where('organization_id', $organizationId)->where('customer_id', $filters['customer_id'])->count(),
                'url' => route('rentals.index'),
            ];
            $records[] = [
                'label' => 'Sales',
                'count' => Sale::query()->where('organization_id', $organizationId)->where('customer_id', $filters['customer_id'])->count(),
                'url' => route('sales.index'),
            ];
        }

        if ($filters['business_partner_id']) {
            $records[] = [
                'label' => 'Rentals',
                'count' => Rental::query()->where('organization_id', $organizationId)->where('business_partner_id', $filters['business_partner_id'])->count(),
                'url' => route('rentals.index'),
            ];
            $records[] = [
                'label' => 'Sales',
                'count' => Sale::query()->where('organization_id', $organizationId)->where('business_partner_id', $filters['business_partner_id'])->count(),
                'url' => route('sales.index'),
            ];
        }

        if ($filters['invoice_id']) {
            $records[] = [
                'label' => 'Invoice',
                'count' => 1,
                'url' => route('invoices.show', $filters['invoice_id']),
            ];
        }

        return array_values(array_filter($records, fn (array $record) => $record['count'] > 0));
    }

    private function contextLabel(int $organizationId, array $filters): string
    {
        return $this->context($organizationId, $filters)['label'];
    }

    private function statusLabel(?string $status): string
    {
        $status = trim((string) $status);

        return $status === '' ? 'Open' : str($status)->replace(['_', '-'], ' ')->title()->toString();
    }

    private function statusTone(?string $status): string
    {
        return match (strtolower((string) $status)) {
            'paid', 'received', 'completed' => 'success',
            'partial', 'pending', 'open' => 'warning',
            'overdue', 'failed', 'cancelled' => 'danger',
            default => 'neutral',
        };
    }

    private function resolveDateRange(string $period, mixed $fromDate, mixed $toDate): array
    {
        $today = now()->startOfDay();

        return match ($period) {
            'today' => [$today->copy(), $today->copy()],
            'this_week' => [$today->copy()->startOfWeek(), $today->copy()->endOfWeek()->startOfDay()],
            'last_7_days' => [$today->copy()->subDays(6), $today->copy()],
            'this_fortnight' => [$today->copy()->day <= 15 ? $today->copy()->startOfMonth() : $today->copy()->startOfMonth()->addDays(15), $today->copy()],
            'last_15_days' => [$today->copy()->subDays(14), $today->copy()],
            'last_30_days' => [$today->copy()->subDays(29), $today->copy()],
            'financial_year' => [$today->month >= 4 ? Carbon::create($today->year, 4, 1) : Carbon::create($today->year - 1, 4, 1), $today->copy()],
            'custom' => [$this->dateValue($fromDate, $today->copy()->startOfMonth()), $this->dateValue($toDate, $today->copy())],
            default => [$today->copy()->startOfMonth(), $today->copy()],
        };
    }

    private function dateValue(mixed $value, ?Carbon $fallback = null): Carbon
    {
        if ($value instanceof Carbon) {
            return $value->copy()->startOfDay();
        }

        if ($value) {
            try {
                return Carbon::parse($value)->startOfDay();
            } catch (\Throwable) {
                return ($fallback ?? now())->copy()->startOfDay();
            }
        }

        return ($fallback ?? now())->copy()->startOfDay();
    }

    private function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
