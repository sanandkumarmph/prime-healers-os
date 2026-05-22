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
    $baseRentalAmount = max((float) ($rental->rental_amount ?? 0) - (float) $renewalHistoryChronological->sum('rental_amount_added'), 0);
    $baseDepositAmount = max((float) ($rental->deposit_amount ?? 0) - (float) $renewalHistoryChronological->sum('deposit_amount_added'), 0);
    $baseTransportAmount = max((float) ($rental->transport_amount ?? 0) - (float) $renewalHistoryChronological->sum('transport_amount_added'), 0);
    $baseOtherAmount = max((float) ($rental->other_amount ?? 0) - (float) $renewalHistoryChronological->sum('other_amount_added'), 0);
    $renewalPaymentIds = $renewalHistoryChronological->pluck('payment_id')->filter()->map(fn ($id) => (int) $id)->all();
    $baseBookingPayments = ($rental->payments ?? collect())->filter(fn ($payment) => !in_array((int) $payment->id, $renewalPaymentIds, true));
    $baseBookingPaidAmount = round((float) $baseBookingPayments->sum('amount'), 2);
    $baseBookingTotal = round($baseRentalAmount + $baseDepositAmount + $baseTransportAmount + $baseOtherAmount, 2);
    $baseBookingBalance = round(max($baseBookingTotal - $baseBookingPaidAmount, 0), 2);
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
    $daysUntilEnd = $rental->end_date
        ? now()->startOfDay()->diffInDays($rental->end_date->copy()->startOfDay(), false)
        : null;
    $daysRemainingLabel = $daysUntilEnd === null
        ? 'Schedule pending'
        : ($daysUntilEnd < 0
            ? abs($daysUntilEnd) . ' day' . (abs($daysUntilEnd) === 1 ? '' : 's') . ' overdue'
            : ($daysUntilEnd === 0
                ? 'Due today'
                : $daysUntilEnd . ' day' . ($daysUntilEnd === 1 ? '' : 's') . ' remaining'));
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
    ])->filter()->implode(' • ');
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
    }
    .ph-rental-notes-shell {
        display: grid;
        gap: 10px;
    }
    .rental-mobile-actions {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:8px;
        align-items:stretch;
        grid-auto-rows:minmax(44px, auto);
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
        min-height:44px;
        justify-content:center;
        font-size:12px;
        font-weight:800;
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
        gap:8px;
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
        min-height:42px;
        padding:10px 15px;
        border-radius:14px;
        background:#0f172a;
        border:1px solid #0f172a;
        color:#fff;
        text-decoration:none;
        font-size:13px;
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
    .booking-snapshot-card.is-total,
    .booking-snapshot-card.is-balance,
    .booking-snapshot-card.is-status {
        grid-column:span 6;
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
    .detail-card { background:#fff; border:1px solid #dbe3ef; border-radius:14px; padding:16px; box-shadow:0 8px 24px rgba(15, 23, 42, 0.04); }
    .detail-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:14px; }
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
        .booking-snapshot-card.is-total,
        .booking-snapshot-card.is-balance,
        .booking-snapshot-card.is-status { grid-column:span 6; }
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
        .ph-rental-detail-row {
            flex-direction: column;
        }
        .ph-rental-detail-row strong {
            text-align: left;
        }
        .detail-page { padding:14px; }
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
        .booking-snapshot-card.is-total,
        .booking-snapshot-card.is-balance,
        .booking-snapshot-card.is-status {
            grid-column:span 1;
            padding:12px;
        }
        .booking-snapshot-card.is-range,
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
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:10px;
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
            min-height:38px;
            padding:7px 10px;
            font-size:12px;
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
            min-height:42px;
            justify-content:center;
        }
        .rental-cta-panel {
            right:0;
            left:auto;
            bottom:calc(100% + 8px);
            width:min(280px, calc(100vw - 24px));
        }
    }
</style>

