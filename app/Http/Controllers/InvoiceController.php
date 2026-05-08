<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Sale;
use App\Services\Finance\PaymentSyncService;
use App\Support\ActivityLogger;
use App\Support\CustomerProfileSupport;
use App\Support\InvoicePdfRenderer;
use App\Support\PhoneNumber;
use App\Support\WhatsAppHelper;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Throwable;

class InvoiceController extends Controller
{
    private ?bool $hasCustomerWhatsappColumn = null;
    private const OPENING_PAYMENT_NOTE = 'Opening paid amount from invoice form.';

    private function orgId(): int
    {
        return (int) Auth::user()->organization_id;
    }

    private function invoicePdfFailureRedirect(Invoice $invoice, string $message)
    {
        return redirect()
            ->route('invoices.show', $invoice->id)
            ->with('error', $message);
    }

    private function invoicePdfViewData(Invoice $invoice): array
    {
        $renderer = app(InvoicePdfRenderer::class);

        return [
            'invoice' => $invoice,
            'amountInWords' => $this->amountToWords((float) $invoice->total_amount),
            'pdfCurrencySymbol' => $renderer->currencySymbol(),
            'pdfCurrencyFallback' => $renderer->currencyFallback(),
        ];
    }

    private function amountToWords(float $amount): string
    {
        $rounded = round($amount, 2);
        $rupees = (int) floor($rounded);
        $paise = (int) round(($rounded - $rupees) * 100);

        if (class_exists(\NumberFormatter::class)) {
            $formatter = \NumberFormatter::create('en_IN', \NumberFormatter::SPELLOUT);
            $rupeeWords = trim((string) $formatter?->format($rupees));

            if ($rupeeWords !== '') {
                return 'Indian Rupee '
                    . ucwords($rupeeWords)
                    . ($paise > 0 ? ' And ' . str_pad((string) $paise, 2, '0', STR_PAD_LEFT) . '/100' : '')
                    . ' Only';
            }
        }

        $convertUnderThousand = function (int $number) use (&$convertUnderThousand): string {
            $ones = [
                0 => 'Zero', 1 => 'One', 2 => 'Two', 3 => 'Three', 4 => 'Four', 5 => 'Five', 6 => 'Six',
                7 => 'Seven', 8 => 'Eight', 9 => 'Nine', 10 => 'Ten', 11 => 'Eleven', 12 => 'Twelve',
                13 => 'Thirteen', 14 => 'Fourteen', 15 => 'Fifteen', 16 => 'Sixteen', 17 => 'Seventeen',
                18 => 'Eighteen', 19 => 'Nineteen',
            ];
            $tens = [
                2 => 'Twenty', 3 => 'Thirty', 4 => 'Forty', 5 => 'Fifty',
                6 => 'Sixty', 7 => 'Seventy', 8 => 'Eighty', 9 => 'Ninety',
            ];

            if ($number < 20) {
                return $ones[$number];
            }

            if ($number < 100) {
                $ten = intdiv($number, 10);
                $remainder = $number % 10;
                return $tens[$ten] . ($remainder ? ' ' . $ones[$remainder] : '');
            }

            $hundreds = intdiv($number, 100);
            $remainder = $number % 100;
            return $ones[$hundreds] . ' Hundred' . ($remainder ? ' ' . $convertUnderThousand($remainder) : '');
        };

        $convertIndian = function (int $number) use (&$convertIndian, $convertUnderThousand): string {
            if ($number < 1000) {
                return $convertUnderThousand($number);
            }

            $segments = [
                10000000 => 'Crore',
                100000 => 'Lakh',
                1000 => 'Thousand',
            ];

            foreach ($segments as $divider => $label) {
                if ($number >= $divider) {
                    $major = intdiv($number, $divider);
                    $remainder = $number % $divider;

                    return $convertIndian($major) . ' ' . $label . ($remainder ? ' ' . $convertIndian($remainder) : '');
                }
            }

            return $convertUnderThousand($number);
        };

        $words = $convertIndian($rupees);

        return 'Indian Rupee '
            . $words
            . ($paise > 0 ? ' And ' . str_pad((string) $paise, 2, '0', STR_PAD_LEFT) . '/100' : '')
            . ' Only';
    }

    private function hasCustomerWhatsappColumn(): bool
    {
        return $this->hasCustomerWhatsappColumn ??= Schema::hasColumn('customers', 'whatsapp_number');
    }

    private function applyInvoiceScope($query)
    {
        $user = Auth::user();

        if ($user && $user->hasScope('self_created', 'invoices') && Schema::hasColumn('invoices', 'created_by')) {
            $query->where('created_by', $user->id);
        }

        return $query;
    }

    private function scopedInvoiceQuery()
    {
        return $this->applyInvoiceScope(
            Invoice::query()->where('organization_id', $this->orgId())
        );
    }

    private function invoiceBaseQuery()
    {
        return $this->applyInvoiceScope(
            Invoice::query()
                ->with(['customer', 'items', 'payments'])
                ->forOrganization($this->orgId())
        );
    }

    private function paymentsRentalIdIsNullable(): bool
    {
        static $isNullable = null;

        if ($isNullable !== null) {
            return $isNullable;
        }

        $column = DB::table('information_schema.columns')
            ->select('is_nullable')
            ->where('table_schema', DB::raw('schema()'))
            ->where('table_name', 'payments')
            ->where('column_name', 'rental_id')
            ->first();

        return $isNullable = strtoupper((string) ($column->is_nullable ?? 'NO')) === 'YES';
    }

