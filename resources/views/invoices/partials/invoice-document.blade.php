@php
    $organization = $invoice->organization;
    $invoiceSourceTypes = $invoice->items->pluck('source_type')->filter()->unique();
    $hasRentalLines = $invoice->rentalRenewal || $invoiceSourceTypes->contains('rental');
    $hasSaleLines = $invoiceSourceTypes->contains('sale');
    $invoiceTitle = $hasRentalLines && !$hasSaleLines
        ? 'Rental Invoice'
        : ($hasSaleLines && !$hasRentalLines ? 'Sale Invoice' : 'Tax Invoice');

    $subjectLine = $invoice->rentalRenewal
        ? 'Monthly Rental Renewal'
        : ($hasRentalLines
            ? 'Rental Charges'
            : ($hasSaleLines ? 'Equipment Sale' : ($invoice->items->first()?->display_description ?: 'Invoice Charges')));

    $statusClass = match ($invoice->payment_status) {
        'paid' => 'status-paid',
        'partial' => 'status-partial',
        default => in_array($invoice->status, ['draft', 'cancelled'], true) ? 'status-draft' : 'status-unpaid',
    };

    $statusLabel = $invoice->payment_status === 'partial'
        ? 'PARTIALLY PAID'
        : strtoupper($invoice->payment_status ?: ($invoice->status ?: 'draft'));

    $organizationAddress = collect([
        $organization?->address,
        trim(collect([
            $organization?->city,
            $organization?->state,
            $organization?->pincode,
        ])->filter()->implode(', ')),
        $organization?->country,
    ])->filter()->implode(', ');

    $billingAddress = collect([
        $invoice->bill_to_address,
        trim(collect([
            $invoice->bill_to_city,
            $invoice->bill_to_state,
            $invoice->bill_to_state_code ? '(' . $invoice->bill_to_state_code . ')' : null,
            $invoice->bill_to_pincode,
        ])->filter()->implode(' ')),
    ])->filter()->implode(', ');

    $shippingAddress = collect([
        $invoice->ship_to_address,
        trim(collect([
            $invoice->ship_to_city,
            $invoice->ship_to_state,
            $invoice->ship_to_state_code ? '(' . $invoice->ship_to_state_code . ')' : null,
            $invoice->ship_to_pincode,
        ])->filter()->implode(' ')),
    ])->filter()->implode(', ');

    $shipMatchesBilling = trim(strtolower(implode('|', [
        $invoice->bill_to_name ?: ($invoice->customer->name ?? ''),
        $invoice->bill_to_phone ?: '',
        $billingAddress,
    ]))) === trim(strtolower(implode('|', [
        $invoice->ship_to_name ?: ($invoice->bill_to_name ?: ($invoice->customer->name ?? '')),
        $invoice->ship_to_phone ?: ($invoice->bill_to_phone ?: ''),
        $shippingAddress ?: $billingAddress,
    ])));

    $showTaxColumns = (float) $invoice->total_tax_amount > 0;
    $hasInvoiceCgstSgst = ((float) $invoice->cgst_amount > 0 || (float) $invoice->sgst_amount > 0);
    $hasInvoiceIgst = (float) $invoice->igst_amount > 0;
    $showGstSplit = $showTaxColumns && $hasInvoiceCgstSgst;
    $showIgst = $showTaxColumns && $hasInvoiceIgst;
    $taxScopeLabel = $showTaxColumns
        ? ($showGstSplit && $showIgst
            ? 'Mixed GST'
            : ($showGstSplit ? 'CGST + SGST' : ($showIgst ? 'IGST' : 'No Tax')))
        : 'No Tax';
    $showDiscount = (float) $invoice->discount_amount > 0;
    $showShipping = (float) $invoice->shipping_charges > 0;
    $showDeposit = (float) $invoice->deposit_amount > 0;
    $otherCharges = (float) ($invoice->other_charges ?? 0);
    $showOtherCharges = $otherCharges > 0;
    $billingTerms = $invoice->terms_conditions ?: ($organization?->default_terms ?: null);
    $paymentTermsLabel = $invoice->due_date && $invoice->invoice_date
        ? max(optional($invoice->invoice_date)->diffInDays($invoice->due_date, false), 0) . ' day terms'
        : ($billingTerms ? 'As agreed' : 'Due on receipt');
    $termsList = collect(preg_split('/\r\n|\r|\n/', (string) $billingTerms))
        ->map(fn ($line) => preg_replace('/^[\-\x{2022}\s]+/u', '', trim((string) $line)))
        ->filter()
        ->values();
    $displayRentalPeriod = $invoice->inferredRentalPeriod();

    $toDataUri = function (?string $relativePath, string $disk = 'storage'): ?string {
        if (!$relativePath) {
            return null;
        }

        $absolutePath = $disk === 'public'
            ? public_path(ltrim($relativePath, '/'))
            : public_path('storage/' . ltrim($relativePath, '/'));

        if (!is_file($absolutePath) || !is_readable($absolutePath)) {
            return null;
        }

        $mime = function_exists('mime_content_type') ? mime_content_type($absolutePath) : 'image/png';
        $contents = @file_get_contents($absolutePath);

        if ($contents === false) {
            return null;
        }

        return 'data:' . ($mime ?: 'image/png') . ';base64,' . base64_encode($contents);
    };

    $tenantLogo = $tenantLogo ?? (extension_loaded('gd')
        ? $toDataUri('images/prime-healers-logo.png', 'public')
        : null);
    $tenantQr = $tenantQr ?? $toDataUri($organization?->payment_qr_code);
    $tenantSignature = $tenantSignature ?? $toDataUri($organization?->digital_signature);
    $organizationInitials = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $organization?->name ?? 'OR'), 0, 2));
    $currency = trim((string) ($pdfCurrencySymbol ?? ($pdfCurrencyFallback ?? '₹')));
    $currencyHtml = $currency === '₹' ? '&#8377;' : e($currency);
    $showPaymentsTable = $showPaymentsTable ?? true;
