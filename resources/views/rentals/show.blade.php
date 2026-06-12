@extends('layouts.app')

@section('content')
@php
    $deliveryRecord = $rental->deliveryRecord;
    $pickupRecord = $rental->pickupRecord;
    $activeAssets = $rental->activeRentalAssets ?? collect();
    $canUpdateRentals = auth()->user()?->canAccessModule('rentals', 'update') ?? false;
    $canCreateDeliveries = auth()->user()?->canAccessModule('deliveries', 'create') ?? false;
    $canDeleteRentals = auth()->user()?->canAccessModule('rentals', 'delete') ?? false;
    $canDeletePayments = auth()->user()?->canAccessModule('payments', 'delete') ?? false;
    $canUpdateDeliveries = auth()->user()?->canAccessModule('deliveries', 'update') ?? false;
    $canDeleteDeliveries = auth()->user()?->canAccessModule('deliveries', 'delete') ?? false;
    $canCreatePayments = auth()->user()?->canAccessModule('payments', 'create') ?? false;
    $operationalStatus = $rental->operationalStatus();
    $isHotlisted = $rental->shouldHotlist();
    $latestReminderAt = $rental->latestReminderSentAt();
    $renewalHistory = ($rental->renewals ?? collect())->sortByDesc('id')->values();
    $renewalHistoryChronological = $renewalHistory->sortBy('id')->values();
    $renewalFeatureReady = \App\Models\Rental::hasRenewalsTable();
    $saleItems = $rental->saleItems ?? collect();
    $rentalItems = $rental->displayRentalItems();
    $rentalLineAmount = function ($item): float {
        $lineTotal = (float) ($item->line_total ?? 0);

        if ($lineTotal > 0) {
            return round($lineTotal, 2);
        }

        return round(max((int) ($item->quantity ?? 1), 1) * (float) ($item->unit_rental_amount ?? 0), 2);
    };
    $rentalLineTotal = round((float) $rentalItems->sum(fn ($item) => $rentalLineAmount($item)), 2);
    $suggestedRenewalDays = $rental->suggestedRenewalDays();
    $suggestedRenewalAmount = $rental->suggestedRenewalAmount($suggestedRenewalDays);
    $suggestedRenewedEndDate = optional($rental->end_date)->copy()?->addDays($suggestedRenewalDays) ?? now()->addDays($suggestedRenewalDays);
    $currency = fn ($value) => "\u{20B9}" . number_format((float) $value, 2);
    $customerWhatsapp = \App\Support\WhatsAppHelper::resolveCustomerNumber($rental->customer);
    $renewalUrl = $customerWhatsapp ? route('rentals.reminders.open', ['rental' => $rental, 'type' => $isHotlisted ? 'overdue' : 'renewal']) : null;
    $deliveryUrl = $customerWhatsapp ? route('rentals.reminders.open', ['rental' => $rental, 'type' => 'delivery']) : null;
    $pickupUrl = $customerWhatsapp ? route('rentals.reminders.open', ['rental' => $rental, 'type' => 'pickup']) : null;
    $rentalInvoiceStatus = $rentalInvoice?->payment_status ?? null;
    $rentalInvoiceDue = (float) ($rentalInvoice->balance_amount ?? 0);
    $initialBookingEndDate = optional($renewalHistoryChronological->first())->previous_end_date ?: $rental->end_date;
    $baseRentalAmount = max($rentalLineTotal - (float) $renewalHistoryChronological->sum('rental_amount_added'), 0);
    $baseDepositAmount = max((float) ($rental->deposit_amount ?? 0) - (float) $renewalHistoryChronological->sum('deposit_amount_added'), 0);
    $baseTransportAmount = max((float) ($rental->transport_amount ?? 0) - (float) $renewalHistoryChronological->sum('transport_amount_added'), 0);
    $baseOtherAmount = max((float) ($rental->other_amount ?? 0) - (float) $renewalHistoryChronological->sum('other_amount_added'), 0);
    $renewalPaymentIds = $renewalHistoryChronological->pluck('payment_id')->filter()->map(fn ($id) => (int) $id)->all();
    $baseBookingPayments = ($rental->payments ?? collect())->filter(fn ($payment) => !in_array((int) $payment->id, $renewalPaymentIds, true));
    $baseBookingPaidAmount = round((float) $baseBookingPayments->sum('amount'), 2);
    $baseBookingTotal = round($baseRentalAmount + $baseDepositAmount + $baseTransportAmount + $baseOtherAmount, 2);
    $baseBookingBalance = round(max($baseBookingTotal - $baseBookingPaidAmount, 0), 2);
    $currentChargeBreakdown = [
        'Rental' => $rentalLineTotal,
        'Deposit' => (float) ($rental->deposit_amount ?? 0),
        'Transport' => (float) ($rental->transport_amount ?? 0),
        'Other' => (float) ($rental->other_amount ?? 0),
    ];
    $currentRentalTotal = round(array_sum($currentChargeBreakdown), 2);
    $invoiceTotalAmount = round((float) ($rentalInvoice->total_amount ?? $currentRentalTotal), 2);
    $invoicePaidAmount = round((float) ($rentalInvoice->paid_amount ?? 0), 2);
    $invoiceBalanceAmount = round((float) ($rentalInvoice->balance_amount ?? max($invoiceTotalAmount - $invoicePaidAmount, 0)), 2);
    $financeSourceLabel = $rentalInvoice ? 'Invoice #' . $rentalInvoice->invoice_number : 'Rental charges before invoice';
    $collectionPercent = $invoiceTotalAmount > 0 ? min(100, round(($invoicePaidAmount / $invoiceTotalAmount) * 100, 1)) : 0;
    $rentalInvoiceDue = $invoiceBalanceAmount;
    $reminderContactName = $rental->reminderContactName();
    $reminderContactPhone = $rental->reminderContactPhone();
    $deliveryContactName = $rental->deliveryContactName();
    $deliveryContactPhone = $rental->deliveryContactPhone();
    $deliveryContactAddress = $rental->deliveryContactAddress();
    $deliveryContactMapUrl = $rental->deliveryContactMapUrl();
    $reminderCallHref = $reminderContactPhone ? 'tel:' . preg_replace('/\D+/', '', $reminderContactPhone) : null;
    $reminderWhatsappHref = \App\Support\WhatsAppHelper::chatUrl(
        \App\Support\WhatsAppHelper::normalizeNumber($reminderContactPhone),
        $reminderContactPhone ? "Hello {$reminderContactName}, this is a quick update regarding rental #{$rental->id} from Prime Healers." : null
    );
    $rentalFollowUpContextJson = json_encode([
        'customer_id' => $rental->customer_id,
        'business_partner_id' => $rental->business_partner_id,
        'partner_client_id' => $rental->partner_client_id,
        'rental_id' => $rental->id,
        'reference' => 'Rental #' . $rental->id,
        'reminder_contact' => collect([$reminderContactName, $reminderContactPhone])->filter()->implode(' | '),
        'service_contact' => collect([$deliveryContactName, $deliveryContactPhone])->filter()->implode(' | '),
        'service_address' => $deliveryContactAddress,
    ]);
    $rentalQuickActions = collect();
    $rentalMoreActions = collect();
    $rentalInfoItems = collect();

    $badge = function (?string $status) {
        return match ($status) {
            'active', 'completed' => 'background:#dcfce7;color:#166534;',
            'returned' => 'background:#dbeafe;color:#1d4ed8;',
            'overdue' => 'background:#fee2e2;color:#b91c1c;',
            'delivery_pending' => 'background:#fff7ed;color:#c2410c;',
            'cancelled' => 'background:#f1f5f9;color:#64748b;',
            'pending', 'assigned' => 'background:#e2e8f0;color:#334155;',
            'in_progress' => 'background:#fef3c7;color:#b45309;',
            default => 'background:#f1f5f9;color:#475569;',
        };
    };
    $deliveryStatusLabel = fn (?string $status) => match ($status) {
        'pending', 'assigned' => 'Delivery Assigned',
        'in_progress' => 'Delivery In Progress',
        'completed' => 'Delivery Completed',
        default => $status ? ucfirst(str_replace('_', ' ', $status)) : 'Delivery Not Assigned',
    };
    $assignedPersonLabel = fn ($record) => $record?->assignedUser?->name
        ?? $record?->assignedStaff?->name
        ?? $record?->third_party_name
        ?? 'Not assigned';
    $deliveryAssigneeLabel = $assignedPersonLabel($deliveryRecord) !== 'Not assigned'
        ? $assignedPersonLabel($deliveryRecord)
        : ($rental->deliveryStaff->name ?? 'Not assigned');
    $pickupAssigneeLabel = $assignedPersonLabel($pickupRecord) !== 'Not assigned'
        ? $assignedPersonLabel($pickupRecord)
        : ($rental->pickupStaff->name ?? 'Not assigned');
    $hasOpenDeliveryTask = in_array($deliveryRecord?->status, ['pending', 'in_progress'], true);
    $hasOpenPickupTask = in_array($pickupRecord?->status, ['pending', 'in_progress'], true);
    $canAssignPickup = $canCreateDeliveries && $rental->pendingPickupQuantityTotal() > 0;
    $hasPendingDeliveryItems = $rental->pendingDeliveryQuantityTotal() > 0;
    $hasPendingPickupItems = $rental->pendingPickupQuantityTotal() > 0;
    $itemProgressBadge = function (?string $status) {
        return match ($status) {
            'partial', 'partially_delivered', 'partial_return', 'partially_returned', 'with_customer' => 'background:#dbeafe;color:#1d4ed8;',
        'delivered', 'picked_up', 'completed', 'returned' => 'background:#dcfce7;color:#166534;',
        'awaiting_verification' => 'background:#fef3c7;color:#b45309;',
        'pickup_pending', 'delivery_pending' => 'background:#fef3c7;color:#b45309;',
        'overdue' => 'background:#fee2e2;color:#b91c1c;',
            default => 'background:#fef3c7;color:#b45309;',
        };
    };
    $itemProgressLabel = fn (?string $status) => match ($status) {
        'partial' => 'Partial',
        'delivered' => 'Delivered',
        'picked_up' => 'Picked Up',
        'partially_delivered' => 'Partially Delivered',
        'partial_return' => 'Partial Pickup',
        'partially_returned' => 'Partially Returned',
        'with_customer' => 'With Customer',
        'pickup_pending' => 'Pickup Pending',
        'delivery_pending' => 'Delivery Pending',
        'returned' => 'Returned',
        'awaiting_verification' => 'Awaiting Verification',
        'completed' => 'Completed',
        default => ucfirst(str_replace('_', ' ', $status ?: 'pending')),
    };

    if ($reminderCallHref) {
        $rentalQuickActions->push([
            'type' => 'link',
            'label' => 'Call',
            'href' => $reminderCallHref,
        ]);
    }

    if ($reminderWhatsappHref) {
        $rentalQuickActions->push([
            'type' => 'link',
            'label' => 'WhatsApp',
            'href' => $reminderWhatsappHref,
            'target' => '_blank',
            'rel' => 'noopener',
            'accent' => true,
        ]);
    }

    if ($rental->canRenew() && $canUpdateRentals) {
        $rentalQuickActions->push([
            'type' => 'button',
            'label' => 'Renew',
            'attributes' => ['data-open-renewal-modal' => true],
        ]);
    }

    if ($pickupRecord && $hasOpenPickupTask) {
        $rentalQuickActions->push([
            'type' => 'link',
            'label' => 'Pickup',
            'href' => route('deliveries.show', $pickupRecord),
        ]);
    } elseif ($canAssignPickup && !$hasOpenPickupTask) {
        $rentalQuickActions->push([
            'type' => 'link',
            'label' => 'Pickup',
            'href' => route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'pickup']),
        ]);
    }

    if (!empty($rentalInvoice)) {
        $rentalQuickActions->push([
            'type' => 'link',
            'label' => 'Invoice',
            'href' => route('invoices.show', $rentalInvoice),
        ]);
    } elseif ($canUpdateRentals) {
        $rentalQuickActions->push([
            'type' => 'form',
            'label' => 'Invoice',
            'action' => route('rentals.invoice', $rental),
            'method' => 'POST',
        ]);
    }

    if ($canCreatePayments && !in_array($rentalInvoiceStatus, ['paid', 'cancelled'], true)) {
        $rentalQuickActions->push([
            'type' => 'link',
            'label' => 'Payment',
            'href' => '#rental-billing-actions',
        ]);
    }

    $rentalQuickActions->push([
        'type' => 'link',
        'label' => 'Timeline',
        'href' => '#rental-activity-timeline',
    ]);

    $rentalMoreActions->push([
        'type' => 'link',
        'label' => 'Add Note',
        'href' => '#rental-activity-timeline',
    ]);
    $rentalMoreActions->push([
        'type' => 'button',
        'label' => 'Add Follow-up',
        'attributes' => [
            'data-open-follow-up-modal' => true,
            'data-follow-up-context' => $rentalFollowUpContextJson,
            'data-follow-up-type' => \App\Models\FollowUp::TYPE_RENEWAL,
            'data-follow-up-priority' => \App\Models\FollowUp::PRIORITY_HIGH,
        ],
    ]);

    if ($deliveryRecord && $hasOpenDeliveryTask && auth()->user()?->canAccessModule('deliveries', 'update')) {
        $rentalMoreActions->push([
            'type' => 'link',
            'label' => 'Assign Delivery',
            'href' => route('deliveries.edit', $deliveryRecord),
        ]);
    } elseif ($canCreateDeliveries && !$hasOpenDeliveryTask && $hasPendingDeliveryItems) {
        $rentalMoreActions->push([
            'type' => 'link',
            'label' => 'Assign Delivery',
            'href' => route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'delivery']),
        ]);
    }

    if ($pickupRecord && $hasOpenPickupTask && auth()->user()?->canAccessModule('deliveries', 'update')) {
        $rentalMoreActions->push([
            'type' => 'link',
            'label' => 'Assign Staff',
            'href' => route('deliveries.edit', $pickupRecord),
        ]);
    } elseif ($canAssignPickup && !$hasOpenPickupTask) {
        $rentalMoreActions->push([
            'type' => 'link',
            'label' => 'Assign Staff',
            'href' => route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'pickup']),
        ]);
    }

    if ($rental->customer_id) {
        $rentalMoreActions->push([
            'type' => 'link',
            'label' => 'View Customer',
            'href' => route('customers.show', $rental->customer_id),
        ]);
    }

    if ($rental->business_partner_id) {
        $rentalMoreActions->push([
            'type' => 'link',
            'label' => 'View Business Partner',
            'href' => route('business-partners.show', $rental->business_partner_id),
        ]);
        $rentalMoreActions->push([
            'type' => 'link',
            'label' => 'View Actual Client',
            'href' => route('business-partners.show', $rental->business_partner_id) . '#actual-clients',
        ]);
    }

    if ($deliveryContactMapUrl) {
        $rentalMoreActions->push([
            'type' => 'link',
            'label' => 'Open Map',
            'href' => $deliveryContactMapUrl,
            'target' => '_blank',
            'rel' => 'noopener',
        ]);
    }

    $rentalInfoItems->push([
        'label' => 'Reminder / Payment',
        'value' => collect([$reminderContactName, $reminderContactPhone])->filter()->implode(' | '),
    ]);

    $rentalInfoItems->push([
        'label' => 'Delivery / Pickup',
        'value' => collect([$deliveryContactName, $deliveryContactPhone])->filter()->implode(' | '),
    ]);

    if ($deliveryContactAddress) {
        $rentalInfoItems->push([
            'label' => 'Service Location',
            'value' => $deliveryContactAddress,
            'href' => $deliveryContactMapUrl,
            'target' => '_blank',
            'rel' => 'noopener',
            'linkLabel' => 'Open Map',
        ]);
    }

    if (filled($rental->referred_by ?? null)) {
        $rentalInfoItems->push([
            'label' => 'Referred By',
            'value' => collect([
                $rental->referred_by,
                filled($rental->referral_source_type ?? null) ? ucfirst(str_replace('_', ' ', $rental->referral_source_type)) : null,
            ])->filter()->implode(' | '),
        ]);
    }

    $mobilePrimaryActions = collect();
    $mobileMoreActions = collect();

    if ($rental->canRenew() && auth()->user()->canAccessModule('rentals', 'update')) {
        $mobilePrimaryActions->push([
            'type' => 'button',
            'label' => 'Renew',
            'attributes' => ['data-open-renewal-modal' => true],
        ]);
    }

    if (!empty($rentalInvoice)) {
        $mobilePrimaryActions->push([
            'type' => 'link',
            'label' => 'Invoice',
            'href' => route('invoices.show', $rentalInvoice),
        ]);
    } elseif (auth()->user()->canAccessModule('rentals', 'update')) {
        $mobilePrimaryActions->push([
            'type' => 'form',
            'label' => 'Invoice',
            'action' => route('rentals.invoice', $rental),
            'method' => 'POST',
        ]);
    }

    if ($canCreatePayments && !in_array($rentalInvoiceStatus, ['paid', 'cancelled'], true)) {
        $mobilePrimaryActions->push([
            'type' => 'link',
            'label' => 'Payment',
            'href' => '#rental-billing-actions',
        ]);
    }

    if ($canUpdateRentals) {
        $mobilePrimaryActions->push([
            'type' => 'link',
            'label' => 'Edit',
            'href' => route('rentals.edit', $rental),
        ]);
    }

    $mobilePrimaryActions = $mobilePrimaryActions->take(2)->values();
    $usedMobileLabels = $mobilePrimaryActions->pluck('label')->all();
    $pushMoreAction = function (array $action) use (&$mobileMoreActions, $usedMobileLabels): void {
        if (in_array($action['label'], $usedMobileLabels, true)) {
            return;
        }

        $mobileMoreActions->push($action);
    };

    $pushMoreAction([
        'type' => 'link',
        'label' => 'Back to Rentals',
        'href' => route('rentals.index'),
    ]);

    if ($canCreatePayments && !in_array($rentalInvoiceStatus, ['paid', 'cancelled'], true)) {
        $pushMoreAction([
            'type' => 'form',
            'label' => 'Mark as Paid',
            'action' => route('rentals.markPaid', $rental),
            'method' => 'POST',
        ]);
        $pushMoreAction([
            'type' => 'link',
            'label' => 'Partial Payment',
            'href' => '#rental-billing-actions',
        ]);
    }

    if ($rental->reminderContactPhone()) {
        $pushMoreAction([
            'type' => 'link',
            'label' => 'Call Customer',
            'href' => 'tel:' . preg_replace('/\D+/', '', $rental->reminderContactPhone()),
        ]);
    }

    if ($canUpdateRentals) {
        $pushMoreAction([
            'type' => 'link',
            'label' => 'Edit Rental',
            'href' => route('rentals.edit', $rental),
        ]);
    }

    if ($deliveryRecord && $hasOpenDeliveryTask && auth()->user()?->canAccessModule('deliveries', 'update')) {
        $pushMoreAction([
            'type' => 'link',
            'label' => 'Edit Delivery Assignment',
            'href' => route('deliveries.edit', $deliveryRecord),
        ]);
    } elseif ($canCreateDeliveries && !$hasOpenDeliveryTask && $hasPendingDeliveryItems) {
        $pushMoreAction([
            'type' => 'link',
            'label' => 'Assign Delivery',
            'href' => route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'delivery']),
        ]);
    }

    if ($pickupRecord && $hasOpenPickupTask && auth()->user()?->canAccessModule('deliveries', 'update')) {
        $pushMoreAction([
            'type' => 'link',
            'label' => 'Edit Pickup Assignment',
            'href' => route('deliveries.edit', $pickupRecord),
        ]);
    } elseif ($canAssignPickup && !$hasOpenPickupTask) {
        $pushMoreAction([
            'type' => 'link',
            'label' => 'Assign Pickup',
            'href' => route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'pickup']),
        ]);
    } elseif ($pickupRecord && $hasOpenPickupTask) {
        $pushMoreAction([
            'type' => 'link',
            'label' => 'View Pickup Task',
            'href' => route('deliveries.show', $pickupRecord),
        ]);
    }

    if ($renewalUrl) {
        $pushMoreAction([
            'type' => 'link',
            'label' => 'WhatsApp Renewal',
            'href' => $renewalUrl,
            'target' => '_blank',
            'rel' => 'noopener',
        ]);
    }

    if ($rental->canRenew() && auth()->user()->canAccessModule('rentals', 'update')) {
        $pushMoreAction([
            'type' => 'button',
            'label' => 'Renew Options',
            'attributes' => ['data-open-renewal-modal' => true],
        ]);
    }

    if ($rental->canBeReturned()) {
        $pushMoreAction([
            'type' => 'form',
            'label' => 'Complete Pickup',
            'action' => route('rentals.return', $rental),
            'method' => 'PUT',
            'confirm' => 'Mark this rental as returned?',
        ]);
    }

    if (auth()->user()->canAccessModule('rentals', 'update') && !in_array($rental->status, ['returned', 'cancelled'], true)) {
        $pushMoreAction([
            'type' => 'form',
            'label' => 'Cancel Rental',
            'action' => route('rentals.cancel', $rental),
            'method' => 'PUT',
            'confirm' => 'Cancel this rental? This keeps the record for audit history.',
            'danger' => true,
        ]);
    }

    if ($canDeleteRentals) {
        $pushMoreAction([
            'type' => 'form',
            'label' => 'Delete Rental',
            'action' => route('rentals.destroy', $rental),
            'method' => 'DELETE',
            'confirm' => 'Delete this rental order permanently? This will be blocked if invoices, payments, deliveries, renewals, or linked sales exist.',
            'danger' => true,
        ]);
    }

    $viewer = auth()->user();
    $canViewFinanceAmounts = $viewer?->canViewFinanceDashboard() ?? false;
    $canSeeRentalFinance = $canViewFinanceAmounts;
    if (!$canViewFinanceAmounts) {
        $rentalInvoiceDue = 0.0;
    }
    $rentalTypeLabel = $rental->usesBusinessPartnerFlow() ? 'Business Partner' : 'Direct Customer';
    $daysUntilEnd = $rental->remainingDaysInclusive(now()->startOfDay());
    $daysRemainingLabel = $rental->customerFacingRemainingLabel(now()->startOfDay());
    $daysRemainingTone = $daysUntilEnd === null
        ? 'neutral'
        : ($daysUntilEnd < 0 ? 'danger' : ($daysUntilEnd <= 2 ? 'warning' : 'success'));
    $rentalStatusTone = $isHotlisted
        ? 'danger'
        : match ($operationalStatus) {
            'active', 'completed' => 'success',
            'returned' => 'info',
            'delivery_pending' => 'warning',
            'cancelled' => 'neutral',
            default => 'warning',
        };
    $invoiceStatusTone = $rentalInvoice
        ? match ($rentalInvoiceStatus) {
            'paid' => 'success',
            'partial' => 'warning',
            'cancelled' => 'neutral',
            default => 'info',
        }
        : 'neutral';
    $paymentStatusTone = match ($rentalInvoiceStatus) {
        'paid' => 'success',
        'partial' => 'warning',
        'cancelled' => 'neutral',
        default => 'danger',
    };
    $deliveryStatusTone = match ($rental->deliveryStatus()) {
        'completed' => 'success',
        'in_progress' => 'warning',
        'pending', 'assigned' => 'info',
        default => 'neutral',
    };
    $pickupStatusTone = match ($rental->pickupStatus()) {
        'picked_up', 'returned', 'completed' => 'success',
        'in_progress' => 'warning',
        'pending', 'assigned' => 'info',
        default => 'neutral',
    };
    $customerAddress = collect([
        $rental->customer?->address,
        $rental->customer?->city,
        $rental->customer?->state,
        $rental->customer?->pincode,
    ])->filter()->implode(', ');
    $partnerBillingAddress = $rental->businessPartner?->billingAddressLine();
    $partnerBillingMeta = collect([
        $rental->businessPartner?->billingStateValue(),
        $rental->businessPartner?->gstin ? 'GSTIN ' . $rental->businessPartner->gstin : null,
    ])->filter()->implode(' - ');
    $paymentStatusLabel = $rentalInvoiceStatus ? ucfirst(str_replace('_', ' ', $rentalInvoiceStatus)) : 'Pending';
    $latestPayment = ($rental->payments ?? collect())->sortByDesc('payment_date')->sortByDesc('id')->first();
    $lifecycleSteps = collect([
        ['label' => 'Created', 'meta' => optional($rental->created_at)->format('d M Y') ?: 'Logged', 'tone' => 'done'],
        ['label' => 'Delivered', 'meta' => $deliveryRecord?->completed_at ? $deliveryRecord->completed_at->format('d M Y') : $deliveryStatusLabel($rental->deliveryStatus()), 'tone' => $rental->deliveryStatus() === 'completed' ? 'done' : ($hasOpenDeliveryTask ? 'active' : 'pending')],
        ['label' => 'Active', 'meta' => ucfirst(str_replace('_', ' ', $operationalStatus)), 'tone' => in_array($operationalStatus, ['active', 'delivery_pending'], true) ? 'active' : ($operationalStatus === 'returned' ? 'done' : ($isHotlisted ? 'problem' : 'pending'))],
        ['label' => 'Return Due', 'meta' => optional($rental->end_date)->format('d M Y') ?: 'Not set', 'tone' => $daysUntilEnd !== null && $daysUntilEnd < 0 ? 'problem' : ($daysUntilEnd !== null && $daysUntilEnd <= 2 ? 'warn' : 'pending')],
        ['label' => 'Completed', 'meta' => $rental->returned_at ? $rental->returned_at->format('d M Y') : 'Pending', 'tone' => $operationalStatus === 'returned' ? 'done' : 'pending'],
    ]);
    $workflowSteps = collect([
        [
            'label' => 'Rental',
            'status' => ucfirst(str_replace('_', ' ', $operationalStatus)),
            'person' => $rental->createdBy->name ?? 'System',
            'scheduled' => optional($rental->start_date)->format('d M Y') ?: 'Not set',
            'completed' => optional($rental->created_at)->format('d M Y h:i A') ?: null,
            'tone' => $isHotlisted ? 'problem' : 'done',
            'action' => $canUpdateRentals ? ['label' => 'Edit', 'href' => route('rentals.edit', $rental)] : null,
        ],
        [
            'label' => 'Delivery',
            'status' => $deliveryStatusLabel($rental->deliveryStatus()),
            'person' => $deliveryAssigneeLabel,
            'scheduled' => $deliveryRecord?->scheduled_at ? $deliveryRecord->scheduled_at->format('d M Y h:i A') : 'Not scheduled',
            'completed' => $deliveryRecord?->completed_at ? $deliveryRecord->completed_at->format('d M Y h:i A') : null,
            'tone' => $rental->deliveryStatus() === 'completed' ? 'done' : ($hasOpenDeliveryTask ? 'active' : 'pending'),
            'action' => $deliveryRecord ? ['label' => 'Open', 'href' => route('deliveries.show', $deliveryRecord)] : (($canCreateDeliveries && $hasPendingDeliveryItems) ? ['label' => 'Assign', 'href' => route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'delivery'])] : null),
        ],
        [
            'label' => 'Active Usage',
            'status' => $daysRemainingLabel,
            'person' => $deliveryContactName,
            'scheduled' => optional($rental->end_date)->format('d M Y') ?: 'Not set',
            'completed' => null,
            'tone' => $isHotlisted ? 'problem' : ($daysUntilEnd !== null && $daysUntilEnd <= 2 ? 'warn' : 'active'),
            'action' => $renewalUrl ? ['label' => 'Reminder', 'href' => $renewalUrl, 'target' => '_blank'] : null,
        ],
        [
            'label' => 'Pickup',
            'status' => ucfirst(str_replace('_', ' ', $rental->pickupStatus() ?: 'pending')),
            'person' => $pickupAssigneeLabel,
            'scheduled' => $pickupRecord?->scheduled_at ? $pickupRecord->scheduled_at->format('d M Y h:i A') : 'Not scheduled',
            'completed' => $pickupRecord?->completed_at ? $pickupRecord->completed_at->format('d M Y h:i A') : null,
            'tone' => in_array($rental->pickupStatus(), ['completed', 'picked_up', 'returned'], true) ? 'done' : ($hasOpenPickupTask ? 'active' : ($isHotlisted ? 'problem' : 'pending')),
            'action' => $pickupRecord ? ['label' => 'Open', 'href' => route('deliveries.show', $pickupRecord)] : ($canAssignPickup ? ['label' => 'Schedule', 'href' => route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'pickup'])] : null),
        ],
        [
            'label' => 'Return Verification',
            'status' => $operationalStatus === 'returned' ? 'Returned' : 'Pending',
            'person' => $pickupAssigneeLabel,
            'scheduled' => $rental->returned_at ? $rental->returned_at->format('d M Y h:i A') : 'After pickup',
            'completed' => $rental->returned_at ? $rental->returned_at->format('d M Y h:i A') : null,
            'tone' => $operationalStatus === 'returned' ? 'done' : 'pending',
            'action' => $rental->canBeReturned() ? ['label' => 'Return', 'form' => true] : null,
        ],
    ]);
    $timelinePreview = ($activityLogs ?? collect())->take(10);
    $renewalSummaryStatus = $rental->canRenew()
        ? ($daysUntilEnd !== null && $daysUntilEnd < 0 ? abs($daysUntilEnd) . ' days overdue' : 'Renewal available')
        : 'Renewal closed';
    $lastRenewal = $renewalHistory->first();
    $latestRenewalForWarning = $renewalHistory->first();
    $showDuplicateRenewalWarning = $latestRenewalForWarning
        && $latestRenewalForWarning->canBeEdited()
        && optional($latestRenewalForWarning->renewed_end_date)?->greaterThan(now()->startOfDay());