    private function syncOpeningPaymentEntry(Invoice $invoice): void
    {
        $invoice->loadMissing('payments');

        $openingPayment = $invoice->payments
            ->first(fn ($payment) => $payment->notes === self::OPENING_PAYMENT_NOTE);

        $nonOpeningPaymentsTotal = (float) $invoice->payments
            ->reject(fn ($payment) => $payment->notes === self::OPENING_PAYMENT_NOTE)
            ->sum('amount');

        $storedPaidAmount = (float) ($invoice->getRawOriginal('paid_amount') ?? $invoice->paid_amount ?? 0);
        $targetOpeningAmount = max(min($storedPaidAmount - $nonOpeningPaymentsTotal, (float) ($invoice->total_amount ?? 0)), 0);

        if ($targetOpeningAmount <= 0) {
            if ($openingPayment) {
                $openingPayment->delete();
            }

            return;
        }

        $paymentPayload = [
            'organization_id' => $this->orgId(),
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'payment_date' => optional($invoice->invoice_date)->toDateString() ?: now()->toDateString(),
            'amount' => $targetOpeningAmount,
            'payment_method' => 'other',
            'notes' => self::OPENING_PAYMENT_NOTE,
        ];

        $linkedRentalId = $invoice->linkedRentalId();

        if ($linkedRentalId) {
            $paymentPayload['rental_id'] = $linkedRentalId;
        } elseif (!$this->paymentsRentalIdIsNullable()) {
            return;
        }

        if ($openingPayment) {
            $openingPayment->update($paymentPayload);
        } else {
            Payment::create($paymentPayload);
        }
    }