@endphp

<div class="{{ $documentRootClass ?? 'invoice-page' }}">
    <table class="header-table">
        <tr>
            <td class="header-logo-cell">
                <div class="header-logo-box">
                    @if($tenantLogo)
                        <img src="{{ $tenantLogo }}" alt="Prime Healers Logo" class="invoice-logo">
                    @else
                        <div class="header-logo-fallback">{{ $organizationInitials ?: 'CO' }}</div>
                    @endif
                </div>
            </td>
            <td class="header-company-cell">
                <h1 class="header-company-name">{{ $organization?->name ?: 'Company' }}</h1>
                @if($organizationAddress)
                    <p class="header-company-line">{{ $organizationAddress }}</p>
                @endif
                @if($organization?->phone || $organization?->email)
                    <p class="header-company-line">
                        @if($organization?->phone)
                            Phone: {{ $organization->phone }}
                        @endif
                        @if($organization?->phone && $organization?->email)
                            &nbsp;|&nbsp;
                        @endif
                        @if($organization?->email)
                            Email: {{ $organization->email }}
                        @endif
                    </p>
                @endif
                @if($organization?->gst_number)
                    <p class="header-company-line">GSTIN: {{ $organization->gst_number }}</p>
                @endif
            </td>
            <td class="header-title-cell">
                <h2 class="header-invoice-title">{{ strtoupper($invoiceTitle) }}</h2>
                <span class="status-badge {{ $statusClass }}">{{ $statusLabel }}</span>
            </td>
        </tr>
    </table>

    <table class="meta-table">
        <tr>
            <td><span class="meta-label">Invoice #</span><span class="meta-value">{{ $invoice->invoice_number }}</span></td>
            <td><span class="meta-label">Date</span><span class="meta-value">{{ optional($invoice->invoice_date)->format('d M Y') ?: 'N/A' }}</span></td>
            <td><span class="meta-label">Due Date</span><span class="meta-value">{{ optional($invoice->due_date)->format('d M Y') ?: 'N/A' }}</span></td>
        </tr>
        <tr>
            <td><span class="meta-label">Terms</span><span class="meta-value">{{ $paymentTermsLabel }}</span></td>
            <td><span class="meta-label">Place of Supply</span><span class="meta-value">{{ $invoice->place_of_supply_state ?: 'N/A' }}</span></td>
            <td><span class="meta-label">GST Type</span><span class="meta-value">{{ $taxScopeLabel }}</span></td>
        </tr>
    </table>

    <table class="party-table">
        <tr>
            <td>
                <h3 class="section-title">Bill To</h3>
                <p class="party-line"><strong>{{ $invoice->bill_to_name ?: ($invoice->customer->name ?? 'Customer') }}</strong></p>
                @if($invoice->bill_to_phone)
                    <p class="party-line">{{ $invoice->bill_to_phone }}</p>
                @endif
                @if($invoice->bill_to_email)
                    <p class="party-line">{{ $invoice->bill_to_email }}</p>
                @endif
                <p class="party-line">{{ $billingAddress ?: 'Address not provided' }}</p>
                @if($invoice->bill_to_gstin)
                    <p class="party-line">GSTIN: {{ $invoice->bill_to_gstin }}</p>
                @endif
            </td>
            <td>
                <h3 class="section-title">Ship To</h3>
                @if($shipMatchesBilling)
                    <p class="party-line"><strong>{{ $invoice->ship_to_name ?: ($invoice->bill_to_name ?: ($invoice->customer->name ?? 'Customer')) }}</strong></p>
                    <p class="party-line">Same as billing address</p>
                    <p class="party-line">Place of Supply: {{ $invoice->place_of_supply_state ?: 'N/A' }}</p>
                @else
                    <p class="party-line"><strong>{{ $invoice->ship_to_name ?: ($invoice->bill_to_name ?: 'Delivery Address') }}</strong></p>
                    @if($invoice->ship_to_phone)
                        <p class="party-line">{{ $invoice->ship_to_phone }}</p>
                    @endif
                    <p class="party-line">{{ $shippingAddress ?: 'Address not provided' }}</p>
                    <p class="party-line">Place of Supply: {{ $invoice->place_of_supply_state ?: 'N/A' }}</p>
                @endif
            </td>
        </tr>
    </table>

    <div class="subject-row"><strong>Subject:</strong> {{ $subjectLine }}</div>

    <table class="items-table">
        <thead>
            <tr>
                <th style="width:4%;">#</th>
                <th style="width:35%;">Item &amp; Description</th>
                <th style="width:10%;">HSN/SAC</th>
                <th style="width:7%;" class="num">Qty</th>
                <th style="width:11%;" class="num">Rate</th>
                @if($showDiscount)
                    <th style="width:9%;" class="num">Discount</th>
                @endif
                @if($showTaxColumns)
                    <th style="width:10%;" class="num">Tax</th>
                    <th style="width:10%;" class="num">Tax Amt</th>
                @endif
                <th style="width:11%;" class="num">Amount</th>
            </tr>
        </thead>
        <tbody>
            @foreach($invoice->items as $item)
                @php
                    $itemTaxAmount = (float) ($item->cgst_amount ?? 0) + (float) ($item->sgst_amount ?? 0) + (float) ($item->igst_amount ?? 0);
                    $itemHasGstSplit = ((float) ($item->cgst_amount ?? 0) > 0 || (float) ($item->sgst_amount ?? 0) > 0 || ($item->tax_type ?? null) === 'cgst_sgst');
                    $itemHasIgst = ((float) ($item->igst_amount ?? 0) > 0 || ($item->tax_type ?? null) === 'igst');
                    $itemMeta = collect([
                        $item->unit ? 'Unit: ' . $item->unit : null,
                        $item->days ? 'Days: ' . number_format((float) $item->days, 2) : null,
                    ]);

                    if ($item->source_type === 'rental' && $displayRentalPeriod && !$invoice->rentalRenewal) {
                        $itemMeta->push(
                            'Rental Period: '
                            . (optional($displayRentalPeriod['start_date'] ?? null)?->format('d M Y') ?: '-')
                            . ' to '
                            . (optional($displayRentalPeriod['end_date'] ?? null)?->format('d M Y') ?: '-')
                        );

                        if ($invoice->rental?->start_date && $invoice->rental?->end_date) {
                            $durationDays = $invoice->rental->baseDurationDays();
                            $itemMeta->push('Duration: ' . $durationDays . ' day' . ($durationDays === 1 ? '' : 's'));
                        }
                    }

                    if ($item->source_type === 'rental_sale') {
                        $itemMeta->push('Products Sold With Rental');
                    }

                    if ($invoice->rentalRenewal && ($item->unit === 'renewal' || ($item->product_id && $item->days))) {
                        $itemMeta->push(
                            'Rental Period: '
                            . (optional($invoice->rentalRenewal->previous_end_date)->format('d M Y') ?: '-')
                            . ' to '
                            . (optional($invoice->rentalRenewal->renewed_end_date)->format('d M Y') ?: '-')
                        );
                    }
                @endphp
                <tr>
                    <td>{{ $loop->iteration }}</td>
                    <td>
                        <span class="item-title">{{ $item->display_description }}</span>
                        @if($itemMeta->filter()->isNotEmpty())
                            <span class="item-subtext">{{ $itemMeta->filter()->implode(' | ') }}</span>
                        @endif
                    </td>
                    <td>{{ $item->hsn_sac_code ?: '-' }}</td>
                    <td class="num">{{ number_format((float) $item->quantity, 2) }}</td>
                    <td class="num">{!! $currencyHtml !!} {{ number_format((float) $item->rate, 2) }}</td>
                    @if($showDiscount)
                        <td class="num">{!! $currencyHtml !!} {{ number_format((float) $item->discount_amount, 2) }}</td>
                    @endif
                    @if($showTaxColumns)
                        <td class="num">
                            @if($itemHasGstSplit && !$itemHasIgst)
                                CGST {{ number_format((float) ($item->cgst_rate ?? 0), 2) }}%<br>
                                SGST {{ number_format((float) ($item->sgst_rate ?? 0), 2) }}%
                            @elseif($itemHasIgst)
                                IGST {{ number_format((float) ($item->igst_rate ?? $item->tax_percentage ?? 0), 2) }}%
                            @else
                                0.00%
                            @endif
                        </td>
                        <td class="num">
                            @if($itemHasGstSplit && !$itemHasIgst)
                                CGST {!! $currencyHtml !!} {{ number_format((float) ($item->cgst_amount ?? 0), 2) }}<br>
                                SGST {!! $currencyHtml !!} {{ number_format((float) ($item->sgst_amount ?? 0), 2) }}
                            @elseif($itemHasIgst)
                                IGST {!! $currencyHtml !!} {{ number_format((float) ($item->igst_amount ?? 0), 2) }}
                            @else
                                {!! $currencyHtml !!} {{ number_format($itemTaxAmount, 2) }}
                            @endif
                        </td>
                    @endif
                    <td class="num"><strong>{!! $currencyHtml !!} {{ number_format((float) $item->line_total, 2) }}</strong></td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="summary-table">
        <tr>
            <td class="summary-notes-cell">
                <div class="summary-notes-wrap">
                    <div class="notes-box">
                        <div class="section-title">Amount in Words</div>
                        <div>{{ $amountInWords ?? 'Amount not available' }}</div>

                        @if($invoice->notes)
                            <div class="section-title" style="margin-top:10px;">Notes</div>
                            <div>{{ $invoice->notes }}</div>
                        @endif

                        @if($termsList->isNotEmpty())
                            <div class="section-title" style="margin-top:10px;">Terms</div>
                            @foreach($termsList as $term)
                                <div>- {{ $term }}</div>
                            @endforeach
                        @elseif($billingTerms)
                            <div class="section-title" style="margin-top:10px;">Terms</div>
                            <div>{{ $billingTerms }}</div>
                        @endif
                    </div>
                </div>
            </td>
            <td class="summary-totals-cell">
                <div class="summary-totals-wrap">
                    <table class="totals-table">
                        <tr>
                            <td>Subtotal</td>
                            <td class="num">{!! $currencyHtml !!} {{ number_format((float) $invoice->subtotal, 2) }}</td>
                        </tr>
                        @if($showDiscount)
                            <tr>
                                <td>Discount</td>
                                <td class="num">{!! $currencyHtml !!} {{ number_format((float) $invoice->discount_amount, 2) }}</td>
                            </tr>
                        @endif
                        <tr>
                            <td>Tax Total</td>
                            <td class="num">{!! $currencyHtml !!} {{ number_format((float) $invoice->total_tax_amount, 2) }}</td>
                        </tr>
                        @if($showShipping)
                            <tr>
                                <td>Transport / Shipping</td>
                                <td class="num">{!! $currencyHtml !!} {{ number_format((float) $invoice->shipping_charges, 2) }}</td>
                            </tr>
                        @endif
                        @if($showDeposit)
                            <tr>
                                <td>Refundable Deposit</td>
                                <td class="num">{!! $currencyHtml !!} {{ number_format((float) $invoice->deposit_amount, 2) }}</td>
                            </tr>
                        @endif
                        @if($showOtherCharges)
                            <tr>
                                <td>Other Charges</td>
                                <td class="num">{!! $currencyHtml !!} {{ number_format($otherCharges, 2) }}</td>
                            </tr>
                        @endif
                        <tr class="grand-total">
                            <td>Grand Total</td>
                            <td class="num">{!! $currencyHtml !!} {{ number_format((float) $invoice->total_amount, 2) }}</td>
                        </tr>
                        <tr>
                            <td>Payment Received</td>
                            <td class="num">{!! $currencyHtml !!} {{ number_format((float) $invoice->paid_amount, 2) }}</td>
                        </tr>
                        <tr class="balance-due">
                            <td>Balance Due</td>
                            <td class="num">{!! $currencyHtml !!} {{ number_format((float) $invoice->balance_amount, 2) }}</td>
                        </tr>
                    </table>

                    <div class="signature-box">
                        @if($tenantSignature)
                            <img src="{{ $tenantSignature }}" alt="Authorized Signature" style="max-width:160px; max-height:68px; width:auto; height:auto; display:block; margin-left:auto; margin-bottom:5px;">
                        @else
                            <div class="signature-line"></div>
                        @endif
                        <div>Authorized Signature</div>
                    </div>
                </div>
            </td>
        </tr>
    </table>

    <div class="payment-card">
        <div class="section-title">Payment Details</div>
        <table class="payment-details-table">
            <tr>
                <td class="payment-bank-col">
                    @if($organization?->bank_account_name || $organization?->bank_account_number || $organization?->bank_ifsc || $organization?->bank_name || $organization?->bank_branch || $organization?->upi_id)
                        @if($organization?->bank_account_name)
                            <div class="payment-bank-line">A/C Name: {{ $organization->bank_account_name }}</div>
                        @endif
                        @if($organization?->bank_account_number)
                            <div class="payment-bank-line">A/C No: {{ $organization->bank_account_number }}</div>
                        @endif
                        @if($organization?->bank_ifsc)
                            <div class="payment-bank-line">IFSC: {{ $organization->bank_ifsc }}</div>
                        @endif
                        @if($organization?->bank_name)
                            <div class="payment-bank-line">Bank: {{ $organization->bank_name }}</div>
                        @endif
                        @if($organization?->bank_branch)
                            <div class="payment-bank-line">Branch: {{ $organization->bank_branch }}</div>
                        @endif
                        @if($organization?->upi_id)
                            <div class="payment-bank-line">UPI ID: {{ $organization->upi_id }}</div>
                        @endif
                    @else
                        <div class="payment-bank-line">Bank details are not configured for this company.</div>
                    @endif
                </td>
                <td class="payment-qr-col">
                    <div class="payment-qr-box">
                        @if($tenantQr)
                            <img src="{{ $tenantQr }}" alt="Payment QR Code" class="qr-image" style="width:110px; height:110px; max-width:110px; max-height:110px; display:block; margin:0 auto;">
                            <div class="payment-qr-caption">Scan to pay</div>
                        @else
                            <div class="payment-qr-caption">No payment QR configured</div>
                        @endif
                    </div>
                </td>
            </tr>
        </table>
    </div>

    @if($showPaymentsTable && $invoice->payments->isNotEmpty())
        <table class="payments-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Method</th>
                    <th>Notes</th>
                    <th class="num">Amount</th>
                </tr>
            </thead>
            <tbody>
                @foreach($invoice->payments as $payment)
                    <tr>
                        <td>{{ optional($payment->payment_date)->format('d M Y') ?: 'N/A' }}</td>
                        <td>{{ strtoupper($payment->payment_method ?: 'other') }}</td>
                        <td>{{ $payment->notes ?: '-' }}</td>
                        <td class="num">{!! $currencyHtml !!} {{ number_format((float) $payment->amount, 2) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="footer-table">
        <tr>
            <td>Generated digitally by {{ $organization?->name ?: 'Organization' }}</td>
            <td class="footer-right"></td>
        </tr>
    </table>
</div>