@endphp

<style>
    .detail-page { display:grid; gap:12px; padding:12px 16px 20px; }
    .detail-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
    .detail-header h1 { margin:0; font-size:24px; color:#0f172a; line-height:1.08; }
    .detail-header p { margin:4px 0 0; color:#64748b; font-size:11.5px; line-height:1.45; }
    .detail-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .mobile-inline-actions { display:none; }
    .ph-rental-reference-shell {
        display: grid;
        gap: 12px;
        min-width: 0;
        max-width: 100%;
    }
    .ph-rental-reference-shell > * {
        min-width: 0;
        max-width: 100%;
    }
    .ph-rental-hero-tools {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        justify-content: flex-end;
    }
    .ph-rental-hero-tools .detail-btn-secondary,
    .ph-rental-hero-tools .detail-btn-danger {
        min-height: 38px;
    }
    .ph-rental-overview-grid {
        display: grid;
        grid-template-columns: minmax(0, 1.3fr) minmax(320px, .9fr);
        gap: 10px;
    }
    .ph-rental-summary-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }
    .ph-rental-action-links {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        min-width:0;
    }
    .ph-rental-detail-list {
        display: grid;
        gap: 10px;
    }
    .ph-rental-detail-row {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 10px;
        padding-bottom: 10px;
        border-bottom: 1px solid #eef2f7;
    }
    .ph-rental-detail-row:last-child {
        padding-bottom: 0;
        border-bottom: none;
    }
    .ph-rental-detail-row span {
        color: #64748b;
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .05em;
    }
    .ph-rental-detail-row strong {
        color: #0f172a;
        font-size: 13px;
        font-weight: 700;
        line-height: 1.5;
        text-align: right;
        overflow-wrap: anywhere;
    }
    .rental-finance-panel {
        display: grid;
        gap: 10px;
    }
    .rental-finance-kpis {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 8px;
    }
    .rental-finance-kpi {
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 10px;
        background: #f8fafc;
        min-width: 0;
    }
    .rental-finance-kpi span {
        display: block;
        color: #64748b;
        font-size: 10px;
        font-weight: 900;
        letter-spacing: .06em;
        text-transform: uppercase;
    }
    .rental-finance-kpi strong {
        display: block;
        margin-top: 6px;
        color: #0f172a;
        font-size: 18px;
        line-height: 1.1;
        overflow-wrap: anywhere;
    }
    .rental-finance-kpi small {
        display: block;
        margin-top: 5px;
        color: #64748b;
        font-size: 11px;
        line-height: 1.35;
    }
    .rental-finance-kpi.is-due {
        background: #fff7ed;
        border-color: #fed7aa;
    }
    .rental-finance-kpi.is-paid {
        background: #ecfdf5;
        border-color: #bbf7d0;
    }
    .rental-finance-progress {
        height: 8px;
        border-radius: 999px;
        background: #e2e8f0;
        overflow: hidden;
    }
    .rental-finance-progress i {
        display: block;
        height: 100%;
        border-radius: inherit;
        background: linear-gradient(90deg, #4f46e5, #22c55e);
    }
    .rental-finance-source {
        color: #64748b;
        font-size: 11px;
        line-height: 1.4;
    }
    .ph-rental-section-grid {
        display: grid;
        grid-template-columns: repeat(12, minmax(0, 1fr));
        gap: 10px;
    }
    .ph-rental-section-grid > .ph-rental-span-4 { grid-column: span 4; }
    .ph-rental-section-grid > .ph-rental-span-5 { grid-column: span 5; }
    .ph-rental-section-grid > .ph-rental-span-6 { grid-column: span 6; }
    .ph-rental-section-grid > .ph-rental-span-7 { grid-column: span 7; }
    .ph-rental-section-grid > .ph-rental-span-12 { grid-column: span 12; }
    .ph-rental-contact-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 10px;
    }
    .ph-rental-contact-stack {
        display: grid;
        gap: 10px;
    }
    .ph-rental-asset-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 8px;
    }
    .ph-rental-asset-tile {
        display: grid;
        gap: 6px;
        padding: 11px;
        border: 1px solid #e2e8f0;
        border-radius: 14px;
        background: #fff;
    }
    .ph-rental-asset-tile span {
        color: #64748b;
        font-size: 11px;
        font-weight: 800;
        text-transform: uppercase;
        letter-spacing: .05em;
    }
    .ph-rental-asset-tile strong {
        color: #0f172a;
        font-size: 13px;
        line-height: 1.5;
        overflow-wrap: anywhere;
    }
    .ph-rental-inline-toolbar {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
        min-width:0;
    }
    .ph-rental-notes-shell {
        display: grid;
        gap: 10px;
    }
    .rental-mobile-actions {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:6px;
        align-items:stretch;
        grid-auto-rows:minmax(40px, auto);
    }
    .rental-mobile-actions > *,
    .rental-mobile-actions form,
    .rental-mobile-actions button,
    .rental-mobile-actions a {
        min-width:0;
    }
    .rental-mobile-actions .mobile-actions-menu {
        width:100%;
    }
    .rental-mobile-actions .mobile-actions-menu summary,
    .rental-mobile-actions > a,
    .rental-mobile-actions > button,
    .rental-mobile-actions > form > button {
        width:100%;
        min-height:40px;
        justify-content:center;
        font-size:11.5px;
        font-weight:800;
        line-height:1.2;
        padding:8px 10px;
        text-align:center;
        white-space:normal;
    }
    .mobile-actions-menu summary {
        list-style:none;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-height:44px;
        min-width:44px;
        padding:10px 12px;
        border-radius:13px;
        border:1px solid #cbd5e1;
        background:#fff;
        color:#0f172a;
        font-size:12px;
        font-weight:800;
        cursor:pointer;
    }
    .mobile-actions-menu summary::-webkit-details-marker { display:none; }
    .mobile-actions-menu[open] summary { border-color:#93c5fd; box-shadow:0 0 0 3px rgba(37,99,235,.10); }
    .mobile-actions-panel {
        position:absolute;
        right:0;
        bottom:calc(100% + 8px);
        width:min(280px, calc(100vw - 28px));
        display:grid;
        gap:6px;
        padding:10px;
        border:1px solid #dbe3ef;
        border-radius:16px;
        background:#fff;
        box-shadow:0 18px 40px rgba(15,23,42,.18);
        z-index:8;
    }
    .mobile-actions-panel a,
    .mobile-actions-panel button {
        width:100%;
        justify-content:flex-start;
        min-height:42px;
        padding:10px 12px;
        border-radius:12px;
        border:1px solid #e2e8f0;
        background:#fff;
        color:#0f172a;
        font-size:13px;
        font-weight:700;
        text-decoration:none;
    }
    .mobile-actions-panel .is-danger {
        color:#991b1b;
        background:#fff1f2;
        border-color:#fecaca;
    }
    .rental-cta-shell {
        position:sticky;
        bottom:16px;
        z-index:24;
        margin-top:8px;
    }
    .rental-cta-bar {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:14px;
        flex-wrap:wrap;
        padding:14px 16px;
        border:1px solid #dbe3ef;
        border-radius:20px;
        background:rgba(255,255,255,.96);
        backdrop-filter:blur(14px);
        box-shadow:0 18px 42px rgba(15,23,42,.12);
    }
    .rental-cta-meta {
        display:grid;
        gap:4px;
        min-width:220px;
    }
    .rental-cta-eyebrow {
        color:#64748b;
        font-size:11px;
        font-weight:800;
        text-transform:uppercase;
        letter-spacing:.05em;
    }
    .rental-cta-title {
        color:#0f172a;
        font-size:16px;
        font-weight:800;
        line-height:1.2;
    }
    .rental-cta-subtitle {
        color:#64748b;
        font-size:12px;
        line-height:1.45;
    }
    .rental-cta-actions {
        display:flex;
        align-items:center;
        justify-content:flex-end;
        gap:6px;
        flex-wrap:wrap;
        flex:1 1 520px;
    }
    .rental-cta-actions > form,
    .rental-cta-actions > a,
    .rental-cta-actions > button,
    .rental-cta-actions > details {
        margin:0;
        min-width:0;
    }
    .rental-cta-primary {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:6px;
        min-height:38px;
        padding:8px 12px;
        border-radius:14px;
        background:#0f172a;
        border:1px solid #0f172a;
        color:#fff;
        text-decoration:none;
        font-size:12px;
        font-weight:800;
        line-height:1.2;
        box-shadow:0 12px 26px rgba(15,23,42,.18);
    }
    .rental-cta-primary:hover {
        transform:translateY(-1px);
        box-shadow:0 16px 30px rgba(15,23,42,.22);
    }
    .rental-cta-more {
        position:relative;
    }
    .rental-cta-more summary {
        list-style:none;
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-height:42px;
        padding:10px 14px;
        border-radius:14px;
        border:1px solid #cbd5e1;
        background:#fff;
        color:#0f172a;
        font-size:13px;
        font-weight:800;
        cursor:pointer;
    }
    .rental-cta-more summary::-webkit-details-marker { display:none; }
    .rental-cta-more[open] summary {
        border-color:#93c5fd;
        box-shadow:0 0 0 3px rgba(37,99,235,.10);
    }
    .rental-cta-panel {
        position:absolute;
        right:0;
        bottom:calc(100% + 10px);
        width:min(260px, calc(100vw - 32px));
        display:grid;
        gap:6px;
        padding:10px;
        border:1px solid #dbe3ef;
        border-radius:16px;
        background:#fff;
        box-shadow:0 20px 42px rgba(15,23,42,.18);
    }
    .rental-cta-panel a,
    .rental-cta-panel button {
        width:100%;
        justify-content:flex-start;
        min-height:40px;
        padding:10px 12px;
        border-radius:12px;
        border:1px solid #e2e8f0;
        background:#fff;
        color:#0f172a;
        text-decoration:none;
        font-size:13px;
        font-weight:700;
    }
    .rental-cta-panel .is-danger {
        color:#991b1b;
        border-color:#fecaca;
        background:#fff1f2;
    }
    .mobile-charges-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:10px 14px;
    }
    .mobile-charge-row {
        display:flex;
        justify-content:space-between;
        gap:10px;
        padding:8px 0;
        border-bottom:1px solid #eef2f7;
        font-size:13px;
        color:#334155;
    }
    .mobile-charge-row strong { color:#0f172a; }
    .charge-summary-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:12px;
    }
    .charge-summary-tile {
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:#fcfdff;
        padding:12px;
        min-width:0;
    }
    .charge-summary-tile--total {
        grid-column:1 / -1;
        background:#f8fafc;
    }
    .charge-summary-tile .charge-label {
        display:block;
        font-size:11px;
        color:#64748b;
        font-weight:700;
        text-transform:uppercase;
        letter-spacing:.04em;
        margin-bottom:6px;
    }
    .charge-summary-tile .charge-value {
        color:#0f172a;
        font-size:17px;
        font-weight:800;
        line-height:1.25;
        overflow-wrap:anywhere;
    }
    .charge-summary-note {
        margin:0 0 12px;
        color:#64748b;
        font-size:12px;
        line-height:1.5;
    }
    .booking-snapshot-grid {
        display:grid;
        grid-template-columns:repeat(12, minmax(0, 1fr));
        gap:12px;
        margin-top:14px;
    }
    .booking-snapshot-card {
        grid-column:span 3;
        display:grid;
        gap:6px;
        min-width:0;
        padding:14px;
        border:1px solid #e2e8f0;
        border-radius:14px;
        background:linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
    }
    .booking-snapshot-card.is-range,
    .booking-snapshot-card.is-items,
    .booking-snapshot-card.is-total,
    .booking-snapshot-card.is-balance,
    .booking-snapshot-card.is-status {
        grid-column:span 6;
    }
    .booking-snapshot-card.is-items {
        grid-column:span 12;
    }
    .booking-snapshot-items {
        display:grid;
        gap:8px;
    }
    .booking-snapshot-item {
        display:flex;
        justify-content:space-between;
        gap:12px;
        padding:8px 0;
        border-top:1px solid #e2e8f0;
        color:#0f172a;
        font-size:13px;
    }
    .booking-snapshot-item:first-child {
        border-top:none;
    }
    .booking-snapshot-item strong,
    .booking-snapshot-item span {
        overflow-wrap:anywhere;
    }
    .booking-snapshot-card .snapshot-label {
        display:block;
        color:#64748b;
        font-size:11px;
        font-weight:800;
        text-transform:uppercase;
        letter-spacing:.05em;
    }
    .booking-snapshot-card .snapshot-value {
        color:#0f172a;
        font-size:18px;
        font-weight:800;
        line-height:1.3;
        overflow-wrap:anywhere;
    }
    .booking-snapshot-card .snapshot-subtle {
        color:#64748b;
        font-size:12px;
        line-height:1.45;
    }
    .booking-snapshot-card.is-range .snapshot-value {
        font-size:16px;
        line-height:1.45;
    }
    .booking-snapshot-card.is-total {
        background:#f8fafc;
    }
    .booking-snapshot-card.is-balance {
        background:#fffdf7;
    }
    .booking-snapshot-card.is-status {
        align-content:start;
    }
    .detail-btn, .detail-btn-secondary, .detail-btn-danger {
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        border-radius:10px; padding:8px 12px; font-size:13px; font-weight:600; text-decoration:none;
        border:1px solid transparent; cursor:pointer;
    }
    .detail-btn { background:#0f172a; color:#fff; }
    .detail-btn-secondary { background:#fff; color:#334155; border-color:#cbd5e1; }
    .detail-btn-danger { background:#dc2626; color:#fff; }
    .detail-card { background:#fff; border:1px solid #dbe3ef; border-radius:14px; padding:16px; box-shadow:0 8px 24px rgba(15, 23, 42, 0.04); min-width:0; max-width:100%; overflow-x:clip; }
    .detail-card > *,
    .detail-card h2,
    .detail-card p,
    .detail-card span,
    .detail-card strong,
    .detail-card a,
    .detail-card button {
        min-width:0;
        max-width:100%;
        overflow-wrap:anywhere;
    }
    .detail-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:14px; min-width:0; }
    .span-4 { grid-column:span 4; }
    .span-6 { grid-column:span 6; }
    .span-12 { grid-column:span 12; }
    .label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:5px; }
    .value { color:#0f172a; font-size:14px; line-height:1.6; }
    .metric-grid { display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px; }
    .metric-box { border:1px solid #e2e8f0; border-radius:12px; padding:12px; background:#f8fafc; }
    .metric-box .label { margin-bottom:6px; }
    .metric-box strong { font-size:20px; color:#0f172a; }
    .status-badge { display:inline-flex; align-items:center; padding:5px 10px; border-radius:999px; font-size:11px; font-weight:700; letter-spacing:.03em; text-transform:uppercase; }
    .asset-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:10px; }
    .asset-box { border:1px solid #dbe3ef; border-radius:12px; padding:12px; background:#fcfdff; }
    .timeline { display:grid; gap:10px; }
    .timeline-item { border:1px solid #e2e8f0; border-radius:12px; padding:12px; }
    .item-progress-table { width:100%; border-collapse:separate; border-spacing:0; }
    .item-progress-table th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#64748b; padding:10px 12px; border-bottom:1px solid #e2e8f0; }
    .item-progress-table td { padding:12px; border-bottom:1px solid #eef2f7; vertical-align:top; }
    .item-progress-form { display:flex; gap:8px; flex-wrap:wrap; align-items:end; }
    .item-progress-form input, .item-progress-form textarea { border:1px solid #cbd5e1; border-radius:10px; padding:8px 10px; font-size:13px; }
    .item-progress-form input[type="number"] { width:88px; }
    .item-progress-form textarea { width:180px; min-height:38px; resize:vertical; }
    .item-progress-meta { display:grid; gap:4px; color:#64748b; font-size:12px; }
    .item-progress-inline-label { display:none; }
    .ops-muted { color:#64748b; font-size:12px; }
    .billing-form-grid {
        display:grid;
        grid-template-columns:repeat(4,minmax(0,1fr));
        gap:10px;
        margin-top:14px;
    }
    .rental-status-grid {
        display:grid;
        grid-template-columns:repeat(3, minmax(0, 1fr));
        gap:12px;
    }
    .status-card {
        border:1px solid #e2e8f0;
        border-radius:12px;
        padding:12px;
        background:#f8fafc;
    }
    .status-card .label { margin-bottom:6px; }
    .payment-history-list {
        display:grid;
        gap:8px;
        margin-top:14px;
    }
    .payment-history-card {
        display:grid;
        grid-template-columns:130px minmax(0, 1fr) 130px auto;
        gap:10px;
        align-items:center;
        border:1px solid #e2e8f0;
        border-radius:12px;
        padding:10px 12px;
        background:#fcfdff;
    }
    .rental-product-line {
        display:grid;
        grid-template-columns:72px minmax(0, 1.8fr) 110px 140px;
        gap:12px;
        align-items:start;
    }
    .renewal-highlight { border-color:#fde68a; background:linear-gradient(180deg, #fff 0%, #fffbeb 100%); }
    .renewal-history { display:grid; gap:10px; }
    .renewal-entry { border:1px solid #e2e8f0; border-radius:12px; padding:12px; background:#fcfdff; }
    .renewal-modal {
        position:fixed; inset:0; background:rgba(15,23,42,0.45); display:none; align-items:center; justify-content:center;
        padding:12px; z-index:60; overflow-y:auto; overflow-x:hidden;
    }
    .renewal-modal.is-open { display:flex; }
    .renewal-modal-panel {
        width:100%; max-width:720px; max-height:min(90vh, calc(100dvh - 24px)); display:flex; flex-direction:column; overflow:hidden; background:#fff; border-radius:18px;
        border:1px solid #dbe3ef; box-shadow:0 24px 60px rgba(15,23,42,0.2);
    }
    .renewal-modal-panel form {
        flex:1 1 auto;
        min-height:0;
        display:flex;
        flex-direction:column;
        overflow:hidden;
    }
    .renewal-modal-head, .renewal-modal-foot { flex:0 0 auto; display:flex; justify-content:space-between; align-items:center; gap:10px; padding:14px 16px; border-bottom:1px solid #e2e8f0; background:#fff; }
    .renewal-modal-foot { border-bottom:none; border-top:1px solid #e2e8f0; justify-content:flex-end; flex-wrap:wrap; }
    .renewal-modal-body { flex:1 1 auto; min-height:0; overflow-y:auto; overflow-x:hidden; -webkit-overflow-scrolling:touch; overscroll-behavior:contain; }
    .renewal-form-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; padding:16px; }
    .renewal-field { display:grid; gap:6px; }
    .renewal-field label { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; }
    .renewal-field input, .renewal-field select, .renewal-field textarea {
        width:100%; min-height:38px; border:1px solid #cbd5e1; border-radius:10px; padding:8px 10px; font-size:13px; color:#0f172a; background:#fff;
    }
    .renewal-field textarea { min-height:86px; resize:vertical; }
    .renewal-span-2 { grid-column:span 2; }
    @media (max-width: 920px) {
        .ph-rental-overview-grid,
        .ph-rental-contact-grid {
            grid-template-columns: 1fr;
        }
        .ph-rental-section-grid > .ph-rental-span-4,
        .ph-rental-section-grid > .ph-rental-span-5,
        .ph-rental-section-grid > .ph-rental-span-6,
        .ph-rental-section-grid > .ph-rental-span-7 {
            grid-column: span 12;
        }
        .span-4, .span-6 { grid-column:span 12; }
        .metric-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .booking-snapshot-card { grid-column:span 4; }
        .booking-snapshot-card.is-range,
        .booking-snapshot-card.is-items,
        .booking-snapshot-card.is-total,
        .booking-snapshot-card.is-balance,
        .booking-snapshot-card.is-status { grid-column:span 6; }
        .booking-snapshot-card.is-items { grid-column:span 12; }
        .renewal-form-grid { grid-template-columns:1fr; }
        .renewal-span-2 { grid-column:span 1; }
        .item-progress-table,
        .item-progress-table tbody,
        .item-progress-table td { display:block; width:100%; }
        .item-progress-table thead { display:none; }
        .item-progress-table tr {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:0;
            border:1px solid #e2e8f0;
            border-radius:12px;
            margin-bottom:10px;
            overflow:hidden;
            background:#fff;
        }
        .item-progress-table td {
            position:relative;
            padding:12px;
            border-bottom:1px solid #eef2f7;
            border-right:1px solid #eef2f7;
            min-width:0;
        }
        .item-progress-table td:nth-child(1),
        .item-progress-table td:nth-child(7),
        .item-progress-table td:nth-child(8) {
            grid-column:1 / -1;
        }
        .item-progress-table td:nth-child(2n) {
            border-right:none;
        }
        .item-progress-table td:nth-child(7),
        .item-progress-table td:nth-child(8) {
            border-right:none;
        }
        .item-progress-table td::before {
            content:attr(data-label);
            position:absolute;
            top:10px;
            left:12px;
            color:#64748b;
            font-size:10px;
            font-weight:800;
            letter-spacing:.05em;
            text-transform:uppercase;
        }
        .item-progress-table td:last-child { border-bottom:none; }
        .item-progress-inline-label {
            display:block;
            margin-bottom:6px;
            color:#64748b;
            font-size:10px;
            font-weight:800;
            letter-spacing:.05em;
            text-transform:uppercase;
        }
        .item-progress-table td::before {
            content:none;
        }
        .item-progress-table td:first-child {
            padding:14px 12px;
        }
        .item-progress-table td:first-child .value {
            font-size:17px;
            line-height:1.35;
        }
        .item-progress-table td:first-child .item-progress-meta {
            margin-top:6px;
        }
        .item-progress-table td:nth-child(7) {
            background:#fcfdff;
        }
        .item-progress-table td:nth-child(7) > div {
            grid-template-columns:repeat(2, minmax(0, 1fr));
            display:grid !important;
            gap:8px !important;
        }
        .item-progress-table td:nth-child(7) .status-badge {
            justify-content:center;
            text-align:center;
            width:100%;
        }
        .item-progress-table td:nth-child(8) > div {
            gap:8px !important;
        }
        .item-progress-table .item-progress-form {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:8px;
        }
        .item-progress-table .item-progress-form > div:last-of-type {
            grid-column:1 / -1;
        }
        .item-progress-table .item-progress-form button {
            grid-column:1 / -1;
            width:100%;
            justify-content:center;
        }
    }
    @media (max-width: 640px) {
        .ph-rental-summary-grid {
            grid-template-columns: 1fr;
        }
        .rental-finance-kpis {
            grid-template-columns: 1fr;
        }
        .ph-rental-detail-row {
            flex-direction: column;
        }
        .ph-rental-detail-row strong {
            text-align: left;
        }
        .detail-page { padding:14px; }
        .ph-rental-reference-shell {
            gap: 10px;
        }
        .ph-rental-reference-shell .ph-operational-header {
            gap: 10px;
            padding-bottom: 2px;
        }
        .ph-rental-reference-shell .ph-operational-header__main {
            width: 100%;
            gap: 6px;
        }
        .ph-rental-reference-shell .ph-operational-header__back {
            font-size: 12px;
        }
        .ph-rental-reference-shell .ph-operational-header h1 {
            font-size: 20px;
            line-height: 1.08;
            letter-spacing: -.03em;
        }
        .ph-rental-reference-shell .ph-operational-header p {
            font-size: 11.5px;
            line-height: 1.45;
        }
        .ph-rental-reference-shell .ph-operational-header__meta {
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 8px 10px;
        }
        .ph-rental-reference-shell .ph-operational-header__meta-item strong {
            font-size: 12px;
            line-height: 1.35;
        }
        .ph-rental-reference-shell .ph-operational-header__chips,
        .ph-rental-reference-shell .ph-operational-header__slot {
            width: 100%;
        }
        .ph-rental-hero-tools {
            width: 100%;
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 6px;
        }
        .ph-rental-hero-tools > *,
        .ph-rental-hero-tools form {
            min-width: 0;
            width: 100%;
            margin: 0;
        }
        .ph-rental-hero-tools .detail-btn-secondary,
        .ph-rental-hero-tools .detail-btn-danger {
            width: 100%;
            min-height: 36px;
            padding: 7px 10px;
            font-size: 11.5px;
            justify-content: center;
            text-align: center;
            white-space: nowrap;
        }
        .metric-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .detail-actions { display:none; }
        .mobile-inline-actions { display:none; }
        .renewal-modal { align-items:flex-end; padding:8px; }
        .renewal-modal-panel { max-width:100%; max-height:min(92vh, calc(100dvh - 16px)); border-radius:18px; }
        .renewal-modal-head, .renewal-modal-foot { padding:12px 14px; }
        .renewal-modal-foot { display:grid; grid-template-columns:1fr; }
        .renewal-modal-foot .detail-btn,
        .renewal-modal-foot .detail-btn-secondary { width:100%; justify-content:center; }
        .mobile-charges-grid {
            grid-template-columns:1fr;
            gap:0;
        }
        .booking-snapshot-grid {
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:10px;
        }
        .booking-snapshot-card,
        .booking-snapshot-card.is-items,
        .booking-snapshot-card.is-total,
        .booking-snapshot-card.is-balance,
        .booking-snapshot-card.is-status {
            grid-column:span 1;
            padding:12px;
        }
        .booking-snapshot-card.is-range,
        .booking-snapshot-card.is-items,
        .booking-snapshot-card.is-total,
        .booking-snapshot-card.is-balance,
        .booking-snapshot-card.is-status {
            grid-column:1 / -1;
        }
        .booking-snapshot-card .snapshot-value {
            font-size:16px;
        }
        .booking-snapshot-card.is-range .snapshot-value {
            font-size:15px;
        }
        .charge-summary-grid {
            grid-template-columns:1fr;
            gap:10px;
        }
        .detail-grid {
            grid-template-columns:1fr;
            padding-bottom:calc(112px + env(safe-area-inset-bottom, 0px));
        }
        .span-4,
        .span-6,
        .span-12 {
            grid-column:span 1;
        }
        .charge-summary-tile {
            padding:10px;
        }
        .charge-summary-tile .charge-value {
            font-size:15px;
        }
        .billing-form-grid {
            grid-template-columns:1fr;
        }
        .detail-page {
            padding-bottom:16px;
        }
        .rental-status-grid,
        .payment-history-card,
        .rental-product-line {
            grid-template-columns:repeat(2, minmax(0, 1fr));
        }
        .payment-history-card > div:nth-child(2),
        .payment-history-card form,
        .rental-product-line > div:nth-child(2) {
            grid-column:1 / -1;
        }
        #rental-billing-actions .detail-btn,
        #rental-billing-actions .detail-btn-secondary {
            min-height:36px;
            padding:6px 9px;
            font-size:11.5px;
        }
        .rental-cta-shell {
            position:fixed;
            left:12px;
            right:12px;
            bottom:calc(var(--ph-mobile-actions-offset, 10px) + 4px);
            margin-top:0;
            z-index:44;
        }
        .rental-cta-bar {
            padding:12px;
            border-radius:18px;
        }
        .rental-cta-meta {
            min-width:0;
            width:100%;
        }
        .rental-cta-title {
            font-size:15px;
        }
        .rental-cta-actions {
            width:100%;
            justify-content:flex-start;
            gap:6px;
        }
        .rental-cta-actions > form,
        .rental-cta-actions > a,
        .rental-cta-actions > details,
        .rental-cta-actions > button {
            flex:1 1 calc(50% - 6px);
        }
        .rental-cta-actions .rental-cta-more {
            flex:1 1 calc(50% - 6px);
        }
        .rental-cta-primary,
        .rental-cta-more summary,
        .rental-cta-actions .detail-btn,
        .rental-cta-actions .detail-btn-secondary {
            width:100%;
            min-height:38px;
            justify-content:center;
            padding:7px 9px;
            font-size:11.5px;
            line-height:1.2;
        }
        .ph-rental-action-links,
        .ph-rental-inline-toolbar {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:6px;
            width:100%;
        }
        .ph-rental-action-links > *,
        .ph-rental-inline-toolbar > *,
        .ph-rental-action-links form,
        .ph-rental-inline-toolbar form {
            min-width:0;
            width:100%;
        }
        .ph-rental-action-links .detail-btn,
        .ph-rental-action-links .detail-btn-secondary,
        .ph-rental-inline-toolbar .detail-btn,
        .ph-rental-inline-toolbar .detail-btn-secondary {
            width:100%;
            min-height:36px;
            padding:6px 8px;
            font-size:11.5px;
            line-height:1.2;
            justify-content:center;
            text-align:center;
            white-space:normal;
        }
        .rental-cta-panel {
            right:0;
            left:auto;
            bottom:calc(100% + 8px);
            width:min(280px, calc(100vw - 24px));
        }
        .ph-rental-section-nav {
            position: static;
            top: auto;
            margin: -2px 0 8px;
            padding: 0;
            border: 0;
            background: transparent;
            box-shadow: none;
            backdrop-filter: none;
            overflow: hidden;
        }
        .ph-rental-section-nav .ph-section-nav {
            width: 100%;
            max-width: 100%;
            padding: 4px;
            gap: 6px;
            border-radius: 16px;
            justify-content: flex-start;
            overflow-x: auto;
            -webkit-overflow-scrolling: touch;
            scroll-padding-inline: 4px;
        }
        .ph-rental-section-nav .ph-section-nav-link {
            min-height: 30px;
            padding: 6px 10px;
            font-size: 10.5px;
        }
    }
    .rcc-shell {
        display:grid;
        gap:12px;
    }
    .rcc-card {
        background:#fff;
        border:1px solid #dbe3ef;
        border-radius:16px;
        box-shadow:0 10px 28px rgba(15, 23, 42, .05);
        min-width:0;
        overflow:hidden;
    }
    .rcc-pad { padding:14px; }
    .rcc-command-grid {
        display:grid;
        grid-template-columns:minmax(0, 1.05fr) minmax(280px, 1.35fr) minmax(260px, .95fr);
        gap:12px;
        align-items:stretch;
    }
    .rcc-panel {
        border:1px solid #e2e8f0;
        border-radius:14px;
        background:#f8fafc;
        padding:12px;
        min-width:0;
    }
    .rcc-eyebrow {
        color:#64748b;
        font-size:11px;
        font-weight:900;
        letter-spacing:.06em;
        text-transform:uppercase;
    }
    .rcc-title {
        margin:6px 0 0;
        color:#0f172a;
        font-size:20px;
        line-height:1.15;
        font-weight:900;
        overflow-wrap:anywhere;
    }
    .rcc-meta {
        display:grid;
        gap:5px;
        margin-top:10px;
        color:#64748b;
        font-size:12px;
        line-height:1.35;
    }
    .rcc-meta strong { color:#334155; }
    .rcc-chip {
        display:inline-flex;
        align-items:center;
        width:max-content;
        max-width:100%;
        border-radius:999px;
        padding:4px 8px;
        font-size:11px;
        font-weight:800;
        background:#eef2ff;
        color:#4338ca;
        line-height:1.1;
    }
    .rcc-chip.done { background:#dcfce7; color:#166534; }
    .rcc-chip.active { background:#dbeafe; color:#1d4ed8; }
    .rcc-chip.warn { background:#fef3c7; color:#92400e; }
    .rcc-chip.problem { background:#fee2e2; color:#b91c1c; }
    .rcc-chip.pending { background:#f1f5f9; color:#475569; }
    .rcc-lifecycle {
        display:grid;
        grid-template-columns:repeat(5, minmax(0, 1fr));
        gap:8px;
    }
    .rcc-step {
        position:relative;
        display:grid;
        gap:6px;
        min-width:0;
        padding:10px;
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:#fff;
    }
    .rcc-step strong {
        color:#0f172a;
        font-size:12px;
        line-height:1.2;
    }
    .rcc-step span:not(.rcc-chip) {
        color:#64748b;
        font-size:11px;
        line-height:1.3;
        overflow-wrap:anywhere;
    }
    .rcc-finance-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:8px;
        margin-top:10px;
    }
    .rcc-money {
        display:grid;
        gap:4px;
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:#fff;
        padding:9px;
        min-width:0;
    }
    .rcc-money span {
        color:#64748b;
        font-size:10px;
        font-weight:900;
        text-transform:uppercase;
        letter-spacing:.05em;
    }
    .rcc-money strong {
        color:#0f172a;
        font-size:16px;
        line-height:1.15;
        overflow-wrap:anywhere;
    }
    .rcc-actions {
        display:flex;
        flex-wrap:wrap;
        gap:8px;
        align-items:center;
    }
    .rcc-actions form { margin:0; }
    .rcc-action-more {
        margin-left:auto;
        position:relative;
    }
    .rcc-more-menu {
        position:absolute;
        right:0;
        top:calc(100% + 6px);
        z-index:20;
        display:none;
        min-width:220px;
        padding:8px;
        border:1px solid #dbe3ef;
        border-radius:12px;
        background:#fff;
        box-shadow:0 16px 32px rgba(15, 23, 42, .14);
    }
    .rcc-action-more:hover .rcc-more-menu,
    .rcc-action-more:focus-within .rcc-more-menu {
        display:grid;
        gap:6px;
    }
    .rcc-more-menu a,
    .rcc-more-menu button {
        display:flex;
        width:100%;
        justify-content:flex-start;
        border:0;
        background:#f8fafc;
        border-radius:8px;
        padding:8px 9px;
        color:#334155;
        font-size:12px;
        font-weight:800;
        text-decoration:none;
        cursor:pointer;
    }
    .rcc-section-head {
        display:flex;
        justify-content:space-between;
        gap:10px;
        align-items:flex-start;
        padding:14px 14px 0;
    }
    .rcc-section-head h2 {
        margin:0;
        color:#0f172a;
        font-size:17px;
        line-height:1.2;
    }
    .rcc-section-head p {
        margin:4px 0 0;
        color:#64748b;
        font-size:12px;
        line-height:1.4;
    }
    .rcc-workflow {
        display:grid;
        grid-template-columns:repeat(5, minmax(0, 1fr));
        gap:8px;
        padding:14px;
    }
    .rcc-workflow-item {
        display:grid;
        gap:7px;
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:#fff;
        padding:10px;
        min-width:0;
    }
    .rcc-workflow-item strong {
        color:#0f172a;
        font-size:13px;
        line-height:1.2;
    }
    .rcc-workflow-item small {
        color:#64748b;
        font-size:11px;
        line-height:1.35;
        overflow-wrap:anywhere;
    }
    .rcc-workflow-item a,
    .rcc-workflow-item button {
        width:max-content;
        max-width:100%;
        border:1px solid #c7d2fe;
        border-radius:8px;
        background:#eef2ff;
        color:#4338ca;
        padding:6px 8px;
        font-size:11px;
        font-weight:900;
        text-decoration:none;
        cursor:pointer;
    }
    .rcc-two-col {
        display:grid;
        grid-template-columns:minmax(0, 1.25fr) minmax(320px, .75fr);
        gap:12px;
    }
    .rcc-product-list {
        display:grid;
        gap:8px;
        padding:14px;
    }
    .rcc-product-row {
        display:grid;
        grid-template-columns:minmax(220px, 1.2fr) repeat(4, minmax(86px, .45fr)) minmax(110px, .55fr);
        gap:8px;
        align-items:center;
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:#fff;
        padding:10px;
        min-width:0;
    }
    .rcc-product-name {
        display:grid;
        gap:3px;
        min-width:0;
    }
    .rcc-product-name strong {
        color:#0f172a;
        font-size:13px;
        line-height:1.25;
        overflow-wrap:anywhere;
    }
    .rcc-product-name small,
    .rcc-cell small {
        color:#64748b;
        font-size:11px;
        line-height:1.35;
        overflow-wrap:anywhere;
    }
    .rcc-cell {
        display:grid;
        gap:3px;
        min-width:0;
    }
    .rcc-cell span {
        color:#64748b;
        font-size:10px;
        font-weight:900;
        text-transform:uppercase;
        letter-spacing:.05em;
    }
    .rcc-cell strong {
        color:#0f172a;
        font-size:13px;
        line-height:1.2;
    }
    .rcc-finance-panel {
        display:grid;
        gap:10px;
        padding:14px;
    }
    .rcc-finance-kpis {
        display:grid;
        grid-template-columns:repeat(3, minmax(0, 1fr));
        gap:8px;
    }
    .rcc-finance-actions {
        display:flex;
        gap:8px;
        flex-wrap:wrap;
    }
    .rcc-payment-form {
        display:grid;
        grid-template-columns:repeat(4, minmax(0, 1fr)) auto;
        gap:8px;
        align-items:end;
        padding:10px;
        border:1px dashed #cbd5e1;
        border-radius:12px;
        background:#f8fafc;
    }
    .rcc-payment-form label {
        display:grid;
        gap:5px;
        color:#64748b;
        font-size:10px;
        font-weight:900;
        text-transform:uppercase;
        letter-spacing:.05em;
    }
    .rcc-payment-form input {
        min-height:36px;
        width:100%;
        border:1px solid #cbd5e1;
        border-radius:9px;
        padding:7px 9px;
        color:#0f172a;
        font-size:13px;
        font-weight:600;
        background:#fff;
    }
    .rcc-renewal-summary {
        display:grid;
        grid-template-columns:repeat(4, minmax(0, 1fr));
        gap:8px;
        padding:14px;
    }
    .rcc-details {
        border-top:1px solid #e2e8f0;
        padding:0 14px 14px;
    }
    .rcc-details summary {
        cursor:pointer;
        color:#4338ca;
        font-size:12px;
        font-weight:900;
        padding:12px 0;
    }
    .rcc-timeline-list {
        display:grid;
        gap:8px;
        padding:14px;
    }
    .rcc-timeline-item {
        display:grid;
        grid-template-columns:110px minmax(0, 1fr);
        gap:10px;
        border-top:1px solid #e2e8f0;
        padding-top:8px;
    }
    .rcc-timeline-item:first-child {
        border-top:0;
        padding-top:0;
    }
    .rcc-timeline-item time {
        color:#64748b;
        font-size:11px;
        line-height:1.35;
    }
    .rcc-timeline-item strong {
        color:#0f172a;
        font-size:13px;
        line-height:1.25;
    }
    .rcc-timeline-item p {
        margin:3px 0 0;
        color:#64748b;
        font-size:12px;
        line-height:1.35;
    }
    @media (max-width: 1100px) {
        .rcc-command-grid,
        .rcc-two-col {
            grid-template-columns:1fr;
        }
        .rcc-workflow {
            grid-template-columns:repeat(3, minmax(0, 1fr));
        }
        .rcc-product-row {
            grid-template-columns:minmax(0, 1fr) repeat(2, minmax(86px, .4fr));
        }
        .rcc-finance-kpis,
        .rcc-renewal-summary {
            grid-template-columns:repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 720px) {
        .rcc-pad,
        .rcc-section-head,
        .rcc-workflow,
        .rcc-product-list,
        .rcc-finance-panel,
        .rcc-renewal-summary,
        .rcc-timeline-list {
            padding:10px;
        }
        .rcc-lifecycle {
            display:flex;
            overflow-x:auto;
            padding-bottom:2px;
        }
        .rcc-step {
            min-width:150px;
        }
        .rcc-actions {
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
        }
        .rcc-actions .detail-btn,
        .rcc-actions .detail-btn-secondary,
        .rcc-actions .detail-btn-danger,
        .rcc-actions form,
        .rcc-actions button {
            width:100%;
        }
        .rcc-action-more {
            margin-left:0;
        }
        .rcc-workflow,
        .rcc-finance-kpis,
        .rcc-payment-form,
        .rcc-renewal-summary {
            grid-template-columns:1fr;
        }
        .rcc-product-row {
            grid-template-columns:1fr;
        }
        .rcc-timeline-item {
            grid-template-columns:1fr;
            gap:3px;
        }
    }
    .rcc-shell {
        color:#111827;
    }
    .rcc-shell .rcc-card {
        border-color:#cbd5e1;
        border-radius:14px;
        box-shadow:0 8px 22px rgba(15,23,42,.06);
    }
    .rcc-shell .rcc-pad,
    .rcc-shell .rcc-workflow,
    .rcc-shell .rcc-product-list,
    .rcc-shell .rcc-finance-panel,
    .rcc-shell .rcc-renewal-summary,
    .rcc-shell .rcc-timeline-list {
        padding:12px;
    }
    .rcc-shell .rcc-panel {
        border-color:#cbd5e1;
        background:#f7f9fc;
        padding:10px;
    }
    .rcc-shell .rcc-eyebrow,
    .rcc-shell .rcc-meta,
    .rcc-shell .rcc-section-head p,
    .rcc-shell .rcc-product-name small,
    .rcc-shell .rcc-cell small,
    .rcc-shell .rcc-cell span,
    .rcc-shell .rcc-money span,
    .rcc-shell .rcc-workflow-item small,
    .rcc-shell .rcc-timeline-item time,
    .rcc-shell .rcc-timeline-item p,
    .rcc-shell .rcc-payment-form label {
        color:#475569;
    }
    .rcc-shell .rcc-chip.done { background:#d9fbe7; color:#14532d; }
    .rcc-shell .rcc-chip.active { background:#dbeafe; color:#1e3a8a; }
    .rcc-shell .rcc-chip.warn { background:#fef3c7; color:#78350f; }
    .rcc-shell .rcc-chip.problem { background:#fee2e2; color:#991b1b; }
    .rcc-shell .rcc-chip.pending { background:#e2e8f0; color:#334155; }
    .rcc-shell .rcc-lifecycle {
        display:grid;
        grid-template-columns:repeat(3, minmax(0, 1fr));
        gap:6px;
        overflow:visible;
        padding-bottom:0;
    }
    .rcc-shell .rcc-step {
        min-width:0;
        gap:4px;
        padding:8px 9px;
        border-color:#cbd5e1;
        border-left:4px solid #94a3b8;
        border-radius:10px;
    }
    .rcc-shell .rcc-step.done { border-left-color:#16a34a; background:#f0fdf4; }
    .rcc-shell .rcc-step.active { border-left-color:#2563eb; background:#eff6ff; }
    .rcc-shell .rcc-step.warn { border-left-color:#d97706; background:#fffbeb; }
    .rcc-shell .rcc-step.problem { border-left-color:#dc2626; background:#fef2f2; }
    .rcc-shell .rcc-step.pending { border-left-color:#94a3b8; background:#f8fafc; }
    .rcc-shell .rcc-section-head {
        padding:12px 12px 0;
    }
    .rcc-shell .rcc-workflow-item,
    .rcc-shell .rcc-money,
    .rcc-shell .rcc-product-row {
        border-color:#cbd5e1;
    }
    .rcc-shell .rcc-product-row {
        grid-template-columns:minmax(260px,.7fr) minmax(0,1.6fr);
        gap:10px;
        align-items:start;
    }
    .rcc-shell .rcc-two-col {
        grid-template-columns:1fr;
    }
    .rcc-product-metrics {
        display:grid;
        grid-template-columns:repeat(4,minmax(82px,.7fr)) minmax(170px,1.3fr) minmax(130px,.8fr);
        gap:8px;
        min-width:0;
    }
    .rcc-product-metrics .rcc-cell {
        min-width:0;
    }
    .rcc-product-metrics .rcc-chip {
        max-width:100%;
        white-space:normal;
        line-height:1.15;
    }
    .rcc-compact-actions {
        display:flex;
        flex-wrap:wrap;
        gap:8px;
        margin-top:10px;
    }
    .rcc-details-panel {
        border:1px solid #cbd5e1;
        border-radius:12px;
        background:#fff;
        overflow:hidden;
    }
    .rcc-details-panel summary {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:8px;
        cursor:pointer;
        padding:10px 12px;
        color:#0f172a;
        font-size:13px;
        font-weight:900;
        list-style:none;
    }
    .rcc-details-panel summary::-webkit-details-marker { display:none; }
    .rcc-details-panel.rcc-payment-details summary {
        background:#eff6ff;
        color:#1e3a8a;
    }
    .rcc-details-panel.rcc-payment-details summary::after {
        content:"Click to enter payment";
        color:#2563eb;
        font-size:11px;
        font-weight:900;
    }
    .rcc-details-panel-body {
        display:grid;
        gap:10px;
        padding:0 12px 12px;
    }
    .rcc-snapshot-lines {
        display:grid;
        gap:6px;
    }
    .rcc-snapshot-line,
    .rcc-payment-line {
        display:grid;
        grid-template-columns:minmax(0,1fr) auto;
        gap:10px;
        align-items:center;
        padding:8px 0;
        border-top:1px solid #e2e8f0;
        color:#334155;
        font-size:12px;
    }
    .rcc-snapshot-line:first-child,
    .rcc-payment-line:first-child { border-top:0; }
    .rcc-snapshot-line strong,
    .rcc-payment-line strong { color:#0f172a; }
    .rcc-mini-grid {
        display:grid;
        grid-template-columns:repeat(4,minmax(120px,1fr));
        gap:8px;
    }
    .rcc-shell .rcc-finance-kpis {
        grid-template-columns:repeat(6,minmax(120px,1fr));
    }
    .rcc-shell .rcc-money {
        padding:8px 10px;
    }
    .rcc-shell .rcc-money strong {
        font-size:14px;
        line-height:1.2;
        word-break:break-word;
    }
    .rcc-shell .rcc-money span {
        font-size:9.5px;
    }
    .rcc-payment-details .rcc-payment-form {
        grid-template-columns:150px 150px minmax(140px,1fr) minmax(180px,1.2fr) auto;
        padding:8px;
    }
    .rcc-payment-details .rcc-payment-form button {
        min-height:38px;
    }
    .rcc-action-more {
        margin-left:auto;
        position:relative;
    }
    .rcc-action-more summary {
        list-style:none;
    }
    .rcc-action-more summary::-webkit-details-marker { display:none; }
    .rcc-action-more[open] .rcc-more-menu {
        display:grid;
        gap:6px;
        position:absolute;
        right:0;
        top:calc(100% + 8px);
        z-index:50;
    }
    .rcc-action-more[open] summary {
        background:#eef2ff;
        color:#3730a3;
    }
    #rental-actions-bar,
    #rental-actions-bar.rcc-card {
        overflow:visible;
        position:relative;
        z-index:20;
    }
    @media (max-width: 720px) {
        .rcc-shell .rcc-product-row,
        .rcc-product-metrics,
        .rcc-mini-grid {
            grid-template-columns:1fr;
        }
        .rcc-shell .rcc-finance-kpis {
            grid-template-columns:repeat(2,minmax(0,1fr));
        }
        .rcc-payment-details .rcc-payment-form {
            grid-template-columns:1fr;
        }
        .rcc-shell .rcc-step {
            min-width:128px;
        }
        .rcc-shell .rcc-lifecycle {
            display:flex;
            overflow-x:auto;
            padding-bottom:2px;
        }
        .rcc-action-more[open] .rcc-more-menu {
            position:static;
            margin-top:8px;
        }
    }
</style>

<div class="container detail-page rcc-shell">
    <div class="ph-rental-reference-shell rcc-shell">
        <x-operational-page-header
            eyebrow="Rental Command Center"
            :title="'Rental #' . $rental->id"
            subtitle="One compact workspace for customer, lifecycle, products, finance, renewal, and activity."
            :back-url="route('rentals.index')"
            back-label="Back to Rentals"
            :chips="[
                ['label' => $isHotlisted ? 'Hotlisted' : ucfirst(str_replace('_', ' ', $operationalStatus)), 'tone' => $rentalStatusTone],
                ['label' => $rentalTypeLabel, 'tone' => 'accent'],
                ['label' => $daysRemainingLabel, 'tone' => $daysRemainingTone],
            ]"
        />

        @if(session('success'))
            <div style="background:#dcfce7;color:#166534;border:1px solid #bbf7d0;padding:12px 14px;border-radius:12px;">
                {{ session('success') }}
            </div>
        @endif

        @if(session('error'))
            <div style="background:#fee2e2;color:#991b1b;border:1px solid #fecaca;padding:12px 14px;border-radius:12px;">
                {{ session('error') }}
            </div>
        @endif

        @if(!empty($needsAssetAssignment))
            <div style="background:#fef3c7;color:#92400e;border:1px solid #fde68a;padding:12px 14px;border-radius:12px;">
                <strong>Needs Asset Assignment</strong><br>
                This tracked rental still has one or more lines without assigned asset units. Assign the missing assets before the next delivery or return workflow.
            </div>
        @endif

        @if(session('renewal_whatsapp_url'))
            <div style="background:#ecfdf5;color:#166534;border:1px solid #bbf7d0;padding:12px 14px;border-radius:12px; display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                <div>Renewal saved. Open WhatsApp to send the renewal confirmation now.</div>
                <a href="{{ session('renewal_whatsapp_url') }}" target="_blank" class="detail-btn-secondary">Open WhatsApp</a>
            </div>
        @endif

        <section class="rcc-card rcc-pad" id="rental-command-center">
            <div class="rcc-command-grid">
                <div class="rcc-panel">
                    <span class="rcc-eyebrow">Customer Summary</span>
                    <h1 class="rcc-title">Rental #{{ $rental->id }}</h1>
                    <div class="rcc-meta">
                        <div><strong>{{ $rental->billingContactName() }}</strong></div>
                        <div>{{ $reminderContactPhone ?: 'No phone' }}</div>
                        <div>{{ $rental->billingContactCity() ?: $rental->deliveryContactCity() ?: 'City not set' }}</div>
                        @if($rental->usesBusinessPartnerFlow())
                            <div><strong>Partner:</strong> {{ $rental->businessPartner?->displayName() ?? 'Business Partner' }}</div>
                            <div><strong>Actual client:</strong> {{ $rental->partnerClient?->displayName() ?? $rental->deliveryContactName() }}</div>
                        @endif
                        @if(filled($rental->referred_by ?? null))
                            <div><strong>Referred by:</strong> {{ $rental->referred_by }}</div>
                        @endif
                    </div>
                    <div class="rcc-compact-actions">
                        @if($reminderCallHref)
                            <a href="{{ $reminderCallHref }}" class="detail-btn-secondary">Call</a>
                        @endif
                        @if($reminderWhatsappHref)
                            <a href="{{ $reminderWhatsappHref }}" target="_blank" rel="noopener" class="detail-btn-secondary">WhatsApp</a>
                        @endif
                        @if($deliveryContactMapUrl)
                            <a href="{{ $deliveryContactMapUrl }}" target="_blank" rel="noopener" class="detail-btn-secondary">Map</a>
                        @endif
                        @if($rental->customer_id)
                            <a href="{{ route('customers.show', $rental->customer_id) }}" class="detail-btn-secondary">View Customer</a>
                        @elseif($rental->business_partner_id)
                            <a href="{{ route('business-partners.show', $rental->business_partner_id) }}" class="detail-btn-secondary">View Partner</a>
                        @endif
                    </div>
                </div>

                <div class="rcc-panel">
                    <span class="rcc-eyebrow">Rental Lifecycle</span>
                    <div class="rcc-lifecycle" style="margin-top:10px;">
                        @foreach($lifecycleSteps as $step)
                            <div class="rcc-step {{ $step['tone'] }}">
                                <span class="rcc-chip {{ $step['tone'] }}">{{ ucfirst($step['tone']) }}</span>
                                <strong>{{ $step['label'] }}</strong>
                                <span>{{ $step['meta'] }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>

                <div class="rcc-panel">
                    <span class="rcc-eyebrow">Financial Snapshot</span>
                    @if($canSeeRentalFinance)
                        <div class="rcc-finance-grid">
                            <div class="rcc-money"><span>Rental Value</span><strong>{{ $currency($rentalLineTotal) }}</strong></div>
                            <div class="rcc-money"><span>Invoice Value</span><strong>{{ $currency($invoiceTotalAmount) }}</strong></div>
                            <div class="rcc-money"><span>Paid</span><strong>{{ $currency($invoicePaidAmount) }}</strong></div>
                            <div class="rcc-money"><span>Outstanding</span><strong>{{ $currency($invoiceBalanceAmount) }}</strong></div>
                        </div>
                        <div style="margin-top:10px;"><span class="rcc-chip {{ $rentalInvoiceStatus === 'paid' ? 'done' : ($rentalInvoiceStatus === 'partial' ? 'warn' : 'problem') }}">{{ $paymentStatusLabel }}</span></div>
                    @else
                        <div class="rcc-meta">
                            <div><strong>Invoice:</strong> {{ $rentalInvoice ? 'Generated' : 'Pending' }}</div>
                            <div><strong>Payment:</strong> {{ $paymentStatusLabel }}</div>
                            <div><strong>Renewal:</strong> {{ $rental->canRenew() ? 'Available' : 'Closed' }}</div>
                        </div>
                    @endif
                </div>
            </div>
        </section>

        <section class="rcc-card rcc-pad" id="rental-actions-bar">
            <div class="rcc-actions">
                @if($reminderCallHref)
                    <a href="{{ $reminderCallHref }}" class="detail-btn-secondary">Call</a>
                @endif
                @if($reminderWhatsappHref)
                    <a href="{{ $reminderWhatsappHref }}" target="_blank" rel="noopener" class="detail-btn-secondary">WhatsApp</a>
                @endif
                @if($deliveryUrl)
                    <a href="{{ $deliveryUrl }}" target="_blank" rel="noopener" class="detail-btn-secondary">Delivery Message</a>
                @endif
                @if($pickupUrl)
                    <a href="{{ $pickupUrl }}" target="_blank" rel="noopener" class="detail-btn-secondary">Pickup Reminder</a>
                @endif
                @if($deliveryContactMapUrl)
                    <a href="{{ $deliveryContactMapUrl }}" target="_blank" rel="noopener" class="detail-btn-secondary">Open Service Map</a>
                @endif
                @if($rentalInvoice)
                    <a href="{{ route('invoices.show', $rentalInvoice) }}" class="detail-btn-secondary">Open Invoice</a>
                @elseif($canUpdateRentals)
                    <form action="{{ route('rentals.invoice', $rental) }}" method="POST">
                        @csrf
                        <button type="submit" class="detail-btn-secondary">Create Invoice</button>
                    </form>
                @endif
                @if($canCreatePayments && !in_array($rentalInvoiceStatus, ['paid', 'cancelled'], true))
                    <button
                        type="button"
                        class="detail-btn"
                        onclick="document.getElementById('record-payment-panel')?.setAttribute('open', 'open'); document.getElementById('record-payment-panel')?.scrollIntoView({behavior:'smooth', block:'center'});"
                    >Record Payment</button>
                @endif
                @if($rental->canRenew() && $canUpdateRentals)
                    <button type="button" class="detail-btn-secondary" data-open-renewal-modal>Renew Rental</button>
                @endif
                @if($deliveryRecord && $hasOpenDeliveryTask && $canUpdateDeliveries)
                    <a href="{{ route('deliveries.edit', $deliveryRecord) }}" class="detail-btn-secondary">Assign Delivery</a>
                @elseif($canCreateDeliveries && !$hasOpenDeliveryTask && $hasPendingDeliveryItems)
                    <a href="{{ route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'delivery']) }}" class="detail-btn-secondary">Assign Delivery</a>
                @endif
                @if($pickupRecord && $hasOpenPickupTask && $canUpdateDeliveries)
                    <a href="{{ route('deliveries.edit', $pickupRecord) }}" class="detail-btn-secondary">Assign Pickup</a>
                @elseif($canAssignPickup && !$hasOpenPickupTask)
                    <a href="{{ route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'pickup']) }}" class="detail-btn-secondary">Assign Pickup</a>
                @endif
                <details class="rcc-action-more">
                    <summary class="detail-btn-secondary">More</summary>
                    <div class="rcc-more-menu">
                        @if($canUpdateRentals)
                            <a href="{{ route('rentals.edit', $rental) }}">Edit Rental</a>
                        @endif
                        <a href="#rental-timeline-workspace">Timeline</a>
                        @if($deliveryContactMapUrl)
                            <a href="{{ $deliveryContactMapUrl }}" target="_blank" rel="noopener">Open Map</a>
                        @endif
                        @if($rental->canBeReturned())
                            <form action="{{ route('rentals.return', $rental) }}" method="POST">
                                @csrf
                                @method('PUT')
                                <button type="submit" onclick="return confirm('Mark this rental as returned?');">Complete Pickup</button>
                            </form>
                        @endif
                        @if($canUpdateRentals && !in_array($rental->status, ['returned', 'cancelled'], true))
                            <form action="{{ route('rentals.cancel', $rental) }}" method="POST">
                                @csrf
                                @method('PUT')
                                <button type="submit" onclick="return confirm('Cancel this rental? This keeps the record for audit history.');">Cancel Rental</button>
                            </form>
                        @endif
                        @if($canDeleteRentals)
                            <form action="{{ route('rentals.destroy', $rental) }}" method="POST">
                                @csrf
                                @method('DELETE')
                                <button type="submit" onclick="return confirm('Delete this rental order permanently?');">Delete Rental</button>
                            </form>
                        @endif
                    </div>
                </details>
            </div>
        </section>

        <section class="rcc-card" id="rental-operations-workspace">
            <div class="rcc-section-head">
                <div>
                    <h2>Operations Workspace</h2>
                    <p>Rental, delivery, active usage, pickup, and return verification in one operational row.</p>
                </div>
            </div>
            <div class="rcc-workflow">
                @foreach($workflowSteps as $step)
                    <div class="rcc-workflow-item">
                        <span class="rcc-chip {{ $step['tone'] }}">{{ $step['status'] }}</span>
                        <strong>{{ $step['label'] }}</strong>
                        <small>Owner: {{ $step['person'] }}</small>
                        <small>Scheduled: {{ $step['scheduled'] }}</small>
                        @if($step['completed'])
                            <small>Completed: {{ $step['completed'] }}</small>
                        @endif
                        @if(!empty($step['action']['form']))
                            <form action="{{ route('rentals.return', $rental) }}" method="POST" style="margin:0;">
                                @csrf
                                @method('PUT')
                                <button type="submit" onclick="return confirm('Mark this rental as returned?');">{{ $step['action']['label'] }}</button>
                            </form>
                        @elseif(!empty($step['action']['href']))
                            <a href="{{ $step['action']['href'] }}" @if(!empty($step['action']['target'])) target="{{ $step['action']['target'] }}" rel="noopener" @endif>{{ $step['action']['label'] }}</a>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>

        <div class="rcc-two-col">
            <section class="rcc-card" id="rental-products-section">
                <div class="rcc-section-head">
                    <div>
                        <h2>Product Workspace</h2>
                        <p>Rental items, tracked assets, progress quantities, period, status, and amount.</p>
                    </div>
                </div>
                <div class="rcc-product-list">
                    @foreach($rentalItems as $item)
                        @php
                            $orderedQty = (int) ($item->ordered_quantity ?? $item->quantity ?? 0);
                            $deliveredQty = (int) ($item->delivered_quantity_value ?? $item->delivered_quantity ?? 0);
                            $returnedQty = (int) ($item->returned_quantity_value ?? $item->returned_quantity ?? 0);
                            $itemAssetIds = collect($item->asset_ids ?? [])->map(fn ($assetId) => (int) $assetId)->filter()->values();
                            $itemAssetSummary = $activeAssets
                                ->filter(fn ($assignment) => $itemAssetIds->contains((int) ($assignment->asset_id ?? $assignment->asset?->id)))
                                ->map(fn ($assignment) => $assignment->asset?->serial_number ?: $assignment->asset?->barcode_value)
                                ->filter()
                                ->implode(', ');
                            $itemStatus = $returnedQty >= $orderedQty && $orderedQty > 0 ? 'Returned' : ($deliveredQty >= $orderedQty && $orderedQty > 0 ? 'With Customer' : ($deliveredQty > 0 ? 'Partial Delivery' : 'Pending Delivery'));
                            $itemTone = $itemStatus === 'Returned' ? 'done' : ($itemStatus === 'With Customer' ? 'active' : 'warn');
                        @endphp
                        <div class="rcc-product-row">
                            <div class="rcc-product-name">
                                <strong>{{ $item->product?->name ?? 'Rental item' }}</strong>
                                <small>{{ $itemAssetSummary ?: 'No tracked serial linked' }}</small>
                            </div>
                            <div class="rcc-product-metrics">
                                <div class="rcc-cell"><span>Requested</span><strong>{{ $orderedQty }}</strong></div>
                                <div class="rcc-cell"><span>Delivered</span><strong>{{ $deliveredQty }}</strong></div>
                                <div class="rcc-cell"><span>Returned</span><strong>{{ $returnedQty }}</strong></div>
                                <div class="rcc-cell"><span>Period</span><small>{{ optional($rental->start_date)->format('d M') }} - {{ optional($rental->end_date)->format('d M Y') }}</small></div>
                                <div class="rcc-cell"><span>Status</span><span class="rcc-chip {{ $itemTone }}">{{ $itemStatus }}</span></div>
                                <div class="rcc-cell"><span>Amount</span><strong>{{ $canSeeRentalFinance ? $currency($rentalLineAmount($item)) : 'Restricted' }}</strong></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>

            <section class="rcc-card" id="rental-finance-workspace">
                <span id="rental-billing-actions" class="section-nav-target"></span>
                <div class="rcc-section-head">
                    <div>
                        <h2>Finance Workspace</h2>
                        <p>Invoice, payment, deposit, transport, and latest collection in one place.</p>
                    </div>
                    <span class="rcc-chip {{ $rentalInvoiceStatus === 'paid' ? 'done' : ($rentalInvoiceStatus === 'partial' ? 'warn' : 'problem') }}">{{ $paymentStatusLabel }}</span>
                </div>
                <div class="rcc-finance-panel">
                    @if($canSeeRentalFinance)
                        <div class="rcc-finance-kpis">
                            <div class="rcc-money"><span>Invoice</span><strong>{{ $rentalInvoice?->invoice_number ?? 'Not generated' }}</strong></div>
                            <div class="rcc-money"><span>Invoice Amount</span><strong>{{ $currency($invoiceTotalAmount) }}</strong></div>
                            <div class="rcc-money"><span>Paid</span><strong>{{ $currency($invoicePaidAmount) }}</strong></div>
                            <div class="rcc-money"><span>Outstanding</span><strong>{{ $currency($invoiceBalanceAmount) }}</strong></div>
                            <div class="rcc-money"><span>Deposit</span><strong>{{ $currency($rental->deposit_amount) }}</strong></div>
                            <div class="rcc-money"><span>Transport</span><strong>{{ $currency($rental->transport_amount) }}</strong></div>
                        </div>
                        <div class="rcc-meta">
                            <div><strong>Latest payment:</strong> {{ $latestPayment ? $currency($latestPayment->amount) . ' on ' . optional($latestPayment->payment_date)->format('d M Y') : 'No collection logged' }}</div>
                        </div>
                    @else
                        <div class="rcc-meta"><div>Finance amounts are hidden for your role.</div></div>
                    @endif
                    <div class="rcc-finance-actions">
                        @if($rentalInvoice)
                            <a href="{{ route('invoices.show', $rentalInvoice) }}" class="detail-btn-secondary">Open Invoice</a>
                            <a href="{{ route('invoices.show', $rentalInvoice) }}" class="detail-btn-secondary">Download PDF</a>
                        @elseif($canUpdateRentals)
                            <form action="{{ route('rentals.invoice', $rental) }}" method="POST" style="margin:0;">
                                @csrf
                                <button type="submit" class="detail-btn-secondary">Create Invoice</button>
                            </form>
                        @endif
                        @if($renewalUrl)
                            <a href="{{ $renewalUrl }}" target="_blank" rel="noopener" class="detail-btn-secondary">Send Reminder</a>
                        @endif
                    </div>
                    @if($canSeeRentalFinance)
                        <details class="rcc-details-panel" open>
                            <summary>
                                <span>Original Booking Snapshot</span>
                                <span class="rcc-chip pending">{{ $currency($baseBookingTotal) }}</span>
                            </summary>
                            <div class="rcc-details-panel-body">
                                <div class="rcc-mini-grid">
                                    <div class="rcc-money"><span>Original Period</span><strong>{{ optional($rental->start_date)->format('d M Y') }} - {{ optional($initialBookingEndDate)->format('d M Y') }}</strong></div>
                                    <div class="rcc-money"><span>Rental</span><strong>{{ $currency($baseRentalAmount) }}</strong></div>
                                    <div class="rcc-money"><span>Deposit</span><strong>{{ $currency($baseDepositAmount) }}</strong></div>
                                    <div class="rcc-money"><span>Transport</span><strong>{{ $currency($baseTransportAmount) }}</strong></div>
                                </div>
                                <div class="rcc-snapshot-lines">
                                    @foreach($rentalItems as $item)
                                        <div class="rcc-snapshot-line">
                                            <div>
                                                <strong>{{ $item->product?->name ?? 'Rental item' }}</strong>
                                                <div>Qty {{ (int) ($item->ordered_quantity ?? $item->quantity ?? 0) }}</div>
                                            </div>
                                            <strong>{{ $currency($rentalLineAmount($item)) }}</strong>
                                        </div>
                                    @endforeach
                                </div>
                                <div class="rcc-mini-grid">
                                    <div class="rcc-money"><span>Other</span><strong>{{ $currency($baseOtherAmount) }}</strong></div>
                                    <div class="rcc-money"><span>Collected</span><strong>{{ $currency($baseBookingPaidAmount) }}</strong></div>
                                    <div class="rcc-money"><span>Remaining</span><strong>{{ $currency($baseBookingBalance) }}</strong></div>
                                    <div class="rcc-money"><span>Invoice Flow</span><strong>{{ strtoupper($rentalInvoiceStatus ?? 'Pending') }}</strong></div>
                                </div>
                            </div>
                        </details>
                        <details class="rcc-details-panel">
                            <summary>
                                <span>Payment History</span>
                                <span class="rcc-chip {{ $latestPayment ? 'done' : 'pending' }}">{{ $rental->payments?->count() ?? 0 }} payment(s)</span>
                            </summary>
                            <div class="rcc-details-panel-body">
                                @forelse(($rental->payments ?? collect())->sortByDesc('payment_date')->sortByDesc('id')->take(8) as $payment)
                                    <div class="rcc-payment-line">
                                        <div>
                                            <strong>{{ optional($payment->payment_date)->format('d M Y') ?: 'Date not set' }}</strong>
                                            <div>{{ ucfirst(str_replace('_', ' ', $payment->payment_method ?? 'payment')) }}{{ $payment->invoice?->invoice_number ? '  -  ' . $payment->invoice->invoice_number : '' }}</div>
                                            @if(filled($payment->notes ?? null))
                                                <div>{{ $payment->notes }}</div>
                                            @endif
                                        </div>
                                        <strong>{{ $currency($payment->amount) }}</strong>
                                    </div>
                                @empty
                                    <div class="rcc-meta" style="margin-top:0;">No payments recorded for this rental.</div>
                                @endforelse
                            </div>
                        </details>
                    @endif
                    @if($canCreatePayments && !in_array($rentalInvoiceStatus, ['paid', 'cancelled'], true))
                        <details class="rcc-details-panel rcc-payment-details" id="record-payment-panel">
                            <summary>
                                <span>Record Payment</span>
                                <span class="rcc-chip active">{{ $currency($rentalInvoiceDue) }} due</span>
                            </summary>
                            <div class="rcc-details-panel-body">
                                <form action="{{ route('rentals.recordPayment', $rental) }}" method="POST" class="rcc-payment-form">
                                    @csrf
                                    <label>Date<input type="date" name="payment_date" value="{{ old('payment_date', now()->toDateString()) }}"></label>
                                    <label>Amount<input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount', $rentalInvoiceDue > 0 ? number_format($rentalInvoiceDue, 2, '.', '') : '') }}"></label>
                                    <label>Method<input type="text" name="payment_method" value="{{ old('payment_method', 'other') }}" placeholder="cash / upi / bank"></label>
                                    <label>Note<input type="text" name="notes" value="{{ old('notes') }}" placeholder="Payment note"></label>
                                    <button type="submit" class="detail-btn">Record</button>
                                </form>
                            </div>
                        </details>
                    @endif
                </div>
            </section>
        </div>

        <section class="rcc-card" id="renewal-workspace">
            <div class="rcc-section-head">
                <div>
                    <h2>Renewal Workspace</h2>
                    <p>Collapsed summary first; expand only when renewal history is needed.</p>
                </div>
                @if($rental->canRenew() && $canUpdateRentals)
                    <button type="button" class="detail-btn" data-open-renewal-modal>Quick Renew</button>
                @endif
            </div>
            <div class="rcc-renewal-summary">
                <div class="rcc-money"><span>Status</span><strong>{{ $renewalSummaryStatus }}</strong></div>
                <div class="rcc-money"><span>Due Date</span><strong>{{ optional($rental->end_date)->format('d M Y') ?: 'Not set' }}</strong></div>
                <div class="rcc-money"><span>Last Renewal Invoice</span><strong>{{ $lastRenewal?->invoice?->invoice_number ?? 'None' }}</strong></div>
                <div class="rcc-money"><span>Suggested Extension</span><strong>{{ $suggestedRenewalDays }} days</strong></div>
            </div>
            <details class="rcc-details">
                <summary>View renewal history</summary>
                @if(!$renewalFeatureReady)
                    <div class="ops-muted">Renewal history logging is waiting on the latest migration.</div>
                @elseif($renewalHistory->isEmpty())
                    <x-empty-state title="No renewals yet" message="Use Quick Renew when this rental needs extension." />
                @else
                    <div class="payment-history-list">
                        @foreach($renewalHistory as $renewal)
                            <div class="payment-history-card">
                                <div>
                                    <span class="label">Renewed To</span>
                                    <strong>{{ optional($renewal->renewed_end_date)->format('d M Y') ?: '-' }}</strong>
                                    <div class="ops-muted">{{ ucfirst($renewal->renewal_type) }} renewal  -  {{ $currency(($renewal->rental_amount_added ?? 0) + ($renewal->deposit_amount_added ?? 0) + ($renewal->transport_amount_added ?? 0) + ($renewal->other_amount_added ?? 0)) }}</div>
                                </div>
                                <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
                                    @if($renewal->invoice)
                                        <a href="{{ route('invoices.show', $renewal->invoice) }}" class="detail-btn-secondary">Invoice</a>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </details>
        </section>

        <section class="rcc-card" id="rental-timeline-workspace">
            <div class="rcc-section-head">
                <div>
                    <h2>Timeline & Communication</h2>
                    <p>Latest rental activity, reminders, invoice events, delivery updates, and notes.</p>
                </div>
                <a href="#rental-full-timeline" class="detail-btn-secondary">View full timeline</a>
            </div>
            <div class="rcc-timeline-list">
                @forelse($timelinePreview as $log)
                    <div class="rcc-timeline-item">
                        <time>{{ optional($log->created_at)->format('d M Y h:i A') }}</time>
                        <div>
                            <strong>{{ $log->description ?? ucfirst(str_replace(['_', '.'], ' ', $log->action ?? 'Activity')) }}</strong>
                            @if(!empty($log->properties) && is_array($log->properties))
                                <p>{{ collect($log->properties)->take(2)->map(fn ($value, $key) => ucfirst(str_replace('_', ' ', $key)) . ': ' . (is_scalar($value) ? $value : json_encode($value)))->implode('  -  ') }}</p>
                            @endif
                        </div>
                    </div>
                @empty
                    <x-empty-state title="No activity yet" message="Rental activity and internal notes will appear here as the order moves." />
                @endforelse
            </div>
            <details class="rcc-details" id="rental-full-timeline">
                <summary>Open notes and recent activity</summary>
                <div class="section-nav-target" id="rental-activity-timeline"></div>
                <div class="section-nav-target" id="rental-notes-section"></div>
                <form action="{{ route('rentals.notes.store', $rental) }}" method="POST" style="display:grid;gap:8px;margin:0 0 12px;">
                    @csrf
                    <input type="hidden" name="note_type" value="general">
                    <textarea name="note" rows="3" placeholder="Add internal note..." style="width:100%;border:1px solid #cbd5e1;border-radius:10px;padding:10px;font-size:13px;resize:vertical;"></textarea>
                    <div style="display:flex;justify-content:flex-end;">
                        <button type="submit" class="detail-btn-secondary">Add Note</button>
                    </div>
                </form>
                <div class="rcc-timeline-list" style="padding:0;">
                    @foreach(($activityLogs ?? collect())->take(20) as $log)
                        <div class="rcc-timeline-item">
                            <time>{{ optional($log->created_at)->format('d M Y h:i A') }}</time>
                            <div>
                                <strong>{{ $log->description ?? ucfirst(str_replace(['_', '.'], ' ', $log->action ?? 'Activity')) }}</strong>
                            </div>
                        </div>
                    @endforeach
                </div>
            </details>
        </section>
    </div>
</div>
<div class="renewal-modal" id="renewalModal" aria-hidden="true">
    <div class="renewal-modal-panel">
        <div class="renewal-modal-head">
            <div>
                <strong id="renewalModalTitle">Custom Renewal</strong>
                <div class="ops-muted" id="renewalModalSubtitle" style="margin-top:4px;">Extend the current rental without creating a duplicate rental record.</div>
            </div>
            <button type="button" class="detail-btn-secondary" data-close-renewal-modal>Close</button>
        </div>

        <form
            action="{{ route('rentals.renew', $rental) }}"
            method="POST"
            id="renewalModalForm"
            data-create-action="{{ route('rentals.renew', $rental) }}"
            data-update-action-template="{{ url('/rentals/' . $rental->id . '/renewals/__RENEWAL__') }}"
        >
            @csrf
            <input type="hidden" name="_method" value="PUT" data-renewal-method-field disabled>
            <div class="renewal-modal-body">
            <div
                id="renewalDuplicateWarning"
                data-visible-on-create="{{ $showDuplicateRenewalWarning ? '1' : '0' }}"
                style="display:none; margin:0 0 14px; padding:12px 14px; border-radius:14px; background:#fff7ed; border:1px solid #fdba74; color:#9a3412;"
            >
                A renewal is already logged for this rental. If you only need to adjust payment, invoice, or notes, edit the latest renewal instead of creating a duplicate extension.
            </div>
            <div class="renewal-form-grid">
                <div class="renewal-field">
                    <label>Current End Date</label>
                    <input type="text" id="renewal_current_end_date" value="{{ optional($rental->end_date)->format('d M Y') }}" readonly>
                </div>
                <div class="renewal-field">
                    <label>Renewal Duration</label>
                    <select name="duration_preset" id="renewal_duration_preset">
                        <option value="7_days" @selected(old('duration_preset', '7_days') === '7_days')>7 Days</option>
                        <option value="15_days" @selected(old('duration_preset') === '15_days')>15 Days</option>
                        <option value="30_days" @selected(old('duration_preset') === '30_days')>30 Days</option>
                        <option value="3_months" @selected(old('duration_preset') === '3_months')>3 Months</option>
                        <option value="6_months" @selected(old('duration_preset') === '6_months')>6 Months</option>
                        <option value="custom" @selected(old('duration_preset') === 'custom')>Custom</option>
                    </select>
                </div>
                <div class="renewal-field">
                    <label>New End Date</label>
                    <input type="date" name="new_end_date" id="renewal_new_end_date" value="{{ old('new_end_date', $suggestedRenewedEndDate?->format('Y-m-d')) }}" required>
                </div>
                <div class="renewal-field">
                    <label>Rental Amount to Add</label>
                    <input type="number" step="0.01" min="0" name="rental_amount_added" value="{{ old('rental_amount_added', $suggestedRenewalAmount) }}">
                </div>
                <div class="renewal-field">
                    <label>Deposit to Add</label>
                    <input type="number" step="0.01" min="0" name="deposit_amount_added" value="{{ old('deposit_amount_added', 0) }}">
                </div>
                <div class="renewal-field">
                    <label>Transport to Add</label>
                    <input type="number" step="0.01" min="0" name="transport_amount_added" value="{{ old('transport_amount_added', 0) }}">
                </div>
                <div class="renewal-field">
                    <label>Other Charges to Add</label>
                    <input type="number" step="0.01" min="0" name="other_amount_added" value="{{ old('other_amount_added', 0) }}">
                </div>
                <div class="renewal-field">
                    <label>Payment Received Now</label>
                    <input type="number" step="0.01" min="0" name="payment_amount" value="{{ old('payment_amount', 0) }}">
                </div>
                <div class="renewal-field">
                    <label>Payment Date</label>
                    <input type="date" name="payment_date" value="{{ old('payment_date', now()->format('Y-m-d')) }}">
                </div>
                <div class="renewal-field">
                    <label>Payment Method</label>
                    <select name="payment_method">
                        <option value="">Select method</option>
                        @foreach(\App\Models\Payment::METHODS as $method)
                            <option value="{{ $method }}" @selected(old('payment_method') === $method)>{{ ucfirst(str_replace('_', ' ', $method)) }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="renewal-field renewal-span-2">
                    <label>Notes</label>
                    <textarea name="notes" placeholder="Renewal notes, customer approval, payment remarks...">{{ old('notes') }}</textarea>
                </div>
                <div class="renewal-field renewal-span-2">
                    <label style="display:flex; align-items:center; gap:8px; text-transform:none; letter-spacing:0; font-size:13px; color:#0f172a;">
                        <input type="checkbox" name="send_whatsapp" value="1" @checked(old('send_whatsapp')) style="width:auto; min-height:auto;">
                        Prepare WhatsApp renewal confirmation and log it after saving
                    </label>
                </div>
            </div>
            </div>
            <div class="renewal-modal-foot">
                <button type="button" class="detail-btn-secondary" data-close-renewal-modal>Cancel</button>
                <button type="submit" class="detail-btn" id="renewalModalSubmit">Save Renewal</button>
            </div>
        </form>
    </div>
</div>

@include('partials.mobile-action-bar', [
    'label' => 'Rental mobile actions',
    'moreLabel' => 'Rental secondary actions',
    'actions' => $mobilePrimaryActions->all(),
    'moreActions' => $mobileMoreActions->all(),
])
@include('partials.follow-up-modal')
    {{--
        <div class="rental-cta-meta">
            <span class="rental-cta-eyebrow">Rental Actions</span>
            <div class="rental-cta-title">Rental #{{ $rental->id }} Ã‚ -  {{ $rental->billingContactName() }}</div>
            <div class="rental-cta-subtitle">
                @if($canCreatePayments && !in_array($rentalInvoiceStatus, ['paid', 'cancelled'], true))
                    {{ $rentalInvoiceDue > 0 ? 'Invoice due ' . $currency($rentalInvoiceDue) . '. Use Receive Payment or Mark Paid.' : 'Payment action is available for this rental.' }}
                @else
                    Keep renewal, invoice, and support actions close by without crowding the page.
                @endif
            </div>
        </div>

        <div class="rental-cta-actions">
            @if($canCreatePayments && !in_array($rentalInvoiceStatus, ['paid', 'cancelled'], true))
                <a href="#rental-billing-actions" class="rental-cta-primary">Receive Payment</a>
            @endif

            @if($rental->canRenew() && auth()->user()->canAccessModule('rentals', 'update'))
                <button type="button" class="detail-btn" data-open-renewal-modal>Renew</button>
            @endif

            @if($canUpdateRentals)
                <a href="{{ route('rentals.edit', $rental) }}" class="detail-btn-secondary">Edit</a>
            @endif

            @if(!empty($rentalInvoice))
                <a href="{{ route('invoices.show', $rentalInvoice) }}" class="detail-btn-secondary">Invoice</a>
            @elseif(auth()->user()->canAccessModule('rentals', 'update'))
                <form action="{{ route('rentals.invoice', $rental) }}" method="POST">
                    @csrf
                    <button type="submit" class="detail-btn-secondary">Invoice</button>
                </form>
            @endif

            <details class="rental-cta-more">
                <summary type="button">More</summary>
                <div class="rental-cta-panel">
                    <a href="#rental-activity-timeline">View Timeline</a>
                    @if($rental->reminderContactPhone())
                        <a href="tel:{{ preg_replace('/\D+/', '', $rental->reminderContactPhone()) }}">Call Customer</a>
                    @endif
                    @if($canCreatePayments && !in_array($rentalInvoiceStatus, ['paid', 'cancelled'], true))
                        <form action="{{ route('rentals.markPaid', $rental) }}" method="POST" style="margin:0;">
                            @csrf
                            <button type="submit">Mark as Paid</button>
                        </form>
                        <a href="#rental-billing-actions">Partial Payment</a>
                    @endif
                    @if($deliveryRecord && $hasOpenDeliveryTask && auth()->user()?->canAccessModule('deliveries', 'update'))
                        <a href="{{ route('deliveries.edit', $deliveryRecord) }}">Edit Delivery Assignment</a>
                    @elseif($canCreateDeliveries && !$hasOpenDeliveryTask && $hasPendingDeliveryItems)
                        <a href="{{ route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'delivery']) }}">Assign Delivery</a>
                    @endif
                    @if($pickupRecord && $hasOpenPickupTask && auth()->user()?->canAccessModule('deliveries', 'update'))
                        <a href="{{ route('deliveries.edit', $pickupRecord) }}">Edit Pickup Assignment</a>
                    @elseif($canAssignPickup && !$hasOpenPickupTask)
                        <a href="{{ route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'pickup']) }}">Assign Pickup</a>
                    @endif
                    @if($renewalUrl)
                        <a href="{{ $renewalUrl }}" target="_blank">WhatsApp Renewal</a>
                    @endif
                    @if($rental->canRenew() && auth()->user()->canAccessModule('rentals', 'update'))
                        <form method="POST" action="{{ route('rentals.quick-renew', $rental) }}" style="margin:0;" data-quick-renew-form data-renewal-days="{{ $suggestedRenewalDays }}">
                            @csrf
                            <button type="submit">Quick Renew</button>
                        </form>
                    @endif
                    @if($rental->canBeReturned())
                        <form action="{{ route('rentals.return', $rental) }}" method="POST" style="margin:0;">
                            @csrf
                            @method('PUT')
                            <button type="submit">Complete Pickup</button>
                        </form>
                    @elseif($pickupRecord && $hasOpenPickupTask)
                        <a href="{{ route('deliveries.show', $pickupRecord) }}">View Pickup Task</a>
                    @endif
                    @if(auth()->user()->canAccessModule('rentals', 'update') && !in_array($rental->status, ['returned', 'cancelled'], true))
                        <form action="{{ route('rentals.cancel', $rental) }}" method="POST" style="margin:0;">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="is-danger" onclick="return confirm('Cancel this rental? This keeps the record for audit history.');">Cancel Rental</button>
                        </form>
                    @endif
                    @if($canDeleteRentals)
                        <form action="{{ route('rentals.destroy', $rental) }}" method="POST" style="margin:0;">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="is-danger" onclick="return confirm('Delete this rental order permanently? This will be blocked if invoices, payments, deliveries, renewals, or linked sales exist.');">Delete Rental</button>
                        </form>
                    @endif
                </div>
            </details>
        </div>
    </div>
</div>
    --}}
@endsection

@push('scripts')
<script>
    (() => {
        const modal = document.getElementById('renewalModal');
        if (!modal) {
            return;
        }

        const openButtons = document.querySelectorAll('[data-open-renewal-modal]');
        const closeButtons = document.querySelectorAll('[data-close-renewal-modal]');
        const form = document.getElementById('renewalModalForm');
        const methodField = form?.querySelector('[data-renewal-method-field]');
        const modalTitle = document.getElementById('renewalModalTitle');
        const modalSubmit = document.getElementById('renewalModalSubmit');
        const currentEndDisplay = document.getElementById('renewal_current_end_date');
        const durationPreset = document.getElementById('renewal_duration_preset');
        const newEndDateInput = document.getElementById('renewal_new_end_date');
        const currentEndDate = '{{ optional($rental->end_date)->format('Y-m-d') }}';
        const currentEndText = '{{ optional($rental->end_date)->format('d M Y') }}';
        let activeCurrentEndDate = currentEndDate;
        const rentalAmountInput = form?.querySelector('[name="rental_amount_added"]');
        const depositAmountInput = form?.querySelector('[name="deposit_amount_added"]');
        const transportAmountInput = form?.querySelector('[name="transport_amount_added"]');
        const otherAmountInput = form?.querySelector('[name="other_amount_added"]');
        const paymentAmountInput = form?.querySelector('[name="payment_amount"]');
        const paymentDateInput = form?.querySelector('[name="payment_date"]');
        const paymentMethodInput = form?.querySelector('[name="payment_method"]');
        const notesInput = form?.querySelector('[name="notes"]');
        const duplicateWarning = document.getElementById('renewalDuplicateWarning');
        const quickRenewForms = document.querySelectorAll('[data-quick-renew-form]');
        const actionMenus = document.querySelectorAll('.rental-cta-more');
        const modalBody = modal.querySelector('.renewal-modal-body');
        const defaultValues = {
            action: form?.dataset.createAction || '',
            currentEndDate,
            currentEndText,
            preset: durationPreset?.value || '7_days',
            newEndDate: newEndDateInput?.value || '',
            rentalAmount: rentalAmountInput?.value || '0',
            depositAmount: depositAmountInput?.value || '0',
            transportAmount: transportAmountInput?.value || '0',
            otherAmount: otherAmountInput?.value || '0',
            paymentAmount: paymentAmountInput?.value || '0',
            paymentDate: paymentDateInput?.value || '',
            paymentMethod: paymentMethodInput?.value || '',
            notes: notesInput?.value || '',
        };

        document.addEventListener('click', (event) => {
            actionMenus.forEach((menu) => {
                if (!menu.contains(event.target)) {
                    menu.removeAttribute('open');
                }
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            actionMenus.forEach((menu) => menu.removeAttribute('open'));
        });

        const applyRenewalPreset = () => {
            if (!durationPreset || !newEndDateInput || !activeCurrentEndDate || durationPreset.value === 'custom') {
                return;
            }

            const endDate = new Date(activeCurrentEndDate + 'T00:00:00');

            if (Number.isNaN(endDate.getTime())) {
                return;
            }

            switch (durationPreset.value) {
                case '7_days':
                    endDate.setDate(endDate.getDate() + 7);
                    break;
                case '15_days':
                    endDate.setDate(endDate.getDate() + 15);
                    break;
                case '30_days':
                    endDate.setDate(endDate.getDate() + 30);
                    break;
                case '3_months':
                    endDate.setMonth(endDate.getMonth() + 3);
                    break;
                case '6_months':
                    endDate.setMonth(endDate.getMonth() + 6);
                    break;
                default:
                    return;
            }

            const year = endDate.getFullYear();
            const month = String(endDate.getMonth() + 1).padStart(2, '0');
            const day = String(endDate.getDate()).padStart(2, '0');
            newEndDateInput.value = `${year}-${month}-${day}`;
        };

        const setCreateMode = () => {
            if (!form) {
                return;
            }

            form.action = defaultValues.action;
            activeCurrentEndDate = defaultValues.currentEndDate;
            if (methodField) {
                methodField.disabled = true;
            }
            if (modalTitle) {
                modalTitle.textContent = 'Custom Renewal';
            }
            if (modalSubmit) {
                modalSubmit.textContent = 'Save Renewal';
            }
            if (currentEndDisplay) {
                currentEndDisplay.value = defaultValues.currentEndText;
            }
            if (durationPreset) {
                durationPreset.value = defaultValues.preset;
            }
            if (newEndDateInput) {
                newEndDateInput.value = defaultValues.newEndDate;
            }
            if (rentalAmountInput) {
                rentalAmountInput.value = defaultValues.rentalAmount;
            }
            if (depositAmountInput) {
                depositAmountInput.value = defaultValues.depositAmount;
            }
            if (transportAmountInput) {
                transportAmountInput.value = defaultValues.transportAmount;
            }
            if (otherAmountInput) {
                otherAmountInput.value = defaultValues.otherAmount;
            }
            if (paymentAmountInput) {
                paymentAmountInput.value = defaultValues.paymentAmount;
            }
            if (paymentDateInput) {
                paymentDateInput.value = defaultValues.paymentDate;
            }
            if (paymentMethodInput) {
                paymentMethodInput.value = defaultValues.paymentMethod;
            }
            if (notesInput) {
                notesInput.value = defaultValues.notes;
            }
            if (duplicateWarning) {
                duplicateWarning.style.display = duplicateWarning.dataset.visibleOnCreate === '1' ? 'block' : 'none';
            }
            applyRenewalPreset();
        };

        const setEditMode = (button) => {
            if (!form || !button) {
                return;
            }

            const renewalId = button.dataset.renewalId || '';
            const updateActionTemplate = form.dataset.updateActionTemplate || '';
            activeCurrentEndDate = button.dataset.renewalCurrentEndRaw || currentEndDate;

            form.action = updateActionTemplate.replace('__RENEWAL__', renewalId);
            if (methodField) {
                methodField.disabled = false;
            }
            if (modalTitle) {
                modalTitle.textContent = button.dataset.renewalTitle || 'Edit Renewal';
            }
            if (modalSubmit) {
                modalSubmit.textContent = button.dataset.renewalSubmit || 'Update Renewal';
            }
            if (currentEndDisplay) {
                currentEndDisplay.value = button.dataset.renewalCurrentEnd || currentEndText;
            }
            if (durationPreset) {
                durationPreset.value = button.dataset.renewalPreset || 'custom';
            }
            if (newEndDateInput) {
                newEndDateInput.value = button.dataset.renewalNewEnd || '';
            }
            if (rentalAmountInput) {
                rentalAmountInput.value = button.dataset.renewalRental || '0';
            }
            if (depositAmountInput) {
                depositAmountInput.value = button.dataset.renewalDeposit || '0';
            }
            if (transportAmountInput) {
                transportAmountInput.value = button.dataset.renewalTransport || '0';
            }
            if (otherAmountInput) {
                otherAmountInput.value = button.dataset.renewalOther || '0';
            }
            if (paymentAmountInput) {
                paymentAmountInput.value = button.dataset.renewalPayment || '0';
            }
            if (paymentDateInput) {
                paymentDateInput.value = button.dataset.renewalPaymentDate || '';
            }
            if (paymentMethodInput) {
                paymentMethodInput.value = button.dataset.renewalPaymentMethod || '';
            }
            if (notesInput) {
                notesInput.value = button.dataset.renewalNotes || '';
            }
            if (duplicateWarning) {
                duplicateWarning.style.display = 'none';
            }
        };

        const openModal = (button = null) => {
            if (button?.dataset.renewalMode === 'edit') {
                setEditMode(button);
            } else {
                setCreateMode();
            }

            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            window.rentnexisModalLock?.lock();
            const firstField = modal.querySelector('input, select, textarea, button');
            if (firstField) {
                firstField.focus({ preventScroll: true });
                window.rentnexisModalScrollFieldIntoView?.(firstField, modalBody);
            }
        };

        const closeModal = () => {
            modal.classList.remove('is-open');
            modal.setAttribute('aria-hidden', 'true');
            window.rentnexisModalLock?.unlock();
        };

        openButtons.forEach((button) => button.addEventListener('click', () => openModal(button)));
        closeButtons.forEach((button) => button.addEventListener('click', closeModal));
        durationPreset?.addEventListener('change', applyRenewalPreset);
        quickRenewForms.forEach((form) => {
            form.addEventListener('submit', (event) => {
                const days = form.dataset.renewalDays || '';
                const message = days
                    ? `This rental will be extended by ${days} day${Number(days) === 1 ? '' : 's'}. Continue?`
                    : 'This rental will be renewed immediately. Continue?';

                if (!window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });

        modal.addEventListener('click', (event) => {
            if (event.target === modal) {
                closeModal();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && modal.classList.contains('is-open')) {
                closeModal();
            }
        });

        modal.addEventListener('focusin', (event) => {
            const target = event.target;
            if (target instanceof HTMLElement && target.matches('input, select, textarea')) {
                window.rentnexisModalScrollFieldIntoView?.(target, modalBody);
            }
        });

        @if($errors->any())
            openModal();
        @endif

        setCreateMode();
    })();
</script>
@endpush


