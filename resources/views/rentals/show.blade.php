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
@endphp

<style>
    .detail-page { display:grid; gap:16px; padding:18px 22px 28px; }
    .detail-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
    .detail-header h1 { margin:0; font-size:28px; color:#0f172a; }
    .detail-header p { margin:6px 0 0; color:#64748b; font-size:13px; }
    .detail-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .mobile-inline-actions { display:none; }
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
    <div class="detail-header">
        <div>
            <h1>Rental #{{ $rental->id }}</h1>
            <p>Compact operational view of customer, charges, assets, warehouse, delivery, and return workflow.</p>
        </div>
        <div class="detail-actions">
            <a href="{{ route('rentals.index') }}" class="detail-btn-secondary">Back</a>
            @if($canUpdateRentals)
                <a href="{{ route('rentals.edit', $rental) }}" class="detail-btn-secondary">Edit</a>
            @endif
            @if(!empty($rentalInvoice))
                <a href="{{ route('invoices.show', $rentalInvoice) }}" class="detail-btn-secondary">Rental Invoice</a>
            @elseif(auth()->user()->canAccessModule('rentals', 'update'))
                <form action="{{ route('rentals.invoice', $rental) }}" method="POST" style="margin:0;">
                    @csrf
                    <button type="submit" class="detail-btn-secondary">Generate Rental Invoice</button>
                </form>
            @endif
            @if($canCreatePayments && !in_array($rentalInvoiceStatus, ['paid', 'cancelled'], true))
                <form action="{{ route('rentals.markPaid', $rental) }}" method="POST" style="margin:0;">
                    @csrf
                    <button type="submit" class="detail-btn-secondary">Mark as Paid</button>
                </form>
                <a href="#rental-billing-actions" class="detail-btn">Mark Partial Payment</a>
            @endif
            @if($deliveryRecord && $hasOpenDeliveryTask && auth()->user()?->canAccessModule('deliveries', 'update'))
                <a href="{{ route('deliveries.edit', $deliveryRecord) }}" class="detail-btn-secondary">Edit Delivery Assignment</a>
            @elseif($canCreateDeliveries && !$hasOpenDeliveryTask && $hasPendingDeliveryItems)
                <a href="{{ route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'delivery']) }}" class="detail-btn-secondary">Assign Delivery</a>
            @endif
            @if($pickupRecord && $hasOpenPickupTask && auth()->user()?->canAccessModule('deliveries', 'update'))
                <a href="{{ route('deliveries.edit', $pickupRecord) }}" class="detail-btn-secondary">Edit Pickup Assignment</a>
            @elseif($canAssignPickup && !$hasOpenPickupTask)
                <a href="{{ route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'pickup']) }}" class="detail-btn-secondary">Assign Pickup</a>
            @endif
            @if($renewalUrl)
                <a href="{{ $renewalUrl }}" target="_blank" class="detail-btn-secondary">WhatsApp Renewal</a>
            @endif
            @if($rental->canRenew() && auth()->user()->canAccessModule('rentals', 'update'))
                <form method="POST" action="{{ route('rentals.quick-renew', $rental) }}" style="margin:0;" data-quick-renew-form data-renewal-days="{{ $suggestedRenewalDays }}">
                    @csrf
                    <button type="submit" class="detail-btn-secondary">Quick Renew</button>
                </form>
                <button type="button" class="detail-btn" data-open-renewal-modal>Renew Rental</button>
            @endif
            @if($rental->canBeReturned())
                <form action="{{ route('rentals.return', $rental) }}" method="POST" style="margin:0;">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="detail-btn-danger" onclick="return confirm('Mark this rental as returned?');">Return Rental</button>
                </form>
            @endif
            @if(auth()->user()->canAccessModule('rentals', 'update') && !in_array($rental->status, ['returned', 'cancelled'], true))
                <form action="{{ route('rentals.cancel', $rental) }}" method="POST" style="margin:0;">
                    @csrf
                    @method('PUT')
                    <button type="submit" class="detail-btn-danger" onclick="return confirm('Cancel this rental? This keeps the record for audit history.');">Cancel Rental</button>
                </form>
            @endif
            @if($canDeleteRentals)
                <form action="{{ route('rentals.destroy', $rental) }}" method="POST" style="margin:0;">
                    @csrf
                    @method('DELETE')
                    <button type="submit" class="detail-btn-danger" onclick="return confirm('Delete this rental order permanently? This will be blocked if invoices, payments, deliveries, renewals, or linked sales exist.');">Delete Rental</button>
                </form>
            @endif
        </div>
    </div>

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

    <div id="rental-activity-timeline">
        @include('partials.activity-timeline', [
            'timeline' => $activityLogs ?? collect(),
            'title' => 'Operations History',
            'subtitle' => 'Renewals, reminders, invoices, delivery updates, and return activity for this rental.',
            'timelineFilter' => $timelineFilter ?? 'all',
            'timelineRoute' => 'rentals.show',
            'noteAction' => route('rentals.notes.store', $rental),
            'noteLabel' => 'Add Note',
            'anchorId' => 'rental-activity-timeline',
        ])
    </div>

    <div class="detail-card" id="rental-billing-actions">
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

    <div class="detail-card">
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
                        <div class="value">{{ $currency($payment->amount) }}</div>
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

    <div class="detail-card renewal-highlight" id="renewal-workspace">
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

    <div class="detail-card">
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
                            <span class="label">Line Amount</span>
                            <div class="value">{{ $currency($item->line_total ?? 0) }}</div>
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
                                    Qty {{ $saleItem->quantity }} | Unit {{ $currency($saleItem->unit_price ?? 0) }}
                                    @if($saleItem->asset)
                                        | Asset {{ $saleItem->asset->serial_number ?: ($saleItem->asset->asset_name ?: ('#' . $saleItem->asset->id)) }}
                                    @endif
                                    @if($saleItem->warehouse)
                                        | Warehouse {{ $saleItem->warehouse->name }}
                                    @endif
                                </div>
                            </div>
                            <div style="font-weight:700; color:#0f172a;">{{ $currency($saleItem->line_total ?? 0) }}</div>
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
        <div class="detail-card span-6">
            <h2 style="margin-top:0; margin-bottom:14px; font-size:18px;">Customer Summary</h2>
            <div class="detail-grid">
                <div class="span-6">
                    <span class="label">Reminder / Billing Contact</span>
                    <div class="value">{{ $rental->billingContactName() }}</div>
                </div>
                <div class="span-6">
                    <span class="label">Reminder Phone</span>
                    <div class="value">{{ $rental->reminderContactPhone() ?: '-' }}</div>
                </div>
                <div class="span-6">
                    <span class="label">Delivery / Pickup Contact</span>
                    <div class="value">{{ $rental->deliveryContactName() }}</div>
                </div>
                <div class="span-6">
                    <span class="label">Delivery Phone</span>
                    <div class="value">{{ $rental->deliveryContactPhone() ?: '-' }}</div>
                </div>
                <div class="span-12">
                    <span class="label">Delivery Address</span>
                    <div class="value">{{ collect([$rental->deliveryContactAddress(), $rental->deliveryContactCity(), $rental->deliveryContactState(), $rental->deliveryContactPincode()])->filter()->join(', ') ?: 'No delivery address captured.' }}</div>
                </div>
                <div class="span-6">
                    <span class="label">Dates</span>
                    <div class="value">{{ optional($rental->start_date)->format('d M Y') }} to {{ optional($rental->end_date)->format('d M Y') }}</div>
                </div>
                <div class="span-6">
                    <span class="label">Created By</span>
                    <div class="value">{{ $rental->createdBy->name ?? 'N/A' }}</div>
                </div>
                <div class="span-6">
                    <span class="label">Delivery Staff</span>
                    <div class="value">{{ $deliveryAssigneeLabel }}</div>
                </div>
                <div class="span-6">
                    <span class="label">Pickup Staff</span>
                    <div class="value">{{ $pickupAssigneeLabel }}</div>
                </div>
                <div class="span-12">
                    <span class="label">Customer Actions</span>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        @if($deliveryUrl)
                            <a href="{{ $deliveryUrl }}" target="_blank" class="detail-btn-secondary">Delivery Message</a>
                        @endif
                        @if($pickupUrl)
                            <a href="{{ $pickupUrl }}" target="_blank" class="detail-btn-secondary">Pickup Reminder</a>
                        @endif
                    </div>
                    @if($latestReminderAt)
                        <div class="ops-muted" style="margin-top:8px;">Last reminder sent {{ $latestReminderAt->format('d M Y h:i A') }}</div>
                    @endif
                </div>
            </div>
        </div>

        <div class="detail-card span-6">
            <h2 style="margin-top:0; margin-bottom:14px; font-size:18px;">Charges</h2>
            <p class="charge-summary-note">Quick cost summary for this rental, including deposit and add-on charges.</p>
            <div class="charge-summary-grid">
                <div class="charge-summary-tile">
                    <span class="charge-label">Rental</span>
                    <div class="charge-value">{{ $currency($rental->rental_amount) }}</div>
                </div>
                <div class="charge-summary-tile">
                    <span class="charge-label">Deposit</span>
                    <div class="charge-value">{{ $currency($rental->deposit_amount) }}</div>
                </div>
                <div class="charge-summary-tile">
                    <span class="charge-label">Transport</span>
                    <div class="charge-value">{{ $currency($rental->transport_amount) }}</div>
                </div>
                <div class="charge-summary-tile">
                    <span class="charge-label">Other Charges</span>
                    <div class="charge-value">{{ $currency($rental->other_amount) }}</div>
                </div>
                <div class="charge-summary-tile charge-summary-tile--total">
                    <span class="charge-label">Total Value</span>
                    <div class="charge-value">{{ $currency(($rental->rental_amount ?? 0) + ($rental->deposit_amount ?? 0) + ($rental->transport_amount ?? 0) + ($rental->other_amount ?? 0)) }}</div>
                </div>
            </div>
        </div>

        <div class="detail-card span-12">
            <h2 style="margin-top:0; margin-bottom:14px; font-size:18px;">Product & Assigned Assets</h2>
            <div class="detail-grid" style="margin-bottom:12px;">
                <div class="span-4">
                    <span class="label">Product</span>
                    <div class="value">{{ $rental->product->name ?? 'N/A' }}</div>
                </div>
                <div class="span-4">
                    <span class="label">Quantity</span>
                    <div class="value">{{ $rental->quantity }}</div>
                </div>
                <div class="span-4">
                    <span class="label">Returned At</span>
                    <div class="value">{{ $rental->returned_at ? $rental->returned_at->format('d M Y h:i A') : 'Not returned yet' }}</div>
                </div>
            </div>

            <div class="asset-grid">
                @forelse($activeAssets as $assignment)
                    <div class="asset-box">
                        <span class="label">Serial</span>
                        <div class="value">{{ $assignment->asset->serial_number ?? 'N/A' }}</div>
                        <span class="label" style="margin-top:10px;">Barcode</span>
                        <div class="value">{{ $assignment->asset->barcode_value ?? '-' }}</div>
                        <span class="label" style="margin-top:10px;">Warehouse</span>
                        <div class="value">{{ $assignment->asset->warehouse->name ?? 'Not set' }}</div>
                    </div>
                @empty
                    <div class="asset-box">
                        <div class="value">No specific assets were assigned to this rental.</div>
                        <div class="ops-muted" style="margin-top:6px;">You can assign them later from the rental edit screen.</div>
                    </div>
                @endforelse
            </div>
        </div>

        <div class="detail-card span-6">
            <h2 style="margin-top:0; margin-bottom:14px; font-size:18px;">Delivery Workflow</h2>
            @if($deliveryRecord)
                <div class="timeline">
                      <div class="timeline-item">
                          <span class="label">Status</span>
                          <div class="value">
                              <span class="status-badge" style="{{ $itemProgressBadge($rental->deliveryStatus()) }}">
                                  {{ $itemProgressLabel($rental->deliveryStatus()) }}
                              </span>
                          </div>
                      </div>
                    <div class="timeline-item">
                        <span class="label">Assigned Person</span>
                        <div class="value">{{ $assignedPersonLabel($deliveryRecord) }}</div>
                    </div>
                    <div class="timeline-item">
                        <span class="label">Scheduled</span>
                        <div class="value">{{ $deliveryRecord->scheduled_at ? $deliveryRecord->scheduled_at->format('d M Y h:i A') : 'Not scheduled' }}</div>
                    </div>
                    <div class="timeline-item">
                        <span class="label">Actions</span>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            @if($canUpdateDeliveries)
                                <a href="{{ route('deliveries.edit', $deliveryRecord) }}" class="detail-btn-secondary">Edit Assignment</a>
                            @endif
                            @if($deliveryRecord->status === 'pending')
                                <form method="POST" action="{{ route('deliveries.in_progress', $deliveryRecord) }}" style="margin:0;">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="detail-btn-secondary">Start Delivery</button>
                                </form>
                                @if($canUpdateDeliveries)
                                    <form method="POST" action="{{ route('deliveries.cancel', $deliveryRecord) }}" style="margin:0;">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="detail-btn-danger" onclick="return confirm('Cancel this delivery assignment?');">Cancel Delivery</button>
                                    </form>
                                @endif
                              @elseif($deliveryRecord->status === 'in_progress')
                                  <form method="POST" action="{{ route('deliveries.complete', $deliveryRecord) }}" style="margin:0;">
                                      @csrf
                                      @method('PUT')
                                      @if($hasPendingDeliveryItems)
                                          <input type="hidden" name="confirm_partial" value="1">
                                          <button type="submit" class="detail-btn">Complete as Partial</button>
                                      @else
                                          <button type="submit" class="detail-btn">Complete Delivery</button>
                                      @endif
                                  </form>
                                @if($canUpdateDeliveries)
                                    <form method="POST" action="{{ route('deliveries.cancel', $deliveryRecord) }}" style="margin:0;">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="detail-btn-danger" onclick="return confirm('Cancel this delivery assignment?');">Cancel Delivery</button>
                                      </form>
                                  @endif
                              @else
                                  @if($canCreateDeliveries && !$hasOpenDeliveryTask && $hasPendingDeliveryItems)
                                      <a href="{{ route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'delivery']) }}" class="detail-btn-secondary">Create Follow-up Delivery Task</a>
                                  @else
                                      <div class="ops-muted">No delivery action needed.</div>
                                  @endif
                              @endif
                            @if($canDeleteDeliveries && in_array($deliveryRecord->status, ['pending', 'cancelled'], true))
                                <form method="POST" action="{{ route('deliveries.destroy', $deliveryRecord) }}" style="margin:0;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="detail-btn-danger" onclick="return confirm('Delete this delivery assignment record?');">Delete Delivery</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @elseif($rental->deliveryStaff)
                <div class="timeline">
                    <div class="timeline-item">
                        <span class="label">Status</span>
                        <div class="value">
                            <span class="status-badge" style="{{ $badge('assigned') }}">
                                Delivery Assigned
                            </span>
                        </div>
                    </div>
                    <div class="timeline-item">
                        <span class="label">Assigned Person</span>
                        <div class="value">{{ $rental->deliveryStaff->name }}</div>
                    </div>
                    <div class="timeline-item">
                        <span class="label">Task Record</span>
                        <div class="value">Delivery staff is selected on the rental, but no dispatch task has been created yet.</div>
                    </div>
                    <div class="timeline-item">
                        <span class="label">Actions</span>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                              @if($canCreateDeliveries && !$hasOpenDeliveryTask)
                                  <a href="{{ route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'delivery']) }}" class="detail-btn-secondary">Create Delivery Task</a>
                            @elseif($hasOpenDeliveryTask)
                                <div class="ops-muted">An open delivery task already exists for this rental.</div>
                            @endif
                            @if($canUpdateRentals)
                                <form method="POST" action="{{ route('rentals.delivery-assignment.clear', $rental) }}" style="margin:0;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="detail-btn-danger" onclick="return confirm('Clear this delivery assignment from the rental?');">Clear Delivery Assignment</button>
                                </form>
                            @endif
                            @unless($canCreateDeliveries || $canUpdateRentals)
                                <div class="ops-muted">No delivery action available.</div>
                            @endunless
                        </div>
                    </div>
                </div>
            @else
                <div class="value" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <span>No delivery record yet.</span>
                    @if($canCreateDeliveries && !$hasOpenDeliveryTask)
                        <a href="{{ route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'delivery']) }}" class="detail-btn-secondary">Assign Delivery</a>
                    @elseif($hasOpenDeliveryTask)
                        <span class="ops-muted">Delivery is already assigned.</span>
                    @endif
                </div>
            @endif
        </div>

        <div class="detail-card span-6">
            <h2 style="margin-top:0; margin-bottom:14px; font-size:18px;">Pickup Workflow</h2>
            @if($pickupRecord)
                <div class="timeline">
                      <div class="timeline-item">
                          <span class="label">Status</span>
                          <div class="value">
                              <span class="status-badge" style="{{ $itemProgressBadge($rental->pickupStatus()) }}">
                                  {{ $itemProgressLabel($rental->pickupStatus()) }}
                              </span>
                          </div>
                      </div>
                    <div class="timeline-item">
                        <span class="label">Assigned Person</span>
                        <div class="value">{{ $assignedPersonLabel($pickupRecord) }}</div>
                    </div>
                    <div class="timeline-item">
                        <span class="label">Scheduled</span>
                        <div class="value">{{ $pickupRecord->scheduled_at ? $pickupRecord->scheduled_at->format('d M Y h:i A') : 'Not scheduled' }}</div>
                    </div>
                    <div class="timeline-item">
                        <span class="label">Actions</span>
                        <div style="display:flex; gap:8px; flex-wrap:wrap;">
                            @if($canUpdateDeliveries)
                                <a href="{{ route('deliveries.edit', $pickupRecord) }}" class="detail-btn-secondary">Edit Assignment</a>
                            @endif
                            @if($pickupRecord->status === 'pending')
                                <form method="POST" action="{{ route('deliveries.in_progress', $pickupRecord) }}" style="margin:0;">
                                    @csrf
                                    @method('PUT')
                                    <button type="submit" class="detail-btn-secondary">Start Pickup</button>
                                </form>
                                @if($canUpdateDeliveries)
                                    <form method="POST" action="{{ route('deliveries.cancel', $pickupRecord) }}" style="margin:0;">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="detail-btn-danger" onclick="return confirm('Cancel this pickup assignment?');">Cancel Pickup</button>
                                    </form>
                                @endif
                              @elseif($pickupRecord->status === 'in_progress')
                                  <form method="POST" action="{{ route('deliveries.complete', $pickupRecord) }}" style="margin:0;">
                                      @csrf
                                      @method('PUT')
                                      @if($hasPendingPickupItems)
                                          <input type="hidden" name="confirm_partial" value="1">
                                          <button type="submit" class="detail-btn">Complete as Partial</button>
                                      @else
                                          <button type="submit" class="detail-btn">Complete Pickup</button>
                                      @endif
                                  </form>
                                @if($canUpdateDeliveries)
                                    <form method="POST" action="{{ route('deliveries.cancel', $pickupRecord) }}" style="margin:0;">
                                        @csrf
                                        @method('PUT')
                                        <button type="submit" class="detail-btn-danger" onclick="return confirm('Cancel this pickup assignment?');">Cancel Pickup</button>
                                      </form>
                                  @endif
                              @else
                                  @if($canAssignPickup && !$hasOpenPickupTask && $hasPendingPickupItems)
                                      <a href="{{ route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'pickup']) }}" class="detail-btn-secondary">Create Follow-up Pickup Task</a>
                                  @else
                                      <div class="ops-muted">No pickup action needed.</div>
                                  @endif
                              @endif
                            @if($canDeleteDeliveries && in_array($pickupRecord->status, ['pending', 'cancelled'], true))
                                <form method="POST" action="{{ route('deliveries.destroy', $pickupRecord) }}" style="margin:0;">
                                    @csrf
                                    @method('DELETE')
                                    <button type="submit" class="detail-btn-danger" onclick="return confirm('Delete this pickup assignment record?');">Delete Pickup</button>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @else
                <div class="value" style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                    <span>No pickup record yet.</span>
                      @if($canAssignPickup && !$hasOpenPickupTask)
                          <a href="{{ route('deliveries.create', ['rental_id' => $rental->id, 'type' => 'pickup']) }}" class="detail-btn-secondary">Assign Pickup</a>
                      @elseif($hasOpenPickupTask)
                          <span class="ops-muted">Pickup is already assigned.</span>
                      @elseif($canCreateDeliveries)
                          <span class="ops-muted">Pickup unlocks when at least one item has been delivered.</span>
                      @endif
                  </div>
              @endif
        </div>
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
