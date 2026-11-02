<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\Sale;
use App\Services\Finance\InvoiceLinkResolver;
use App\Services\Finance\PaymentSyncService;
use App\Services\NotificationCenterService;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    private function notifications(): NotificationCenterService
    {
        return app(NotificationCenterService::class);
    }

    private function orgId(): int
    {
        return (int) Auth::user()->organization_id;
    }

    private function paymentBaseQuery()
    {
        return Payment::query()
            ->with(['customer', 'invoice', 'rental.product'])
            ->forOrganization($this->orgId());
    }

    private function applyFilters($query, Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $fromDate = trim((string) $request->get('from_date', ''));
        $toDate = trim((string) $request->get('to_date', ''));
        $customerId = trim((string) $request->get('customer_id', ''));
        $paymentMethod = Payment::normalizeMethod($request->get('payment_method'));

        if ($search !== '') {
            $query->where(function ($paymentQuery) use ($search) {
                $paymentQuery
                    ->where('notes', 'like', '%' . $search . '%')
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery
                            ->where('name', 'like', '%' . $search . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('invoice', function ($invoiceQuery) use ($search) {
                        $invoiceQuery
                            ->where('invoice_number', 'like', '%' . $search . '%')
                            ->orWhere('reference_number', 'like', '%' . $search . '%');
                    });

                if (is_numeric($search)) {
                    $paymentQuery->orWhere('rental_id', (int) $search);
                }
            });
        }

        if ($customerId !== '') {
            $query->where('customer_id', (int) $customerId);
        }

        if ($paymentMethod !== null) {
            $query->where('payment_method', $paymentMethod);
        }

        if ($fromDate !== '' && $toDate !== '') {
            $query->whereBetween('payment_date', [$fromDate, $toDate]);
        } elseif ($fromDate !== '') {
            $query->whereDate('payment_date', '>=', $fromDate);
        } elseif ($toDate !== '') {
            $query->whereDate('payment_date', '<=', $toDate);
        }

        return $query;
    }

    private function streamPaymentsCsv($payments, string $filename)
    {
        return response()->streamDownload(function () use ($payments) {
            $output = fopen('php://output', 'w');

            \App\Support\SafeCsv::row($output, [
                'Payment Date',
                'Customer',
                'Invoice Ref',
                'Rental Ref',
                'Amount',
                'Mode',
                'Notes',
            ]);

            foreach ($payments as $payment) {
                \App\Support\SafeCsv::row($output, [
                    optional($payment->payment_date)->format('Y-m-d'),
                    $payment->customer->name ?? 'N/A',
                    $payment->invoice->invoice_number ?? '',
                    $payment->rental_id ?: '',
                    number_format((float) $payment->amount, 2, '.', ''),
                    $payment->paymentMethodLabel(),
                    $payment->notes,
                ]);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function validatePayment(Request $request): array
    {
        return $request->validate([
            'payment_date' => 'required|date',
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
        ]);
    }

    private function rentalInvoice(Rental $rental): ?Invoice
    {
        return app(InvoiceLinkResolver::class)->rentalInvoice(
            $rental,
            $this->orgId(),
            Schema::hasColumn('invoices', 'rental_id')
        );
    }

    private function saleInvoice(Sale $sale): ?Invoice
    {
        return app(InvoiceLinkResolver::class)->saleInvoice(
            $sale,
            $this->orgId(),
            Schema::hasColumn('invoices', 'sale_id')
        );
    }

    private function refreshInvoiceAfterPaymentDeletion(Invoice $invoice): void
    {
        app(PaymentSyncService::class)->refreshInvoiceAfterPaymentDeletion($this->orgId(), $invoice);
    }

    public function create(Rental $rental)
    {
        abort_unless((int) $rental->organization_id === $this->orgId(), 404);
        $this->authorize('createForRental', [Payment::class, $rental]);

        return view('payments.create', compact('rental'));
    }

    public function store(Request $request, Rental $rental)
    {
        abort_unless((int) $rental->organization_id === $this->orgId(), 404);
        $this->authorize('createForRental', [Payment::class, $rental]);

        $validated = $this->validatePayment($request);
        $invoice = $this->rentalInvoice($rental);
        $amount = (float) $validated['amount'];

        if ($invoice) {
            $invoice->syncFinancialStatus();
        }

        $payment = $invoice
            ? app(PaymentSyncService::class)->createInvoicePayment(
                $this->orgId(),
                $invoice,
                $validated,
                $rental->id,
                true,
                'Payment amount cannot exceed the rental invoice balance due.'
            )
            : Payment::create([
                'organization_id' => $this->orgId(),
                'customer_id' => $rental->customer_id,
                'rental_id' => $rental->id,
                'invoice_id' => null,
                'payment_date' => $validated['payment_date'],
                'amount' => $amount,
                'payment_method' => Payment::normalizeMethod($validated['payment_method'] ?? null),
                'notes' => $validated['notes'] ?? null,
            ]);

        if ($invoice) {
            $invoice->refresh();
        }

        ActivityLogger::log('payment.recorded', $payment, [
            'source' => $invoice ? 'rental_invoice' : 'rental',
            'amount' => $payment->amount,
            'payment_method' => $payment->payment_method,
            'invoice_id' => $invoice?->id,
            'invoice_number' => $invoice?->invoice_number,
        ], 'Rental payment recorded.');

        $payment->loadMissing(['customer', 'invoice']);
        $this->notifications()->notifyPaymentRecorded(
            $payment,
            $this->notifications()->organizationFinanceUsers($this->orgId())
        );

        return redirect()->route('rentals.show', $rental)->with('success', 'Payment added successfully.');
    }

    public function storeForInvoice(Request $request, Invoice $invoice)
    {
        abort_unless((int) $invoice->organization_id === $this->orgId(), 404);
        $this->authorize('createForInvoice', [Payment::class, $invoice]);

        $validated = $this->validatePayment($request);
        $payment = app(PaymentSyncService::class)->createInvoicePayment(
            $this->orgId(),
            $invoice,
            $validated,
            $invoice->linkedRentalId(),
            true
        );

        ActivityLogger::log('payment.recorded', $payment, [
            'source' => 'invoice',
            'invoice_number' => $invoice->invoice_number,
            'amount' => $payment->amount,
            'payment_method' => $payment->payment_method,
            'invoice_payment_status' => $invoice->payment_status,
        ], 'Invoice payment recorded.');

        $payment->loadMissing(['customer', 'invoice']);
        $this->notifications()->notifyPaymentRecorded(
            $payment,
            $this->notifications()->organizationFinanceUsers($this->orgId())
        );

        return redirect()->route('invoices.show', $invoice->id)->with('success', 'Invoice payment recorded successfully.');
    }

    public function exportCsv(Request $request)
    {
        $this->authorize('export', Payment::class);

        $payments = $this->applyFilters($this->paymentBaseQuery(), $request)
            ->latest('payment_date')
            ->latest('id')
            ->get();

        return $this->streamPaymentsCsv($payments, 'payments-' . now()->format('Ymd-His') . '.csv');
    }

    public function correction(Payment $payment)
    {
        abort_unless((int) $payment->organization_id === $this->orgId(), 404);
        $this->authorize('delete', $payment);
        $version = app(\App\Services\Finance\PaymentCorrectionService::class)->version($payment);

        $reversal = Payment::where('organization_id', $this->orgId())->where('reversal_of_payment_id', $payment->id)->first();

        $canViewFinance = Auth::user()?->canViewFinance() ?? false;

        $backUrl = $payment->invoice && auth()->user()->can('view', $payment->invoice)
            ? route('invoices.show', $payment->invoice_id)
            : ($payment->rental && auth()->user()->can('view', $payment->rental) ? route('rentals.show', $payment->rental_id) : null);

        return view('payments.correct', compact('payment', 'version', 'reversal', 'canViewFinance', 'backUrl'));
    }

    public function reverse(Request $request, Payment $payment)
    {
        abort_unless((int) $payment->organization_id === $this->orgId(), 404);
        $this->authorize('delete', $payment);
        $data = $request->validate([
            'reason' => 'required|string|min:5|max:1000',
            'version' => 'required|string|size:64',
            'confirm' => 'accepted',
        ]);
        app(\App\Services\Finance\PaymentCorrectionService::class)->reverse($payment, $data['reason'], $data['version']);

        return redirect()->route('payments.correction', $payment)->with('success', 'Payment reversed. Original entry retained. No customer refund recorded.');
    }

    public function destroy(Payment $payment)
    {
        abort_unless((int) $payment->organization_id === $this->orgId(), 404);
        $this->authorize('delete', $payment);

        if (request()->expectsJson()) {
            throw ValidationException::withMessages(['payment' => 'Payments cannot be deleted. Use the appropriate correction workflow.']);
        }

        return redirect()->route('payments.correction', $payment)
            ->with('error', 'Payments cannot be deleted. Review the entry and give a reason to reverse it.');
    }
}