    private function applyInvoiceFilters($query, Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $status = trim((string) $request->get('status', ''));
        $customerId = trim((string) $request->get('customer_id', ''));
        $city = trim((string) $request->get('city', ''));
        $fromDate = trim((string) $request->get('from_date', ''));
        $toDate = trim((string) $request->get('to_date', ''));

        if ($search !== '') {
            $query->where(function ($invoiceQuery) use ($search) {
                $invoiceQuery
                    ->where('invoice_number', 'like', '%' . $search . '%')
                    ->orWhere('reference_number', 'like', '%' . $search . '%')
                    ->orWhere('purchase_order_number', 'like', '%' . $search . '%')
                    ->orWhere('bill_to_name', 'like', '%' . $search . '%')
                    ->orWhere('bill_to_phone', 'like', '%' . $search . '%')
                    ->orWhere('bill_to_gstin', 'like', '%' . $search . '%')
                    ->orWhereHas('customer', function ($customerQuery) use ($search) {
                        $customerQuery
                            ->where('name', 'like', '%' . $search . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%')
                            ->orWhere('gst_number', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('items', function ($itemQuery) use ($search) {
                        $itemQuery->where('description', 'like', '%' . $search . '%');

                        if (is_numeric($search)) {
                            $itemQuery->orWhere('source_id', (int) $search);
                        }
                    });
            });
        }

        if ($customerId !== '') {
            $query->where('customer_id', (int) $customerId);
        }

        if ($city !== '') {
            $query->where(function ($cityQuery) use ($city) {
                $cityQuery
                    ->where('bill_to_city', 'like', '%' . $city . '%')
                    ->orWhereHas('customer', function ($customerQuery) use ($city) {
                        $customerQuery->where('city', 'like', '%' . $city . '%');
                    });
            });
        }

        if ($fromDate !== '' && $toDate !== '') {
            $query->whereBetween('invoice_date', [$fromDate, $toDate]);
        } elseif ($fromDate !== '') {
            $query->whereDate('invoice_date', '>=', $fromDate);
        } elseif ($toDate !== '') {
            $query->whereDate('invoice_date', '<=', $toDate);
        }

        if ($status !== '') {
            if ($status === 'overdue') {
                $query->overdue();
            } else {
                $query->where(function ($statusQuery) use ($status) {
                    $statusQuery
                        ->where('payment_status', $status)
                        ->orWhere('status', $status);
                });
            }
        }

        return $query;
    }

    private function streamInvoicesCsv($invoices, string $filename)
    {
        return response()->streamDownload(function () use ($invoices) {
            $output = fopen('php://output', 'w');
            fwrite($output, "\xEF\xBB\xBF");

            fputcsv($output, [
                'Invoice No',
                'Customer',
                'Customer Phone',
                'Customer Email',
                'GSTIN',
                'Date',
                'Due Date',
                'PO Number',
                'Reference',
                'Place of Supply',
                'Tax Type',
                'GST Mode',
                'Subtotal',
                'Discount',
                'Deposit',
                'Transportation / Shipping',
                'Other Charges',
                'Taxable Amount',
                'CGST',
                'SGST',
                'IGST',
                'Total GST',
                'Amount',
                'Paid',
                'Due',
                'Status',
            ]);

            foreach ($invoices as $invoice) {
                fputcsv($output, [
                    $invoice->invoice_number,
                    $invoice->bill_to_name ?: ($invoice->customer->name ?? ''),
                    $invoice->bill_to_phone ?: ($invoice->customer->phone ?? ''),
                    $invoice->bill_to_email ?: ($invoice->customer->email ?? ''),
                    $invoice->bill_to_gstin ?: ($invoice->customer->gst_number ?? ''),
                    optional($invoice->invoice_date)->format('Y-m-d'),
                    optional($invoice->due_date)->format('Y-m-d'),
                    $invoice->purchase_order_number,
                    $invoice->reference_number,
                    $invoice->place_of_supply_state,
                    strtoupper(str_replace('_', ' + ', (string) $invoice->tax_type)),
                    ucfirst((string) ($invoice->tax_calculation_mode ?: 'exclusive')),
                    number_format((float) $invoice->subtotal, 2, '.', ''),
                    number_format((float) $invoice->discount_amount, 2, '.', ''),
                    number_format((float) $invoice->deposit_amount, 2, '.', ''),
                    number_format((float) $invoice->shipping_charges, 2, '.', ''),
                    '0.00',
                    number_format((float) $invoice->taxable_amount, 2, '.', ''),
                    number_format((float) $invoice->cgst_amount, 2, '.', ''),
                    number_format((float) $invoice->sgst_amount, 2, '.', ''),
                    number_format((float) $invoice->igst_amount, 2, '.', ''),
                    number_format((float) $invoice->total_tax_amount, 2, '.', ''),
                    number_format((float) $invoice->total_amount, 2, '.', ''),
                    number_format((float) $invoice->paid_amount, 2, '.', ''),
                    number_format((float) $invoice->balance_amount, 2, '.', ''),
                    $invoice->payment_status ?: $invoice->status,
                ]);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    private function syncInvoiceCollectionStatuses($invoices)
    {
        $invoices->each(function (Invoice $invoice) {
            $this->syncPaidSaleInvoice($invoice);
            $this->syncOpeningPaymentEntry($invoice);
            $invoice->refresh();
            $invoice->syncFinancialStatus();
        });

        return $invoices;
    }

    private function syncPaidSaleInvoice(Invoice $invoice): void
    {
        $invoice->loadMissing('items');

        $saleItem = $invoice->items->first(fn ($item) => $item->source_type === 'sale' && !empty($item->source_id));

        if (!$saleItem) {
            return;
        }

        $sale = Sale::query()
            ->where('organization_id', $this->orgId())
            ->find($saleItem->source_id);

        if (!$sale || $sale->payment_status !== 'paid') {
            return;
        }

        $invoice->syncFinancialStatus();

        if ((float) $invoice->balance_amount <= 0) {
            return;
        }

        $paymentData = [
            'organization_id' => $this->orgId(),
            'customer_id' => $invoice->customer_id,
            'invoice_id' => $invoice->id,
            'payment_date' => $sale->sale_date ?: now()->toDateString(),
            'amount' => $invoice->balance_amount,
            'payment_method' => 'other',
            'notes' => 'Auto-recorded from paid sale #' . $sale->id . '.',
        ];

        if ($sale->rental_id) {
            $paymentData['rental_id'] = $sale->rental_id;
            Payment::create($paymentData);
            $invoice->refresh();

            return;
        }

        if ($this->paymentsRentalIdIsNullable()) {
            Payment::create($paymentData);
            $invoice->refresh();

            return;
        }

        $invoice->forceFill([
            'paid_amount' => (float) $invoice->total_amount,
            'balance_amount' => 0,
            'payment_status' => 'paid',
            'status' => 'paid',
        ])->save();
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Invoice::class);

        $customers = Customer::where('organization_id', $this->orgId())->orderBy('name')->get();
        $cities = Customer::where('organization_id', $this->orgId())
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->orderBy('city')
            ->pluck('city');

        $search = trim((string) $request->get('search', ''));
        $status = trim((string) $request->get('status', ''));
        $customerId = trim((string) $request->get('customer_id', ''));
        $city = trim((string) $request->get('city', ''));
        $fromDate = trim((string) $request->get('from_date', ''));
        $toDate = trim((string) $request->get('to_date', ''));

        $invoices = $this->applyInvoiceFilters($this->invoiceBaseQuery(), $request)
            ->latest('invoice_date')
            ->latest('id')
            ->get();

        $this->syncInvoiceCollectionStatuses($invoices);

        return view('invoices.index', compact(
            'invoices',
            'customers',
            'cities',
            'search',
            'status',
            'customerId',
            'city',
            'fromDate',
            'toDate'
        ));
    }

    public function exportCsv(Request $request)
    {
        $this->authorize('export', Invoice::class);

        $invoices = $this->applyInvoiceFilters($this->invoiceBaseQuery(), $request)
            ->when($request->filled('invoice_ids'), function ($query) use ($request) {
                $ids = collect((array) $request->input('invoice_ids'))
                    ->filter(fn ($id) => is_numeric($id))
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values();

                if ($ids->isNotEmpty()) {
                    $query->whereIn('id', $ids);
                }
            })
            ->latest('invoice_date')
            ->latest('id')
            ->get();

        $this->syncInvoiceCollectionStatuses($invoices);

        return $this->streamInvoicesCsv($invoices, 'invoices-' . now()->format('Ymd-His') . '.csv');
    }

    public function bulkPrint(Request $request)
    {
        $this->authorize('printAny', Invoice::class);

        $ids = collect((array) $request->input('invoice_ids'))
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return redirect()->route('invoices.index')->with('error', 'Select at least one invoice to download or print.');
        }

        $invoices = $this->applyInvoiceScope(
            Invoice::with(['items.product', 'items.rental', 'customer', 'organization', 'payments', 'rentalRenewal'])
                ->where('organization_id', $this->orgId())
                ->whereIn('id', $ids)
        )
            ->orderBy('invoice_date')
            ->orderBy('id')
            ->get();

        if ($invoices->isEmpty()) {
            return redirect()->route('invoices.index')->with('error', 'No permitted invoices were found for bulk download.');
        }

        $invoices->each(function (Invoice $invoice) {
            $invoice->items->each->syncLegacyRenewalDescription();
            $this->syncPaidSaleInvoice($invoice);
            $invoice->syncFinancialStatus();
        });

        return view('invoices.bulk-print', compact('invoices'));
    }

    public function create()
    {
        $this->authorize('create', Invoice::class);

        $organizationId = $this->orgId();

        $customers = Customer::where('organization_id', $organizationId)->orderBy('name')->get();
        $products = Product::where('organization_id', $organizationId)->orderBy('name')->get();
        $rentals = Rental::with(['product', 'customer'])
            ->where('organization_id', $organizationId)
            ->latest()
            ->get();
        $sales = Sale::with(['customer', 'product'])
            ->where('organization_id', $organizationId)
            ->latest()
            ->get();
        $organization = Organization::findOrFail($organizationId);

        $invoiceNumber = 'INV-' . str_pad((Invoice::max('id') ?? 0) + 1, 6, '0', STR_PAD_LEFT);
        $taxOptions = [0, 5, 12, 18, 28];

        return view('invoices.create', compact(
            'customers',
            'products',
            'rentals',
            'sales',
            'organization',
            'invoiceNumber',
            'taxOptions'
        ));
    }

    public function store(Request $request)
    {
        $this->authorize('create', Invoice::class);

        $organizationId = $this->orgId();
        $organization = Organization::findOrFail($organizationId);

        $this->validateInvoice($request);

        [$invoiceData, $itemsToCreate] = $this->prepareInvoiceData($request, $organization, $organizationId);

        $invoice = Invoice::create($invoiceData);

        foreach ($itemsToCreate as $item) {
            $invoice->items()->create($item);
        }

        $this->syncOpeningPaymentEntry($invoice->fresh(['payments', 'items']));
        $invoice->syncFinancialStatus();

        ActivityLogger::log('invoice.created', $invoice, [
            'invoice_number' => $invoice->invoice_number,
            'total_amount' => $invoice->total_amount,
            'payment_status' => $invoice->payment_status,
            'items_count' => count($itemsToCreate),
        ], 'Invoice created.');

        return redirect()->route('invoices.index')->with('success', 'Invoice created successfully.');
    }

    public function show($id)
    {
        $invoice = $this->applyInvoiceScope(
            Invoice::with(['items.product', 'items.rental', 'customer', 'organization', 'payments.customer', 'payments.rental.product', 'rentalRenewal'])
                ->where('organization_id', $this->orgId())
        )
            ->findOrFail($id);
        $this->authorize('view', $invoice);

        $invoice->items->each->syncLegacyRenewalDescription();
        $invoice->load(['items.product', 'items.rental', 'customer', 'organization', 'payments.customer', 'payments.rental.product', 'rentalRenewal']);

        $this->syncPaidSaleInvoice($invoice);
        $this->syncOpeningPaymentEntry($invoice);
        $invoice->refresh();
        $invoice->syncFinancialStatus();

        $customerWhatsapp = WhatsAppHelper::resolveCustomerNumber($invoice->customer);
        $whatsAppLinks = [
            'invoice' => WhatsAppHelper::chatUrl($customerWhatsapp, WhatsAppHelper::invoiceMessage($invoice)),
            'payment_reminder' => WhatsAppHelper::chatUrl($customerWhatsapp, WhatsAppHelper::paymentReminderForInvoice($invoice)),
            'thank_you' => WhatsAppHelper::chatUrl(
                $customerWhatsapp,
                $customerWhatsapp ? "Hello {$invoice->bill_to_name}, thank you for the payment against invoice {$invoice->invoice_number}. We appreciate your prompt settlement." : null
            ),
        ];
        $activityLogs = ActivityLogger::recentFor($invoice);

        return view('invoices.show', compact('invoice', 'whatsAppLinks', 'activityLogs'));
    }

    public function edit($id)
    {
        $organizationId = $this->orgId();

        $invoice = $this->applyInvoiceScope(
            Invoice::with(['items.product', 'items.rental', 'rentalRenewal'])
                ->where('organization_id', $organizationId)
        )
            ->findOrFail($id);
        $this->authorize('update', $invoice);

        $invoice->items->each->syncLegacyRenewalDescription();
        $invoice->load(['items.product', 'items.rental', 'rentalRenewal']);

        $this->syncPaidSaleInvoice($invoice);
        $this->syncOpeningPaymentEntry($invoice);
        $invoice->refresh();
        $invoice->syncFinancialStatus();

        $customers = Customer::where('organization_id', $organizationId)->orderBy('name')->get();
        $products = Product::where('organization_id', $organizationId)->orderBy('name')->get();
        $rentals = Rental::with(['product', 'customer'])
            ->where('organization_id', $organizationId)
            ->latest()
            ->get();
        $sales = Sale::with(['customer', 'product'])
            ->where('organization_id', $organizationId)
            ->latest()
            ->get();
        $organization = Organization::findOrFail($organizationId);
        $taxOptions = [0, 5, 12, 18, 28];

        return view('invoices.edit', compact(
            'invoice',
            'customers',
            'products',
            'rentals',
            'sales',
            'organization',
            'taxOptions'
        ));
    }

    public function quickStoreCustomer(Request $request)
    {
        $organizationId = $this->orgId();

        $validated = $request->validate(
            CustomerProfileSupport::validationRules(
                $this->hasCustomerWhatsappColumn(),
                false,
                $organizationId
            )
        );

        $displayName = trim((string) ($validated['name'] ?? ''));
        if ($displayName === '') {
            $displayName = trim((string) ($validated['company_name'] ?? ''));
        }
        if ($displayName === '') {
            $displayName = trim(collect([
                $validated['first_name'] ?? null,
                $validated['last_name'] ?? null,
            ])->filter()->implode(' '));
        }

        if ($displayName === '') {
            return response()->json([
                'message' => 'Please provide a company name or customer name.',
                'errors' => [
                    'name' => ['Please provide a company name or customer name.'],
                ],
            ], 422);
        }

        $customerState = $validated['state'] ?? null;
        $customerPlaceOfSupply = $validated['place_of_supply'] ?? null;

        if (!empty($customerState) && empty($customerPlaceOfSupply)) {
            $customerPlaceOfSupply = $customerState;
        }

        if (!empty($customerPlaceOfSupply) && empty($customerState)) {
            $customerState = $customerPlaceOfSupply;
        }

        $customer = Customer::create([
            'name' => $displayName,
            'customer_type' => $validated['customer_type'],
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'company_name' => $validated['company_name'] ?? null,
            'phone' => PhoneNumber::normalize($validated['phone'] ?? null),
            'email' => $validated['email'] ?? null,
            'gst_treatment' => $validated['gst_treatment'] ?? null,
            'place_of_supply' => $customerPlaceOfSupply,
            'gst_number' => $validated['gst_number'] ?? null,
            'address' => $validated['address'] ?? null,
            'city' => $validated['city'] ?? null,
            'state' => $customerState,
            'pincode' => $validated['pincode'] ?? null,
            'patient_name' => $validated['patient_name'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'organization_id' => $organizationId,
        ]);

        if ($this->hasCustomerWhatsappColumn()) {
            $customer->forceFill([
                'whatsapp_number' => PhoneNumber::normalize($validated['whatsapp_number'] ?? null),
            ])->save();
        }

        return response()->json([
            'message' => 'Customer created successfully.',
            'customer' => [
                'id' => $customer->id,
                'name' => $customer->name,
                'customer_type' => $customer->customer_type,
                'first_name' => $customer->first_name,
                'last_name' => $customer->last_name,
                'company_name' => $customer->company_name,
                'phone' => $customer->phone,
                'phone_country_code' => \App\Support\PhoneNumber::countryCode($customer->phone),
                'whatsapp_number' => $this->hasCustomerWhatsappColumn() ? $customer->whatsapp_number : null,
                'whatsapp_number_country_code' => $this->hasCustomerWhatsappColumn() ? \App\Support\PhoneNumber::countryCode($customer->whatsapp_number) : null,
                'email' => $customer->email,
                'gst_treatment' => $customer->gst_treatment,
                'place_of_supply' => $customer->place_of_supply,
                'gst_number' => $customer->gst_number,
                'address' => $customer->address,
                'city' => $customer->city,
                'state' => $customer->state,
                'pincode' => $customer->pincode,
                'patient_name' => $customer->patient_name,
                'notes' => $customer->notes,
            ],
        ]);
    }

    public function update(Request $request, $id)
    {
        $organizationId = $this->orgId();
        $organization = Organization::findOrFail($organizationId);

        $invoice = $this->applyInvoiceScope(
            Invoice::with('items')
                ->where('organization_id', $organizationId)
        )
            ->findOrFail($id);
        $this->authorize('update', $invoice);

        $this->validateInvoice($request, $invoice->id);

        [$invoiceData, $itemsToCreate] = $this->prepareInvoiceData($request, $organization, $organizationId, $invoice->id);

        $invoice->update($invoiceData);
        $invoice->items()->delete();

        foreach ($itemsToCreate as $item) {
            $invoice->items()->create($item);
        }

        $this->syncOpeningPaymentEntry($invoice->fresh(['payments', 'items']));
        $invoice->syncFinancialStatus();

        ActivityLogger::log('invoice.updated', $invoice->refresh(), [
            'invoice_number' => $invoice->invoice_number,
            'total_amount' => $invoice->total_amount,
            'payment_status' => $invoice->payment_status,
            'items_count' => count($itemsToCreate),
        ], 'Invoice updated.');

        return redirect()->route('invoices.show', $invoice->id)->with('success', 'Invoice updated successfully.');
    }

    public function print($id)
    {
        $invoice = Invoice::with(['items.product', 'items.rental', 'customer', 'organization', 'payments', 'rentalRenewal'])
            ->where('organization_id', $this->orgId())
            ->findOrFail($id);
        $this->authorize('print', $invoice);

        try {
            $this->syncPaidSaleInvoice($invoice);
            $this->syncOpeningPaymentEntry($invoice);
            $invoice->refresh();
            $invoice->syncFinancialStatus();

            $pdf = app(InvoicePdfRenderer::class)->render(
                $invoice,
                $this->invoicePdfViewData($invoice)
            );
        } catch (Throwable $exception) {
            report($exception);

            return $this->invoicePdfFailureRedirect(
                $invoice,
                trim($exception->getMessage()) !== ''
                    ? $exception->getMessage()
                    : 'Invoice PDF could not be generated on this environment. Check PDF_ENGINE and server PDF runtime configuration.'
            );
        }

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="' . $invoice->invoice_number . '.pdf"',
            'Cache-Control' => 'private, max-age=0, must-revalidate',
        ]);
    }

    public function markPaid($id)
    {
        $invoice = Invoice::where('organization_id', $this->orgId())->findOrFail($id);
        $invoice->syncFinancialStatus();

        if ((float) $invoice->balance_amount <= 0) {
            return redirect()->route('invoices.show', $invoice->id)->with('success', 'Invoice is already settled.');
        }

        if (!$invoice->linkedRentalId() && !$this->paymentsRentalIdIsNullable()) {
            return redirect()
                ->route('invoices.show', $invoice->id)
                ->with('error', 'Invoice payment could not be recorded because the payments table still requires rental_id. Run the latest migration and try again.');
        }

        $payment = app(PaymentSyncService::class)->createInvoicePayment(
            $this->orgId(),
            $invoice,
            [
                'payment_date' => now()->toDateString(),
                'amount' => (float) $invoice->balance_amount,
                'payment_method' => 'other',
                'notes' => 'Auto-recorded from Mark Paid action.',
            ],
            $invoice->linkedRentalId(),
            true
        );

        ActivityLogger::log('invoice.marked_paid', $invoice, [
            'invoice_number' => $invoice->invoice_number,
            'payment_id' => $payment->id,
            'payment_amount' => $payment->amount,
            'payment_status' => $invoice->payment_status,
        ], 'Invoice marked as paid.');

        return redirect()->route('invoices.show', $invoice->id)->with('success', 'Invoice marked as paid.');
    }

    public function bulkAction(Request $request)
    {
        $validated = $request->validate([
            'invoice_ids' => 'required|array|min:1',
            'invoice_ids.*' => 'integer',
            'bulk_action' => 'required|string|in:mark_paid,void',
        ]);

        $invoiceIds = collect($validated['invoice_ids'])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $invoices = $this->applyInvoiceScope(
            Invoice::query()
                ->where('organization_id', $this->orgId())
                ->whereIn('id', $invoiceIds)
        )->get();

        $updated = 0;
        $skipped = 0;

        DB::transaction(function () use ($invoices, $validated, &$updated, &$skipped) {
            foreach ($invoices as $invoice) {
                if ($validated['bulk_action'] === 'void') {
                    if ($invoice->status === 'cancelled' || $invoice->payment_status === 'cancelled') {
                        $skipped++;
                        continue;
                    }

                    $invoice->forceFill([
                        'status' => 'cancelled',
                        'payment_status' => 'cancelled',
                        'balance_amount' => 0,
                    ])->save();

                    ActivityLogger::log('invoice.bulk_voided', $invoice->refresh(), [
                        'invoice_number' => $invoice->invoice_number,
                        'payment_status' => $invoice->payment_status,
                    ], 'Invoice voided through bulk action.');

                    $updated++;
                    continue;
                }

                $invoice->syncFinancialStatus();

                if ((float) $invoice->balance_amount <= 0 || in_array($invoice->payment_status, ['paid', 'cancelled'], true)) {
                    $skipped++;
                    continue;
                }

                if (!$invoice->linkedRentalId() && !$this->paymentsRentalIdIsNullable()) {
                    $skipped++;
                    continue;
                }

                $payment = app(PaymentSyncService::class)->createInvoicePayment(
                    $this->orgId(),
                    $invoice,
                    [
                        'payment_date' => now()->toDateString(),
                        'amount' => (float) $invoice->balance_amount,
                        'payment_method' => 'other',
                        'notes' => 'Auto-recorded from bulk Mark Paid action.',
                    ],
                    $invoice->linkedRentalId(),
                    true
                );

                ActivityLogger::log('invoice.bulk_marked_paid', $invoice, [
                    'invoice_number' => $invoice->invoice_number,
                    'payment_id' => $payment->id,
                    'payment_amount' => $payment->amount,
                    'payment_status' => $invoice->payment_status,
                ], 'Invoice marked paid through bulk action.');

                $updated++;
            }
        });

        $label = $validated['bulk_action'] === 'void' ? 'voided' : 'marked paid';
        $message = "{$updated} invoice(s) {$label}.";

        if ($skipped > 0) {
            $message .= " {$skipped} skipped.";
        }

        return back()->with($updated > 0 ? 'success' : 'error', $message);
    }

    private function deleteInvoiceRecord(Invoice $invoice): void
    {
        if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'invoice_id')) {
            Payment::query()
                ->where('organization_id', $this->orgId())
                ->where('invoice_id', $invoice->id)
                ->update(['invoice_id' => null]);
        }

        if (Schema::hasTable('rental_renewals') && Schema::hasColumn('rental_renewals', 'invoice_id')) {
            DB::table('rental_renewals')
                ->where('organization_id', $this->orgId())
                ->where('invoice_id', $invoice->id)
                ->update(['invoice_id' => null]);
        }

        if (Schema::hasTable('activity_logs') && Schema::hasColumn('activity_logs', 'invoice_id')) {
            DB::table('activity_logs')
                ->where('organization_id', $this->orgId())
                ->where('invoice_id', $invoice->id)
                ->delete();
        }

        $invoice->items()->delete();
        $invoice->delete();
    }

    public function bulkDelete(Request $request)
    {
        $validated = $request->validate([
            'invoice_ids' => 'required|array|min:1',
            'invoice_ids.*' => 'integer',
        ]);

        $invoiceIds = collect($validated['invoice_ids'])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $invoices = $this->applyInvoiceScope(
            Invoice::query()
                ->with(['items'])
                ->where('organization_id', $this->orgId())
                ->whereIn('id', $invoiceIds)
        )->get();

        if ($invoices->isEmpty()) {
            return back()->with('error', 'No permitted invoices were found for deletion.');
        }

        DB::transaction(function () use ($invoices) {
            $invoices->each(fn (Invoice $invoice) => $this->deleteInvoiceRecord($invoice));
        });

        return back()->with('success', $invoices->count() . ' invoice(s) deleted successfully.');
    }

    public function destroy($id)
    {
        $invoice = $this->scopedInvoiceQuery()
            ->with(['items'])
            ->findOrFail($id);
        $this->authorize('delete', $invoice);

        DB::transaction(function () use ($invoice) {
            $this->deleteInvoiceRecord($invoice);
        });

        return redirect()->route('invoices.index')->with('success', 'Invoice deleted successfully.');
    }

    public function voidInvoice($id)
    {
        $invoice = Invoice::where('organization_id', $this->orgId())->findOrFail($id);

        if ($invoice->status === 'cancelled' || $invoice->payment_status === 'cancelled') {
            return redirect()->route('invoices.show', $invoice->id)->with('error', 'This invoice is already voided.');
        }

        $invoice->forceFill([
            'status' => 'cancelled',
            'payment_status' => 'cancelled',
            'balance_amount' => 0,
        ])->save();

        ActivityLogger::log('invoice.voided', $invoice->refresh(), [
            'invoice_number' => $invoice->invoice_number,
            'payment_status' => $invoice->payment_status,
        ], 'Invoice voided.');

        return redirect()->route('invoices.show', $invoice->id)->with('success', 'Invoice voided successfully.');
    }

    private function validateInvoice(Request $request, $ignoreId = null): array
    {
        return $request->validate([
            'invoice_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('invoices', 'invoice_number')->ignore($ignoreId),
            ],
            'invoice_date' => 'required|date',
            'due_date' => 'nullable|date',
            'customer_id' => [
                'nullable',
                Rule::exists('customers', 'id')->where(fn ($query) => $query->where('organization_id', $this->orgId())),
            ],

            'purchase_order_number' => 'nullable|string|max:255',
            'reference_number' => 'nullable|string|max:255',

            'bill_to_name' => 'nullable|string|max:255',
            'bill_to_phone' => PhoneNumber::validationRules(),
            'bill_to_phone_country_code' => 'nullable|string|max:8',
            'ship_to_name' => 'nullable|string|max:255',
            'ship_to_phone' => PhoneNumber::validationRules(),
            'ship_to_phone_country_code' => 'nullable|string|max:8',
            'place_of_supply_state' => 'nullable|string|max:255',
            'tax_calculation_mode' => 'required|string|in:exclusive,inclusive',

            'deposit_amount' => 'nullable|numeric|min:0',
            'shipping_charges' => 'nullable|numeric|min:0',
            'paid_amount' => 'nullable|numeric|min:0',

            'item_description.*' => 'nullable|string|max:255',
            'item_custom_name.*' => 'nullable|string|max:255',
            'item_product_id.*' => 'nullable|integer',
            'item_source_type.*' => 'nullable|string|in:rental,sale,manual,refill',
            'item_source_id.*' => 'nullable',
            'item_quantity.*' => 'nullable|numeric|min:0',
            'item_rate.*' => 'nullable|numeric|min:0',
            'item_discount.*' => 'nullable|numeric|min:0',
            'item_tax_percentage.*' => 'nullable|numeric|min:0',
        ]);
    }

    private function inferProductId(
        ?int $productId,
        ?string $sourceType,
        ?int $sourceId,
        string $effectiveDescription,
        \Illuminate\Support\Collection $productNames,
        int $organizationId
    ): ?int {
        if ($productId) {
            return $productId;
        }

        if ($sourceType === 'rental' && $sourceId) {
            $rentalProductId = Rental::query()
                ->where('organization_id', $organizationId)
                ->whereKey($sourceId)
                ->value('product_id');

            if ($rentalProductId) {
                return (int) $rentalProductId;
            }
        }

        if ($sourceType === 'sale' && $sourceId) {
            $saleProductId = Sale::query()
                ->where('organization_id', $organizationId)
                ->whereKey($sourceId)
                ->value('product_id');

            if ($saleProductId) {
                return (int) $saleProductId;
            }
        }

        $normalizedDescription = Str::lower(trim($effectiveDescription));

        if ($normalizedDescription === '') {
            return null;
        }

        $matchedProductId = $productNames->first(function ($name, $id) use ($normalizedDescription) {
            return Str::lower(trim((string) $name)) === $normalizedDescription;
        }, null);

        return $matchedProductId ? (int) $matchedProductId : null;
    }

    private function prepareInvoiceData(Request $request, Organization $organization, int $organizationId, $ignoreId = null): array
    {
        $placeOfSupplyState = $this->normalizeStateName($request->place_of_supply_state);
        $organizationState = $this->normalizeStateName($organization->state);
        $taxType = 'cgst_sgst';
        $taxCalculationMode = $request->input('tax_calculation_mode', 'exclusive') === 'inclusive'
            ? 'inclusive'
            : 'exclusive';

        if ($placeOfSupplyState !== null && $organizationState !== null) {
            $taxType = $organizationState === $placeOfSupplyState ? 'cgst_sgst' : 'igst';
        }

        $subtotal = 0;
        $discountAmount = 0;
        $taxableAmount = 0;
        $cgstAmount = 0;
        $sgstAmount = 0;
        $igstAmount = 0;
        $totalTaxAmount = 0;

        $depositAmount = (float) ($request->deposit_amount ?? 0);
        $shippingCharges = (float) ($request->shipping_charges ?? 0);

        $descriptions = $request->item_description ?? [];
        $customNames = $request->item_custom_name ?? [];
        $productIds = $request->item_product_id ?? [];
        $sourceTypes = $request->item_source_type ?? [];
        $sourceIds = $request->item_source_id ?? [];
        $hsnCodes = $request->item_hsn_sac_code ?? [];
        $quantities = $request->item_quantity ?? [];
        $units = $request->item_unit ?? [];
        $days = $request->item_days ?? [];
        $rates = $request->item_rate ?? [];
        $discounts = $request->item_discount ?? [];
        $taxPercentages = $request->item_tax_percentage ?? [];

        $itemsToCreate = [];
        $itemIndexes = collect([
            ...array_keys($descriptions),
            ...array_keys($customNames),
            ...array_keys($productIds),
        ])->unique()->sort()->values()->all();
        $selectedProductIds = collect($productIds)
            ->filter(fn ($productId) => !empty($productId))
            ->map(fn ($productId) => (int) $productId)
            ->unique()
            ->values();
        $productNames = Product::where('organization_id', $organization->id)
            ->pluck('name', 'id');

        foreach ($itemIndexes as $index) {
            $description = trim((string) ($descriptions[$index] ?? ''));
            $customName = trim((string) ($customNames[$index] ?? ''));
            $productId = !empty($productIds[$index]) ? (int) $productIds[$index] : null;
            $sourceType = !empty($sourceTypes[$index]) ? $sourceTypes[$index] : null;
            $effectiveDescription = $description;

            if ($effectiveDescription === '' && $customName !== '') {
                $effectiveDescription = $customName;
            }

            if ($effectiveDescription === '' && $productId) {
                $effectiveDescription = trim((string) ($productNames[$productId] ?? ''));
            }

            if (!$effectiveDescription && !$productId) {
                continue;
            }

            $quantity = (float) ($quantities[$index] ?? 0);
            $rate = (float) ($rates[$index] ?? 0);
            $discount = (float) ($discounts[$index] ?? 0);
            $taxPercentage = (float) ($taxPercentages[$index] ?? 0);
            $daysValue = ($days[$index] ?? null) !== null && $days[$index] !== '' ? (float) $days[$index] : null;
            $sourceIdValue = $sourceIds[$index] ?? null;
            $sourceIdValue = is_numeric($sourceIdValue) ? (int) $sourceIdValue : null;
            $productId = $this->inferProductId(
                $productId,
                $sourceType,
                $sourceIdValue,
                $effectiveDescription,
                $productNames,
                $organizationId
            );

            if ($effectiveDescription === '' && $productId) {
                $effectiveDescription = trim((string) ($productNames[$productId] ?? ''));
            }

            $baseAmount = $quantity * $rate;
            $effectiveAmount = max($baseAmount - $discount, 0);

            $lineCgstRate = 0;
            $lineSgstRate = 0;
            $lineIgstRate = 0;
            $lineCgstAmount = 0;
            $lineSgstAmount = 0;
            $lineIgstAmount = 0;
            $lineTaxTotal = 0;

            if ($taxCalculationMode === 'inclusive' && $taxPercentage > 0) {
                $taxableLineAmount = $effectiveAmount / (1 + ($taxPercentage / 100));
            } else {
                $taxableLineAmount = $effectiveAmount;
            }

            if ($taxType === 'cgst_sgst') {
                $lineCgstRate = $taxPercentage / 2;
                $lineSgstRate = $taxPercentage / 2;
                $lineCgstAmount = ($taxableLineAmount * $lineCgstRate) / 100;
                $lineSgstAmount = ($taxableLineAmount * $lineSgstRate) / 100;
            } elseif ($taxType === 'igst') {
                $lineIgstRate = $taxPercentage;
                $lineIgstAmount = ($taxableLineAmount * $lineIgstRate) / 100;
            }

            $lineTaxTotal = $lineCgstAmount + $lineSgstAmount + $lineIgstAmount;
            $lineTotal = $taxCalculationMode === 'inclusive'
                ? $effectiveAmount
                : ($taxableLineAmount + $lineTaxTotal);

            $subtotal += $baseAmount;
            $discountAmount += $discount;
            $taxableAmount += $taxableLineAmount;
            $cgstAmount += $lineCgstAmount;
            $sgstAmount += $lineSgstAmount;
            $igstAmount += $lineIgstAmount;
            $totalTaxAmount += $lineTaxTotal;

            $itemsToCreate[] = [
                'product_id' => $productId,
                'source_type' => $sourceType,
                'source_id' => $sourceIdValue,
                'description' => $effectiveDescription,
                'hsn_sac_code' => !empty($hsnCodes[$index]) ? $hsnCodes[$index] : null,
                'quantity' => $quantity,
                'unit' => !empty($units[$index]) ? $units[$index] : null,
                'days' => $daysValue,
                'rate' => $rate,
                'discount_amount' => $discount,
                'taxable_amount' => $taxableLineAmount,
                'tax_percentage' => $taxPercentage,
                'tax_type' => $taxType,
                'cgst_rate' => $lineCgstRate,
                'sgst_rate' => $lineSgstRate,
                'igst_rate' => $lineIgstRate,
                'cgst_amount' => $lineCgstAmount,
                'sgst_amount' => $lineSgstAmount,
                'igst_amount' => $lineIgstAmount,
                'line_total' => $lineTotal,
            ];
        }

        $totalAmount = $taxableAmount + $totalTaxAmount + $depositAmount + $shippingCharges;
        $paidAmount = min((float) ($request->paid_amount ?? 0), $totalAmount);
        $balanceAmount = max($totalAmount - $paidAmount, 0);

        $financialStatus = Invoice::determineFinancialStatus(
            $totalAmount,
            $paidAmount,
            $request->filled('due_date') ? \Illuminate\Support\Carbon::parse($request->due_date) : null
        );

        $invoiceData = [
            'organization_id' => $organizationId,
            'invoice_number' => $request->invoice_number,
            'invoice_date' => $request->invoice_date,
            'due_date' => $request->due_date,
            'purchase_order_number' => $request->purchase_order_number,
            'purchase_order_date' => $request->purchase_order_date,
            'reference_number' => $request->reference_number,
            'customer_id' => $request->customer_id,

            'bill_to_name' => $request->bill_to_name,
            'bill_to_phone' => PhoneNumber::normalize($request->bill_to_phone, $request->bill_to_phone_country_code),
            'bill_to_email' => $request->bill_to_email,
            'bill_to_address' => $request->bill_to_address,
            'bill_to_city' => $request->bill_to_city,
            'bill_to_state' => $request->bill_to_state,
            'bill_to_state_code' => $request->bill_to_state_code,
            'bill_to_pincode' => $request->bill_to_pincode,
            'bill_to_gstin' => $request->bill_to_gstin,

            'ship_to_name' => $request->ship_to_name,
            'ship_to_phone' => PhoneNumber::normalize($request->ship_to_phone, $request->ship_to_phone_country_code),
            'ship_to_address' => $request->ship_to_address,
            'ship_to_city' => $request->ship_to_city,
            'ship_to_state' => $request->ship_to_state,
            'ship_to_state_code' => $request->ship_to_state_code,
            'ship_to_pincode' => $request->ship_to_pincode,

            'place_of_supply_state' => $request->place_of_supply_state,
            'place_of_supply_code' => null,
            'tax_type' => $taxType,
            'tax_calculation_mode' => $taxCalculationMode,

            'status' => $financialStatus,
            'payment_status' => $financialStatus,

            'subtotal' => $subtotal,
            'discount_amount' => $discountAmount,
            'deposit_amount' => $depositAmount,
            'shipping_charges' => $shippingCharges,
            'taxable_amount' => $taxableAmount,
            'cgst_amount' => $cgstAmount,
            'sgst_amount' => $sgstAmount,
            'igst_amount' => $igstAmount,
            'total_tax_amount' => $totalTaxAmount,
            'total_amount' => $totalAmount,
            'paid_amount' => $paidAmount,
            'balance_amount' => $balanceAmount,

            'notes' => $request->notes,
            'terms_conditions' => $request->terms_conditions ?: $organization->default_terms,
            'created_by' => Auth::id(),
        ];

        return [$invoiceData, $itemsToCreate];
    }

    private function normalizeStateName(?string $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }
}
