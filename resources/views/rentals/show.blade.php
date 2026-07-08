@extends('layouts.app')

@section('content')
@php
    $deliveryRecord = $rental->deliveryRecord;
    $pickupRecord = $rental->pickupRecord;
    $activeAssets = $rental->activeRentalAssets ?? collect();
    $allRentalAssetAssignments = $rental->rentalAssets ?? collect();
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
    $returnedRentalAssetAssignments = $allRentalAssetAssignments
        ->filter(fn ($assignment) => filled($assignment->returned_at) || $assignment->asset?->asset_status === \App\Models\Asset::STATUS_AWAITING_VERIFICATION)
        ->values();
    $returnVerificationPendingStatuses = [
        \App\Models\Asset::STATUS_RENTED,
        \App\Models\Asset::STATUS_RESERVED,
        \App\Models\Asset::STATUS_AWAITING_VERIFICATION,
    ];
    $returnVerificationPendingAssets = $returnedRentalAssetAssignments
        ->filter(fn ($assignment) => in_array((string) ($assignment->asset?->asset_status ?? ''), $returnVerificationPendingStatuses, true))
        ->values();
    $hasReturnedTrackedAssets = $returnedRentalAssetAssignments->isNotEmpty();
    $hasPendingReturnVerification = $returnVerificationPendingAssets->isNotEmpty()
        || ($operationalStatus === 'returned' && $rental->pickupStatus() === 'completed' && !$hasReturnedTrackedAssets);
    $returnVerificationStatusLabel = $hasPendingReturnVerification
        ? 'Awaiting Verification'
        : ($operationalStatus === 'returned' && $hasReturnedTrackedAssets ? 'Verified' : 'Pending');
    $returnVerificationTone = $hasPendingReturnVerification
        ? 'warn'
        : ($operationalStatus === 'returned' && $hasReturnedTrackedAssets ? 'done' : 'pending');
    $returnVerificationLatestUpdate = $returnedRentalAssetAssignments
        ->map(fn ($assignment) => $assignment->updated_at)
        ->filter()
        ->sortDesc()
        ->first();
    $returnVerificationCompletedAt = $returnVerificationTone === 'done'
        ? optional($returnVerificationLatestUpdate)->format('d M Y h:i A')
        : null;
    $returnVerificationAction = $hasPendingReturnVerification
        ? ['label' => 'Open Queue', 'href' => route('assets.pending-verification')]
        : ($rental->canBeReturned() ? ['label' => 'Return', 'form' => true] : null);
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
    $rentalFulfilmentSourceLabel = \Illuminate\Support\Str::of((string) ($rental->fulfilment_source ?: 'in_house'))->replace(['_', '-'], ' ')->title();
    $isVendorSuppliedRental = $rental->isVendorSupplied();
    $defaultPickupMethod = $isVendorSuppliedRental ? 'vendor_pickup' : 'internal_pickup';
    $pickupMethodOptions = $isVendorSuppliedRental
        ? [
            'vendor_pickup' => ['Vendor Pickup', 'Vendor handles pickup and receipt.'],
            'third_party_pickup' => ['Third Party Pickup', 'Courier returns equipment to vendor.'],
            'customer_self_drop' => ['Customer Self Drop', 'Customer returns directly to vendor.'],
        ]
        : [
            'internal_pickup' => ['Internal Pickup', 'Assign delivery / operations.'],
            'third_party_pickup' => ['Third Party Pickup', 'Courier returns equipment to PH.'],
            'customer_self_drop' => ['Customer Self Drop', 'Customer drops at PH for verification.'],
        ];
    $rentalFulfilledByLabel = $rental->vendor?->name
        ?? $rental->vendorOrderDetail?->vendor?->name
        ?? ($deliveryAssigneeLabel !== 'Not assigned' ? $deliveryAssigneeLabel : null)
        ?? (string) $rentalFulfilmentSourceLabel;
    $hasOpenDeliveryTask = in_array($deliveryRecord?->status, ['pending', 'in_progress'], true);
    $hasOpenPickupTask = in_array($pickupRecord?->status, ['pending', 'in_progress'], true);
    $canAssignPickup = $canCreateDeliveries && $canUpdateRentals && $rental->pendingPickupQuantityTotal() > 0;
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
            'type' => 'button',
            'label' => 'Pickup',
            'attributes' => ['data-open-pickup-modal' => true],
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
            'type' => 'button',
            'label' => 'Assign Staff',
            'attributes' => ['data-open-pickup-modal' => true],
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
            'type' => 'button',
            'label' => 'Assign Pickup',
            'attributes' => ['data-open-pickup-modal' => true],
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
    $canViewFinanceAmounts = $viewer?->canViewRecordFinance() ?? false;
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
            'action' => $pickupRecord ? ['label' => 'Open', 'href' => route('deliveries.show', $pickupRecord)] : ($canAssignPickup ? ['label' => 'Schedule', 'button' => true, 'attributes' => ['data-open-pickup-modal' => true]] : null),
        ],
        [
            'label' => 'Return Verification',
            'status' => $returnVerificationStatusLabel,
            'person' => $pickupAssigneeLabel,
            'scheduled' => $rental->returned_at ? $rental->returned_at->format('d M Y h:i A') : 'After pickup',
            'completed' => $returnVerificationCompletedAt,
            'tone' => $returnVerificationTone,
            'action' => $returnVerificationAction,
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
    $mobileHeroActions = collect();
    if ($reminderCallHref) {
        $mobileHeroActions->push([
            'type' => 'link',
            'label' => 'Call',
            'href' => $reminderCallHref,
            'icon' => 'call',
        ]);
    }
    if ($reminderWhatsappHref) {
        $mobileHeroActions->push([
            'type' => 'link',
            'label' => 'WhatsApp',
            'href' => $reminderWhatsappHref,
            'target' => '_blank',
            'rel' => 'noopener',
            'icon' => 'whatsapp',
        ]);
    }
    if (!empty($rentalInvoice)) {
        $mobileHeroActions->push([
            'type' => 'link',
            'label' => 'Invoice',
            'href' => route('invoices.show', $rentalInvoice),
            'icon' => 'invoice',
        ]);
    } elseif (auth()->user()->canAccessModule('rentals', 'update')) {
        $mobileHeroActions->push([
            'type' => 'form',
            'label' => 'Invoice',
            'action' => route('rentals.invoice', $rental),
            'method' => 'POST',
            'icon' => 'invoice',
        ]);
    }
    if ($canCreatePayments && !in_array($rentalInvoiceStatus, ['paid', 'cancelled'], true)) {
        $mobileHeroActions->push([
            'type' => 'link',
            'label' => 'Payment',
            'href' => '#mobile-rental-finance',
            'icon' => 'payment',
        ]);
    }
    $mobileHeroActions = $mobileHeroActions->take(4)->values();
    $mobileStatusSummary = collect([
        $deliveryStatusLabel($rental->deliveryStatus()),
        $paymentStatusLabel,
        $rentalFulfilmentSourceLabel,
    ])->filter()->take(3)->values();
    $mobileOverviewItems = collect([
        ['label' => 'Source', 'value' => $rentalFulfilmentSourceLabel],
        ['label' => 'Fulfilled By', 'value' => $rentalFulfilledByLabel],
        ['label' => 'Referred By', 'value' => filled($rental->referred_by ?? null) ? $rental->referred_by : 'Not captured'],
        ['label' => 'Original Period', 'value' => optional($rental->start_date)->format('d M Y') . ' - ' . optional($rental->end_date)->format('d M Y')],
        ['label' => 'Due Date', 'value' => optional($rental->end_date)->format('d M Y') ?: 'Not set'],
        ['label' => 'Status', 'value' => ucfirst(str_replace('_', ' ', $operationalStatus))],
    ]);
    $mobileProductSummary = $rentalItems->map(function ($item) use ($rentalLineAmount, $currency, $allRentalAssetAssignments) {
        $orderedQty = max((int) ($item->ordered_quantity ?? $item->quantity ?? 0), 0);
        $deliveredQty = max((int) ($item->delivered_quantity ?? 0), 0);
        $itemAssetIds = collect($item->rentalAssets ?? [])->pluck('asset_id')->filter()->map(fn ($id) => (int) $id);
        $itemAssetSummary = $allRentalAssetAssignments
            ->filter(fn ($assignment) => $itemAssetIds->contains((int) ($assignment->asset_id ?? $assignment->asset?->id)))
            ->map(fn ($assignment) => $assignment->asset?->serial_number ?: $assignment->asset?->barcode_value)
            ->filter()
            ->implode(', ');

        return [
            'name' => $item->product?->name ?? 'Rental item',
            'qty' => $orderedQty,
            'status' => $deliveredQty >= $orderedQty && $orderedQty > 0 ? 'Delivered' : ($deliveredQty > 0 ? 'Partial Delivery' : 'Pending Delivery'),
            'amount' => $currency($rentalLineAmount($item)),
            'asset' => $itemAssetSummary ?: 'No tracked asset',
        ];
    });
    $mobileCustomerName = $rental->billingContactName();
    $mobileCustomerInitials = \Illuminate\Support\Str::of($mobileCustomerName)
        ->explode(' ')
        ->filter()
        ->map(fn ($part) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($part, 0, 1)))
        ->take(2)
        ->implode('') ?: 'R';
    $mobileLocation = collect([$rental->billingContactCity() ?: $rental->deliveryContactCity(), $rental->customer?->state])
        ->filter()
        ->implode(', ') ?: 'Location not set';
    $mobileDateRange = collect([
        optional($rental->start_date)->format('d M Y'),
        optional($rental->end_date)->format('d M Y'),
    ])->filter()->implode(' - ') ?: 'Dates not set';
    $mobileFinanceKpis = collect([
        ['label' => 'Rental', 'value' => $canSeeRentalFinance ? $currency($rentalLineTotal) : 'Hidden', 'tone' => 'blue', 'icon' => 'calendar'],
        ['label' => 'Invoice', 'value' => $canSeeRentalFinance ? $currency($invoiceTotalAmount) : ($rentalInvoice ? 'Generated' : 'Pending'), 'tone' => 'violet', 'icon' => 'document'],
        ['label' => 'Paid', 'value' => $canSeeRentalFinance ? $currency($invoicePaidAmount) : 'Hidden', 'tone' => 'green', 'icon' => 'wallet'],
        ['label' => 'Outstanding', 'value' => $canSeeRentalFinance ? $currency($invoiceBalanceAmount) : 'Hidden', 'tone' => $invoiceBalanceAmount > 0 ? 'red' : 'green', 'icon' => 'target'],
        ['label' => 'Deposit', 'value' => $canSeeRentalFinance ? $currency($rental->deposit_amount) : 'Hidden', 'tone' => 'amber', 'icon' => 'shield'],
        ['label' => 'Transport', 'value' => $canSeeRentalFinance ? $currency($rental->transport_amount) : 'Hidden', 'tone' => 'indigo', 'icon' => 'truck'],
    ]);
    $desktopCustomerName = $rental->billingContactName() ?: 'Customer';
    $desktopCustomerInitials = \Illuminate\Support\Str::of($desktopCustomerName)
        ->explode(' ')
        ->filter()
        ->map(fn ($part) => \Illuminate\Support\Str::upper(\Illuminate\Support\Str::substr($part, 0, 1)))
        ->take(2)
        ->implode('') ?: 'R';
    $desktopCustomerLocation = collect([
        $rental->billingContactCity() ?: $rental->deliveryContactCity(),
        $rental->customer?->state,
    ])->filter()->implode(', ') ?: 'Location not set';
    $desktopPeriodStart = optional($rental->start_date)->format('d M Y') ?: 'Start not set';
    $desktopPeriodEnd = optional($rental->end_date)->format('d M Y') ?: 'End not set';
    $desktopProductSummary = $rentalItems->count() . ' item' . ($rentalItems->count() === 1 ? '' : 's') . ' - ' . $activeAssets->count() . ' asset' . ($activeAssets->count() === 1 ? '' : 's');
    $desktopPrimaryProduct = $rentalItems->first()?->product?->name ?? 'Rental products';
    $desktopRecentActivity = ($activityLogs ?? collect())->take(4);
    $desktopKpiCards = collect([
        ['label' => 'Rental Amount', 'value' => $canSeeRentalFinance ? $currency($rentalLineTotal) : 'Hidden', 'tone' => 'blue', 'icon' => 'calendar'],
        ['label' => 'Invoice Amount', 'value' => $canSeeRentalFinance ? $currency($invoiceTotalAmount) : ($rentalInvoice ? 'Generated' : 'Pending'), 'tone' => 'violet', 'icon' => 'document'],
        ['label' => 'Paid Amount', 'value' => $canSeeRentalFinance ? $currency($invoicePaidAmount) : 'Hidden', 'tone' => 'green', 'icon' => 'wallet'],
        ['label' => 'Outstanding', 'value' => $canSeeRentalFinance ? $currency($invoiceBalanceAmount) : 'Hidden', 'tone' => $invoiceBalanceAmount > 0 ? 'red' : 'green', 'icon' => 'payment'],
        ['label' => 'Deposit', 'value' => $canSeeRentalFinance ? $currency($rental->deposit_amount) : 'Hidden', 'tone' => 'amber', 'icon' => 'shield'],
        ['label' => 'Transport', 'value' => $canSeeRentalFinance ? $currency($rental->transport_amount) : 'Hidden', 'tone' => 'indigo', 'icon' => 'truck'],
    ]);
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
        position:fixed; inset:0; background:rgba(15,23,42,0.45); display:none; align-items:flex-start; justify-content:center;
        padding:96px 12px 12px; z-index:60; overflow-y:auto; overflow-x:hidden;
    }
    .renewal-modal.is-open { display:flex; }
    .renewal-modal-panel {
        width:100%; max-width:720px; max-height:min(90vh, calc(100dvh - 120px)); display:flex; flex-direction:column; overflow:hidden; background:#fff; border-radius:18px;
        border:1px solid #dbe3ef; box-shadow:0 24px 60px rgba(15,23,42,0.2);
    }
    .renewal-modal-panel form {
        flex:1 1 auto;
        min-height:0;
        display:flex;
        flex-direction:column;
        overflow:hidden;
    }
    .renewal-modal-head, .renewal-modal-foot { flex:0 0 auto; display:flex; justify-content:space-between; align-items:center; gap:10px; padding:11px 14px; border-bottom:1px solid #e2e8f0; background:#fff; }
    .renewal-modal-foot { border-bottom:none; border-top:1px solid #e2e8f0; justify-content:flex-end; flex-wrap:wrap; }
    .renewal-modal-body { flex:1 1 auto; min-height:0; overflow-y:auto; overflow-x:hidden; -webkit-overflow-scrolling:touch; overscroll-behavior:contain; }
    .renewal-form-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:9px; padding:12px; }
    .renewal-field { display:grid; gap:5px; }
    .renewal-field label { font-size:11px; font-weight:700; color:#64748b; text-transform:uppercase; letter-spacing:.05em; }
    .renewal-field input, .renewal-field select, .renewal-field textarea {
        width:100%; min-height:36px; border:1px solid #cbd5e1; border-radius:10px; padding:7px 10px; font-size:13px; color:#0f172a; background:#fff;
    }
    .renewal-field textarea { min-height:64px; resize:vertical; }
    .renewal-span-2 { grid-column:span 2; }
    .pickup-method-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px; }
    .pickup-method-card { display:flex; gap:9px; align-items:flex-start; padding:8px 9px; border:1px solid #dbe3ef; border-radius:12px; background:#f8fafc; cursor:pointer; }
    .pickup-method-card input { width:16px; min-height:16px; margin-top:2px; accent-color:#4f46e5; }
    .pickup-method-card strong { display:block; color:#0f172a; font-size:13px; line-height:1.2; }
    .pickup-method-card small { display:block; margin-top:2px; color:#64748b; font-size:10.5px; line-height:1.25; }
    .pickup-method-card:has(input:checked) { border-color:#2563eb; background:#eff6ff; box-shadow:0 0 0 1px rgba(37,99,235,.12); }
    [data-pickup-method-panel][hidden] { display:none !important; }
    .pickup-self-drop-note { border:1px solid #fed7aa; background:#fff7ed; color:#9a3412; border-radius:12px; padding:8px 10px; font-size:12px; font-weight:700; line-height:1.3; }
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
        .pickup-method-grid { grid-template-columns:1fr; }
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
    .rental-exec-card {
        overflow:visible;
        padding:16px;
        border-color:#d7e0ec;
        background:linear-gradient(180deg,#fff 0%,#fbfdff 100%);
    }
    .rental-exec-top {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        padding-bottom:12px;
        border-bottom:1px solid #e5edf6;
    }
    .rental-exec-back {
        display:inline-flex;
        align-items:center;
        gap:8px;
        color:#334155;
        font-size:13px;
        font-weight:800;
        text-decoration:none;
    }
    .rental-exec-title-row {
        display:flex;
        align-items:center;
        gap:10px;
        flex-wrap:wrap;
        margin-top:6px;
    }
    .rental-exec-title-row h1 {
        margin:0;
        color:#0f172a;
        font-size:24px;
        line-height:1.1;
        font-weight:900;
    }
    .rental-exec-tools {
        display:flex;
        align-items:center;
        gap:8px;
        flex-wrap:wrap;
        justify-content:flex-end;
    }
    .rental-exec-grid {
        display:grid;
        grid-template-columns:minmax(260px,1.45fr) minmax(160px,.85fr) minmax(170px,.95fr) minmax(170px,.95fr) minmax(150px,.8fr) minmax(170px,.9fr);
        gap:0;
        margin-top:14px;
        border:1px solid #e5edf6;
        border-radius:14px;
        overflow:hidden;
        background:#fff;
    }
    .rental-exec-cell {
        display:grid;
        align-content:center;
        gap:7px;
        min-width:0;
        padding:14px 16px;
        border-left:1px solid #e5edf6;
    }
    .rental-exec-cell:first-child { border-left:0; }
    .rental-exec-label {
        color:#64748b;
        font-size:10px;
        font-weight:900;
        letter-spacing:.06em;
        text-transform:uppercase;
    }
    .rental-exec-value {
        color:#0f172a;
        font-size:14px;
        font-weight:900;
        line-height:1.25;
        overflow-wrap:anywhere;
    }
    .rental-exec-muted {
        color:#52647a;
        font-size:12px;
        font-weight:650;
        line-height:1.35;
    }
    .rental-exec-money {
        color:#dc2626;
        font-size:22px;
        font-weight:950;
        line-height:1.05;
    }
    .rental-exec-customer {
        display:grid;
        grid-template-columns:54px minmax(0,1fr);
        gap:12px;
        align-items:center;
    }
    .rental-exec-avatar {
        display:grid;
        place-items:center;
        width:54px;
        height:54px;
        border-radius:18px;
        background:#dcfce7;
        color:#15803d;
        font-size:22px;
        font-weight:950;
    }
    .rental-exec-customer strong {
        display:block;
        color:#0f172a;
        font-size:16px;
        line-height:1.2;
        overflow-wrap:anywhere;
    }
    .rental-exec-actions {
        display:flex;
        align-items:center;
        gap:10px;
        flex-wrap:wrap;
        padding-top:14px;
        margin-top:14px;
        border-top:1px solid #e5edf6;
    }
    .rental-exec-actions .detail-btn,
    .rental-exec-actions .detail-btn-secondary {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:8px;
        min-height:38px;
        padding:9px 18px;
        border-radius:10px;
        font-size:13px;
        font-weight:900;
    }
    .rental-exec-actions .rental-action-call { color:#2563eb; border-color:#bfdbfe; background:#eff6ff; }
    .rental-exec-actions .rental-action-whatsapp { color:#128c7e; border-color:#bbf7d0; background:#ecfdf5; }
    .rental-exec-actions .rental-action-invoice { color:#4f46e5; border-color:#c7d2fe; background:#eef2ff; }
    .rental-exec-actions .rental-action-pay { color:#b45309; border-color:#fed7aa; background:#fff7ed; }
    .rental-exec-actions .rental-action-call:hover,
    .rental-exec-actions .rental-action-whatsapp:hover,
    .rental-exec-actions .rental-action-invoice:hover,
    .rental-exec-actions .rental-action-pay:hover { filter:saturate(1.05) brightness(.99); }
    .rental-exec-actions .rental-renew-primary {
        background:#0f172a;
        border-color:#0f172a;
        color:#fff;
        min-width:170px;
    }
    .rental-kpi-strip {
        display:grid;
        grid-template-columns:repeat(6,minmax(150px,1fr));
        gap:10px;
    }
    .rental-kpi-card {
        display:grid;
        grid-template-columns:40px minmax(0,1fr);
        gap:10px;
        align-items:center;
        padding:12px 14px;
        border:1px solid #dbe3ef;
        border-radius:14px;
        background:#fff;
        box-shadow:0 8px 20px rgba(15,23,42,.045);
        min-width:0;
    }
    .rental-kpi-icon {
        display:grid;
        place-items:center;
        flex:0 0 auto;
        width:40px;
        height:40px;
        border-radius:12px;
        background:#eff6ff;
        color:#2563eb;
        font-weight:950;
        line-height:1;
        overflow:hidden;
    }
    .rental-kpi-icon svg,
    .rental-overview-icon svg,
    .rental-activity-dot svg,
    .rental-action-svg {
        width:18px;
        height:18px;
        display:block;
        flex:0 0 auto;
    }
    .rental-kpi-card.green .rental-kpi-icon { background:#dcfce7; color:#15803d; }
    .rental-kpi-card.red .rental-kpi-icon { background:#fee2e2; color:#dc2626; }
    .rental-kpi-card.amber .rental-kpi-icon { background:#fef3c7; color:#d97706; }
    .rental-kpi-card.violet .rental-kpi-icon { background:#ede9fe; color:#6d28d9; }
    .rental-kpi-card.indigo .rental-kpi-icon { background:#e0e7ff; color:#4338ca; }
    .rental-kpi-card span {
        display:block;
        color:#64748b;
        font-size:10px;
        font-weight:900;
        letter-spacing:.05em;
        text-transform:uppercase;
    }
    .rental-kpi-card strong {
        display:block;
        margin-top:4px;
        color:#0f172a;
        font-size:16px;
        line-height:1.15;
        font-weight:950;
        overflow-wrap:anywhere;
    }
    .rental-workspace-shell {
        overflow:visible;
    }
    .rental-workspace-tabs {
        padding:0 12px;
        border-bottom:1px solid #e5edf6;
        background:#fff;
    }
    .rental-tab-row {
        display:flex;
        gap:6px;
        overflow-x:auto;
        -webkit-overflow-scrolling:touch;
    }
    .rental-tab-row a {
        display:inline-flex;
        align-items:center;
        min-height:42px;
        padding:0 16px;
        color:#334155;
        border-bottom:2px solid transparent;
        font-size:13px;
        font-weight:900;
        text-decoration:none;
        white-space:nowrap;
    }
    .rental-tab-row a:first-child {
        color:#2563eb;
        border-bottom-color:#2563eb;
    }
    .rental-overview-grid-v2 {
        display:grid;
        grid-template-columns:1.05fr 1fr 1fr .95fr 1.15fr;
        gap:10px;
        padding:12px;
    }
    .rental-overview-card-v2 {
        display:grid;
        gap:10px;
        align-content:start;
        min-width:0;
        padding:14px;
        border:1px solid #dbe3ef;
        border-radius:14px;
        background:#fff;
    }
    .rental-overview-card-v2 h3 {
        margin:0;
        color:#0f172a;
        font-size:14px;
        line-height:1.2;
        font-weight:950;
    }
    .rental-overview-title {
        display:flex;
        align-items:center;
        gap:9px;
    }
    .rental-overview-icon {
        display:grid;
        place-items:center;
        flex:0 0 auto;
        width:34px;
        height:34px;
        border-radius:11px;
        background:#eef2ff;
        color:#4338ca;
        font-size:13px;
        font-weight:950;
        line-height:1;
        overflow:hidden;
    }
    .rental-line-list {
        display:grid;
        gap:8px;
    }
    .rental-line-item {
        display:grid;
        grid-template-columns:minmax(0,1fr) auto;
        gap:10px;
        align-items:start;
        color:#475569;
        font-size:12px;
        line-height:1.35;
    }
    .rental-line-item strong {
        color:#0f172a;
        font-weight:900;
        text-align:right;
        overflow-wrap:anywhere;
    }
    .rental-activity-mini {
        display:grid;
        gap:8px;
    }
    .rental-activity-mini-row {
        display:grid;
        grid-template-columns:24px minmax(0,1fr) auto;
        gap:8px;
        align-items:start;
        padding-top:8px;
        border-top:1px solid #edf2f7;
    }
    .rental-activity-mini-row:first-child { border-top:0; padding-top:0; }
    .rental-activity-dot {
        display:grid;
        place-items:center;
        flex:0 0 auto;
        width:24px;
        height:24px;
        border-radius:999px;
        background:#dcfce7;
        color:#15803d;
        font-size:11px;
        font-weight:950;
        line-height:1;
        overflow:hidden;
    }
    .rental-activity-mini-row strong {
        display:block;
        color:#0f172a;
        font-size:12px;
        line-height:1.25;
        overflow-wrap:anywhere;
    }
    .rental-activity-mini-row span,
    .rental-activity-mini-row time {
        color:#64748b;
        font-size:11px;
        line-height:1.35;
    }
    .rental-exec-card.compact-density,
    #rental-executive-command-center {
        padding:12px;
    }
    #rental-executive-command-center .rental-exec-top {
        padding-bottom:8px;
        gap:8px;
    }
    #rental-executive-command-center .rental-exec-title-row {
        margin-top:3px;
        gap:7px;
    }
    #rental-executive-command-center .rental-exec-title-row h1 {
        font-size:22px;
    }
    #rental-executive-command-center .rcc-chip {
        padding:3px 8px;
        font-size:10px;
    }
    #rental-executive-command-center .rental-exec-grid {
        margin-top:8px;
        grid-template-columns:minmax(260px,1.35fr) minmax(130px,.72fr) minmax(160px,.9fr) minmax(150px,.85fr) minmax(145px,.8fr) minmax(150px,.8fr);
    }
    #rental-executive-command-center .rental-exec-cell {
        padding:10px 12px;
        gap:4px;
        min-height:78px;
    }
    #rental-executive-command-center .rental-exec-customer {
        grid-template-columns:42px minmax(0,1fr);
        gap:9px;
    }
    #rental-executive-command-center .rental-exec-avatar {
        width:42px;
        height:42px;
        border-radius:14px;
        font-size:18px;
    }
    #rental-executive-command-center .rental-exec-customer strong {
        font-size:15px;
    }
    #rental-executive-command-center .rental-exec-muted,
    #rental-executive-command-center .rental-exec-value {
        font-size:11.5px;
        line-height:1.22;
    }
    #rental-executive-command-center .rental-exec-label {
        font-size:9px;
    }
    #rental-executive-command-center .rental-exec-money {
        font-size:19px;
    }
    #rental-executive-command-center .rental-exec-actions {
        margin-top:8px;
        padding-top:8px;
        gap:8px;
    }
    #rental-executive-command-center .rental-exec-actions .detail-btn,
    #rental-executive-command-center .rental-exec-actions .detail-btn-secondary,
    #rental-executive-command-center .rental-exec-tools .detail-btn,
    #rental-executive-command-center .rental-exec-tools .detail-btn-secondary {
        min-height:34px;
        padding:7px 13px;
        border-radius:9px;
        font-size:12px;
    }
    #rental-executive-command-center .rental-renew-primary {
        min-width:148px;
    }
    #rental-kpi-strip {
        gap:8px;
    }
    #rental-kpi-strip .rental-kpi-card {
        grid-template-columns:30px minmax(0,1fr);
        gap:8px;
        padding:8px 10px;
        min-height:56px;
    }
    #rental-kpi-strip .rental-kpi-icon {
        width:30px;
        height:30px;
        border-radius:9px;
        font-size:11px;
    }
    #rental-kpi-strip .rental-kpi-card span {
        font-size:9px;
    }
    #rental-kpi-strip .rental-kpi-card strong {
        margin-top:2px;
        font-size:14px;
    }
    #rental-workspace-shell .rental-workspace-tabs {
        padding:0 10px;
    }
    #rental-workspace-shell .rental-tab-row a {
        min-height:38px;
        padding:0 13px;
        font-size:12px;
    }
    #rental-overview-section {
        gap:8px;
        padding:10px;
    }
    #rental-overview-section .rental-overview-card-v2 {
        gap:8px;
        padding:11px;
        min-height:0;
    }
    #rental-overview-section .rental-overview-title {
        gap:7px;
    }
    #rental-overview-section .rental-overview-icon {
        width:28px;
        height:28px;
        border-radius:9px;
        font-size:11px;
    }
    #rental-overview-section .rental-overview-card-v2 h3 {
        font-size:13px;
    }
    #rental-overview-section .rental-line-list {
        gap:5px;
    }
    #rental-overview-section .rental-line-item {
        gap:8px;
        font-size:11.5px;
        line-height:1.25;
    }
    #rental-overview-section .detail-btn,
    #rental-overview-section .detail-btn-secondary {
        min-height:32px;
        padding:6px 10px;
        font-size:12px;
    }
    .rcc-shell .rcc-section-head {
        padding:9px 10px 0;
    }
    .rcc-shell .rcc-section-head h2 {
        font-size:16px;
    }
    .rcc-shell .rcc-section-head p {
        font-size:11px;
        line-height:1.3;
        margin-top:2px;
    }
    .rcc-shell .rcc-workflow {
        padding:10px;
        gap:8px;
    }
    .rcc-shell .rcc-workflow-item {
        padding:9px;
        gap:5px;
        min-height:118px;
    }
    .rcc-shell .rcc-workflow-item strong {
        font-size:13px;
    }
    .rcc-shell .rcc-workflow-item small {
        font-size:10.5px;
        line-height:1.25;
    }
    .rcc-shell .rcc-workflow-item a,
    .rcc-shell .rcc-workflow-item button {
        min-height:30px;
        padding:5px 9px;
        font-size:11px;
    }
    .rcc-shell .rcc-product-list {
        padding:10px;
        gap:8px;
    }
    .rcc-shell .rcc-product-row {
        grid-template-columns:minmax(260px,.9fr) minmax(0,2fr);
        padding:10px 12px;
        gap:8px;
        align-items:center;
    }
    .rcc-product-metrics {
        grid-template-columns:repeat(3,minmax(64px,.55fr)) minmax(145px,1fr) minmax(130px,.9fr) minmax(100px,.65fr);
        gap:7px;
        align-items:center;
    }
    .rcc-shell .rcc-cell span {
        font-size:9px;
    }
    .rcc-shell .rcc-cell strong {
        font-size:12.5px;
    }
    .rcc-shell .rcc-cell small {
        font-size:10.5px;
    }
    .rcc-shell .rcc-chip {
        padding:3px 8px;
        font-size:10px;
    }
    .rcc-shell .rcc-finance-panel,
    .rcc-shell .rcc-renewal-summary,
    .rcc-shell .rcc-timeline-list {
        padding:10px;
    }
    .rcc-shell .rcc-finance-kpis {
        grid-template-columns:repeat(6,minmax(100px,1fr));
        gap:7px;
    }
    .rcc-shell .rcc-money {
        padding:7px 9px;
        border-radius:10px;
    }
    .rcc-shell .rcc-money strong {
        font-size:13px;
    }
    .rcc-shell .rcc-money span {
        font-size:9px;
    }
    .rcc-finance-actions,
    .rcc-compact-actions {
        gap:6px;
    }
    .rcc-finance-actions .detail-btn,
    .rcc-finance-actions .detail-btn-secondary,
    .rcc-details-panel .detail-btn,
    .rcc-details-panel .detail-btn-secondary {
        min-height:32px;
        padding:6px 10px;
        font-size:12px;
    }
    .rcc-payment-details .rcc-payment-form {
        grid-template-columns:130px 130px minmax(120px,1fr) minmax(150px,1.2fr) auto;
        gap:6px;
    }
    .rcc-payment-details .rcc-payment-form input {
        min-height:32px;
        padding:6px 8px;
        font-size:12px;
    }
    .rcc-details-panel summary,
    .rcc-details summary {
        padding:8px 10px;
        min-height:36px;
        font-size:12px;
    }
    .rcc-details-panel-body {
        gap:8px;
        padding:0 10px 10px;
    }
    .rcc-mini-grid {
        gap:7px;
    }
    .rcc-timeline-item {
        grid-template-columns:92px minmax(0,1fr);
        gap:8px;
        padding-top:6px;
    }
    .rcc-timeline-item time,
    .rcc-timeline-item p {
        font-size:10.5px;
    }
    .rcc-timeline-item strong {
        font-size:12px;
    }
    .rcc-accordion-section {
        overflow:hidden;
    }
    .rcc-accordion-section > summary {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
        min-height:46px;
        padding:9px 12px;
        cursor:pointer;
        list-style:none;
        border-bottom:1px solid #e5edf6;
    }
    .rcc-accordion-section > summary::-webkit-details-marker { display:none; }
    .rcc-accordion-title {
        display:flex;
        align-items:center;
        gap:10px;
        min-width:0;
    }
    .rcc-accordion-title strong {
        color:#0f172a;
        font-size:14px;
        font-weight:950;
    }
    .rcc-accordion-title span:not(.rcc-chip) {
        color:#64748b;
        font-size:11px;
        font-weight:700;
    }
    .rcc-accordion-meta {
        display:flex;
        align-items:center;
        gap:8px;
        flex-wrap:wrap;
        justify-content:flex-end;
        color:#334155;
        font-size:12px;
        font-weight:900;
    }
    .rcc-accordion-section:not([open]) > .rcc-accordion-body {
        display:none;
    }
    .rcc-accordion-section[open] > summary {
        background:#fbfdff;
    }
    .rcc-accordion-chevron::after {
        content:'v';
        color:#64748b;
        font-weight:900;
    }
    .rcc-accordion-section[open] .rcc-accordion-chevron::after {
        content:'^';
    }
    @media (max-width: 1280px) {
        .rental-exec-grid { grid-template-columns:repeat(3,minmax(0,1fr)); }
        .rental-exec-cell:nth-child(4) { border-left:0; border-top:1px solid #e5edf6; }
        .rental-exec-cell:nth-child(5),
        .rental-exec-cell:nth-child(6) { border-top:1px solid #e5edf6; }
        .rental-kpi-strip { grid-template-columns:repeat(3,minmax(0,1fr)); }
        .rental-overview-grid-v2 { grid-template-columns:repeat(2,minmax(0,1fr)); }
        .rental-overview-card-v2:last-child { grid-column:1 / -1; }
    }
    @media (max-width: 900px) {
        .rental-exec-grid,
        .rental-kpi-strip,
        .rental-overview-grid-v2 { grid-template-columns:1fr; }
        .rental-exec-cell { border-left:0; border-top:1px solid #e5edf6; }
        .rental-exec-cell:first-child { border-top:0; }
        .rental-exec-top { align-items:flex-start; flex-direction:column; }
        .rental-exec-tools { justify-content:flex-start; }
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
    .ph-rental-mobile-shell,
    .ph-rental-mobile-footer {
        display:none;
    }
    @media (max-width: 767px) {
        .mobile-topbar-search {
            display:none !important;
        }
        .app-shell-main {
            padding-top:82px !important;
        }
        .ph-rental-reference-shell > .ph-operational-header,
        #rental-command-center,
        #rental-executive-command-center,
        #rental-kpi-strip,
        #rental-workspace-shell,
        #rental-actions-bar,
        #rental-operations-workspace,
        .rcc-two-col,
        #renewal-workspace,
        #rental-timeline-workspace,
        .ph-mobile-action-bar,
        .ph-mobile-action-spacer {
            display:none !important;
        }
        .container.detail-page.rcc-shell {
            padding:6px 10px calc(180px + env(safe-area-inset-bottom, 0px));
            overflow-x:hidden;
            gap:8px;
        }
        .ph-rental-reference-shell {
            gap:8px;
        }
        .ph-rental-mobile-shell {
            display:grid;
            gap:8px;
            min-width:0;
        }
        .ph-rental-mobile-top {
            display:grid;
            grid-template-columns:36px 1fr 36px;
            align-items:center;
            gap:8px;
            padding:0 2px 2px;
        }
        .ph-rental-icon-btn {
            min-width:36px;
            width:36px;
            height:36px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            border:0;
            border-radius:999px;
            background:#fff;
            color:#0f172a;
            text-decoration:none;
        }
        .ph-rental-mobile-title {
            display:flex;
            align-items:center;
            gap:8px;
            min-width:0;
        }
        .ph-rental-mobile-title strong {
            font-size:20px;
            line-height:1.05;
            color:#0f172a;
        }
        .ph-rental-pill {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:22px;
            padding:3px 9px;
            border-radius:999px;
            font-size:11px;
            font-weight:900;
            text-transform:uppercase;
            white-space:nowrap;
        }
        .ph-rental-pill.success { background:#dcfce7; color:#15803d; }
        .ph-rental-pill.warning { background:#fef3c7; color:#b45309; }
        .ph-rental-pill.danger { background:#fee2e2; color:#b91c1c; }
        .ph-rental-pill.info { background:#dbeafe; color:#1d4ed8; }
        .ph-rental-pill.neutral { background:#e2e8f0; color:#334155; }
        .ph-rental-mobile-hero {
            overflow:visible;
            position:relative;
            z-index:20;
            border:1px solid #bbf7d0;
            border-radius:16px;
            background:linear-gradient(135deg, #f0fdf4 0%, #fff 62%);
            box-shadow:0 12px 28px rgba(15,23,42,.07);
        }
        .ph-rental-hero-main {
            display:grid;
            grid-template-columns:1fr;
            gap:8px;
            padding:12px;
        }
        .ph-rental-customer-row {
            display:grid;
            grid-template-columns:46px 1fr;
            gap:10px;
            align-items:center;
            min-width:0;
        }
        .ph-rental-avatar {
            width:46px;
            height:46px;
            border-radius:999px;
            display:flex;
            align-items:center;
            justify-content:center;
            background:#dcfce7;
            color:#16a34a;
            font-size:20px;
            font-weight:900;
            border:1px solid #bbf7d0;
        }
        .ph-rental-customer-copy {
            display:grid;
            gap:2px;
            min-width:0;
        }
        .ph-rental-customer-copy small,
        .ph-rental-meta-line {
            color:#475569;
            font-size:11.5px;
            line-height:1.25;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .ph-rental-customer-copy h1 {
            margin:0;
            color:#0f172a;
            font-size:17px;
            line-height:1.15;
        }
        .ph-rental-amount-panel {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            border-top:1px solid #dcfce7;
            padding-top:8px;
        }
        .ph-rental-amount-panel strong {
            color:#b91c1c;
            font-size:20px;
            line-height:1;
        }
        .ph-rental-amount-panel span {
            display:block;
            color:#475569;
            font-size:10px;
            font-weight:900;
            text-transform:uppercase;
        }
        .ph-rental-period-strip {
            display:flex;
            align-items:center;
            gap:8px;
            padding:8px 12px;
            border-top:1px solid #dcfce7;
            border-bottom:1px solid #dcfce7;
            color:#0f172a;
            font-weight:800;
            font-size:12.5px;
            line-height:1.25;
            min-width:0;
        }
        .ph-rental-period-strip strong {
            min-width:0;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .ph-rental-period-strip span:last-child {
            color:#16a34a;
            font-size:12px;
            white-space:nowrap;
        }
        .ph-rental-quick-actions {
            display:flex;
            gap:7px;
            overflow:visible;
            padding:9px 10px 11px;
            position:relative;
            z-index:30;
        }
        .ph-rental-quick-actions::-webkit-scrollbar {
            display:none;
        }
        .ph-rental-quick-action,
        .ph-rental-quick-action button,
        .ph-rental-more-summary {
            min-width:54px;
            width:54px;
            border:0;
            background:transparent;
            color:#0f172a;
            text-decoration:none;
            font-size:10.5px;
            font-weight:800;
            text-align:center;
            display:grid;
            justify-items:center;
            gap:3px;
            cursor:pointer;
            white-space:nowrap;
        }
        .ph-rental-action-icon {
            width:38px;
            height:38px;
            border-radius:13px;
            display:flex;
            align-items:center;
            justify-content:center;
            background:#f8fafc;
            color:#2563eb;
            box-shadow:0 8px 18px rgba(15,23,42,.08);
            border:1px solid #e2e8f0;
            font-size:14px;
            font-weight:900;
        }
        .ph-rental-action-icon svg {
            width:18px;
            height:18px;
            display:block;
        }
        .ph-rental-action-icon.invoice { color:#4f46e5; background:#eef2ff; }
        .ph-rental-action-icon.call,
        .ph-rental-action-icon.whatsapp { color:#16a34a; background:#f0fdf4; }
        .ph-rental-action-icon.payment { color:#ea580c; background:#fff7ed; }
        .ph-rental-kpi-grid {
            display:flex;
            gap:7px;
            overflow-x:auto;
            overscroll-behavior-x:contain;
            scrollbar-width:none;
            padding:0 2px 3px;
            max-width:100%;
            box-sizing:border-box;
        }
        .ph-rental-kpi-grid::-webkit-scrollbar {
            display:none;
        }
        .ph-rental-kpi-card {
            flex:0 0 auto;
            min-width:76px;
            min-height:66px;
            border:1px solid #dbe3ef;
            border-radius:14px;
            background:#fff;
            padding:7px 8px;
            display:grid;
            grid-template-columns:1fr;
            justify-items:center;
            align-content:center;
            gap:3px;
            box-shadow:0 8px 20px rgba(15,23,42,.05);
            box-sizing:border-box;
        }
        .ph-rental-kpi-dot {
            width:24px;
            height:24px;
            border-radius:999px;
            display:flex;
            align-items:center;
            justify-content:center;
        }
        .ph-rental-kpi-dot svg {
            width:14px;
            height:14px;
            display:block;
        }
        .ph-rental-kpi-card strong {
            color:#0f172a;
            font-size:12px;
            line-height:1;
            text-align:center;
            white-space:nowrap;
        }
        .ph-rental-kpi-card span {
            color:#334155;
            font-size:10px;
            font-weight:800;
            text-align:center;
            white-space:nowrap;
        }
        .ph-rental-kpi-card.blue .ph-rental-kpi-dot { background:#dbeafe; color:#1d4ed8; }
        .ph-rental-kpi-card.violet .ph-rental-kpi-dot { background:#ede9fe; color:#6d28d9; }
        .ph-rental-kpi-card.green .ph-rental-kpi-dot { background:#dcfce7; color:#15803d; }
        .ph-rental-kpi-card.red .ph-rental-kpi-dot { background:#fee2e2; color:#b91c1c; }
        .ph-rental-kpi-card.amber .ph-rental-kpi-dot { background:#fef3c7; color:#b45309; }
        .ph-rental-kpi-card.indigo .ph-rental-kpi-dot { background:#e0e7ff; color:#4338ca; }
        .ph-rental-mobile-accordions {
            border:1px solid #e2e8f0;
            border-radius:15px;
            background:#fff;
            overflow:hidden;
            box-shadow:0 12px 28px rgba(15,23,42,.06);
        }
        .ph-rental-mobile-accordion {
            border-top:1px solid #e2e8f0;
        }
        .ph-rental-mobile-accordion:first-child {
            border-top:0;
        }
        .ph-rental-mobile-accordion summary {
            list-style:none;
            display:grid;
            grid-template-columns:42px 1fr 24px;
            align-items:center;
            gap:8px;
            padding:10px 12px;
            cursor:pointer;
            min-height:56px;
        }
        .ph-rental-mobile-accordion summary::-webkit-details-marker {
            display:none;
        }
        .ph-rental-section-icon {
            width:32px;
            height:32px;
            border-radius:11px;
            display:flex;
            align-items:center;
            justify-content:center;
            background:#eef2ff;
            color:#4f46e5;
            font-weight:900;
        }
        .ph-rental-section-title {
            min-width:0;
        }
        .ph-rental-section-title strong {
            display:block;
            color:#0f172a;
            font-size:14px;
            line-height:1.1;
        }
        .ph-rental-section-title span {
            display:block;
            color:#64748b;
            font-size:11px;
            line-height:1.25;
            white-space:nowrap;
            overflow:hidden;
            text-overflow:ellipsis;
        }
        .ph-rental-chevron {
            color:#334155;
            font-size:20px;
            text-align:center;
        }
        .ph-rental-mobile-accordion[open] .ph-rental-chevron {
            transform:rotate(180deg);
        }
        .ph-rental-accordion-body {
            display:grid;
            gap:7px;
            padding:0 12px 12px 52px;
            min-width:0;
        }
        .ph-rental-info-grid {
            display:grid;
            grid-template-columns:1fr 1fr;
            gap:8px 12px;
        }
        .ph-rental-info-item {
            display:grid;
            gap:3px;
            min-width:0;
        }
        .ph-rental-info-item span {
            color:#64748b;
            font-size:10px;
            font-weight:900;
            text-transform:uppercase;
        }
        .ph-rental-info-item strong {
            color:#0f172a;
            font-size:13px;
            line-height:1.25;
            word-break:break-word;
        }
        .ph-rental-mobile-line {
            display:flex;
            justify-content:space-between;
            gap:10px;
            padding:7px 0;
            border-top:1px solid #eef2f7;
            color:#475569;
            font-size:12px;
            min-width:0;
        }
        .ph-rental-mobile-line span,
        .ph-rental-mobile-line strong {
            min-width:0;
            overflow-wrap:anywhere;
        }
        .ph-rental-mobile-line:first-child {
            border-top:0;
            padding-top:0;
        }
        .ph-rental-mobile-line strong {
            color:#0f172a;
            text-align:right;
        }
        .ph-rental-mobile-footer {
            position:fixed;
            left:10px;
            right:10px;
            bottom:calc(var(--ph-mobile-nav-height, 74px) + env(safe-area-inset-bottom, 0px) + 6px);
            z-index:45;
            display:none;
            grid-template-columns:minmax(0, 1.3fr) minmax(0, .9fr);
            gap:6px;
            padding:6px;
            border:1px solid #e2e8f0;
            border-radius:16px;
            background:rgba(255,255,255,.96);
            box-shadow:0 14px 34px rgba(15,23,42,.16);
            backdrop-filter:blur(10px);
        }
        .ph-rental-mobile-footer .detail-btn,
        .ph-rental-mobile-footer .detail-btn-secondary,
        .ph-rental-mobile-footer button {
            min-height:38px;
            width:100%;
            justify-content:center;
            border-radius:12px;
            font-size:12px;
            font-weight:900;
            padding:8px 10px;
        }
        .ph-rental-footer-more {
            position:relative;
        }
        .ph-rental-footer-more summary {
            min-height:38px;
            border:1px solid #cbd5e1;
            border-radius:12px;
            display:flex;
            align-items:center;
            justify-content:center;
            gap:8px;
            font-weight:900;
            font-size:12px;
            cursor:pointer;
            list-style:none;
        }
        .ph-rental-footer-more summary::-webkit-details-marker {
            display:none;
        }
        .ph-rental-footer-panel {
            position:absolute;
            right:0;
            bottom:calc(100% + 8px);
            width:min(280px, calc(100vw - 22px));
            display:grid;
            gap:6px;
            padding:8px;
            border:1px solid #e2e8f0;
            border-radius:16px;
            background:#fff;
            box-shadow:0 16px 34px rgba(15,23,42,.18);
        }
        .ph-rental-top-more .mobile-actions-panel,
        .ph-rental-hero-more .mobile-actions-panel {
            top:calc(100% + 8px);
            bottom:auto;
            z-index:120;
            max-height:min(70vh, 420px);
            overflow-y:auto;
        }
        .ph-rental-hero-more .mobile-actions-panel {
            right:0;
            width:min(282px, calc(100vw - 42px));
            gap:6px;
            padding:8px;
        }
        .ph-rental-hero-more .mobile-actions-panel form {
            margin:0;
            width:100%;
        }
        .ph-rental-hero-more .mobile-actions-panel a,
        .ph-rental-hero-more .mobile-actions-panel button,
        .ph-rental-top-more .mobile-actions-panel a,
        .ph-rental-top-more .mobile-actions-panel button {
            width:100%;
            min-height:42px;
            display:flex;
            align-items:center;
            justify-content:flex-start;
            text-align:left;
            padding:10px 12px;
            line-height:1.2;
            box-sizing:border-box;
        }
        .ph-rental-hero-more .mobile-actions-panel button,
        .ph-rental-top-more .mobile-actions-panel button {
            font:inherit;
        }
        .ph-rental-footer-panel a,
        .ph-rental-footer-panel button {
            min-height:40px;
            padding:8px 10px;
            border-radius:12px;
            border:1px solid #e2e8f0;
            background:#f8fafc;
            color:#0f172a;
            text-decoration:none;
            display:flex;
            align-items:center;
            justify-content:flex-start;
            font-size:12px;
            font-weight:800;
        }
        .ph-rental-mobile-footer {
            display:grid;
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

        <section class="ph-rental-mobile-shell" aria-label="Rental mobile overview">
            <div class="ph-rental-mobile-top">
                <a href="{{ route('rentals.index') }}" class="ph-rental-icon-btn" aria-label="Back to rentals">
                    <span aria-hidden="true">&larr;</span>
                </a>
                <div class="ph-rental-mobile-title">
                    <strong>Rental #{{ $rental->id }}</strong>
                    <span class="ph-rental-pill {{ $rentalStatusTone }}">{{ ucfirst(str_replace('_', ' ', $operationalStatus)) }}</span>
                </div>
                <details class="mobile-actions-menu ph-rental-top-more" style="position:relative;" data-rental-mobile-menu>
                    <summary class="ph-rental-icon-btn" aria-label="More rental actions">&ctdot;</summary>
                    <div class="mobile-actions-panel">
                        @foreach($rentalMoreActions->take(8) as $action)
                            @if(($action['type'] ?? 'link') === 'form')
                                <form action="{{ $action['action'] }}" method="POST" style="margin:0;">
                                    @csrf
                                    @if(($action['method'] ?? 'POST') !== 'POST')
                                        @method($action['method'])
                                    @endif
                                    <button type="submit" @if(!empty($action['confirm'])) onclick="return confirm('{{ $action['confirm'] }}');" @endif class="{{ !empty($action['danger']) ? 'is-danger' : '' }}">{{ $action['label'] }}</button>
                                </form>
                            @elseif(($action['type'] ?? 'link') === 'button')
                                <button type="button" @foreach(($action['attributes'] ?? []) as $attribute => $value) {{ $attribute }}="{{ is_bool($value) ? ($value ? 'true' : 'false') : $value }}" @endforeach>{{ $action['label'] }}</button>
                            @else
                                <a href="{{ $action['href'] }}" @if(!empty($action['target'])) target="{{ $action['target'] }}" rel="noopener" @endif>{{ $action['label'] }}</a>
                            @endif
                        @endforeach
                    </div>
                </details>
            </div>

            <div class="ph-rental-mobile-hero">
                <div class="ph-rental-hero-main">
                    <div class="ph-rental-customer-row">
                        <div class="ph-rental-avatar">{{ $mobileCustomerInitials }}</div>
                        <div class="ph-rental-customer-copy">
                            <small>{{ $rentalTypeLabel }}</small>
                            <h1>{{ $mobileCustomerName }}</h1>
                            <div class="ph-rental-meta-line">{{ $reminderContactPhone ?: 'No phone' }}</div>
                            <div class="ph-rental-meta-line">{{ $mobileLocation }}</div>
                        </div>
                    </div>
                    <div class="ph-rental-amount-panel">
                        <div>
                            <strong>{{ $canSeeRentalFinance ? $currency($invoiceBalanceAmount) : 'Hidden' }}</strong>
                            <span>Due Amount</span>
                        </div>
                        <span class="ph-rental-pill {{ $paymentStatusTone }}">{{ $paymentStatusLabel }}</span>
                    </div>
                </div>
                <div class="ph-rental-period-strip">
                    <strong>{{ $mobileDateRange }}</strong>
                    <span>&bull;</span>
                    <span>{{ $daysRemainingLabel }}</span>
                </div>
                <div class="ph-rental-quick-actions">
                    @foreach($mobileHeroActions as $action)
                        @php
                            $iconClass = $action['icon'] ?? 'action';
                            $iconLabel = match ($iconClass) {
                                'call' => '',
                                'whatsapp' => '',
                                'invoice' => '',
                                'payment' => 'Pay',
                                default => 'Go',
                            };
                            $mobileActionLabel = match ($action['label']) {
                                'WhatsApp' => 'WA',
                                'Invoice' => 'Inv',
                                'Payment' => 'Pay',
                                default => $action['label'],
                            };
                        @endphp
                        @if(($action['type'] ?? 'link') === 'form')
                            <form action="{{ $action['action'] }}" method="POST" class="ph-rental-quick-action">
                                @csrf
                                @if(($action['method'] ?? 'POST') !== 'POST')
                                    @method($action['method'])
                                @endif
                                <button type="submit">
                                    <span class="ph-rental-action-icon {{ $iconClass }}">
                                        @if($iconClass === 'whatsapp')
                                            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20 11.4c0 4.7-3.9 8.6-8.8 8.6-1.5 0-3-.4-4.2-1.1L3 20l1.2-3.7A8.4 8.4 0 0 1 2.4 11.4C2.4 6.7 6.3 3 11.2 3 16.1 3 20 6.7 20 11.4Zm-4.8 2.2c-.2-.1-1.2-.6-1.4-.7s-.3-.1-.4.1-.5.7-.7.9-.3.2-.5.1a5.9 5.9 0 0 1-1.7-1c-.6-.5-1-1.2-1.1-1.4-.1-.2 0-.3.1-.4l.3-.4.2-.3v-.4c0-.1-.4-1.1-.6-1.6-.2-.4-.3-.4-.4-.4h-.4c-.1 0-.4 0-.6.3-.2.2-.8.8-.8 1.9s.8 2.1 1 2.3c.1.1 1.5 2.3 3.8 3.2.5.2 1 .4 1.3.5.6.2 1.2.2 1.7.1.5-.1 1.2-.5 1.4-1 .2-.5.2-1 .1-1Z"/></svg>
                                        @elseif($iconClass === 'call')
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.33 1.77.63 2.61a2 2 0 0 1-.45 2.11L8.1 9.9a16 16 0 0 0 6 6l1.46-1.19a2 2 0 0 1 2.11-.45c.84.3 1.71.51 2.61.63A2 2 0 0 1 22 16.92z"/></svg>
                                        @elseif($iconClass === 'invoice')
                                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z"/><path d="M14 2v5h5"/><path d="M9 13h6"/><path d="M9 17h4"/></svg>
                                        @else
                                            {{ $iconLabel }}
                                        @endif
                                    </span>
                                    <span>{{ $mobileActionLabel }}</span>
                                </button>
                            </form>
                        @else
                            <a href="{{ $action['href'] }}" class="ph-rental-quick-action" @if(!empty($action['target'])) target="{{ $action['target'] }}" rel="{{ $action['rel'] ?? 'noopener' }}" @endif>
                                <span class="ph-rental-action-icon {{ $iconClass }}">
                                    @if($iconClass === 'whatsapp')
                                        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20 11.4c0 4.7-3.9 8.6-8.8 8.6-1.5 0-3-.4-4.2-1.1L3 20l1.2-3.7A8.4 8.4 0 0 1 2.4 11.4C2.4 6.7 6.3 3 11.2 3 16.1 3 20 6.7 20 11.4Zm-4.8 2.2c-.2-.1-1.2-.6-1.4-.7s-.3-.1-.4.1-.5.7-.7.9-.3.2-.5.1a5.9 5.9 0 0 1-1.7-1c-.6-.5-1-1.2-1.1-1.4-.1-.2 0-.3.1-.4l.3-.4.2-.3v-.4c0-.1-.4-1.1-.6-1.6-.2-.4-.3-.4-.4-.4h-.4c-.1 0-.4 0-.6.3-.2.2-.8.8-.8 1.9s.8 2.1 1 2.3c.1.1 1.5 2.3 3.8 3.2.5.2 1 .4 1.3.5.6.2 1.2.2 1.7.1.5-.1 1.2-.5 1.4-1 .2-.5.2-1 .1-1Z"/></svg>
                                    @elseif($iconClass === 'call')
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.33 1.77.63 2.61a2 2 0 0 1-.45 2.11L8.1 9.9a16 16 0 0 0 6 6l1.46-1.19a2 2 0 0 1 2.11-.45c.84.3 1.71.51 2.61.63A2 2 0 0 1 22 16.92z"/></svg>
                                    @elseif($iconClass === 'invoice')
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z"/><path d="M14 2v5h5"/><path d="M9 13h6"/><path d="M9 17h4"/></svg>
                                    @else
                                        {{ $iconLabel }}
                                    @endif
                                </span>
                                <span>{{ $mobileActionLabel }}</span>
                            </a>
                        @endif
                    @endforeach
                    <details class="mobile-actions-menu ph-rental-hero-more" style="position:relative;" data-rental-mobile-menu>
                        <summary class="ph-rental-more-summary">
                            <span class="ph-rental-action-icon">&ctdot;</span>
                        </summary>
                        <div class="mobile-actions-panel">
                            @foreach($rentalMoreActions->take(8) as $action)
                                @if(($action['type'] ?? 'link') === 'form')
                                    <form action="{{ $action['action'] }}" method="POST" style="margin:0;">
                                        @csrf
                                        @if(($action['method'] ?? 'POST') !== 'POST')
                                            @method($action['method'])
                                        @endif
                                        <button type="submit" @if(!empty($action['confirm'])) onclick="return confirm('{{ $action['confirm'] }}');" @endif class="{{ !empty($action['danger']) ? 'is-danger' : '' }}">{{ $action['label'] }}</button>
                                    </form>
                                @elseif(($action['type'] ?? 'link') === 'button')
                                    <button type="button" @foreach(($action['attributes'] ?? []) as $attribute => $value) {{ $attribute }}="{{ is_bool($value) ? ($value ? 'true' : 'false') : $value }}" @endforeach>{{ $action['label'] }}</button>
                                @else
                                    <a href="{{ $action['href'] }}" @if(!empty($action['target'])) target="{{ $action['target'] }}" rel="noopener" @endif>{{ $action['label'] }}</a>
                                @endif
                            @endforeach
                        </div>
                    </details>
                </div>
            </div>

            <div class="ph-rental-kpi-grid" aria-label="Rental finance summary">
                @foreach($mobileFinanceKpis as $kpi)
                    <div class="ph-rental-kpi-card {{ $kpi['tone'] }}">
                        <span class="ph-rental-kpi-dot" aria-hidden="true">
                            @switch($kpi['icon'])
                                @case('calendar')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/></svg>
                                    @break
                                @case('document')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z"/><path d="M14 2v5h5"/><path d="M9 13h6"/><path d="M9 17h4"/></svg>
                                    @break
                                @case('wallet')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7H5a2 2 0 0 1 0-4h12"/><path d="M20 7v14H5a2 2 0 0 1-2-2V5"/><path d="M16 13h4v4h-4a2 2 0 0 1 0-4z"/></svg>
                                    @break
                                @case('payment')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="M6 13l8 8"/><path d="M6 13h3.5a4.5 4.5 0 0 0 0-9H6"/></svg>
                                    @break
                                @case('shield')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                                    @break
                                @case('truck')
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M10 17h4V5H2v12h3"/><path d="M14 8h4l4 4v5h-3"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></svg>
                                    @break
                            @endswitch
                        </span>
                        <strong>{{ $kpi['value'] }}</strong>
                        <span>{{ $kpi['label'] }}</span>
                    </div>
                @endforeach
            </div>

            <div class="ph-rental-mobile-accordions">
                <details class="ph-rental-mobile-accordion" open>
                    <summary>
                        <span class="ph-rental-section-icon">O</span>
                        <span class="ph-rental-section-title">
                            <strong>Overview</strong>
                            <span>Source, fulfilment, referral, dates</span>
                        </span>
                        <span class="ph-rental-chevron">v</span>
                    </summary>
                    <div class="ph-rental-accordion-body">
                        <div class="ph-rental-info-grid">
                            @foreach($mobileOverviewItems as $item)
                                <div class="ph-rental-info-item">
                                    <span>{{ $item['label'] }}</span>
                                    <strong>{{ $item['value'] }}</strong>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </details>

                <details class="ph-rental-mobile-accordion">
                    <summary>
                        <span class="ph-rental-section-icon">D</span>
                        <span class="ph-rental-section-title">
                            <strong>Operations</strong>
                            <span>Delivery, pickup, return, assignments</span>
                        </span>
                        <span class="ph-rental-chevron">v</span>
                    </summary>
                    <div class="ph-rental-accordion-body">
                        @foreach($workflowSteps as $step)
                            <div class="ph-rental-mobile-line">
                                <span>{{ $step['label'] }} - {{ $step['person'] }}</span>
                                <strong>{{ $step['status'] }}</strong>
                            </div>
                        @endforeach
                    </div>
                </details>

                <details class="ph-rental-mobile-accordion">
                    <summary>
                        <span class="ph-rental-section-icon">P</span>
                        <span class="ph-rental-section-title">
                            <strong>Products</strong>
                            <span>{{ $mobileProductSummary->count() }} item{{ $mobileProductSummary->count() === 1 ? '' : 's' }} - {{ $allRentalAssetAssignments->count() }} asset{{ $allRentalAssetAssignments->count() === 1 ? '' : 's' }}</span>
                        </span>
                        <span class="ph-rental-chevron">v</span>
                    </summary>
                    <div class="ph-rental-accordion-body">
                        @forelse($mobileProductSummary as $product)
                            <div class="ph-rental-mobile-line">
                                <span>{{ $product['name'] }} - Qty {{ $product['qty'] }}</span>
                                <strong>{{ $product['status'] }}</strong>
                            </div>
                        @empty
                            <div class="ph-rental-mobile-line"><span>No products linked.</span><strong>-</strong></div>
                        @endforelse
                    </div>
                </details>

                <details class="ph-rental-mobile-accordion" id="mobile-rental-finance">
                    <summary>
                        <span class="ph-rental-section-icon">F</span>
                        <span class="ph-rental-section-title">
                            <strong>Finance</strong>
                            <span>Invoice, payments, deposit, transport</span>
                        </span>
                        <span class="ph-rental-chevron">v</span>
                    </summary>
                    <div class="ph-rental-accordion-body">
                        @foreach($mobileFinanceKpis as $kpi)
                            <div class="ph-rental-mobile-line">
                                <span>{{ $kpi['label'] }}</span>
                                <strong>{{ $kpi['value'] }}</strong>
                            </div>
                        @endforeach
                        @if($canCreatePayments && !in_array($rentalInvoiceStatus, ['paid', 'cancelled'], true))
                            <form action="{{ route('rentals.recordPayment', $rental) }}" method="POST" class="rcc-payment-form" style="margin-top:4px;">
                                @csrf
                                <label>Date<input type="date" name="payment_date" value="{{ old('payment_date', now()->toDateString()) }}"></label>
                                <label>Amount<input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount', $rentalInvoiceDue > 0 ? number_format($rentalInvoiceDue, 2, '.', '') : '') }}"></label>
                                <label>Method<input type="text" name="payment_method" value="{{ old('payment_method', 'other') }}" placeholder="cash / upi / bank"></label>
                                <label>Note<input type="text" name="notes" value="{{ old('notes') }}" placeholder="Payment note"></label>
                                <button type="submit" class="detail-btn">Record Payment</button>
                            </form>
                        @endif
                    </div>
                </details>

                <details class="ph-rental-mobile-accordion">
                    <summary>
                        <span class="ph-rental-section-icon">R</span>
                        <span class="ph-rental-section-title">
                            <strong>Renewal</strong>
                            <span>{{ $rental->canRenew() ? 'Renewal available' : 'Renewal closed' }} - Due on {{ optional($rental->end_date)->format('d M Y') ?: 'not set' }}</span>
                        </span>
                        <span class="ph-rental-chevron">v</span>
                    </summary>
                    <div class="ph-rental-accordion-body">
                        <div class="ph-rental-mobile-line">
                            <span>Suggested extension</span>
                            <strong>{{ $suggestedRenewalDays }} days</strong>
                        </div>
                        <div class="ph-rental-mobile-line">
                            <span>Renewed to</span>
                            <strong>{{ optional($suggestedRenewedEndDate)->format('d M Y') }}</strong>
                        </div>
                        @if($rental->canRenew() && $canUpdateRentals)
                            <button type="button" class="detail-btn" data-open-renewal-modal>Renew Rental</button>
                        @endif
                    </div>
                </details>

                <details class="ph-rental-mobile-accordion">
                    <summary>
                        <span class="ph-rental-section-icon">A</span>
                        <span class="ph-rental-section-title">
                            <strong>Activity</strong>
                            <span>Latest notes and timeline</span>
                        </span>
                        <span class="ph-rental-chevron">v</span>
                    </summary>
                    <div class="ph-rental-accordion-body">
                        @forelse($timelinePreview->take(5) as $log)
                            <div class="ph-rental-mobile-line">
                                <span>{{ optional($log->created_at)->format('d M h:i A') }}</span>
                                <strong>{{ \Illuminate\Support\Str::limit($log->description ?? ucfirst(str_replace(['_', '.'], ' ', $log->action ?? 'Activity')), 32) }}</strong>
                            </div>
                        @empty
                            <div class="ph-rental-mobile-line"><span>No activity yet.</span><strong>-</strong></div>
                        @endforelse
                    </div>
                </details>
            </div>
        </section>
        <section class="rcc-card rental-exec-card" id="rental-executive-command-center">
            <div class="rental-exec-top">
                <div>
                    <a href="{{ route('rentals.index') }}" class="rental-exec-back">&larr; Rentals</a>
                    <div class="rental-exec-title-row">
                        <h1>Rental #{{ $rental->id }}</h1>
                        <span class="rcc-chip {{ $rentalStatusTone }}">{{ ucfirst(str_replace('_', ' ', $operationalStatus)) }}</span>
                        <span class="rcc-chip {{ $invoiceStatusTone }}">{{ $paymentStatusLabel }}</span>
                        <span class="rcc-chip {{ $daysRemainingTone }}">{{ $daysRemainingLabel }}</span>
                    </div>
                </div>
                <div class="rental-exec-tools">
                    <a href="{{ route('rentals.index') }}" class="detail-btn-secondary">Back</a>
                    @if($canUpdateRentals)
                        <a href="{{ route('rentals.edit', $rental) }}" class="detail-btn">Edit Rental</a>
                    @endif
                </div>
            </div>

            <div class="rental-exec-grid">
                <div class="rental-exec-cell">
                    <div class="rental-exec-customer">
                        <span class="rental-exec-avatar">{{ $desktopCustomerInitials }}</span>
                        <div>
                            <strong>{{ $desktopCustomerName }}</strong>
                            <div class="rental-exec-muted">{{ $reminderContactPhone ?: 'No phone' }}</div>
                            <div class="rental-exec-muted">{{ $desktopCustomerLocation }}</div>
                            <div style="margin-top:6px;"><span class="rcc-chip done">{{ $rentalTypeLabel }}</span></div>
                        </div>
                    </div>
                </div>
                <div class="rental-exec-cell">
                    <span class="rental-exec-label">Due Amount</span>
                    <strong class="rental-exec-money">{{ $canSeeRentalFinance ? $currency($invoiceBalanceAmount) : 'Hidden' }}</strong>
                    <span class="rcc-chip {{ $rentalInvoiceStatus === 'paid' ? 'done' : ($rentalInvoiceStatus === 'partial' ? 'warn' : 'problem') }}">{{ $paymentStatusLabel }}</span>
                </div>
                <div class="rental-exec-cell">
                    <span class="rental-exec-label">Delivery Status</span>
                    <strong class="rental-exec-value">{{ $deliveryStatusLabel($rental->deliveryStatus()) }}</strong>
                    <span class="rcc-chip {{ $deliveryStatusTone }}">{{ $deliveryAssigneeLabel }}</span>
                    <span class="rental-exec-muted">Pickup: {{ $pickupAssigneeLabel }}</span>
                </div>
                <div class="rental-exec-cell">
                    <span class="rental-exec-label">Period</span>
                    <strong class="rental-exec-value">{{ $desktopPeriodStart }}</strong>
                    <strong class="rental-exec-value">{{ $desktopPeriodEnd }}</strong>
                    <span class="rental-exec-muted">{{ $daysRemainingLabel }}</span>
                </div>
                <div class="rental-exec-cell">
                    <span class="rental-exec-label">Fulfilled By</span>
                    <strong class="rental-exec-value">{{ $rentalFulfilledByLabel }}</strong>
                    <span class="rcc-chip active">{{ $rentalFulfilmentSourceLabel }}</span>
                </div>
                <div class="rental-exec-cell">
                    <span class="rental-exec-label">Invoice</span>
                    @if($rentalInvoice)
                        <strong class="rental-exec-value">{{ $rentalInvoice->invoice_number }}</strong>
                        <a href="{{ route('invoices.show', $rentalInvoice) }}" class="rental-exec-muted" style="color:#2563eb;font-weight:900;text-decoration:none;">View Invoice &rarr;</a>
                    @else
                        <strong class="rental-exec-value">Not generated</strong>
                        @if($canUpdateRentals)
                            <form action="{{ route('rentals.invoice', $rental) }}" method="POST" style="margin:0;">
                                @csrf
                                <button type="submit" class="detail-btn-secondary" style="min-height:34px;padding:7px 12px;">Create Invoice</button>
                            </form>
                        @endif
                    @endif
                </div>
            </div>

            <div class="rental-exec-actions">
                @if($reminderCallHref)
                    <a href="{{ $reminderCallHref }}" class="detail-btn-secondary rental-action-call"><svg class="rental-action-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.8 19.8 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.12 4.18 2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.12.9.33 1.77.63 2.61a2 2 0 0 1-.45 2.11L8.1 9.9a16 16 0 0 0 6 6l1.46-1.19a2 2 0 0 1 2.11-.45c.84.3 1.71.51 2.61.63A2 2 0 0 1 22 16.92z"/></svg><span>Call</span></a>
                @endif
                @if($reminderWhatsappHref)
                    <a href="{{ $reminderWhatsappHref }}" target="_blank" rel="noopener" class="detail-btn-secondary rental-action-whatsapp"><svg class="rental-action-svg" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20 11.4c0 4.7-3.9 8.6-8.8 8.6-1.5 0-3-.4-4.2-1.1L3 20l1.2-3.7A8.4 8.4 0 0 1 2.4 11.4C2.4 6.7 6.3 3 11.2 3 16.1 3 20 6.7 20 11.4Zm-4.8 2.2c-.2-.1-1.2-.6-1.4-.7s-.3-.1-.4.1-.5.7-.7.9-.3.2-.5.1a5.9 5.9 0 0 1-1.7-1c-.6-.5-1-1.2-1.1-1.4-.1-.2 0-.3.1-.4l.3-.4.2-.3v-.4c0-.1-.4-1.1-.6-1.6-.2-.4-.3-.4-.4-.4h-.4c-.1 0-.4 0-.6.3-.2.2-.8.8-.8 1.9s.8 2.1 1 2.3c.1.1 1.5 2.3 3.8 3.2.5.2 1 .4 1.3.5.6.2 1.2.2 1.7.1.5-.1 1.2-.5 1.4-1 .2-.5.2-1 .1-1Z"/></svg><span>WhatsApp</span></a>
                @endif
                @if($rentalInvoice)
                    <a href="{{ route('invoices.show', $rentalInvoice) }}" class="detail-btn-secondary rental-action-invoice"><svg class="rental-action-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z"/><path d="M14 2v5h5"/><path d="M9 13h6"/><path d="M9 17h4"/></svg><span>Invoice</span></a>
                @elseif($canUpdateRentals)
                    <form action="{{ route('rentals.invoice', $rental) }}" method="POST" style="margin:0;">
                        @csrf
                        <button type="submit" class="detail-btn-secondary rental-action-invoice"><svg class="rental-action-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z"/><path d="M14 2v5h5"/><path d="M9 13h6"/><path d="M9 17h4"/></svg><span>Invoice</span></button>
                    </form>
                @endif
                @if($canCreatePayments && !in_array($rentalInvoiceStatus, ['paid', 'cancelled'], true))
                    <button
                        type="button"
                        class="detail-btn-secondary rental-action-pay"
                        onclick="document.getElementById('rental-finance-workspace')?.setAttribute('open', 'open'); document.getElementById('record-payment-panel')?.setAttribute('open', 'open'); document.getElementById('record-payment-panel')?.scrollIntoView({behavior:'smooth', block:'center'});"
                    ><svg class="rental-action-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 3h12"/><path d="M6 8h12"/><path d="M6 13l8 8"/><path d="M6 13h3.5a4.5 4.5 0 0 0 0-9H6"/></svg><span>Pay</span></button>
                @endif
                @if($rental->canRenew() && $canUpdateRentals)
                    <button type="button" class="detail-btn rental-renew-primary" data-open-renewal-modal>Renew Rental</button>
                @endif
                <details class="rcc-action-more">
                    <summary class="detail-btn-secondary">More Actions</summary>
                    <div class="rcc-more-menu">
                        @if($deliveryUrl)
                            <a href="{{ $deliveryUrl }}" target="_blank" rel="noopener">Delivery Message</a>
                        @endif
                        @if($pickupUrl)
                            <a href="{{ $pickupUrl }}" target="_blank" rel="noopener">Pickup Reminder</a>
                        @endif
                        @if($deliveryContactMapUrl)
                            <a href="{{ $deliveryContactMapUrl }}" target="_blank" rel="noopener">Open Service Map</a>
                        @endif
                        @if($deliveryRecord && $hasOpenDeliveryTask && $canUpdateDeliveries)
                            <a href="{{ route('deliveries.edit', $deliveryRecord) }}">Assign Delivery</a>
                        @elseif($canCreateDeliveries && !$hasOpenDeliveryTask && $hasPendingDeliveryItems)
                            <a href="{{ route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'delivery']) }}">Assign Delivery</a>
                        @endif
                        @if($pickupRecord && $hasOpenPickupTask && $canUpdateDeliveries)
                            <a href="{{ route('deliveries.edit', $pickupRecord) }}">Assign Pickup</a>
                        @elseif($canAssignPickup && !$hasOpenPickupTask)
                            <button type="button" data-open-pickup-modal>Assign Pickup</button>
                        @endif
                        @if($canUpdateRentals)
                            <a href="{{ route('rentals.edit', $rental) }}">Edit Rental</a>
                        @endif
                        <a href="#rental-timeline-workspace">Timeline</a>
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

        <section class="rental-kpi-strip" id="rental-kpi-strip" aria-label="Rental financial summary">
            @foreach($desktopKpiCards as $kpi)
                <div class="rental-kpi-card {{ $kpi['tone'] }}">
                    <span class="rental-kpi-icon" aria-hidden="true">
                        @switch($kpi['icon'])
                            @case('calendar')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M8 2v4"/><path d="M16 2v4"/><rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18"/></svg>
                                @break
                            @case('document')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z"/><path d="M14 2v5h5"/><path d="M9 13h6"/><path d="M9 17h4"/></svg>
                                @break
                            @case('wallet')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M20 7H5a2 2 0 0 1 0-4h12"/><path d="M20 7v14H5a2 2 0 0 1-2-2V5"/><path d="M16 13h4v4h-4a2 2 0 0 1 0-4z"/></svg>
                                @break
                            @case('payment')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="M6 13l8 8"/><path d="M6 13h3.5a4.5 4.5 0 0 0 0-9H6"/></svg>
                                @break
                            @case('shield')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/></svg>
                                @break
                            @case('truck')
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M10 17h4V5H2v12h3"/><path d="M14 8h4l4 4v5h-3"/><circle cx="7" cy="17" r="2"/><circle cx="17" cy="17" r="2"/></svg>
                                @break
                            @default
                                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 8v4"/><path d="M12 16h.01"/></svg>
                        @endswitch
                    </span>
                    <div>
                        <span>{{ $kpi['label'] }}</span>
                        <strong>{{ $kpi['value'] }}</strong>
                    </div>
                </div>
            @endforeach
        </section>

        <section class="rcc-card rental-workspace-shell" id="rental-workspace-shell">
            <nav class="rental-workspace-tabs" aria-label="Rental workspace sections">
                <div class="rental-tab-row">
                    <a href="#rental-overview-section">Overview</a>
                    <a href="#rental-operations-workspace">Operations</a>
                    <a href="#rental-products-section">Products</a>
                    <a href="#rental-billing-actions">Finance</a>
                    <a href="#renewal-workspace">Renewal</a>
                    <a href="#rental-timeline-workspace">Activity</a>
                    <a href="#rental-notes-section">Notes</a>
                </div>
            </nav>
            <div class="rental-overview-grid-v2 section-nav-target" id="rental-overview-section">
                <article class="rental-overview-card-v2">
                    <div class="rental-overview-title"><span class="rental-overview-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M20 21a8 8 0 0 0-16 0"/><circle cx="12" cy="7" r="4"/></svg></span><h3>Customer Details</h3></div>
                    <div class="rental-line-list">
                        <div class="rental-line-item"><span>Customer Type</span><strong>{{ $rentalTypeLabel }}</strong></div>
                        <div class="rental-line-item"><span>Customer Name</span><strong>{{ $desktopCustomerName }}</strong></div>
                        <div class="rental-line-item"><span>Phone</span><strong>{{ $reminderContactPhone ?: 'No phone' }}</strong></div>
                        <div class="rental-line-item"><span>Location</span><strong>{{ $desktopCustomerLocation }}</strong></div>
                        <div class="rental-line-item"><span>Referred By</span><strong>{{ filled($rental->referred_by ?? null) ? $rental->referred_by : 'Not captured' }}</strong></div>
                        <div class="rental-line-item"><span>Fulfilled By</span><strong>{{ $rentalFulfilledByLabel }}</strong></div>
                    </div>
                    @if($rental->customer_id)
                        <a href="{{ route('customers.show', $rental->customer_id) }}" class="detail-btn-secondary">View Customer</a>
                    @elseif($rental->business_partner_id)
                        <a href="{{ route('business-partners.show', $rental->business_partner_id) }}" class="detail-btn-secondary">View Partner</a>
                    @endif
                </article>
                <article class="rental-overview-card-v2">
                    <div class="rental-overview-title"><span class="rental-overview-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="m21 16-9 5-9-5"/><path d="m21 12-9 5-9-5"/><path d="m12 3 9 5-9 5-9-5 9-5z"/></svg></span><h3>Rental Overview</h3></div>
                    <div class="rental-line-list">
                        <div class="rental-line-item"><span>Rental Items</span><strong>{{ $desktopProductSummary }}</strong></div>
                        <div class="rental-line-item"><span>Primary Product</span><strong>{{ $desktopPrimaryProduct }}</strong></div>
                        <div class="rental-line-item"><span>Start Date</span><strong>{{ $desktopPeriodStart }}</strong></div>
                        <div class="rental-line-item"><span>End Date</span><strong>{{ $desktopPeriodEnd }}</strong></div>
                        <div class="rental-line-item"><span>Status</span><strong>{{ ucfirst(str_replace('_', ' ', $operationalStatus)) }}</strong></div>
                    </div>
                    <a href="#rental-products-section" class="detail-btn-secondary">View Products</a>
                </article>
                <article class="rental-overview-card-v2">
                    <div class="rental-overview-title"><span class="rental-overview-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="M6 13l8 8"/><path d="M6 13h3.5a4.5 4.5 0 0 0 0-9H6"/></svg></span><h3>Financial Snapshot</h3></div>
                    <div class="rental-line-list">
                        <div class="rental-line-item"><span>Rental Value</span><strong>{{ $canSeeRentalFinance ? $currency($rentalLineTotal) : 'Hidden' }}</strong></div>
                        <div class="rental-line-item"><span>Deposit</span><strong>{{ $canSeeRentalFinance ? $currency($rental->deposit_amount) : 'Hidden' }}</strong></div>
                        <div class="rental-line-item"><span>Transport</span><strong>{{ $canSeeRentalFinance ? $currency($rental->transport_amount) : 'Hidden' }}</strong></div>
                        <div class="rental-line-item"><span>Invoice Value</span><strong>{{ $canSeeRentalFinance ? $currency($invoiceTotalAmount) : ($rentalInvoice ? 'Generated' : 'Pending') }}</strong></div>
                        <div class="rental-line-item"><span>Due Amount</span><strong style="color:#dc2626;">{{ $canSeeRentalFinance ? $currency($invoiceBalanceAmount) : 'Hidden' }}</strong></div>
                    </div>
                    <a href="#rental-billing-actions" class="detail-btn-secondary">Record Payment</a>
                </article>
                <article class="rental-overview-card-v2">
                    <div class="rental-overview-title"><span class="rental-overview-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg></span><h3>Renewal Summary</h3></div>
                    <div class="rental-line-list">
                        <div class="rental-line-item"><span>Due Date</span><strong>{{ optional($rental->end_date)->format('d M Y') ?: 'Not set' }}</strong></div>
                        <div class="rental-line-item"><span>Window</span><strong>{{ $daysRemainingLabel }}</strong></div>
                        <div class="rental-line-item"><span>Status</span><strong>{{ $renewalSummaryStatus }}</strong></div>
                        <div class="rental-line-item"><span>Last Renewal</span><strong>{{ $lastRenewal ? optional($lastRenewal->created_at)->format('d M Y') : 'None' }}</strong></div>
                    </div>
                    @if($rental->canRenew() && $canUpdateRentals)
                        <button type="button" class="detail-btn" data-open-renewal-modal>Renew Rental</button>
                    @else
                        <a href="#renewal-workspace" class="detail-btn-secondary">View Renewal</a>
                    @endif
                </article>
                <article class="rental-overview-card-v2">
                    <div class="rental-overview-title"><span class="rental-overview-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3 8 4-16 3 8h4"/></svg></span><h3>Recent Activity</h3></div>
                    <div class="rental-activity-mini">
                        @forelse($desktopRecentActivity as $log)
                            <div class="rental-activity-mini-row">
                                <span class="rental-activity-dot" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="m5 12 4 4L19 6"/></svg></span>
                                <div>
                                    <strong>{{ \Illuminate\Support\Str::limit($log->description ?? ucfirst(str_replace(['_', '.'], ' ', $log->action ?? 'Activity')), 44) }}</strong>
                                    <span>{{ $log->causer?->name ?? 'System' }}</span>
                                </div>
                                <time>{{ optional($log->created_at)->format('d M h:i A') }}</time>
                            </div>
                        @empty
                            <div class="rental-line-item"><span>No recent activity</span><strong>-</strong></div>
                        @endforelse
                    </div>
                    <a href="#rental-timeline-workspace" class="detail-btn-secondary">View All</a>
                </article>
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
                        @elseif(!empty($step['action']['button']))
                            <button type="button" @foreach(($step['action']['attributes'] ?? []) as $attribute => $value) {{ $attribute }}="{{ is_bool($value) ? ($value ? 'true' : 'false') : $value }}" @endforeach>{{ $step['action']['label'] }}</button>
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

            <details class="rcc-card rcc-accordion-section" id="rental-finance-workspace">
                <span id="rental-billing-actions" class="section-nav-target"></span>
                <summary>
                    <span class="rcc-accordion-title"><span class="rental-overview-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h12"/><path d="M6 8h12"/><path d="M6 13l8 8"/><path d="M6 13h3.5a4.5 4.5 0 0 0 0-9H6"/></svg></span><span><strong>Finance</strong><br><span>Invoice, payments, deposit, transport</span></span></span>
                    <span class="rcc-accordion-meta"><span class="rcc-chip {{ $rentalInvoiceStatus === 'paid' ? 'done' : ($rentalInvoiceStatus === 'partial' ? 'warn' : 'problem') }}">{{ $paymentStatusLabel }}</span><span>{{ $canSeeRentalFinance ? $currency($invoiceBalanceAmount) . ' Outstanding' : 'Restricted' }}</span><span class="rcc-accordion-chevron"></span></span>
                </summary>
                <div class="rcc-accordion-body">
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
                </div>
            </details>
        </div>

        <details class="rcc-card rcc-accordion-section" id="renewal-workspace">
            <summary>
                <span class="rcc-accordion-title"><span class="rental-overview-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg></span><span><strong>Renewal</strong><br><span>Due {{ optional($rental->end_date)->format('d M Y') ?: 'Not set' }}</span></span></span>
                <span class="rcc-accordion-meta"><span class="rcc-chip {{ $daysRemainingTone }}">{{ $daysRemainingLabel }}</span><span>{{ $renewalSummaryStatus }}</span><span class="rcc-accordion-chevron"></span></span>
            </summary>
            <div class="rcc-accordion-body">
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
            </div>
        </details>

        <details class="rcc-card rcc-accordion-section" id="rental-timeline-workspace">
            <summary>
                <span class="rcc-accordion-title"><span class="rental-overview-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12h4l3 8 4-16 3 8h4"/></svg></span><span><strong>Activity</strong><br><span>Recent events and notes</span></span></span>
                <span class="rcc-accordion-meta"><span>{{ $timelinePreview->count() }} recent</span><span class="rcc-accordion-chevron"></span></span>
            </summary>
            <div class="rcc-accordion-body">
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
            </div>
        </details>
    </div>
</div>
<div class="renewal-modal" id="pickupAssignmentModal" aria-hidden="true" data-default-pickup-method="{{ $defaultPickupMethod }}">
    <div class="renewal-modal-panel" style="max-width:620px;">
        <div class="renewal-modal-head">
            <div>
                <strong>Assign Pickup</strong>
                <div class="ops-muted" style="margin-top:3px;">{{ $rentalFulfilmentSourceLabel }} rental - Rental #{{ $rental->id }}</div>
            </div>
            <button type="button" class="detail-btn-secondary" data-close-pickup-modal>Close</button>
        </div>

        <form action="{{ route('renewal-center.schedule-pickup', $rental) }}" method="POST" id="pickupAssignmentForm">
            @csrf
            <div class="renewal-modal-body">
                <div class="renewal-form-grid">
                    <div class="renewal-field renewal-span-2">
                        <label>Rental</label>
                        <input type="text" value="Rental #{{ $rental->id }} - {{ $rental->billingContactName() }}" readonly>
                    </div>
                    <div class="renewal-field">
                        <label>Pickup Date</label>
                        <input type="date" name="pickup_date" value="{{ old('pickup_date', optional($rental->end_date)->format('Y-m-d') ?: now()->toDateString()) }}" required>
                    </div>
                    <div class="renewal-field">
                        <label>Time Slot</label>
                        <select name="pickup_time_slot">
                            <option value="">Flexible</option>
                            @foreach(['09:00-12:00', '12:00-15:00', '15:00-18:00', '18:00-20:00'] as $slot)
                                <option value="{{ $slot }}" @selected(old('pickup_time_slot') === $slot)>{{ $slot }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="renewal-field renewal-span-2">
                        <label>Pickup Method</label>
                        <div class="pickup-method-grid">
                            @foreach($pickupMethodOptions as $methodValue => [$methodLabel, $methodHint])
                                <label class="pickup-method-card">
                                    <input type="radio" name="pickup_method" value="{{ $methodValue }}" @checked(old('pickup_method', $defaultPickupMethod) === $methodValue)>
                                    <span>
                                        <strong>{{ $methodLabel }}</strong>
                                        <small>{{ $methodHint }}</small>
                                    </span>
                                </label>
                            @endforeach
                        </div>
                    </div>
                    <div class="renewal-field renewal-span-2" data-pickup-method-panel="internal_pickup">
                        <label>Assign To</label>
                        <select name="assigned_user_id">
                            <option value="">Choose delivery / operations executive</option>
                            @foreach(($pickupAssignableUsers ?? collect()) as $user)
                                <option value="{{ $user->id }}" @selected((string) old('assigned_user_id') === (string) $user->id)>
                                    {{ $user->name }}{{ $user->effective_role ? ' - ' . ucwords(str_replace('_', ' ', $user->effective_role)) : '' }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="renewal-field renewal-span-2" data-pickup-method-panel="vendor_pickup">
                        <label>Vendor</label>
                        <select name="vendor_id">
                            <option value="">Choose vendor</option>
                            @foreach(($pickupVendors ?? collect()) as $vendor)
                                <option value="{{ $vendor->id }}" @selected((string) old('vendor_id') === (string) $vendor->id)>{{ $vendor->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="renewal-field renewal-span-2" data-pickup-method-panel="third_party_pickup">
                        <label>Third Party Details</label>
                        <div class="renewal-form-grid">
                            <div class="renewal-field">
                                <label>Provider</label>
                                <input type="text" name="third_party_provider" value="{{ old('third_party_provider') }}" placeholder="Provider name">
                            </div>
                            <div class="renewal-field">
                                <label>Tracking Number</label>
                                <input type="text" name="third_party_tracking_number" value="{{ old('third_party_tracking_number') }}" placeholder="Tracking / docket">
                            </div>
                            <div class="renewal-field">
                                <label>Contact Person</label>
                                <input type="text" name="third_party_contact_person" value="{{ old('third_party_contact_person') }}" placeholder="Contact name">
                            </div>
                            <div class="renewal-field">
                                <label>Phone Number</label>
                                <input type="text" name="third_party_phone" value="{{ old('third_party_phone') }}" placeholder="Phone">
                            </div>
                            <div class="renewal-field renewal-span-2">
                                <label>Pickup Cost</label>
                                <input type="number" step="0.01" min="0" name="third_party_pickup_cost" value="{{ old('third_party_pickup_cost') }}" placeholder="0.00">
                            </div>
                        </div>
                    </div>
                    <div class="renewal-field renewal-span-2" data-pickup-method-panel="customer_self_drop">
                        <div class="pickup-self-drop-note">
                            @if($isVendorSuppliedRental)
                                Customer self drop closes the rental as returned directly to vendor. PH return verification is skipped.
                            @else
                                Customer self drop moves PH-owned assets to Return Verification immediately. No pickup task is created.
                            @endif
                        </div>
                    </div>
                    <div class="renewal-field renewal-span-2">
                        <label>Pickup Notes</label>
                        <textarea name="pickup_notes" placeholder="Pickup instruction, landmark, customer timing, or equipment note...">{{ old('pickup_notes', $rental->deliveryContactNotes()) }}</textarea>
                    </div>
                </div>
            </div>
            <div class="renewal-modal-foot">
                <button type="button" class="detail-btn-secondary" data-close-pickup-modal>Cancel</button>
                <button type="submit" class="detail-btn">Assign Pickup</button>
            </div>
        </form>
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

<div class="ph-rental-mobile-footer" aria-label="Rental quick actions">
    @if($rental->canRenew() && $canUpdateRentals)
        <button type="button" class="detail-btn" data-open-renewal-modal>Renew Rental</button>
    @elseif($rentalInvoice)
        <a href="{{ route('invoices.show', $rentalInvoice) }}" class="detail-btn">Open Invoice</a>
    @elseif($canUpdateRentals)
        <form action="{{ route('rentals.invoice', $rental) }}" method="POST" style="margin:0;">
            @csrf
            <button type="submit" class="detail-btn">Create Invoice</button>
        </form>
    @else
        <a href="{{ route('rentals.index') }}" class="detail-btn">Back to Rentals</a>
    @endif
    <details class="ph-rental-footer-more" data-rental-mobile-menu>
        <summary><span aria-hidden="true">&ctdot;</span> More Actions</summary>
        <div class="ph-rental-footer-panel">
            @foreach($mobileMoreActions->take(10) as $action)
                @if(($action['type'] ?? 'link') === 'form')
                    <form action="{{ $action['action'] }}" method="POST" style="margin:0;">
                        @csrf
                        @if(($action['method'] ?? 'POST') !== 'POST')
                            @method($action['method'])
                        @endif
                        <button type="submit" @if(!empty($action['confirm'])) onclick="return confirm('{{ $action['confirm'] }}');" @endif>{{ $action['label'] }}</button>
                    </form>
                @elseif(($action['type'] ?? 'link') === 'button')
                    <button type="button" @foreach(($action['attributes'] ?? []) as $attribute => $value) {{ $attribute }}="{{ is_bool($value) ? ($value ? 'true' : 'false') : $value }}" @endforeach>{{ $action['label'] }}</button>
                @else
                    <a href="{{ $action['href'] }}" @if(!empty($action['target'])) target="{{ $action['target'] }}" rel="noopener" @endif>{{ $action['label'] }}</a>
                @endif
            @endforeach
        </div>
    </details>
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
            <div class="rental-cta-title">Rental #{{ $rental->id }} - {{ $rental->billingContactName() }}</div>
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
                <a href="{{ route('invoices.show', $rentalInvoice) }}" class="detail-btn-secondary rental-action-invoice"><svg class="rental-action-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z"/><path d="M14 2v5h5"/><path d="M9 13h6"/><path d="M9 17h4"/></svg><span>Invoice</span></a>
            @elseif(auth()->user()->canAccessModule('rentals', 'update'))
                <form action="{{ route('rentals.invoice', $rental) }}" method="POST">
                    @csrf
                    <button type="submit" class="detail-btn-secondary rental-action-invoice"><svg class="rental-action-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H7a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V7z"/><path d="M14 2v5h5"/><path d="M9 13h6"/><path d="M9 17h4"/></svg><span>Invoice</span></button>
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
        const rentalMobileMenus = document.querySelectorAll('[data-rental-mobile-menu]');

        if (!rentalMobileMenus.length) {
            return;
        }

        rentalMobileMenus.forEach((menu) => {
            menu.addEventListener('toggle', () => {
                if (!menu.open) {
                    return;
                }

                rentalMobileMenus.forEach((otherMenu) => {
                    if (otherMenu !== menu) {
                        otherMenu.open = false;
                    }
                });
            });
        });

        document.addEventListener('click', (event) => {
            rentalMobileMenus.forEach((menu) => {
                if (menu.open && !menu.contains(event.target)) {
                    menu.open = false;
                }
            });
        });

        document.addEventListener('keydown', (event) => {
            if (event.key !== 'Escape') {
                return;
            }

            rentalMobileMenus.forEach((menu) => {
                menu.open = false;
            });
        });
    })();
</script>
<script>
    (() => {
        const modal = document.getElementById('pickupAssignmentModal');
        if (!modal) {
            return;
        }

        const openButtons = document.querySelectorAll('[data-open-pickup-modal]');
        const closeButtons = document.querySelectorAll('[data-close-pickup-modal]');
        const modalBody = modal.querySelector('.renewal-modal-body');
        const methodInputs = modal.querySelectorAll('input[name="pickup_method"]');
        const methodPanels = modal.querySelectorAll('[data-pickup-method-panel]');
        const submitButton = modal.querySelector('button[type="submit"].detail-btn');

        const syncPickupMethod = () => {
            let activeMethod = modal.querySelector('input[name="pickup_method"]:checked')?.value || modal.dataset.defaultPickupMethod || 'internal_pickup';

            if (!modal.querySelector(`[data-pickup-method-panel="${activeMethod}"]`)) {
                activeMethod = modal.querySelector('input[name="pickup_method"]')?.value || activeMethod;
            }

            const activeInput = modal.querySelector(`input[name="pickup_method"][value="${activeMethod}"]`);
            if (activeInput && !activeInput.checked) {
                activeInput.checked = true;
            }

            methodPanels.forEach((panel) => {
                const isActive = panel.dataset.pickupMethodPanel === activeMethod;
                panel.hidden = !isActive;
                panel.querySelectorAll('input, select, textarea').forEach((field) => {
                    field.disabled = !isActive;
                });
            });

            if (submitButton) {
                submitButton.textContent = activeMethod === 'customer_self_drop' ? 'Verify Return' : 'Assign Pickup';
            }
        };

        const openModal = () => {
            syncPickupMethod();
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            window.rentnexisModalLock?.lock();
            const firstField = modal.querySelector('input:not([readonly]), select, textarea, button');
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

        openButtons.forEach((button) => button.addEventListener('click', openModal));
        closeButtons.forEach((button) => button.addEventListener('click', closeModal));
        methodInputs.forEach((input) => input.addEventListener('change', syncPickupMethod));
        syncPickupMethod();

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
    })();
</script>
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
<script>
    (() => {
        const compactPanels = ['rental-finance-workspace', 'renewal-workspace', 'rental-timeline-workspace'];
        const storageKey = 'phos:rental:compact-panels';

        const loadState = () => {
            try {
                return JSON.parse(localStorage.getItem(storageKey) || '{}');
            } catch (error) {
                return {};
            }
        };

        const saveState = () => {
            const state = {};
            compactPanels.forEach((id) => {
                const panel = document.getElementById(id);
                if (panel) {
                    state[id] = panel.open === true;
                }
            });
            localStorage.setItem(storageKey, JSON.stringify(state));
        };

        const openPanelForTarget = (target) => {
            if (!target) return;
            const element = document.getElementById(target.replace('#', ''));
            const panel = element?.closest?.('.rcc-accordion-section');
            if (panel && panel.tagName === 'DETAILS') {
                panel.open = true;
                saveState();
            }
        };

        const state = loadState();
        compactPanels.forEach((id) => {
            const panel = document.getElementById(id);
            if (!panel) return;
            panel.open = state[id] === true;
            panel.addEventListener('toggle', saveState);
        });

        openPanelForTarget(window.location.hash);
        window.addEventListener('hashchange', () => openPanelForTarget(window.location.hash));

        document.addEventListener('click', (event) => {
            const link = event.target.closest('a[href^="#"]');
            if (link) {
                openPanelForTarget(link.getAttribute('href'));
            }
        });
    })();
</script>
@endpush