<div class="container detail-page">
    <div class="ph-rental-reference-shell">
        <x-operational-page-header
            eyebrow="Rental Command View"
            :title="'Rental #' . $rental->id"
            subtitle="Premium operational view for customer communication, delivery, pickup, invoicing, and renewal coordination."
            :back-url="route('rentals.index')"
            back-label="Back to Rentals"
            :meta="[
                ['label' => 'Start Date', 'value' => optional($rental->start_date)->format('d M Y') ?: 'Not set'],
                ['label' => 'End Date', 'value' => optional($rental->end_date)->format('d M Y') ?: 'Not set'],
                ['label' => 'Timeline', 'value' => $daysRemainingLabel],
            ]"
            :chips="[
                ['label' => $isHotlisted ? 'Hotlisted' : ucfirst(str_replace('_', ' ', $operationalStatus)), 'tone' => $rentalStatusTone],
                ['label' => $rentalTypeLabel, 'tone' => 'accent'],
                ['label' => $daysRemainingLabel, 'tone' => $daysRemainingTone],
            ]"
        >
            <div class="ph-rental-hero-tools">
                @if($canUpdateRentals)
                    <a href="{{ route('rentals.edit', $rental) }}" class="detail-btn-secondary">Edit Rental</a>
                @endif
                @if($rental->canBeReturned())
                    <form action="{{ route('rentals.return', $rental) }}" method="POST" style="margin:0;">
                        @csrf
                        @method('PUT')
                        <button type="submit" class="detail-btn-danger" onclick="return confirm('Mark this rental as returned?');">Return Rental</button>
                    </form>
                @endif
            </div>
        </x-operational-page-header>

        <x-operational-action-bar
            label="Rental Actions"
            description="Keep the primary rental actions close without leaving the operational view."
            :actions="$rentalQuickActions->all()"
            :more-actions="$rentalMoreActions->all()"
            :info-items="$rentalInfoItems->all()"
        />

        <x-section-nav
            label="Rental page sections"
            :items="[
                ['id' => 'rental-overview-section', 'label' => 'Overview'],
                ['id' => 'rental-customer-section', 'label' => 'Customer'],
                ['id' => 'rental-products-section', 'label' => 'Product'],
                ['id' => 'rental-billing-actions', 'label' => 'Invoice'],
                ['id' => 'rental-delivery-section', 'label' => 'Delivery'],
                ['id' => 'rental-pickup-section', 'label' => 'Pickup'],
                ['id' => 'rental-payments-section', 'label' => 'Payments'],
                ['id' => 'rental-activity-timeline', 'label' => 'Timeline'],
                ['id' => 'rental-notes-section', 'label' => 'Notes'],
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

        <x-operational-card
            class="section-nav-target"
            id="rental-overview-section"
            title="Overview"
            subtitle="Fast operational snapshot of the rental, finance posture, and next actions."
        >
            <div class="ph-rental-overview-grid">
                <div class="ph-rental-summary-grid">
                    <x-summary-card label="Rental ID" :value="'#' . $rental->id" meta="Primary reference for delivery, pickup, and finance coordination." />
                    <x-summary-card label="Rental Type" :value="$rentalTypeLabel" meta="{{ $rentalTypeLabel === 'Business Partner' ? 'Partner handles reminders and payment communication.' : 'Customer remains the single contact for service and payment.' }}" />
                    <x-summary-card label="Delivery Status" :value="$deliveryStatusLabel($rental->deliveryStatus())" :tone="$deliveryStatusTone" meta="{{ $deliveryAssigneeLabel !== 'Not assigned' ? 'Assigned to ' . $deliveryAssigneeLabel : 'Delivery team still needs assignment.' }}" />
                    <x-summary-card label="Pickup Status" :value="ucfirst(str_replace('_', ' ', $rental->pickupStatus() ?: 'pending'))" :tone="$pickupStatusTone" meta="{{ $pickupAssigneeLabel !== 'Not assigned' ? 'Assigned to ' . $pickupAssigneeLabel : 'Pickup workflow has not started yet.' }}" />
                    <x-summary-card label="End / Renewal Date" :value="optional($rental->end_date)->format('d M Y') ?: 'Not set'" :tone="$daysRemainingTone" :meta="$daysRemainingLabel" />
                    <x-summary-card label="Created By" :value="$rental->createdBy->name ?? 'N/A'" meta="{{ optional($rental->created_at)->format('d M Y h:i A') ?: 'Created date unavailable' }}" />
                </div>

                <x-operational-card
                    title="{{ $canSeeRentalFinance ? 'Financial Summary' : 'Operational Summary' }}"
                    subtitle="{{ $canSeeRentalFinance ? 'Invoice status, payment posture, and quick next steps.' : 'Finance amounts are hidden for your role, but status remains visible.' }}"
                    padding="sm"
                >
                    <div class="ph-rental-detail-list">
                        @if($canSeeRentalFinance)
                            <div class="ph-rental-detail-row">
                                <span>Rent Amount</span>
                                <strong>{{ $canViewFinanceAmounts ? $currency($rental->rental_amount) : ucfirst($rentalInvoiceStatus ?: 'pending') }}</strong>
                            </div>
                            <div class="ph-rental-detail-row">
                                <span>Deposit</span>
                                <strong>{{ $canViewFinanceAmounts ? $currency($rental->deposit_amount) : ($rental->deposit_amount > 0 ? 'Captured' : 'Not captured') }}</strong>
                            </div>
                            <div class="ph-rental-detail-row">
                                <span>Invoice Status</span>
                                <strong>{{ $rentalInvoice ? ucfirst(str_replace('_', ' ', $rentalInvoiceStatus ?: 'unpaid')) : 'Not generated' }}</strong>
                            </div>
                            <div class="ph-rental-detail-row">
                                <span>Payment Status</span>
                                <strong>
                                    {{ $rentalInvoiceStatus ? ucfirst(str_replace('_', ' ', $rentalInvoiceStatus)) : 'Pending' }}
                                    @if($canViewFinanceAmounts && $rentalInvoiceDue > 0)
                                        • Due {{ $currency($rentalInvoiceDue) }}
                                    @endif
                                </strong>
                            </div>
                        @else
                            <div class="ph-rental-detail-row">
                                <span>Invoice</span>
                                <strong>{{ $rentalInvoice ? 'Generated' : 'Pending' }}</strong>
                            </div>
                            <div class="ph-rental-detail-row">
                                <span>Payment</span>
                                <strong>{{ $rentalInvoiceStatus ? ucfirst(str_replace('_', ' ', $rentalInvoiceStatus)) : 'Pending' }}</strong>
                            </div>
                            <div class="ph-rental-detail-row">
                                <span>Renewal</span>
                                <strong>{{ $rental->canRenew() ? 'Available' : 'Not available yet' }}</strong>
                            </div>
                            <div class="ph-rental-detail-row">
                                <span>Reminder Sent</span>
                                <strong>{{ $latestReminderAt ? $latestReminderAt->format('d M Y h:i A') : 'Not logged yet' }}</strong>
                            </div>
                        @endif
                    </div>

                    <div class="ph-rental-action-links">
                        @if($rentalInvoice)
                            <a href="{{ route('invoices.show', $rentalInvoice) }}" class="detail-btn-secondary">View Invoice</a>
                        @elseif($canUpdateRentals)
                            <form action="{{ route('rentals.invoice', $rental) }}" method="POST" style="margin:0;">
                                @csrf
                                <button type="submit" class="detail-btn-secondary">Generate Invoice</button>
                            </form>
                        @endif
                        @if($canCreatePayments && !in_array($rentalInvoiceStatus, ['paid', 'cancelled'], true))
                            <a href="#rental-billing-actions" class="detail-btn">Record Payment</a>
                        @endif
                        @if($pickupRecord && $hasOpenPickupTask)
                            <a href="{{ route('deliveries.show', $pickupRecord) }}" class="detail-btn-secondary">View Pickup</a>
                        @elseif($canAssignPickup)
                            <a href="{{ route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'pickup']) }}" class="detail-btn-secondary">Schedule Pickup</a>
                        @endif
                        @if($rental->canRenew() && $canUpdateRentals)
                            <button type="button" class="detail-btn-secondary" data-open-renewal-modal>Renew / Extend</button>
                        @endif
                    </div>
                </x-operational-card>
            </div>
        </x-operational-card>

    <div class="detail-card section-nav-target" id="rental-billing-actions">
        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
            <div>
                <h2 style="margin:0; font-size:18px;">Billing Actions</h2>
                <div class="ops-muted" style="margin-top:6px;">
                    {{ $rentalInvoice ? 'Keep invoice and payment actions here on the rental itself.' : 'Generate the invoice here, then record full or partial payment without leaving this page.' }}
                </div>
            </div>
            @if($rentalInvoice)
                <div class="status-badge" style="{{ $badge($rentalInvoiceStatus === 'paid' ? 'active' : ($rentalInvoiceStatus === 'partial' ? 'in_progress' : 'pending')) }}">
                    {{ ucfirst($rentalInvoiceStatus ?: 'unpaid') }}
                    @if($rentalInvoiceDue > 0)
                        · Due {{ $currency($rentalInvoiceDue) }}
                    @endif
                </div>
            @endif
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:14px;">
            @if($canUpdateRentals)
                <a href="{{ route('rentals.edit', $rental) }}" class="detail-btn-secondary">Edit Rental</a>
            @endif
            @if($rentalInvoice)
                <a href="{{ route('invoices.show', $rentalInvoice) }}" class="detail-btn-secondary">Open Invoice</a>
            @elseif(auth()->user()->canAccessModule('rentals', 'update'))
                <form action="{{ route('rentals.invoice', $rental) }}" method="POST" style="margin:0;">
                    @csrf
                    <button type="submit" class="detail-btn-secondary">Generate Invoice</button>
                </form>
            @endif
            @if($canCreatePayments && !in_array($rentalInvoiceStatus, ['paid', 'cancelled'], true))
                <form action="{{ route('rentals.markPaid', $rental) }}" method="POST" style="margin:0;">
                    @csrf
                    <button type="submit" class="detail-btn">Mark as Paid</button>
                </form>
            @endif
        </div>
        @if($canCreatePayments && !in_array($rentalInvoiceStatus, ['paid', 'cancelled'], true))
            <form action="{{ route('rentals.recordPayment', $rental) }}" method="POST" class="billing-form-grid">
                @csrf
                <div>
                    <span class="label">Payment Date</span>
                    <input type="date" name="payment_date" value="{{ old('payment_date', now()->toDateString()) }}" style="width:100%; min-height:38px; border:1px solid #cbd5e1; border-radius:10px; padding:8px 10px; font-size:13px;">
                </div>
                <div>
                    <span class="label">Amount</span>
                    <input type="number" step="0.01" min="0.01" name="amount" value="{{ old('amount', $rentalInvoiceDue > 0 ? number_format($rentalInvoiceDue, 2, '.', '') : '') }}" style="width:100%; min-height:38px; border:1px solid #cbd5e1; border-radius:10px; padding:8px 10px; font-size:13px;">
                </div>
                <div>
                    <span class="label">Payment Method</span>
                    <input type="text" name="payment_method" value="{{ old('payment_method', 'other') }}" placeholder="cash / upi / bank" style="width:100%; min-height:38px; border:1px solid #cbd5e1; border-radius:10px; padding:8px 10px; font-size:13px;">
                </div>
                <div>
                    <span class="label">Notes</span>
                    <input type="text" name="notes" value="{{ old('notes') }}" placeholder="Partial payment note" style="width:100%; min-height:38px; border:1px solid #cbd5e1; border-radius:10px; padding:8px 10px; font-size:13px;">
                </div>
                <div style="grid-column:1 / -1; display:flex; justify-content:flex-end;">
                    <button type="submit" class="detail-btn-secondary">Record Partial Payment</button>
                </div>
            </form>
        @endif
    </div>

    <div class="detail-card">
        <div class="rental-status-grid">
            <div class="status-card">
                <span class="label">Rental</span>
                <span class="status-badge" style="{{ $badge($isHotlisted ? 'overdue' : $operationalStatus) }}">
                    {{ $isHotlisted ? 'Hotlisted' : ucfirst(str_replace('_', ' ', $operationalStatus)) }}
                </span>
            </div>
            <div class="status-card">
                <span class="label">Delivery</span>
                <span class="status-badge" style="{{ $badge($rental->deliveryStatus()) }}">
                    {{ $deliveryStatusLabel($rental->deliveryStatus()) }}
                </span>
            </div>
            <div class="status-card">
                <span class="label">Pickup</span>
                <span class="status-badge" style="{{ $badge($rental->pickupStatus()) }}">
                    {{ ucfirst(str_replace('_', ' ', $rental->pickupStatus() ?: 'pending')) }}
                </span>
            </div>
            <div class="status-card">
                <span class="label">Invoice</span>
                <span class="status-badge" style="{{ $badge($rentalInvoice ? 'completed' : 'pending') }}">
                    {{ $rentalInvoice ? 'Generated' : 'Pending' }}
                </span>
            </div>
            <div class="status-card">
                <span class="label">Payment</span>
                <span class="status-badge" style="{{ $badge($rentalInvoiceStatus === 'paid' ? 'completed' : ($rentalInvoiceStatus === 'partial' ? 'in_progress' : 'pending')) }}">
                    {{ $rentalInvoiceStatus ? ucfirst(str_replace('_', ' ', $rentalInvoiceStatus)) : 'Pending' }}
                </span>
            </div>
            <div class="status-card">
                <span class="label">Return</span>
                <span class="status-badge" style="{{ $badge($operationalStatus === 'returned' ? 'completed' : 'pending') }}">
                    {{ $operationalStatus === 'returned' ? 'Completed' : 'Pending' }}
                </span>
            </div>
        </div>
    </div>

    <div class="detail-card">
        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
            <div>
                <h2 style="margin:0; font-size:18px;">Item Delivery & Pickup Progress</h2>
                <div class="ops-muted" style="margin-top:6px;">Track delivered and returned quantities per rental item so field teams can complete this order in multiple visits.</div>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <span class="status-badge" style="{{ $itemProgressBadge($rental->deliveryStatus()) }}">{{ $itemProgressLabel($rental->deliveryStatus()) }}</span>
                <span class="status-badge" style="{{ $itemProgressBadge($rental->pickupStatus()) }}">{{ $itemProgressLabel($rental->pickupStatus()) }}</span>
            </div>
        </div>
        <div style="overflow:auto; margin-top:14px;">
            <table class="item-progress-table">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th>Ordered</th>
                        <th>Delivered</th>
                        <th>Pending Delivery</th>
                        <th>Returned</th>
                        <th>Pending Pickup</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($rentalItems as $item)
                        @php
                            $orderedQty = (int) ($item->ordered_quantity ?? $item->quantity ?? 0);
                            $deliveredQty = (int) ($item->delivered_quantity_value ?? 0);
                            $pendingDeliveryQty = (int) ($item->pending_delivery_quantity ?? max($orderedQty - $deliveredQty, 0));
                            $returnedQty = (int) ($item->returned_quantity_value ?? 0);
                            $pendingPickupQty = (int) ($item->pending_pickup_quantity ?? max($deliveredQty - $returnedQty, 0));
                            $linkedAssetIds = collect($item->asset_ids ?? [])
                                ->filter(fn ($assetId) => filled($assetId))
                                ->map(fn ($assetId) => (int) $assetId)
                                ->filter(fn ($assetId) => $assetId > 0)
                                ->values();
                            $hasAwaitingVerificationAsset = $linkedAssetIds->isNotEmpty()
                                && \App\Models\Asset::query()
                                    ->where('organization_id', $rental->organization_id)
                                    ->whereIn('id', $linkedAssetIds->all())
                                    ->where('asset_status', \App\Models\Asset::STATUS_AWAITING_VERIFICATION)
                                    ->exists();
                            $itemDeliveryStatus = $pendingDeliveryQty > 0
                                ? ($deliveredQty > 0 ? 'partially_delivered' : 'delivery_pending')
                                : ($deliveredQty > 0 ? 'delivered' : 'delivery_pending');
                            $itemLifecycleStatuses = collect();

                            if ($deliveredQty > $returnedQty) {
                                $itemLifecycleStatuses->push('with_customer');
                            }

                            if ($pendingPickupQty > 0) {
                                $itemLifecycleStatuses->push($returnedQty > 0 ? 'partially_returned' : 'pickup_pending');
                            } elseif ($deliveredQty > 0 && $returnedQty === $deliveredQty) {
                                $itemLifecycleStatuses->push($hasAwaitingVerificationAsset ? 'awaiting_verification' : 'returned');
                            }

                            $itemLifecycleStatuses = $itemLifecycleStatuses->unique()->values();
                        @endphp
                        <tr>
                            <td data-label="Item">
                                <div class="value" style="font-weight:700;">{{ $item->product->name ?? $rental->product->name ?? 'Rental item' }}</div>
                                <div class="item-progress-meta">
                                    @if(!empty($item->notes))
                                        <span>{{ $item->notes }}</span>
                                    @endif
                                    @if(!empty($item->asset_ids))
                                        <span>{{ count($item->asset_ids) }} linked asset{{ count($item->asset_ids) === 1 ? '' : 's' }}</span>
                                    @endif
                                </div>
                            </td>
                            <td class="value" data-label="Ordered">
                                <span class="item-progress-inline-label">Ordered</span>
                                {{ $orderedQty }}
                            </td>
                            <td class="value" data-label="Delivered">
                                <span class="item-progress-inline-label">Delivered</span>
                                {{ $deliveredQty }}
                            </td>
                            <td class="value" data-label="Pending Delivery">
                                <span class="item-progress-inline-label">Pending Delivery</span>
                                {{ $pendingDeliveryQty }}
                            </td>
                            <td class="value" data-label="Returned">
                                <span class="item-progress-inline-label">Returned</span>
                                {{ $returnedQty }}
                            </td>
                            <td class="value" data-label="Pending Pickup">
                                <span class="item-progress-inline-label">Pending Pickup</span>
                                {{ $pendingPickupQty }}
                            </td>
                            <td data-label="Status">
                                <span class="item-progress-inline-label">Status</span>
                                <div style="display:grid; gap:6px;">
                                    <span class="status-badge" style="{{ $itemProgressBadge($itemDeliveryStatus) }}">{{ $itemProgressLabel($itemDeliveryStatus) }}</span>
                                    @foreach($itemLifecycleStatuses as $itemLifecycleStatus)
                                        <span class="status-badge" style="{{ $itemProgressBadge($itemLifecycleStatus) }}">{{ $itemProgressLabel($itemLifecycleStatus) }}</span>
                                    @endforeach
                                </div>
                            </td>
                            <td data-label="Actions">
                                <span class="item-progress-inline-label">Actions</span>
                                <div style="display:grid; gap:10px;">
                                    @if($deliveryRecord && in_array($deliveryRecord->status, ['pending', 'in_progress'], true) && $pendingDeliveryQty > 0)
                                        <form method="POST" action="{{ route('deliveries.partial_delivery', $deliveryRecord) }}" class="item-progress-form">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="rental_item_id" value="{{ $item->id }}">
                                            <div>
                                                <span class="label">Deliver Qty</span>
                                                <input type="number" name="quantity" min="1" max="{{ $pendingDeliveryQty }}" value="1">
                                            </div>
                                            <div>
                                                <span class="label">Notes</span>
                                                <input type="text" name="notes" placeholder="Optional note">
                                            </div>
                                            <button type="submit" class="detail-btn-secondary">Deliver</button>
                                        </form>
                                    @endif
                                    @if($pickupRecord && in_array($pickupRecord->status, ['pending', 'in_progress'], true) && $pendingPickupQty > 0)
                                        <form method="POST" action="{{ route('deliveries.partial_pickup', $pickupRecord) }}" class="item-progress-form">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="rental_item_id" value="{{ $item->id }}">
                                            <div>
                                                <span class="label">Pickup Qty</span>
                                                <input type="number" name="quantity" min="1" max="{{ $pendingPickupQty }}" value="1">
                                            </div>
                                            <div>
                                                <span class="label">Notes</span>
                                                <input type="text" name="notes" placeholder="Optional note">
                                            </div>
                                            <button type="submit" class="detail-btn-secondary">Pickup</button>
                                        </form>
                                    @endif
                                    @if((!$deliveryRecord || !in_array($deliveryRecord->status, ['pending', 'in_progress'], true)) && (! $pickupRecord || !in_array($pickupRecord->status, ['pending', 'in_progress'], true)))
                                        <span class="ops-muted">Open a delivery or pickup task to record item quantities.</span>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    @if($canSeeRentalFinance)
    <div class="detail-card">
        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
            <div>
                <h2 style="margin:0; font-size:18px;">Original Booking Snapshot</h2>
                <div class="ops-muted" style="margin-top:6px;">Base rental charges before renewals were added, so the first agreed price and collection stay visible.</div>
            </div>
        </div>
        <div class="booking-snapshot-grid">
            <div class="booking-snapshot-card is-range">
                <span class="snapshot-label">Original Period</span>
                <div class="snapshot-value">
                    {{ optional($rental->start_date)->format('d M Y') ?: '-' }}
                    <span style="color:#94a3b8; font-weight:700;">→</span>
                    {{ optional($initialBookingEndDate)->format('d M Y') ?: '-' }}
                </div>
                <div class="snapshot-subtle">Initial agreed rental window before renewals extended the case.</div>
            </div>
            <div class="booking-snapshot-card">
                <span class="snapshot-label">Rental</span>
                <div class="snapshot-value">{{ $currency($baseRentalAmount) }}</div>
            </div>
            <div class="booking-snapshot-card">
                <span class="snapshot-label">Deposit</span>
                <div class="snapshot-value">{{ $currency($baseDepositAmount) }}</div>
            </div>
            <div class="booking-snapshot-card">
                <span class="snapshot-label">Transport</span>
                <div class="snapshot-value">{{ $currency($baseTransportAmount) }}</div>
            </div>
            <div class="booking-snapshot-card">
                <span class="snapshot-label">Other</span>
                <div class="snapshot-value">{{ $currency($baseOtherAmount) }}</div>
            </div>
            <div class="booking-snapshot-card is-total">
                <span class="snapshot-label">Original Booking Total</span>
                <div class="snapshot-value">{{ $currency($baseBookingTotal) }}</div>
                <div class="snapshot-subtle">Base rental value before renewal additions and later adjustments.</div>
            </div>
            <div class="booking-snapshot-card">
                <span class="snapshot-label">Collected Against Base Booking</span>
                <div class="snapshot-value">{{ $currency($baseBookingPaidAmount) }}</div>
            </div>
            <div class="booking-snapshot-card is-balance">
                <span class="snapshot-label">Remaining on Base Booking</span>
                <div class="snapshot-value">{{ $currency($baseBookingBalance) }}</div>
            </div>
            <div class="booking-snapshot-card is-status">
                <span class="snapshot-label">Base Invoice Status</span>
                <div class="snapshot-value">{{ strtoupper(str_replace('_', ' ', (string) ($rentalInvoice->payment_status ?? 'not_generated'))) }}</div>
                <div class="snapshot-subtle">Reflects the current invoice workflow state for the original booking.</div>
            </div>
        </div>
    </div>
    @endif

    <div class="detail-card section-nav-target" id="rental-payments-section">
        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
            <div>
                <h2 style="margin:0; font-size:18px;">Payment History</h2>
                <div class="ops-muted" style="margin-top:6px;">This now reflects invoice-linked and rental-linked payments without sending you to a separate payment page.</div>
            </div>
        </div>
        <div class="payment-history-list">
            @forelse($rental->payments as $payment)
                <div class="payment-history-card">
                    <div>
                        <span class="label">Date</span>
                        <div class="value">{{ optional($payment->payment_date)->format('d M Y') ?: '-' }}</div>
                    </div>
                    <div>
                        <strong>{{ $payment->paymentMethodLabel() }}</strong>
                        <div class="ops-muted">
                            {{ $payment->invoice?->invoice_number ? 'Invoice ' . $payment->invoice->invoice_number : 'Rental payment' }}
                            @if($payment->notes)
                                - {{ $payment->notes }}
                            @endif
                        </div>
                    </div>
                    <div>
                        <span class="label">Amount</span>
                        <div class="value">{{ $canViewFinanceAmounts ? $currency($payment->amount) : 'Restricted' }}</div>
                    </div>
                    @if($canDeletePayments)
                        <form action="{{ route('payments.destroy', $payment) }}" method="POST" style="margin:0;">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="detail-btn-danger" onclick="return confirm('Delete this payment? Related invoice balance will be recalculated if applicable.');">Delete</button>
                        </form>
                    @endif
                </div>
            @empty
                <div class="ops-muted">No payments recorded for this rental.</div>
            @endforelse
        </div>
    </div>

    <div class="detail-card renewal-highlight section-nav-target" id="renewal-workspace">
        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
            <div>
                <h2 style="margin:0; font-size:18px;">Renewal Workspace</h2>
                <div class="ops-muted" style="margin-top:6px;">Extend this rental in place, capture renewal payment if received, and keep the full history on the same rental record.</div>
            </div>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                @if($renewalUrl)
                    <a href="{{ $renewalUrl }}" target="_blank" class="detail-btn-secondary">Send Renewal WhatsApp</a>
                @endif
                @if($rental->canRenew() && auth()->user()->canAccessModule('rentals', 'update'))
                    <form method="POST" action="{{ route('rentals.quick-renew', $rental) }}" style="margin:0;" data-quick-renew-form data-renewal-days="{{ $suggestedRenewalDays }}">
                        @csrf
                        <button type="submit" class="detail-btn-secondary">Quick Renew</button>
                    </form>
                    <button type="button" class="detail-btn" data-open-renewal-modal>Renewal Options</button>
                @endif
            </div>
        </div>

    @unless($renewalFeatureReady)
            <div class="flash flash-warning" style="margin-top:14px;">
                Renewal history logging is waiting on the latest migration. Run <code>php artisan migrate</code> so future renewals show up here automatically.
            </div>
        @endunless

        @php
            $latestRenewalForWarning = $renewalHistory->first();
            $showDuplicateRenewalWarning = $latestRenewalForWarning
                && $latestRenewalForWarning->canBeEdited()
                && optional($latestRenewalForWarning->renewed_end_date)?->greaterThan(now()->startOfDay());
        @endphp

        @if($showDuplicateRenewalWarning)
            <div class="flash flash-warning" style="margin-top:14px;">
                A renewal is already logged up to <strong>{{ optional($latestRenewalForWarning->renewed_end_date)->format('d M Y') }}</strong>.
                Use <strong>Edit Renewal</strong> if you only need to correct invoice, payment, notes, or charge details. Create another renewal only if you intentionally want to extend this rental again.
            </div>
        @endif

        <div class="renewal-history" style="margin-top:14px;">
            @forelse($renewalHistory as $index => $renewal)
                @php
                    $renewalInvoiceStatus = $renewal->invoiceWorkflowStatus();
                    $renewalPaymentStatus = $renewal->paymentWorkflowStatus();
                    $renewalOutstanding = $renewal->outstandingAmount();
                    $renewalInvoiceBadgeTone = match ($renewalInvoiceStatus) {
                        'paid' => 'success',
                        'generated', 'partial' => 'info',
                        'pending' => 'warning',
                        default => 'default',
                    };
                    $renewalPaymentBadgeTone = match ($renewalPaymentStatus) {
                        'paid' => 'success',
                        'partial' => 'info',
                        'pending' => 'danger',
                        default => 'default',
                    };
                @endphp
                <div class="renewal-entry">
                    <div style="display:grid; grid-template-columns:72px minmax(0, 1.25fr) minmax(0, .9fr) auto; gap:12px; align-items:start;">
                        <div>
                            <span class="label">S/N</span>
                            <div class="value">{{ $index + 1 }}</div>
                        </div>
                        <div>
                            <strong>{{ optional($renewal->previous_end_date)->format('d M Y') ?: '-' }} to {{ optional($renewal->renewed_end_date)->format('d M Y') }}</strong>
                    <div class="ops-muted" style="margin-top:6px;">
                        {{ $renewal->renewal_days }} day{{ (int) $renewal->renewal_days === 1 ? '' : 's' }} added
                        · Rental {{ $currency($renewal->rental_amount_added ?? 0) }}
                        @if(($renewal->payment_amount ?? 0) > 0)
                            · Payment {{ $currency($renewal->payment_amount) }} via {{ strtoupper(str_replace('_', ' ', $renewal->payment_method ?? 'other')) }}
                        @endif
                        · {{ $renewal->created_at?->format('d M Y h:i A') }}
                        @if($renewal->renewedBy)
                            · by {{ $renewal->renewedBy->name }}
                        @endif
                    </div>
                    @if($renewal->notes)
                        <div class="ops-muted" style="margin-top:6px;">{{ $renewal->notes }}</div>
                    @endif
                    <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center; margin-top:10px;">
                        <span class="status-badge" style="{{ $badge($renewalInvoiceBadgeTone) }}">Invoice {{ strtoupper(str_replace('_', ' ', $renewalInvoiceStatus)) }}</span>
                        <span class="status-badge" style="{{ $badge($renewalPaymentBadgeTone) }}">Payment {{ strtoupper(str_replace('_', ' ', $renewalPaymentStatus)) }}</span>
                        @if($renewalOutstanding > 0.009)
                            <span class="ops-muted">Balance {{ $currency($renewalOutstanding) }} pending</span>
                        @endif
                    </div>
                    @if($renewalPaymentStatus === 'pending')
                        <div style="margin-top:10px; padding:10px 12px; border-radius:12px; background:#fff7ed; border:1px solid #fdba74; color:#9a3412; font-weight:600;">
                            Renewal payment is still pending.
                        </div>
                    @endif
                        </div>
                        <div>
                            <span class="label">Logged</span>
                            <div class="value">{{ $renewal->created_at?->format('d M Y h:i A') }}</div>
                            <div class="ops-muted" style="margin-top:6px;">{{ $renewal->renewedBy?->name ?? 'System' }}</div>
                        </div>
                        <div style="display:flex; gap:8px; flex-wrap:wrap; justify-content:flex-end;">
                            <span class="status-badge" style="{{ $badge('info') }}">{{ ucfirst($renewal->renewal_type) }} Renewal</span>
                            @if($renewal->invoice)
                                <a href="{{ route('invoices.show', $renewal->invoice) }}" class="detail-btn-secondary">Open Invoice</a>
                            @elseif(auth()->user()->canAccessModule('rentals', 'update'))
                                <form action="{{ route('rentals.renewals.invoice', [$rental, $renewal]) }}" method="POST" style="margin:0;">
                                    @csrf
                                    <button type="submit" class="detail-btn-secondary">Generate Invoice</button>
                                </form>
                            @endif
                            @if(auth()->user()->canAccessModule('rentals', 'update'))
                                <button
                                    type="button"
                                    class="detail-btn-secondary"
                                    data-open-renewal-modal
                                    data-renewal-mode="edit"
                                    data-renewal-id="{{ $renewal->id }}"
                                    data-renewal-title="Edit Renewal"
                                    data-renewal-submit="Update Renewal"
                                    data-renewal-current-end="{{ optional($renewal->previous_end_date)->format('d M Y') }}"
                                    data-renewal-current-end-raw="{{ optional($renewal->previous_end_date)->format('Y-m-d') }}"
                                    data-renewal-preset="custom"
                                    data-renewal-new-end="{{ optional($renewal->renewed_end_date)->format('Y-m-d') }}"
                                    data-renewal-rental="{{ (float) ($renewal->rental_amount_added ?? 0) }}"
                                    data-renewal-deposit="{{ (float) ($renewal->deposit_amount_added ?? 0) }}"
                                    data-renewal-transport="{{ (float) ($renewal->transport_amount_added ?? 0) }}"
                                    data-renewal-other="{{ (float) ($renewal->other_amount_added ?? 0) }}"
                                    data-renewal-payment="{{ (float) ($renewal->payment_amount ?? 0) }}"
                                    data-renewal-payment-date="{{ optional($renewal->payment?->payment_date)->format('Y-m-d') ?: '' }}"
                                    data-renewal-payment-method="{{ $renewal->payment_method ?? '' }}"
                                    data-renewal-notes="{{ $renewal->notes ?? '' }}"
                                >Edit Renewal</button>
                            @endif
                            @if(auth()->user()->canAccessModule('rentals', 'update') && !$renewal->invoice_id && (float) ($renewal->payment_amount ?? 0) <= 0.009)
                                <form action="{{ route('rentals.renewals.destroy', [$rental, $renewal]) }}" method="POST" style="margin:0;">
                                    @csrf
                                    @method('DELETE')
                                    <button
                                        type="submit"
                                        class="detail-btn-secondary"
                                        onclick="return confirm('Delete this renewal? This is allowed only before invoice or payment history is linked. Later renewal dates will be recalculated safely if needed.');"
                                    >Delete Renewal</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="renewal-entry">
                    <div class="value">No renewal history yet. The first renewal will extend this same rental and be logged here.</div>
                </div>
            @endforelse
        </div>
    </div>

    <div class="detail-card section-nav-target" id="rental-products-section">
        <h2 style="margin-top:0; margin-bottom:14px; font-size:18px;">Rental Product Rows</h2>
        <div style="display:grid; gap:10px;">
            @foreach($rentalItems as $itemIndex => $item)
                @php($linkedAssets = method_exists($item, 'linkedAssets') ? $item->linkedAssets() : collect())
                <div style="border:1px solid #e2e8f0; border-radius:12px; padding:12px 14px; background:#fcfdff;">
                    <div class="rental-product-line">
                        <div>
                            <span class="label">S/N</span>
                            <div class="value">{{ $itemIndex + 1 }}</div>
                        </div>
                        <div>
                            <strong>{{ $item->product->name ?? ($rental->product->name ?? 'Rental product') }}</strong>
                            @if($item->notes)
                                <div class="ops-muted" style="margin-top:6px;">{{ $item->notes }}</div>
                            @endif
                            @if($linkedAssets->isNotEmpty())
                                <div class="ops-muted" style="margin-top:6px;">
                                    Assets {{ $linkedAssets->map(fn ($asset) => $asset->serial_number ?: ($asset->asset_name ?: ('#' . $asset->id)))->implode(', ') }}
                                </div>
                            @endif
                        </div>
                        <div>
                            <span class="label">Quantity</span>
                            <div class="value">{{ $item->quantity ?? 1 }}</div>
                        </div>
                        <div>
                            <span class="label">{{ $canSeeRentalFinance ? 'Line Amount' : 'Line Status' }}</span>
                            <div class="value">
                                {{ $canSeeRentalFinance ? $currency($item->line_total ?? 0) : 'Operational line' }}
                            </div>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    </div>

    @if($saleItems->isNotEmpty())
        <div class="detail-card">
            <h2 style="margin-top:0; margin-bottom:14px; font-size:18px;">New Products Sold With This Rental</h2>
            <div class="ops-muted" style="margin-bottom:14px;">These items were sold on the same order and stay outside rental duration, overdue logic, and renewal calculations.</div>
            <div style="display:grid; gap:10px;">
                @foreach($saleItems as $saleItem)
                    <div style="border:1px solid #e2e8f0; border-radius:12px; padding:12px 14px; background:#fcfdff;">
                        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap;">
                            <div>
                                <strong>{{ $saleItem->product->name ?? 'New product' }}</strong>
                                <div class="ops-muted" style="margin-top:4px;">
                                    Qty {{ $saleItem->quantity }}
                                    @if($canSeeRentalFinance)
                                        | Unit {{ $currency($saleItem->unit_price ?? 0) }}
                                    @endif
                                    @if($saleItem->asset)
                                        | Asset {{ $saleItem->asset->serial_number ?: ($saleItem->asset->asset_name ?: ('#' . $saleItem->asset->id)) }}
                                    @endif
                                    @if($saleItem->warehouse)
                                        | Warehouse {{ $saleItem->warehouse->name }}
                                    @endif
                                </div>
                            </div>
                            <div style="font-weight:700; color:#0f172a;">{{ $canSeeRentalFinance ? $currency($saleItem->line_total ?? 0) : 'Operational item' }}</div>
                        </div>
                        @if($saleItem->notes)
                            <div class="ops-muted" style="margin-top:8px;">{{ $saleItem->notes }}</div>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="detail-grid">
        <x-operational-card
            class="span-6 section-nav-target"
            id="rental-customer-section"
            :title="$rental->usesBusinessPartnerFlow() ? 'Customer & Partner Contacts' : 'Customer Contact'"
            subtitle="Keep reminder/payment contact and delivery/service contact clearly separated."
        >
            @if($rental->usesBusinessPartnerFlow())
                <div class="ph-rental-contact-grid">
                    <x-contact-block
                        title="Reminder / Payment Contact"
                        :name="$rental->businessPartner?->displayName() ?? $rental->billingContactName()"
                        :role="$rental->businessPartner?->contact_person ? 'Contact person: ' . $rental->businessPartner->contact_person : 'Business Partner'"
                        :phone="$rental->businessPartner?->preferredReminderNumber()"
                        :whatsapp="$rental->businessPartner?->preferredReminderNumber()"
                        :email="$rental->businessPartner?->email"
                        :address="$partnerBillingAddress ?: $rental->businessPartner?->address"
                        :map-url="$rental->businessPartner?->openMapUrl()"
                        :notes="$partnerBillingMeta ?: ($latestReminderAt ? 'Last reminder sent ' . $latestReminderAt->format('d M Y h:i A') : null)"
                        view-label="View Partner"
                        :view-url="route('business-partners.show', $rental->business_partner_id)"
                        :chips="collect([
                            $rental->businessPartner?->gstin ? ['label' => 'GST Available', 'tone' => 'success'] : null,
                            $latestReminderAt ? ['label' => 'Reminder Logged', 'tone' => 'info'] : null,
                        ])->filter()->values()->all()"
                    />

                    <x-contact-block
                        title="Delivery / Service Contact"
                        :name="$rental->partnerClient?->displayName() ?? $rental->deliveryContactName()"
                        role="Actual Client / Delivery Location"
                        :phone="$rental->partnerClient?->primaryPhone() ?? $rental->deliveryContactPhone()"
                        :whatsapp="$rental->partnerClient?->primaryPhone() ?? $rental->deliveryContactPhone()"
                        :address="$rental->partnerClient?->address"
                        :city="$rental->partnerClient?->city"
                        :state="$rental->partnerClient?->state"
                        :pincode="$rental->partnerClient?->pincode"
                        :map-url="$rental->partnerClient?->openMapUrl() ?? $deliveryContactMapUrl"
                        :notes="$rental->partnerClient?->delivery_notes"
                        view-label="View Actual Client"
                        :view-url="route('business-partners.show', $rental->business_partner_id) . '#actual-clients'"
                        :chips="[['label' => 'Service Contact', 'tone' => 'accent']]"
                    />
                </div>
            @else
                <div class="ph-rental-contact-stack">
                    <x-contact-block
                        title="Direct Customer"
                        :name="$rental->customer?->displayName() ?? $rental->billingContactName()"
                        :role="$rental->customer?->contactPersonName() ? 'Contact person: ' . $rental->customer->contactPersonName() : 'Direct customer rental'"
                        :phone="$rental->customer?->phone ?? $rental->reminderContactPhone()"
                        :whatsapp="$rental->customer?->preferredWhatsAppNumber()"
                        :email="$rental->customer?->email"
                        :address="$rental->customer?->address"
                        :city="$rental->customer?->city"
                        :state="$rental->customer?->state"
                        :pincode="$rental->customer?->pincode"
                        :map-url="$rental->customer?->openMapUrl()"
                        :notes="$latestReminderAt ? 'Last reminder sent ' . $latestReminderAt->format('d M Y h:i A') : null"
                        view-label="View Customer"
                        :view-url="$rental->customer_id ? route('customers.show', $rental->customer_id) : null"
                    />
                </div>
            @endif

            <div class="ph-rental-inline-toolbar">
                @if($deliveryUrl)
                    <a href="{{ $deliveryUrl }}" target="_blank" rel="noopener" class="detail-btn-secondary">Delivery Message</a>
                @endif
                @if($pickupUrl)
                    <a href="{{ $pickupUrl }}" target="_blank" rel="noopener" class="detail-btn-secondary">Pickup Reminder</a>
                @endif
                @if($deliveryContactMapUrl)
                    <a href="{{ $deliveryContactMapUrl }}" target="_blank" rel="noopener" class="detail-btn-secondary">Open Service Map</a>
                @endif
            </div>
        </x-operational-card>

        <x-operational-card
            class="span-6"
            title="{{ $canSeeRentalFinance ? 'Finance Snapshot' : 'Operational Snapshot' }}"
            subtitle="{{ $canSeeRentalFinance ? 'Keep charges, payment posture, and collection status visible without leaving the rental.' : 'Your role can see workflow status without exposing finance amounts.' }}"
        >
            @if($canSeeRentalFinance)
                <div class="ph-rental-summary-grid">
                    <x-summary-card label="Rental" :value="$canViewFinanceAmounts ? $currency($rental->rental_amount) : ucfirst($rentalInvoiceStatus ?: 'pending')" />
                    <x-summary-card label="Deposit" :value="$canViewFinanceAmounts ? $currency($rental->deposit_amount) : ($rental->deposit_amount > 0 ? 'Captured' : 'Not captured')" />
                    <x-summary-card label="Transport" :value="$canViewFinanceAmounts ? $currency($rental->transport_amount) : (($rental->transport_amount ?? 0) > 0 ? 'Added' : 'Not added')" />
                    <x-summary-card label="Other Charges" :value="$canViewFinanceAmounts ? $currency($rental->other_amount) : (($rental->other_amount ?? 0) > 0 ? 'Added' : 'Not added')" />
                    <x-summary-card label="Invoice Status" :value="$rentalInvoice ? ucfirst(str_replace('_', ' ', $rentalInvoiceStatus ?: 'unpaid')) : 'Not generated'" :tone="$invoiceStatusTone" />
                    <x-summary-card label="Payment Status" :value="$rentalInvoiceStatus ? ucfirst(str_replace('_', ' ', $rentalInvoiceStatus)) : 'Pending'" :tone="$paymentStatusTone" :meta="$canViewFinanceAmounts && $rentalInvoiceDue > 0 ? 'Due ' . $currency($rentalInvoiceDue) : null" />
                </div>
            @else
                <div class="ph-rental-detail-list">
                    <div class="ph-rental-detail-row">
                        <span>Invoice</span>
                        <strong>{{ $rentalInvoice ? 'Generated' : 'Pending' }}</strong>
                    </div>
                    <div class="ph-rental-detail-row">
                        <span>Payment</span>
                        <strong>{{ $rentalInvoiceStatus ? ucfirst(str_replace('_', ' ', $rentalInvoiceStatus)) : 'Pending' }}</strong>
                    </div>
                    <div class="ph-rental-detail-row">
                        <span>Delivery Staff</span>
                        <strong>{{ $deliveryAssigneeLabel }}</strong>
                    </div>
                    <div class="ph-rental-detail-row">
                        <span>Pickup Staff</span>
                        <strong>{{ $pickupAssigneeLabel }}</strong>
                    </div>
                </div>
            @endif
        </x-operational-card>

        <x-operational-card
            class="span-12"
            title="Product & Assigned Assets"
            subtitle="See which units are still with the customer and what will come back into verification."
        >
            <div class="ph-rental-summary-grid" style="margin-bottom:12px;">
                <x-summary-card label="Primary Product" :value="$rental->product->name ?? 'N/A'" />
                <x-summary-card label="Quantity" :value="$rental->quantity" />
                <x-summary-card label="Returned At" :value="$rental->returned_at ? $rental->returned_at->format('d M Y h:i A') : 'Not returned yet'" />
            </div>

            <div class="ph-rental-asset-grid">
                @forelse($activeAssets as $assignment)
                    <div class="ph-rental-asset-tile">
                        <span>Serial</span>
                        <strong>{{ $assignment->asset->serial_number ?? 'N/A' }}</strong>
                        <span>Barcode</span>
                        <strong>{{ $assignment->asset->barcode_value ?? '-' }}</strong>
                        <span>Warehouse</span>
                        <strong>{{ $assignment->asset->warehouse->name ?? 'Not set' }}</strong>
                    </div>
                @empty
                    <x-empty-state
                        title="No assigned assets yet"
                        message="This rental does not currently have tracked asset units linked. You can still continue delivery and update the assignment later if needed."
                    />
                @endforelse
            </div>
        </x-operational-card>

        <x-operational-card
            class="span-6 section-nav-target"
            id="rental-delivery-section"
            title="Delivery"
            subtitle="Track assignment, service contact, schedule, and completion."
        >
            <div class="ph-rental-detail-list">
                <div class="ph-rental-detail-row">
                    <span>Status</span>
                    <strong>{{ $deliveryStatusLabel($rental->deliveryStatus()) }}</strong>
                </div>
                <div class="ph-rental-detail-row">
                    <span>Assigned Staff</span>
                    <strong>{{ $deliveryAssigneeLabel }}</strong>
                </div>
                <div class="ph-rental-detail-row">
                    <span>Scheduled</span>
                    <strong>{{ $deliveryRecord?->scheduled_at ? $deliveryRecord->scheduled_at->format('d M Y h:i A') : 'Not scheduled' }}</strong>
                </div>
            </div>

            <x-contact-block
                title="Delivery Contact"
                :name="$deliveryContactName"
                role="Delivery / Service"
                :phone="$deliveryContactPhone"
                :whatsapp="$deliveryContactPhone"
                :address="$rental->deliveryContactAddress()"
                :city="$rental->deliveryContactCity()"
                :state="$rental->deliveryContactState()"
                :pincode="$rental->deliveryContactPincode()"
                :map-url="$deliveryContactMapUrl"
                :notes="$rental->deliveryContactNotes()"
            />

            <div class="ph-rental-inline-toolbar">
                @if($deliveryRecord)
                    @if($canUpdateDeliveries)
                        <a href="{{ route('deliveries.edit', $deliveryRecord) }}" class="detail-btn-secondary">Assign</a>
                    @endif
                    @if($deliveryRecord->status === 'pending')
                        <form method="POST" action="{{ route('deliveries.in_progress', $deliveryRecord) }}" style="margin:0;">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="detail-btn-secondary">Start</button>
                        </form>
                    @elseif($deliveryRecord->status === 'in_progress')
                        <form method="POST" action="{{ route('deliveries.complete', $deliveryRecord) }}" style="margin:0;">
                            @csrf
                            @method('PUT')
                            @if($hasPendingDeliveryItems)
                                <input type="hidden" name="confirm_partial" value="1">
                            @endif
                            <button type="submit" class="detail-btn">Mark Delivered</button>
                        </form>
                    @endif
                    @if($deliveryContactMapUrl)
                        <a href="{{ $deliveryContactMapUrl }}" target="_blank" rel="noopener" class="detail-btn-secondary">Open Map</a>
                    @endif
                @elseif($rental->deliveryStaff)
                    @if($canCreateDeliveries && !$hasOpenDeliveryTask)
                        <a href="{{ route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'delivery']) }}" class="detail-btn-secondary">Create Delivery Task</a>
                    @endif
                    @if($canUpdateRentals)
                        <form method="POST" action="{{ route('rentals.delivery-assignment.clear', $rental) }}" style="margin:0;">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="detail-btn-danger" onclick="return confirm('Clear this delivery assignment from the rental?');">Clear Assignment</button>
                        </form>
                    @endif
                @elseif($canCreateDeliveries && !$hasOpenDeliveryTask)
                    <a href="{{ route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'delivery']) }}" class="detail-btn-secondary">Assign Delivery</a>
                @else
                    <span class="ops-muted">Delivery action is not available right now.</span>
                @endif
            </div>
        </x-operational-card>

        <x-operational-card
            class="span-6 section-nav-target"
            id="rental-pickup-section"
            title="Pickup"
            subtitle="Schedule, assign, and close the return workflow from the rental itself."
        >
            <div class="ph-rental-detail-list">
                <div class="ph-rental-detail-row">
                    <span>Status</span>
                    <strong>{{ ucfirst(str_replace('_', ' ', $rental->pickupStatus() ?: 'pending')) }}</strong>
                </div>
                <div class="ph-rental-detail-row">
                    <span>Assigned Staff</span>
                    <strong>{{ $pickupAssigneeLabel }}</strong>
                </div>
                <div class="ph-rental-detail-row">
                    <span>Scheduled</span>
                    <strong>{{ $pickupRecord?->scheduled_at ? $pickupRecord->scheduled_at->format('d M Y h:i A') : 'Not scheduled' }}</strong>
                </div>
            </div>

            <x-contact-block
                title="Pickup Contact"
                :name="$deliveryContactName"
                role="Pickup / Return"
                :phone="$deliveryContactPhone"
                :whatsapp="$deliveryContactPhone"
                :address="$rental->deliveryContactAddress()"
                :city="$rental->deliveryContactCity()"
                :state="$rental->deliveryContactState()"
                :pincode="$rental->deliveryContactPincode()"
                :map-url="$deliveryContactMapUrl"
                :notes="$rental->deliveryContactNotes()"
            />

            <div class="ph-rental-inline-toolbar">
                @if($pickupRecord)
                    @if($canUpdateDeliveries)
                        <a href="{{ route('deliveries.edit', $pickupRecord) }}" class="detail-btn-secondary">Assign Staff</a>
                    @endif
                    @if($pickupRecord->status === 'pending')
                        <form method="POST" action="{{ route('deliveries.in_progress', $pickupRecord) }}" style="margin:0;">
                            @csrf
                            @method('PUT')
                            <button type="submit" class="detail-btn-secondary">Start Pickup</button>
                        </form>
                    @elseif($pickupRecord->status === 'in_progress')
                        <form method="POST" action="{{ route('deliveries.complete', $pickupRecord) }}" style="margin:0;">
                            @csrf
                            @method('PUT')
                            @if($hasPendingPickupItems)
                                <input type="hidden" name="confirm_partial" value="1">
                            @endif
                            <button type="submit" class="detail-btn">Mark Picked Up</button>
                        </form>
                    @endif
                    @if($deliveryContactMapUrl)
                        <a href="{{ $deliveryContactMapUrl }}" target="_blank" rel="noopener" class="detail-btn-secondary">Open Map</a>
                    @endif
                @elseif($canAssignPickup && !$hasOpenPickupTask)
                    <a href="{{ route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'pickup']) }}" class="detail-btn-secondary">Schedule Pickup</a>
                @elseif($canCreateDeliveries)
                    <span class="ops-muted">Pickup becomes actionable after at least one item is delivered.</span>
                @endif
            </div>
        </x-operational-card>
    </div>
</div>

<div class="ph-rental-notes-shell">
    <div class="section-nav-target" id="rental-activity-timeline"></div>
    <div class="section-nav-target" id="rental-notes-section"></div>
    @include('partials.activity-timeline', [
        'timeline' => $activityLogs ?? collect(),
        'title' => 'Timeline & Notes',
        'subtitle' => 'Renewals, reminders, invoices, delivery updates, follow-ups, and internal notes stay together here.',
        'timelineFilter' => $timelineFilter ?? 'all',
        'timelineRoute' => 'rentals.show',
        'noteAction' => route('rentals.notes.store', $rental),
        'noteLabel' => 'Add Note',
        'anchorId' => 'rental-activity-timeline',
    ])
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
            <div class="rental-cta-title">Rental #{{ $rental->id }} · {{ $rental->billingContactName() }}</div>
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
