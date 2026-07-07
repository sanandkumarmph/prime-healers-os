@extends('layouts.app')

@section('content')
@php
    $isSaleTask = (bool) $delivery->sale_id;
    $linkedCustomerName = $delivery->linkedCustomerName();
    $linkedPhone = $delivery->linkedCustomerPhone();
    $linkedWhatsapp = $linkedPhone;
    $linkedAddress = $delivery->linkedCustomerAddress();
    $linkedCity = $delivery->linkedCustomerCity();
    $linkedMapUrl = $delivery->linkedCustomerMapUrl();
    $linkedContactNotes = $delivery->linkedCustomerNotes();
    $rentalAssets = $delivery->rental?->activeRentalAssets ?? collect();
    $rentalSaleItems = $delivery->rental?->saleItems ?? collect();
    $pickupRecord = $delivery->rental?->pickupRecord;
    $pickupAssigned = $pickupRecord && $pickupRecord->status !== 'cancelled';
    $deliverySaleAssets = $delivery->type === 'delivery'
        ? $rentalSaleItems
        ->filter(fn ($item) => $item->asset)
        ->values()
        : collect();
    $rentalItems = $delivery->rental?->displayRentalItems() ?? collect();
    $assigneeName = $delivery->assignedUser?->name
        ?? $delivery->assignedStaff?->name
        ?? $delivery->third_party_name
        ?? 'Not assigned';
    $assigneeRole = $delivery->assignedUser
        ? 'Delivery Team'
        : ($delivery->assignedStaff
            ? ucwords(str_replace('_', ' ', $delivery->assignedStaff->assignment_role ?? 'vendor'))
            : ($delivery->assignment_type === 'third_party' ? 'Third Party' : 'Unassigned'));
    $assigneeSecondaryLabel = $delivery->assignedUser ? 'Assigned User' : 'Assigned Staff';
    $assigneeContact = $delivery->assignedUser?->phone
        ?? $delivery->assignedStaff?->phone
        ?? $delivery->third_party_contact
        ?? $delivery->third_party_phone
        ?? '-';
    $showThirdPartyDetails = filled($delivery->third_party_name)
        || filled($delivery->third_party_contact)
        || filled($delivery->third_party_phone);
    $displayStatus = $delivery->status;

    if (!$isSaleTask && $delivery->rental) {
        if ($delivery->type === 'delivery') {
            $rentalDeliveryStatus = $delivery->rental->deliveryStatus();
            $displayStatus = match (true) {
                in_array($delivery->status, ['pending', 'in_progress', 'cancelled'], true) => $delivery->status,
                in_array($rentalDeliveryStatus, ['completed', 'delivered'], true) => 'delivered',
                $rentalDeliveryStatus === 'partially_delivered' => 'in_progress',
                default => $delivery->status,
            };
        } elseif ($delivery->type === 'pickup') {
            $displayStatus = match ($delivery->rental->pickupStatus()) {
                'completed', 'picked_up', 'returned' => 'picked_up',
                'partial_return' => 'in_progress',
                default => $delivery->status,
            };
        }
    }

    $statusStyle = match ($displayStatus) {
        'pending' => 'background:#e2e8f0;color:#334155;',
        'in_progress' => 'background:#fef3c7;color:#b45309;',
        'delivered', 'picked_up', 'completed' => 'background:#dcfce7;color:#166534;',
        'cancelled' => 'background:#f1f5f9;color:#64748b;',
        default => 'background:#f1f5f9;color:#475569;',
    };
    $itemProgressBadge = fn (?string $status) => match ($status) {
        'partial', 'partially_delivered', 'partial_return', 'partially_returned', 'with_customer' => 'background:#dbeafe;color:#1d4ed8;',
        'delivered', 'picked_up', 'completed', 'returned' => 'background:#dcfce7;color:#166534;',
        'awaiting_verification' => 'background:#fef3c7;color:#b45309;',
        'pickup_pending', 'delivery_pending' => 'background:#fef3c7;color:#b45309;',
        'pickup_not_assigned' => 'background:#e2e8f0;color:#475569;',
        default => 'background:#fef3c7;color:#b45309;',
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
        'pickup_not_assigned' => 'Pickup Not Assigned',
        'delivery_pending' => 'Delivery Pending',
        'returned' => 'Returned',
        'awaiting_verification' => 'Awaiting Verification',
        'completed' => 'Completed',
        default => ucfirst(str_replace('_', ' ', $status ?: 'pending')),
    };
    $displayStatusLabel = match ($displayStatus) {
        'delivered' => 'Delivered',
        'picked_up' => 'Picked Up',
        default => ucfirst(str_replace('_', ' ', $displayStatus)),
    };
    $canUpdateTask = auth()->user()?->can('update', $delivery) ?? false;
    $canDeleteTask = auth()->user()?->can('delete', $delivery) ?? false;
    $deliveryProofs = collect($deliveryProofs ?? []);
    $cancellationReasonOptions = $cancellationReasonOptions ?? [];
    $proofConfig = $proofConfig ?? ['max_kb' => 100, 'target_kb' => 50, 'max_dimension' => 1024];
    $workflowProofSectionId = 'workflow-proof-section';
    $cancellationSectionId = 'delivery-cancellation-section';
    $workflowStage = $delivery->type === 'pickup' ? \App\Models\DeliveryProof::STAGE_PICKUP : \App\Models\DeliveryProof::STAGE_DELIVERY;
    $acknowledgementText = \App\Models\DeliveryProof::acknowledgementFor($workflowStage, ! $isSaleTask);
    $locationProofs = $deliveryProofs->where('proof_type', \App\Models\DeliveryProof::TYPE_LOCATION)->values();
    $fileProofs = $deliveryProofs->reject(fn ($proof) => $proof->proof_type === \App\Models\DeliveryProof::TYPE_LOCATION)->values();
    $latestLocationProof = $locationProofs
        ->sortByDesc(fn ($proof) => optional($proof->captured_at ?? $proof->created_at)?->timestamp ?? 0)
        ->first();
    $proofHistoryItems = $fileProofs
        ->when($latestLocationProof, fn ($collection) => $collection->push($latestLocationProof))
        ->sortByDesc(fn ($proof) => optional($proof->captured_at ?? $proof->created_at)?->timestamp ?? 0)
        ->values();
    $hasPendingWorkflowCapture = $canUpdateTask && in_array($delivery->status, ['pending', 'in_progress'], true);
    $canCancelTask = $canUpdateTask && !in_array($delivery->status, ['completed', 'cancelled'], true);
    $hasProofHistory = $proofHistoryItems->isNotEmpty();
    $workflowCompleted = in_array($displayStatus, ['delivered', 'picked_up', 'completed'], true) || $delivery->status === 'completed';
    $primaryWorkflowCtaLabel = $workflowCompleted
        ? null
        : ($delivery->status === 'pending'
            ? ($delivery->type === 'pickup' ? 'Start Pickup' : 'Start Delivery')
            : ($delivery->type === 'pickup' ? 'Continue Pickup' : 'Continue Delivery'));
    $defaultWorkflowLatitude = old('location_latitude', filled($latestLocationProof?->latitude) ? number_format((float) $latestLocationProof->latitude, 6, '.', '') : '');
    $defaultWorkflowLongitude = old('location_longitude', filled($latestLocationProof?->longitude) ? number_format((float) $latestLocationProof->longitude, 6, '.', '') : '');
    $defaultWorkflowAccuracy = old('location_accuracy', filled($latestLocationProof?->accuracy) ? number_format((float) $latestLocationProof->accuracy, 1, '.', '') : '');
    $defaultWorkflowCapturedAt = old(
        'location_captured_at',
        optional($latestLocationProof?->captured_at ?? $latestLocationProof?->created_at)->toIso8601String()
    );
    $defaultWorkflowLocationReason = old(
        'location_missing_reason',
        ($latestLocationProof && !filled($latestLocationProof->latitude) && !filled($latestLocationProof->longitude))
            ? (string) ($latestLocationProof->notes ?? '')
            : ''
    );
    $workflowErrorFields = [
        'delivery_device_photos',
        'delivery_device_photos.*',
        'premises_photo',
        'pickup_device_photos',
        'pickup_device_photos.*',
        'damage_photos',
        'damage_photos.*',
        'damage_notes',
        'missing_accessories_notes',
        'signature_data',
        'signature_unavailable_reason',
        'location_missing_reason',
        'location_latitude',
        'location_longitude',
        'collection_amount_collected',
        'collection_payment_mode',
        'collection_payment_proof',
        'collection_transaction_reference',
        'collection_note',
        'collection_not_collected_reason',
        'completion_confirmed',
    ];
    $hasWorkflowErrors = collect($workflowErrorFields)->contains(fn ($field) => $errors->has($field));
    $hasCancellationErrors = $errors->has('cancellation_reason') || $errors->has('cancellation_notes');
    $selectedCancellationReason = old('cancellation_reason', $delivery->cancellation_reason);
    $selectedCancellationNotes = old('cancellation_notes', $delivery->cancellation_notes);
    $supportsCollectionStep = (bool) ($delivery->collection_required ?? false);
    $collectionAmountToCollect = (float) ($delivery->collection_amount_to_collect ?? 0);
    $collectionModes = [
        'cash' => 'Cash',
        'upi' => 'UPI',
        'card' => 'Card',
        'bank_transfer' => 'Bank Transfer',
    ];
    $collectionReasonOptions = [
        'customer_refused' => 'Customer refused',
        'already_paid' => 'Already paid',
        'no_payment_proof' => 'No payment proof',
        'other' => 'Other',
    ];
    $workflowPreviewSteps = $delivery->type === 'pickup'
        ? ['Dashboard', 'Taskboard', 'Overview', 'Start Pickup', 'GPS', 'Photos', 'Check', 'Notes', 'Sign', ...($supportsCollectionStep ? ['Collect'] : []), 'Complete']
        : ['Dashboard', 'Taskboard', 'Overview', 'Start Delivery', 'GPS', 'Photos', 'Notes', 'Sign', ...($supportsCollectionStep ? ['Collect'] : []), 'Complete'];
    $completionSteps = $delivery->type === 'pickup'
        ? [
            ['index' => 1, 'key' => 'location', 'label' => 'GPS', 'copy' => 'Capture GPS or add reason.'],
            ['index' => 2, 'key' => 'photos', 'label' => 'Photos', 'copy' => 'Capture pickup proof.'],
            ['index' => 3, 'key' => 'condition', 'label' => 'Check', 'copy' => 'Check damage and accessories.'],
            ['index' => 4, 'key' => 'notes', 'label' => 'Notes', 'copy' => 'Add damage or missing items.'],
            ['index' => 5, 'key' => 'signature', 'label' => 'Signature', 'copy' => 'Capture acknowledgement.'],
            ...($supportsCollectionStep ? [['index' => 6, 'key' => 'collection', 'label' => 'Collect', 'copy' => 'Record collection or reason.']] : []),
            ['index' => $supportsCollectionStep ? 7 : 6, 'key' => 'complete', 'label' => 'Review', 'copy' => 'Review and finish pickup.'],
        ]
        : [
            ['index' => 1, 'key' => 'location', 'label' => 'GPS', 'copy' => 'Capture GPS or add reason.'],
            ['index' => 2, 'key' => 'photos', 'label' => 'Photos', 'copy' => 'Capture delivery proof.'],
            ['index' => 3, 'key' => 'notes', 'label' => 'Notes', 'copy' => 'Add field notes if needed.'],
            ['index' => 4, 'key' => 'signature', 'label' => 'Signature', 'copy' => 'Capture acknowledgement.'],
            ...($supportsCollectionStep ? [['index' => 5, 'key' => 'collection', 'label' => 'Collect', 'copy' => 'Record collection or reason.']] : []),
            ['index' => $supportsCollectionStep ? 6 : 5, 'key' => 'complete', 'label' => 'Review', 'copy' => 'Review and finish delivery.'],
        ];
    $workflowStepCount = count($completionSteps);
    $workflowDisplayOffset = 1;
    $workflowDisplayStepCount = $workflowStepCount + $workflowDisplayOffset;
    $workflowCurrentStep = match (true) {
        $workflowCompleted => count($workflowPreviewSteps),
        $delivery->status === 'in_progress' => 6,
        $delivery->status === 'pending' => 4,
        default => 3,
    };
    $completionStepErrorMap = $delivery->type === 'pickup'
        ? [
            1 => ['location_missing_reason', 'location_latitude', 'location_longitude', 'location_accuracy', 'location_captured_at'],
            2 => ['pickup_device_photos', 'pickup_device_photos.*', 'damage_photos', 'damage_photos.*'],
            3 => ['damage_reported'],
            4 => ['damage_notes', 'missing_accessories_notes'],
            5 => ['signature_data', 'signature_unavailable_reason'],
        ]
        : [
            1 => ['location_missing_reason', 'location_latitude', 'location_longitude', 'location_accuracy', 'location_captured_at'],
            2 => ['delivery_device_photos', 'delivery_device_photos.*', 'premises_photo'],
            3 => ['proof_notes'],
            4 => ['signature_data', 'signature_unavailable_reason'],
        ];
    $initialCompletionStep = $delivery->status === 'in_progress' ? 2 : 1;
    foreach ($completionStepErrorMap as $stepIndex => $stepFields) {
        if (collect($stepFields)->contains(fn ($field) => $errors->has($field))) {
            $initialCompletionStep = $stepIndex;
            break;
        }
    }
    if ($supportsCollectionStep && collect(['collection_amount_collected', 'collection_payment_mode', 'collection_payment_proof', 'collection_transaction_reference', 'collection_note', 'collection_not_collected_reason'])->contains(fn ($field) => $errors->has($field))) {
        $initialCompletionStep = $delivery->type === 'pickup' ? 6 : 5;
    }
    if ($errors->has('completion_confirmed')) {
        $initialCompletionStep = $delivery->type === 'pickup'
            ? ($supportsCollectionStep ? 7 : 6)
            : ($supportsCollectionStep ? 6 : 5);
    }
    $selectedPickupCondition = old('pickup_condition_choice', old('damage_reported') ? 'damaged' : '');
    $orderReferenceLabel = $isSaleTask ? 'Sale' : 'Rental';
    $orderReferenceId = $isSaleTask ? ($delivery->sale->id ?? null) : ($delivery->rental->id ?? null);
    $taskProductLabel = $isSaleTask ? ($delivery->sale?->product?->name ?? 'Sale item') : ($delivery->rental?->product?->name ?? 'Rental item');
    $taskWarehouseLabel = $isSaleTask ? ($delivery->sale?->asset?->warehouse?->name ?? 'Sale dispatch') : ($delivery->rental?->dispatchWarehouse?->name ?? 'Any warehouse');
    $referencePartnerName = $delivery->rental?->businessPartner?->business_name
        ?? $delivery->sale?->businessPartner?->business_name;
    $latestProofPreview = $proofHistoryItems->first()
        ? \App\Models\DeliveryProof::labelForType($proofHistoryItems->first()->proof_type)
        : 'No proof yet';
    $workflowIllustration = function (string $key): string {
        return match ($key) {
            'start' => <<<SVG
                <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect x="12" y="12" width="96" height="96" rx="26" fill="#EFF6FF"/>
                    <circle cx="56" cy="34" r="11" fill="#F8C9A7"/>
                    <path d="M43 52c0-5 4-9 9-9h8c5 0 9 4 9 9v19H43V52Z" fill="#2563EB"/>
                    <path d="M42 72h30c4 0 8 3 8 8v8H34v-8c0-5 4-8 8-8Z" fill="#1D4ED8"/>
                    <rect x="70" y="50" width="22" height="22" rx="4" fill="#F6D7A8" stroke="#D39A42" stroke-width="2"/>
                    <path d="M77 50v-8l8-4 7 4v8" stroke="#D39A42" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M41 88h41" stroke="#BFDBFE" stroke-width="4" stroke-linecap="round"/>
                </svg>
            SVG,
            'gps' => <<<SVG
                <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect x="16" y="16" width="88" height="88" rx="24" fill="#EFF6FF"/>
                    <circle cx="60" cy="58" r="21" fill="#DBEAFE"/>
                    <path d="M60 34c-10.5 0-19 8.2-19 18.7 0 13.4 16.6 27.3 18.4 28.8a1 1 0 0 0 1.2 0C62.4 80 79 66.1 79 52.7 79 42.2 70.5 34 60 34Z" fill="#2563EB"/>
                    <circle cx="60" cy="52" r="6.5" fill="white"/>
                    <path d="M32 88h56" stroke="#BFDBFE" stroke-width="4" stroke-linecap="round"/>
                </svg>
            SVG,
            'photos' => <<<SVG
                <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect x="14" y="18" width="92" height="84" rx="20" fill="#F8FAFC"/>
                    <rect x="24" y="28" width="72" height="54" rx="12" fill="#EFF6FF" stroke="#BFDBFE" stroke-width="2"/>
                    <rect x="42" y="86" width="36" height="8" rx="4" fill="#BFDBFE"/>
                    <path d="M39 57h20l9-12 13 17H39v-5Z" fill="#93C5FD"/>
                    <circle cx="74" cy="44" r="5" fill="#2563EB"/>
                    <path d="M53 41h10c2 0 4 2 4 4v6H49v-6c0-2 2-4 4-4Z" fill="#1D4ED8"/>
                </svg>
            SVG,
            'condition' => <<<SVG
                <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect x="16" y="16" width="88" height="88" rx="24" fill="#F8FAFC"/>
                    <rect x="30" y="28" width="60" height="64" rx="16" fill="white" stroke="#DDE7F2" stroke-width="2"/>
                    <path d="M44 46h32" stroke="#CBD5E1" stroke-width="4" stroke-linecap="round"/>
                    <path d="M44 60h32" stroke="#CBD5E1" stroke-width="4" stroke-linecap="round"/>
                    <path d="m44 74 5 5 9-10" stroke="#16A34A" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
                    <circle cx="84" cy="35" r="10" fill="#FEF3C7"/>
                    <path d="M84 31v8" stroke="#B45309" stroke-width="3" stroke-linecap="round"/>
                    <circle cx="84" cy="42" r="1.5" fill="#B45309"/>
                </svg>
            SVG,
            'signature' => <<<SVG
                <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect x="16" y="18" width="88" height="84" rx="22" fill="#F8FAFC"/>
                    <rect x="28" y="34" width="64" height="42" rx="10" fill="white" stroke="#DDE7F2" stroke-width="2"/>
                    <path d="M38 63c6-12 11 6 17-5 4-8 11-7 14 2 3 9 10 5 12-4" stroke="#1D4ED8" stroke-width="3.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M38 85h44" stroke="#CBD5E1" stroke-width="4" stroke-linecap="round"/>
                    <path d="m84 80 8-16 6 6-14 10Z" fill="#2563EB"/>
                </svg>
            SVG,
            'payment' => <<<SVG
                <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect x="16" y="16" width="88" height="88" rx="24" fill="#F8FAFC"/>
                    <rect x="26" y="34" width="68" height="48" rx="14" fill="white" stroke="#DDE7F2" stroke-width="2"/>
                    <rect x="34" y="44" width="52" height="8" rx="4" fill="#DBEAFE"/>
                    <rect x="34" y="60" width="28" height="8" rx="4" fill="#BFDBFE"/>
                    <circle cx="82" cy="70" r="14" fill="#DCFCE7"/>
                    <path d="m76 70 4 4 8-9" stroke="#16A34A" stroke-width="4" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            SVG,
            'complete' => <<<SVG
                <svg viewBox="0 0 120 120" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
                    <rect x="16" y="16" width="88" height="88" rx="24" fill="#F0FDF4"/>
                    <path d="M60 30 82 38v18c0 17-12 28-22 34-10-6-22-17-22-34V38l22-8Z" fill="#22C55E"/>
                    <path d="m49 58 8 8 15-17" stroke="white" stroke-width="5" stroke-linecap="round" stroke-linejoin="round"/>
                </svg>
            SVG,
            default => '',
        };
    };

    $mobileQuickActions = collect([
        [
            'type' => 'link',
            'label' => 'Back',
            'href' => route('deliveries.index'),
        ],
    ]);

    if ($linkedPhone) {
        $mobileQuickActions->push([
            'type' => 'link',
            'label' => 'Call',
            'href' => 'tel:' . preg_replace('/\D+/', '', $linkedPhone),
        ]);
    }

    if ($linkedWhatsapp) {
        $mobileQuickActions->push([
            'type' => 'link',
            'label' => 'WhatsApp',
            'href' => 'https://wa.me/' . preg_replace('/\D+/', '', $linkedWhatsapp),
            'target' => '_blank',
            'rel' => 'noopener noreferrer',
        ]);
    }

    if ($hasProofHistory) {
        $mobileQuickActions->push([
            'type' => 'link',
            'label' => 'Proof History',
            'href' => '#delivery-proof-history',
        ]);
    }

    if ($canUpdateTask) {
        $mobileQuickActions->push([
            'type' => 'link',
            'label' => 'Edit Assignment',
            'href' => route('deliveries.edit', $delivery),
        ]);
    }

    if ($canCancelTask) {
        $mobileQuickActions->push([
            'type' => 'link',
            'label' => 'Unable to complete',
            'href' => '#' . $cancellationSectionId,
        ]);
    }

    $mobileQuickActions = $mobileQuickActions->values();

    $mobileTaskTone = match ($delivery->status) {
        'completed' => 'completed',
        'in_progress' => 'progress',
        'cancelled' => 'cancelled',
        default => 'pending',
    };

    if ($delivery->scheduled_at && $delivery->scheduled_at->isPast() && !in_array($delivery->status, ['completed', 'cancelled'], true)) {
        $mobileTaskTone = 'pending';
    }
@endphp

<style>
    .delivery-detail { display:grid; gap:10px; padding:8px 0 16px; max-width:1120px; margin:0 auto; }
    .delivery-detail-header { display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap; }
    .delivery-detail-header h1 { margin:0; font-size:23px; color:#0f172a; line-height:1.04; letter-spacing:-0.03em; }
    .delivery-detail-header p { margin:4px 0 0; color:#64748b; font-size:11px; }
    .detail-actions { display:flex; gap:8px; flex-wrap:wrap; }
    .workflow-preview-shell {
        display:grid;
        gap:8px;
        padding:10px 11px;
        border:1px solid #dbe3ef;
        border-radius:15px;
        background:linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
        box-shadow:0 10px 28px rgba(15, 23, 42, 0.04);
    }
    .workflow-preview-head {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:10px;
        flex-wrap:wrap;
    }
    .workflow-preview-head strong {
        color:#0f172a;
        font-size:15px;
        line-height:1.25;
    }
    .workflow-preview-head span {
        display:block;
        color:#64748b;
        font-size:11.5px;
        line-height:1.45;
        margin-top:4px;
    }
    .workflow-preview-track {
        display:flex;
        gap:6px;
        overflow-x:auto;
        padding-bottom:2px;
        scrollbar-width:none;
    }
    .workflow-preview-track::-webkit-scrollbar { display:none; }
    .workflow-preview-pill {
        display:inline-flex;
        align-items:center;
        gap:6px;
        min-height:28px;
        padding:0 8px;
        border-radius:999px;
        border:1px solid #dbe3ef;
        background:#fff;
        color:#64748b;
        font-size:10px;
        font-weight:800;
        white-space:nowrap;
    }
    .workflow-preview-pill.is-active {
        border-color:#2563eb;
        background:#eff6ff;
        color:#1d4ed8;
    }
    .workflow-preview-pill.is-complete {
        border-color:#86efac;
        background:#f0fdf4;
        color:#166534;
    }
    .workflow-preview-pill-index {
        width:16px;
        height:16px;
        flex:0 0 16px;
        border-radius:999px;
        display:grid;
        place-items:center;
        background:#e2e8f0;
        color:#475569;
        font-size:9px;
        font-weight:900;
    }
    .workflow-preview-pill.is-active .workflow-preview-pill-index {
        background:#2563eb;
        color:#fff;
    }
    .workflow-preview-pill.is-complete .workflow-preview-pill-index {
        background:#16a34a;
        color:#fff;
    }
    .mobile-inline-actions { display:none; }
    .fieldops-overview-card {
        display:grid;
        gap:10px;
    }
    .fieldops-overview-head {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:10px;
        flex-wrap:wrap;
    }
    .fieldops-overview-head h2 {
        margin:4px 0 0;
        font-size:16px;
        line-height:1.18;
        color:#0f172a;
    }
    .fieldops-overview-head p {
        margin:4px 0 0;
        color:#64748b;
        font-size:12px;
        line-height:1.45;
    }
    .fieldops-kv-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:9px 12px;
    }
    .fieldops-kv {
        display:grid;
        gap:3px;
        min-width:0;
    }
    .fieldops-kv.is-wide {
        grid-column:span 2;
    }
    .fieldops-kv-label {
        color:#64748b;
        font-size:10px;
        font-weight:800;
        letter-spacing:.05em;
        text-transform:uppercase;
    }
    .fieldops-kv-value {
        color:#0f172a;
        font-size:13px;
        line-height:1.4;
        overflow-wrap:anywhere;
    }
    .fieldops-reference-note {
        display:inline-flex;
        align-items:center;
        gap:6px;
        padding:6px 10px;
        border-radius:999px;
        background:#f8fafc;
        border:1px solid #e2e8f0;
        color:#475569;
        font-size:11px;
        font-weight:700;
    }
    .fieldops-task-shell {
        display:grid;
        gap:10px;
        padding:11px;
        border:1px solid #dbe3ef;
        border-radius:16px;
        background:linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
        box-shadow:0 14px 34px rgba(15, 23, 42, 0.06);
    }
    .fieldops-task-shell-top {
        display:grid;
        grid-template-columns:58px minmax(0, 1fr);
        gap:12px;
        align-items:center;
    }
    .fieldops-task-shell-ill {
        width:58px;
        height:58px;
        border-radius:16px;
        overflow:hidden;
        border:1px solid #bfdbfe;
        background:#eff6ff;
    }
    .fieldops-task-shell-ill svg {
        width:100%;
        height:100%;
        display:block;
    }
    .fieldops-task-shell-copy {
        min-width:0;
        display:grid;
        gap:4px;
    }
    .fieldops-task-shell-copy strong {
        color:#0f172a;
        font-size:18px;
        line-height:1.05;
    }
    .fieldops-task-shell-copy p {
        margin:0;
        color:#64748b;
        font-size:12px;
        line-height:1.45;
    }
    .fieldops-task-shell-chips {
        display:flex;
        align-items:center;
        gap:6px;
        flex-wrap:wrap;
    }
    .fieldops-mobile-back-inline {
        display:none;
    }
    .fieldops-task-meta-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:6px;
    }
    .fieldops-task-meta {
        display:grid;
        gap:3px;
        min-width:0;
        padding:9px 10px;
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:#fff;
    }
    .fieldops-task-meta.is-wide {
        grid-column:span 2;
    }
    .fieldops-task-meta span {
        color:#64748b;
        font-size:10px;
        font-weight:800;
        letter-spacing:.05em;
        text-transform:uppercase;
    }
    .fieldops-task-meta strong {
        color:#0f172a;
        font-size:13px;
        line-height:1.4;
        overflow-wrap:anywhere;
    }
    .fieldops-icon-actions {
        display:grid;
        grid-template-columns:repeat(4, minmax(0, 1fr));
        gap:6px;
    }
    .fieldops-icon-action {
        min-width:0;
        min-height:40px;
        display:grid;
        place-items:center;
        gap:4px;
        padding:7px 6px;
        border-radius:12px;
        border:1px solid #dbe3ef;
        background:#fff;
        color:#0f172a;
        text-decoration:none;
        font-size:10px;
        font-weight:800;
        line-height:1.2;
    }
    .fieldops-icon-action svg {
        width:15px;
        height:15px;
        color:#2563eb;
    }
    .desktop-note-action { display:none; }
    .fieldops-primary-bar {
        display:grid;
        grid-template-columns:minmax(0, 1fr) auto;
        gap:6px;
        align-items:center;
    }
    .fieldops-primary-bar .detail-btn,
    .fieldops-primary-bar .mobile-actions-menu summary {
        min-height:40px;
        border-radius:12px;
    }
    .fieldops-primary-note {
        color:#64748b;
        font-size:11px;
        line-height:1.45;
    }
    .workflow-start-shell {
        gap:16px;
    }
    .workflow-start-hero {
        display:grid;
        gap:12px;
        grid-template-columns:72px minmax(0, 1fr);
        align-items:center;
        padding:14px;
        border:1px solid #dbe3ef;
        border-radius:18px;
        background:linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
    }
    .workflow-start-illustration {
        width:72px;
        height:72px;
        border-radius:20px;
        display:grid;
        place-items:center;
        background:#eff6ff;
        color:#1d4ed8;
        border:1px solid #bfdbfe;
        font-size:28px;
        box-shadow:inset 0 1px 0 rgba(255,255,255,.7);
    }
    .workflow-start-illustration svg,
    .workflow-step-hero-icon svg {
        width:100%;
        height:100%;
        display:block;
    }
    .workflow-step-counter {
        display:inline-flex;
        align-items:center;
        padding:4px 8px;
        border-radius:999px;
        background:#eff6ff;
        color:#1d4ed8;
        font-size:10px;
        font-weight:900;
        letter-spacing:.05em;
        text-transform:uppercase;
    }
    .workflow-start-copy strong,
    .workflow-step-copy strong {
        display:block;
        margin-top:7px;
        color:#0f172a;
        font-size:18px;
        line-height:1.15;
    }
    .workflow-start-copy p,
    .workflow-step-copy p {
        margin:6px 0 0;
        color:#64748b;
        font-size:12px;
        line-height:1.55;
    }
    .workflow-mini-summary {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:8px;
    }
    .workflow-mini-summary-item {
        display:grid;
        gap:3px;
        padding:10px 11px;
        border:1px solid #e2e8f0;
        border-radius:14px;
        background:#fff;
    }
    .workflow-mini-summary-item span {
        color:#64748b;
        font-size:10px;
        font-weight:800;
        letter-spacing:.05em;
        text-transform:uppercase;
    }
    .workflow-mini-summary-item strong {
        color:#0f172a;
        font-size:13px;
        line-height:1.35;
        overflow-wrap:anywhere;
    }
    .workflow-step-hero {
        display:grid;
        grid-template-columns:56px minmax(0, 1fr);
        gap:10px;
        align-items:center;
        padding:10px 12px;
        border:1px solid #dbe3ef;
        border-radius:16px;
        background:linear-gradient(180deg, #f8fbff 0%, #ffffff 100%);
    }
    .workflow-step-hero-icon {
        width:56px;
        height:56px;
        border-radius:18px;
        display:grid;
        place-items:center;
        background:#f8fafc;
        border:1px solid #dbe3ef;
        overflow:hidden;
    }
    .workflow-step-hero-copy {
        display:grid;
        gap:3px;
        min-width:0;
    }
    .workflow-step-hero-copy strong {
        color:#0f172a;
        font-size:13px;
        line-height:1.3;
    }
    .workflow-step-hero-copy span {
        color:#64748b;
        font-size:11px;
        line-height:1.45;
    }
    .workflow-mobile-shell-head {
        display:none;
    }
    .workflow-mobile-shell-body {
        display:grid;
        gap:14px;
    }
    .desktop-workflow-progress {
        display:none;
    }
    .mobile-actions-menu {
        position:relative;
        width:100%;
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
    .delivery-page-spacer {
        display:none;
    }
    .delivery-mobile-sticky-cta {
        display:none;
    }
    .delivery-mobile-sticky-cta a {
        display:flex;
        align-items:center;
        justify-content:center;
        min-height:48px;
        padding:0 14px;
        border-radius:16px;
        background:#2563eb;
        color:#fff;
        text-decoration:none;
        font-size:14px;
        font-weight:900;
        box-shadow:0 18px 36px rgba(37,99,235,.26);
    }
    .delivery-mobile-titlebar {
        display:none;
    }
    .detail-btn, .detail-btn-secondary {
        display:inline-flex; align-items:center; justify-content:center; gap:6px;
        border-radius:10px; padding:8px 12px; font-size:12px; font-weight:700; text-decoration:none;
        border:1px solid transparent; cursor:pointer; white-space:nowrap; min-width:0; text-align:center; line-height:1.2;
    }
    .detail-btn { background:#2563eb; color:#fff; }
    .detail-btn-secondary { background:#fff; border-color:#cbd5e1; color:#334155; }
    .detail-card { background:#fff; border:1px solid #dbe3ef; border-radius:16px; padding:13px; box-shadow:0 8px 24px rgba(15, 23, 42, 0.04); }
    .detail-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:14px; }
    .detail-summary-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:8px 12px; }
    .detail-summary-row { display:grid; gap:4px; min-width:0; }
    .detail-summary-row.span-2 { grid-column:span 2; }
    .detail-summary-row .label { margin-bottom:0; }
    .detail-summary-row .value { overflow-wrap:anywhere; }
    .detail-summary-note { color:#475569; font-size:12px; line-height:1.4; }
    .span-4 { grid-column:span 4; }
    .span-6 { grid-column:span 6; }
    .span-12 { grid-column:span 12; }
    .label { display:block; font-size:11px; font-weight:700; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:5px; }
    .value { color:#0f172a; font-size:14px; }
    .status-badge { display:inline-flex; align-items:center; padding:4px 9px; border-radius:999px; font-size:10.5px; font-weight:800; letter-spacing:.04em; text-transform:uppercase; }
    .progress-card-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:8px; }
    .progress-card { border:1px solid #e2e8f0; border-radius:14px; padding:10px; background:#fcfdff; display:grid; gap:8px; }
    .progress-card-head { display:grid; gap:4px; }
    .progress-card-head strong { color:#0f172a; font-size:14px; line-height:1.4; }
    .progress-card-head small { color:#64748b; font-size:12px; line-height:1.4; }
    .progress-metric-grid { display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:6px; }
    .progress-metric { display:grid; gap:2px; padding:7px 9px; border:1px solid #edf2f7; border-radius:12px; background:#fff; min-width:0; }
    .progress-metric span { color:#64748b; font-size:10px; font-weight:800; letter-spacing:.05em; text-transform:uppercase; }
    .progress-metric strong { color:#0f172a; font-size:14px; }
    .progress-status-row { display:flex; flex-wrap:wrap; gap:6px; }
    .progress-action-copy { color:#64748b; font-size:12px; line-height:1.4; }
    .asset-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px, 1fr)); gap:10px; }
    .asset-box { border:1px solid #dbe3ef; border-radius:12px; padding:12px; background:#fcfdff; }
    .item-progress-table { width:100%; border-collapse:separate; border-spacing:0; }
    .item-progress-table th { text-align:left; font-size:11px; text-transform:uppercase; letter-spacing:.05em; color:#64748b; padding:10px 12px; border-bottom:1px solid #e2e8f0; }
    .item-progress-table td { padding:12px; border-bottom:1px solid #eef2f7; vertical-align:top; }
    .item-progress-form { display:flex; gap:8px; flex-wrap:wrap; align-items:end; }
    .item-progress-form input { border:1px solid #cbd5e1; border-radius:10px; padding:8px 10px; font-size:13px; }
    .item-progress-form input[type="number"] { width:88px; }
    .workflow-proof-card { display:grid; gap:14px; }
    .workflow-mobile-stepper {
        display:grid;
        gap:12px;
    }
    .workflow-mobile-stepper-bar {
        display:flex;
        gap:8px;
        overflow-x:auto;
        padding-bottom:2px;
        scrollbar-width:none;
    }
    .workflow-mobile-stepper-bar::-webkit-scrollbar { display:none; }
    .workflow-mobile-stepper-tab {
        display:inline-flex;
        align-items:center;
        gap:6px;
        min-height:34px;
        padding:0 10px;
        border-radius:999px;
        border:1px solid #dbe3ef;
        background:#fff;
        color:#64748b;
        font-size:11px;
        font-weight:800;
        white-space:nowrap;
        min-width:0;
        flex:0 0 auto;
    }
    .workflow-mobile-stepper-tab span:last-child {
        min-width:0;
        overflow:hidden;
        text-overflow:ellipsis;
    }
    .workflow-mobile-stepper-tab.is-active {
        background:#eff6ff;
        border-color:#93c5fd;
        color:#1d4ed8;
    }
    .workflow-mobile-stepper-tab.is-complete {
        background:#f0fdf4;
        border-color:#86efac;
        color:#166534;
    }
    .workflow-mobile-stepper-tab-index {
        width:20px;
        height:20px;
        border-radius:999px;
        display:grid;
        place-items:center;
        background:#e2e8f0;
        color:#475569;
        font-size:10px;
        font-weight:900;
        line-height:1;
        flex:0 0 20px;
    }
    .workflow-mobile-stepper-tab.is-active .workflow-mobile-stepper-tab-index {
        background:#2563eb;
        color:#fff;
    }
    .workflow-mobile-stepper-tab.is-complete .workflow-mobile-stepper-tab-index {
        background:#16a34a;
        color:#fff;
    }
    .workflow-mobile-stepper-tab.is-complete .workflow-mobile-stepper-tab-index::before {
        content:"✓";
        font-size:11px;
    }
    .workflow-mobile-stepper-tab.is-complete .workflow-mobile-stepper-tab-index {
        font-size:0;
    }
    .workflow-proof-grid { display:grid; grid-template-columns:repeat(12, minmax(0, 1fr)); gap:14px; }
    .workflow-proof-field { grid-column:span 6; display:grid; gap:6px; }
    .workflow-proof-field.span-12 { grid-column:span 12; }
    .workflow-proof-field label { font-size:12px; font-weight:700; color:#334155; }
    .workflow-proof-field input[type="text"],
    .workflow-proof-field input[type="file"],
    .workflow-proof-field textarea {
        width:100%;
        border:1px solid #cbd5e1;
        border-radius:12px;
        padding:10px 12px;
        font-size:13px;
        color:#0f172a;
        background:#fff;
    }
    .workflow-proof-field textarea { min-height:86px; resize:vertical; }
    .workflow-proof-help { color:#64748b; font-size:11.5px; line-height:1.45; }
    .workflow-proof-badges { display:flex; gap:8px; flex-wrap:wrap; }
    .workflow-proof-badge {
        display:inline-flex;
        align-items:center;
        gap:6px;
        padding:6px 10px;
        border-radius:999px;
        background:#eff6ff;
        color:#1d4ed8;
        font-size:11px;
        font-weight:800;
    }
    .workflow-proof-actions { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
    .workflow-proof-signature-wrap .workflow-proof-actions {
        justify-content:space-between;
    }
    .workflow-proof-signature-wrap .workflow-proof-actions .workflow-proof-trigger {
        min-width:132px;
    }
    .workflow-proof-trigger {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        gap:6px;
        min-height:36px;
        padding:8px 12px;
        border-radius:10px;
        border:1px solid #cbd5e1;
        background:#f8fafc;
        color:#0f172a;
        font-size:12px;
        font-weight:700;
        text-decoration:none;
    }
    .workflow-proof-trigger.is-primary {
        background:#2563eb;
        border-color:#2563eb;
        color:#fff;
    }
    .workflow-proof-status {
        min-height:38px;
        padding:10px 12px;
        border-radius:12px;
        border:1px dashed #cbd5e1;
        background:#f8fafc;
        color:#475569;
        font-size:12px;
        line-height:1.45;
    }
    .workflow-proof-status.is-success {
        border-style:solid;
        border-color:#86efac;
        background:#f0fdf4;
        color:#166534;
    }
    .workflow-proof-status.is-warning {
        border-style:solid;
        border-color:#fdba74;
        background:#fff7ed;
        color:#9a3412;
    }
    .workflow-camera-grid {
        display:grid;
        grid-template-columns:repeat(3, minmax(0, 1fr));
        gap:10px;
    }
    .workflow-camera-card {
        position:relative;
        display:grid;
        grid-template-columns:48px minmax(0, 1fr);
        align-items:start;
        align-content:start;
        gap:12px;
        min-height:0;
        padding:14px;
        border:1px solid #dbe3ef;
        border-radius:16px;
        background:#fff;
        text-align:left;
        overflow:hidden;
    }
    .workflow-camera-card input[type="file"] {
        position:absolute;
        inset:0;
        width:100%;
        height:100%;
        opacity:0;
        cursor:pointer;
    }
    .workflow-camera-icon {
        width:46px;
        height:46px;
        border-radius:16px;
        display:grid;
        place-items:center;
        background:#f8fafc;
        border:1px solid #dbe3ef;
        color:#475569;
        font-size:20px;
    }
    .workflow-camera-card strong {
        color:#0f172a;
        font-size:13px;
        line-height:1.35;
    }
    .workflow-camera-card span {
        color:#64748b;
        font-size:11px;
        line-height:1.45;
    }
    .workflow-camera-meta {
        display:grid;
        gap:4px;
        min-width:0;
    }
    .workflow-camera-footer {
        grid-column:1 / -1;
        display:grid;
        grid-template-columns:minmax(0, 1fr) auto;
        align-items:center;
        gap:8px;
        min-width:0;
    }
    .workflow-camera-card .workflow-camera-chip {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-height:22px;
        padding:0 8px;
        border-radius:999px;
        background:#e0ecff;
        color:#1d4ed8;
        font-size:10px;
        font-weight:800;
        text-transform:uppercase;
        letter-spacing:.04em;
    }
    .workflow-camera-card .workflow-camera-chip.is-optional {
        background:#f1f5f9;
        color:#475569;
    }
    .workflow-camera-card .workflow-camera-trigger,
    .workflow-camera-card .workflow-camera-trigger * {
        display:inline-flex;
        align-items:center;
        justify-content:center;
        min-height:34px;
        padding:0 12px;
        border-radius:999px;
        background:#1d4ed8 !important;
        color:#fff !important;
        font-size:11px;
        font-weight:800;
        white-space:nowrap;
        text-shadow:none !important;
        box-shadow:0 6px 16px rgba(37, 99, 235, 0.18);
    }
    .workflow-camera-status {
        display:none;
        grid-column:1 / -1;
        color:#166534;
        font-size:11px;
        font-weight:700;
        line-height:1.4;
        margin-top:-2px;
    }
    .workflow-camera-status.is-visible {
        display:block;
    }
    .workflow-camera-preview {
        display:none;
        grid-column:1 / -1;
        grid-template-columns:repeat(auto-fit, minmax(112px, 1fr));
        gap:8px;
        min-width:0;
    }
    .workflow-camera-preview.is-visible {
        display:grid;
    }
    .workflow-camera-preview img {
        width:100%;
        min-width:0;
        height:124px;
        object-fit:cover;
        border-radius:10px;
        border:1px solid #dbe3ef;
        background:#fff;
    }
    .workflow-condition-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:8px;
    }
    .workflow-condition-card {
        display:grid;
        gap:3px;
        min-height:66px;
        padding:11px 12px;
        border:1px solid #dbe3ef;
        border-radius:14px;
        background:#fff;
        text-align:left;
        cursor:pointer;
        transition:border-color .18s ease, box-shadow .18s ease, transform .18s ease;
    }
    .workflow-condition-card:hover {
        transform:translateY(-1px);
        border-color:#93c5fd;
    }
    .workflow-condition-card.is-selected {
        border-color:#2563eb;
        background:#eff6ff;
        box-shadow:0 0 0 3px rgba(37, 99, 235, .12);
    }
    .workflow-condition-card strong {
        color:#0f172a;
        font-size:12px;
        line-height:1.3;
    }
    .workflow-condition-card span {
        color:#64748b;
        font-size:11px;
        line-height:1.4;
    }
    .workflow-proof-signature-wrap {
        border:1px solid #dbe3ef;
        border-radius:16px;
        padding:12px;
        background:#fcfdff;
        display:grid;
        gap:10px;
        min-width:0;
        overflow:hidden;
    }
    .workflow-proof-signature-pad {
        display:block;
        width:100%;
        min-width:0;
        height:180px;
        border:1px solid #cbd5e1;
        border-radius:14px;
        background:#fff;
        touch-action:none;
        cursor:crosshair;
    }
    .workflow-proof-signature-actions {
        display:flex;
        justify-content:flex-start;
    }
    .workflow-proof-signature-preview {
        display:none;
        gap:8px;
    }
    .workflow-proof-signature-preview.is-visible {
        display:grid;
    }
    .workflow-proof-signature-preview img {
        width:100%;
        max-width:240px;
        min-width:0;
        height:auto;
        border:1px solid #dbe3ef;
        border-radius:12px;
        background:#fff;
    }
    .workflow-step-list { display:grid; gap:12px; }
    .workflow-step {
        display:grid;
        gap:10px;
        padding:12px;
        border:1px solid #e2e8f0;
        border-radius:14px;
        background:#fbfdff;
    }
    .workflow-step-head {
        display:flex;
        align-items:flex-start;
        gap:10px;
        min-width:0;
    }
    .workflow-step-head > div,
    .workflow-step-copy,
    .workflow-step-hero-copy { min-width:0; max-width:100%; }
    .workflow-step-index {
        width:26px;
        height:26px;
        flex:0 0 26px;
        border-radius:999px;
        display:grid;
        place-items:center;
        background:#dbeafe;
        color:#1d4ed8;
        font-size:11px;
        font-weight:800;
    }
    .workflow-step-head strong {
        display:block;
        color:#0f172a;
        font-size:13px;
        line-height:1.35;
    }
    .workflow-step-head span {
        display:block;
        color:#64748b;
        font-size:11.5px;
        line-height:1.45;
        margin-top:3px;
    }
    .workflow-step-actions {
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:8px;
        flex-wrap:nowrap;
        min-width:0;
    }
    .workflow-step-actions--stacked {
        flex-direction:column;
        align-items:stretch;
    }
    .workflow-step-actions .detail-btn,
    .workflow-step-actions .detail-btn-secondary {
        min-height:40px;
        white-space:nowrap;
        min-width:96px;
        flex:1 1 0;
    }
    .workflow-step-actions .detail-btn[disabled] {
        background:#c7d2fe !important;
        border-color:#a5b4fc !important;
        color:#1e293b !important;
        opacity:1 !important;
        box-shadow:none !important;
    }
    .workflow-step-helper {
        color:#64748b;
        font-size:11.5px;
        line-height:1.4;
        min-width:0;
        flex:1 1 auto;
    }
    .workflow-review-list {
        display:grid;
        gap:8px;
    }
    .workflow-review-summary-grid {
        display:grid;
        grid-template-columns:repeat(2, minmax(0, 1fr));
        gap:8px;
        min-width:0;
        width:100%;
        max-width:100%;
    }
    .workflow-review-summary-card {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:10px;
        min-width:0;
        width:100%;
        max-width:100%;
        padding:10px 11px;
        border:1px solid #dbe3ef;
        border-radius:14px;
        background:#fff;
        overflow:hidden;
    }
    .workflow-review-summary-card span {
        color:#64748b;
        font-size:10px;
        font-weight:800;
        letter-spacing:.05em;
        text-transform:uppercase;
        flex:0 0 auto;
    }
    .workflow-review-summary-card strong {
        color:#0f172a;
        font-size:12.5px;
        line-height:1.4;
        overflow-wrap:anywhere;
        text-align:right;
        flex:1 1 auto;
    }
    .workflow-review-item {
        display:grid;
        grid-template-columns:auto minmax(0, 1fr) auto;
        align-items:flex-start;
        gap:10px;
        padding:10px 12px;
        border:1px solid #e2e8f0;
        border-radius:12px;
        background:#fff;
        color:#334155;
        font-size:12px;
        min-width:0;
        width:100%;
        max-width:100%;
        overflow:hidden;
    }
    .workflow-review-item strong {
        color:#0f172a;
        font-size:12px;
        min-width:0;
    }
    .workflow-review-item span { min-width:0; white-space:normal; word-break:break-word; }
    .workflow-review-item[data-review-ready="true"] {
        border-color:#bbf7d0;
        background:#f0fdf4;
    }
    .workflow-review-item[data-review-ready="true"] strong,
    .workflow-review-item[data-review-ready="true"] span {
        color:#166534;
    }
    .workflow-review-item[data-review-ready="false"] {
        border-color:#fed7aa;
        background:#fff7ed;
    }
    .workflow-review-item[data-review-ready="false"] strong,
    .workflow-review-item[data-review-ready="false"] span {
        color:#9a3412;
    }
    .workflow-review-status {
        font-size:11px;
        font-weight:800;
        letter-spacing:.04em;
        text-transform:uppercase;
        white-space:nowrap;
    }
    .workflow-review-step {
        min-width:0;
        width:100%;
        max-width:100%;
        overflow:hidden;
    }
    .workflow-review-step .workflow-review-summary-grid,
    .workflow-review-step .workflow-review-list {
        min-width:0;
        width:100%;
        max-width:100%;
    }
    .workflow-review-step .workflow-review-summary-card,
    .workflow-review-step .workflow-review-item {
        min-width:0;
        width:100%;
        max-width:100%;
        overflow:hidden;
    }
    .workflow-review-step .workflow-review-summary-card strong,
    .workflow-review-step .workflow-review-summary-card span,
    .workflow-review-step .workflow-review-item strong,
    .workflow-review-step .workflow-review-item span {
        max-width:100%;
        min-width:0;
        white-space:normal;
        overflow-wrap:anywhere;
        word-break:break-word;
        writing-mode:horizontal-tb;
    }
    .workflow-review-summary-grid--stacked,
    .workflow-review-list--stacked {
        display:grid;
        grid-template-columns:minmax(0, 1fr);
        gap:8px;
    }
    .workflow-review-summary-card--stacked,
    .workflow-review-item--stacked {
        display:grid;
        grid-template-columns:minmax(0, 1fr);
        gap:6px;
        width:100%;
        max-width:100%;
        min-width:0;
        overflow:hidden;
    }
    .workflow-proof-history {
        display:grid;
        gap:12px;
    }
    .proof-history-shell {
        border:1px solid #e2e8f0;
        border-radius:16px;
        background:#fff;
        overflow:hidden;
    }
    .proof-history-shell > summary {
        list-style:none;
        cursor:pointer;
        padding:12px 14px;
    }
    .proof-history-shell > summary::-webkit-details-marker { display:none; }
    .proof-history-summary {
        display:flex;
        align-items:flex-start;
        justify-content:space-between;
        gap:12px;
        flex-wrap:wrap;
    }
    .proof-history-body { padding:0 14px 14px; }
    .desktop-task-header-meta { display:none; }
    .desktop-secondary-shell {
        border:1px solid #dbe3ef;
        border-radius:16px;
        background:#fff;
        box-shadow:0 8px 24px rgba(15, 23, 42, 0.04);
        overflow:hidden;
    }
    .desktop-secondary-shell > summary {
        list-style:none;
        cursor:pointer;
        padding:12px 14px;
        display:flex;
        align-items:center;
        justify-content:space-between;
        gap:12px;
    }
    .desktop-secondary-shell > summary::-webkit-details-marker { display:none; }
    .desktop-secondary-shell > summary span { color:#0f172a; font-size:14px; font-weight:800; }
    .desktop-secondary-shell > summary strong { color:#64748b; font-size:11px; font-weight:700; }
    .desktop-secondary-shell .detail-support-grid { padding:0 12px 12px; }
    .workflow-proof-history-item {
        display:grid;
        grid-template-columns:minmax(0, 120px) minmax(0, 1fr);
        gap:12px;
        padding:12px;
        border:1px solid #e2e8f0;
        border-radius:14px;
        background:#fff;
    }
    .workflow-proof-history-thumb {
        width:100%;
        max-width:120px;
        border-radius:12px;
        border:1px solid #dbe3ef;
        object-fit:cover;
        background:#f8fafc;
    }
    .workflow-proof-history-meta { display:grid; gap:6px; }
    .workflow-proof-history-meta strong { color:#0f172a; }
    .workflow-proof-history-meta small { color:#64748b; font-size:12px; }
    .workflow-proof-divider { height:1px; background:#e2e8f0; margin:4px 0; }
    .detail-support-grid {
        display:grid;
    }
    @media (max-width: 900px) {
        .span-4, .span-6 { grid-column:span 12; }
        .detail-summary-grid { grid-template-columns:1fr; }
        .detail-summary-row.span-2 { grid-column:span 1; }
        .progress-metric-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .item-progress-table, .item-progress-table tbody, .item-progress-table tr, .item-progress-table td { display:block; width:100%; }
        .item-progress-table thead { display:none; }
        .item-progress-table tr { border:1px solid #e2e8f0; border-radius:12px; margin-bottom:10px; overflow:hidden; }
        .workflow-proof-field { grid-column:span 12; }
        .workflow-proof-history-item { grid-template-columns:1fr; }
        .workflow-camera-grid,
        .workflow-condition-grid,
        .workflow-mini-summary,
        .fieldops-kv-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
    }
    @media (min-width: 1024px) {
        .delivery-detail {
            max-width:min(1500px, calc(100vw - 32px));
            padding:6px 12px 20px;
            display:grid;
            grid-template-columns:minmax(320px, 35%) minmax(0, 65%);
            gap:8px;
            align-items:start;
        }
        .delivery-detail-header {
            grid-column:1 / -1;
            position:static;
            padding:7px 10px;
            border:1px solid rgba(203,213,225,.9);
            border-radius:12px;
            background:#fff;
            box-shadow:0 6px 16px rgba(15,23,42,.045);
            backdrop-filter:none;
            flex-wrap:nowrap;
            align-items:center;
        }
        .delivery-detail-header h1 { font-size:19px; letter-spacing:-.02em; }
        .delivery-detail-header p,
        .workflow-preview-shell { display:none; }
        .desktop-task-header-meta {
            display:flex;
            align-items:center;
            gap:6px;
            flex-wrap:wrap;
            margin-top:5px;
            color:#64748b;
            font-size:10.5px;
            font-weight:700;
        }
        .desktop-task-header-meta > span:not(.status-badge) {
            display:inline-flex;
            align-items:center;
            gap:4px;
            min-height:22px;
            padding:2px 7px;
            border:1px solid #e2e8f0;
            border-radius:999px;
            background:#f8fafc;
        }
        .desktop-task-header-meta strong { color:#0f172a; font-weight:800; }
        .detail-actions { flex-wrap:nowrap; align-items:center; }
        .detail-actions .detail-btn,
        .detail-actions .detail-btn-secondary {
            min-height:30px;
            padding:6px 10px;
            border-radius:9px;
            font-size:11px;
        }
        .fieldops-task-shell {
            grid-column:1;
            position:sticky;
            top:90px;
            align-self:flex-start;
            padding:10px;
            border-radius:14px;
            gap:8px;
            box-shadow:0 10px 26px rgba(15,23,42,.06);
        }
        .fieldops-task-shell-top { grid-template-columns:48px minmax(0, 1fr); gap:10px; }
        .fieldops-task-shell-ill { width:48px; height:48px; border-radius:14px; }
        .fieldops-task-shell-copy strong { font-size:17px; }
        .fieldops-task-shell-copy p { font-size:11px; line-height:1.35; }
        .fieldops-task-meta-grid,
        .fieldops-kv-grid,
        .workflow-mini-summary { gap:5px; }
        .fieldops-task-meta { padding:7px 8px; border-radius:10px; }
        .fieldops-task-meta span,
        .fieldops-kv-label,
        .label { font-size:9.5px; }
        .fieldops-task-meta strong,
        .fieldops-kv-value { font-size:12px; line-height:1.3; }
        .fieldops-icon-actions { grid-template-columns:repeat(5, minmax(0, 1fr)); gap:5px; }
        .desktop-note-action { display:grid; }
        .fieldops-icon-action {
            min-height:36px;
            padding:5px;
            border-radius:10px;
            font-size:9.5px;
        }
        .fieldops-icon-action svg { width:13px; height:13px; }
        .fieldops-primary-bar { grid-template-columns:1fr; gap:5px; }
        .fieldops-primary-bar .detail-btn { min-height:34px; border-radius:10px; }
        .fieldops-primary-note { font-size:10.5px; }
        .fieldops-overview-card { display:none; }
        .detail-card {
            padding:9px;
            border-radius:14px;
            box-shadow:0 8px 22px rgba(15,23,42,.045);
        }
        .delivery-detail > .detail-card:not(.fieldops-task-shell):not(.fieldops-overview-card) {
            grid-column:2;
        }
        .workflow-mobile-shell {
            grid-column:2;
            position:relative;
            top:auto;
            max-height:none;
            overflow:visible;
            padding:9px;
            border-radius:14px;
            border-color:#dbe3ef;
            box-shadow:0 8px 22px rgba(15,23,42,.045);
        }
        .workflow-mobile-shell::before { content:none; }
        .workflow-mobile-shell-body,
        .workflow-proof-card { gap:8px; }
        .desktop-workflow-progress {
            display:flex;
            align-items:center;
            gap:6px;
            padding:7px 8px;
            border:1px solid #dbe3ef;
            border-radius:12px;
            background:#f8fafc;
            overflow-x:auto;
        }
        .desktop-workflow-progress span {
            flex:1 0 auto;
            min-width:70px;
            display:inline-flex;
            align-items:center;
            justify-content:center;
            min-height:24px;
            padding:3px 8px;
            border-radius:999px;
            background:#fff;
            color:#475569;
            border:1px solid #e2e8f0;
            font-size:10px;
            font-weight:900;
            letter-spacing:.04em;
            text-transform:uppercase;
        }
        .desktop-workflow-progress span:first-child {
            background:#2563eb;
            border-color:#2563eb;
            color:#fff;
        }
        .workflow-mobile-shell-body > div:first-child h2 { font-size:16px; }
        .workflow-mobile-shell-body > div:first-child div { font-size:11.5px !important; }
        .workflow-step,
        .workflow-start-hero,
        .workflow-camera-card,
        .workflow-proof-field,
        .workflow-review-summary-card,
        .workflow-review-item { border-radius:12px; }
        .workflow-proof-grid,
        .workflow-camera-grid,
        .workflow-condition-grid { gap:7px; }
        .workflow-step-copy strong { font-size:14px; }
        .workflow-step-copy p,
        .workflow-proof-help,
        .workflow-camera-status { font-size:11.5px; line-height:1.35; }
        .workflow-proof-actions .detail-btn,
        .workflow-proof-actions .detail-btn-secondary,
        .workflow-step-actions .detail-btn,
        .workflow-step-actions .detail-btn-secondary {
            min-height:34px;
            padding:7px 11px;
            border-radius:9px;
            font-size:11.5px;
        }
        .progress-card-grid {
            display:grid;
            grid-template-columns:1fr;
            gap:9px;
            margin-top:8px !important;
        }
        .progress-card { padding:8px; border-radius:12px; }
        .progress-card-head {
            display:flex;
            justify-content:space-between;
            gap:10px;
            align-items:flex-start;
        }
        .progress-card-head strong { font-size:13px; line-height:1.25; }
        .progress-card-head small { max-width:45%; text-align:right; }
        .progress-metric-grid {
            grid-template-columns:repeat(4, minmax(0, 1fr));
            gap:6px;
        }
        .progress-metric { padding:7px; border-radius:9px; }
        .progress-metric strong { font-size:15px; }
        .item-progress-form {
            display:grid;
            grid-template-columns:96px auto minmax(0, 1fr);
            gap:8px;
            align-items:center;
        }
        .item-progress-form input[type="number"] { width:96px; }
        .item-progress-form .detail-btn { justify-self:start; }
        .workflow-proof-divider { margin:1px 0; }
        .proof-history-shell { border-radius:12px; }
        .proof-history-shell > summary { padding:8px 10px; }
        .proof-history-body { padding:0 10px 10px; }
        .timeline-shell,
        .desktop-secondary-shell {
            grid-column:2;
            border-radius:14px;
        }
        .timeline-shell > summary,
        .desktop-secondary-shell > summary { padding:10px 12px; }
        .timeline-summary-copy h2,
        .timeline-head h2 { font-size:14px; }
        .timeline-summary-copy p,
        .timeline-head p { font-size:11px; }
        .timeline-item { padding:8px; border-radius:12px; }
        .detail-support-grid { gap:10px; }
        .asset-grid {
            grid-template-columns:repeat(3, minmax(0, 1fr));
            gap:8px;
        }
        .asset-box { padding:9px; border-radius:10px; }
    }
    @media (max-width: 767px) {
        .app-shell-main {
            padding-top:8px !important;
        }
        .mobile-topbar-search,
        .mobile-back-row {
            display:none !important;
        }
        .delivery-detail {
            gap:8px;
            padding:0 0 calc(132px + env(safe-area-inset-bottom, 0px));
        }
        .delivery-mobile-titlebar {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:10px;
            padding:0 4px 4px;
        }
        .delivery-mobile-titlebar a,
        .delivery-mobile-titlebar button {
            width:38px;
            height:38px;
            display:grid;
            place-items:center;
            border-radius:14px;
            border:1px solid #dbe3ef;
            background:#fff;
            color:#0f172a;
            text-decoration:none;
        }
        .delivery-mobile-titlebar strong {
            min-width:0;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
            color:#0f172a;
            font-size:17px;
            font-weight:900;
        }
        .delivery-mobile-titlebar .status-badge {
            flex:0 0 auto;
            padding:4px 8px;
            font-size:9.5px;
        }
        .delivery-mobile-titlebar.is-pending .status-badge {
            background:#fee2e2 !important;
            color:#b91c1c !important;
        }
        .delivery-mobile-titlebar.is-progress .status-badge {
            background:#ffedd5 !important;
            color:#c2410c !important;
        }
        .delivery-mobile-titlebar.is-completed .status-badge {
            background:#dcfce7 !important;
            color:#166534 !important;
        }
        .delivery-detail-header {
            display:none;
        }
        .detail-actions { display:none; }
        body.workflow-mobile-open {
            overscroll-behavior:none;
        }
        .workflow-preview-shell { display:none; }
        .fieldops-task-shell {
            gap:8px;
            padding:10px;
            border-radius:16px;
            box-shadow:0 12px 28px rgba(15,23,42,.08);
        }
        .fieldops-task-shell--pending {
            border-color:#fecaca;
            background:linear-gradient(135deg, #fff1f2 0%, #fff7ed 46%, #ffffff 100%);
        }
        .fieldops-task-shell--progress {
            border-color:#fed7aa;
            background:linear-gradient(135deg, #fff7ed 0%, #ffffff 100%);
        }
        .fieldops-task-shell--completed {
            border-color:#bbf7d0;
            background:linear-gradient(135deg, #ecfdf5 0%, #ffffff 100%);
        }
        .fieldops-task-shell--cancelled {
            border-color:#cbd5e1;
            background:linear-gradient(135deg, #f8fafc 0%, #ffffff 100%);
        }
        .fieldops-task-shell--pending .fieldops-task-shell-ill {
            background:#fee2e2;
            border-color:#fecaca;
        }
        .fieldops-task-shell--progress .fieldops-task-shell-ill {
            background:#ffedd5;
            border-color:#fed7aa;
        }
        .fieldops-task-shell--completed .fieldops-task-shell-ill {
            background:#dcfce7;
            border-color:#bbf7d0;
        }
        .fieldops-task-shell--pending .fieldops-task-shell-chips .status-badge:first-child {
            background:#fee2e2 !important;
            color:#b91c1c !important;
        }
        .fieldops-task-shell--progress .fieldops-task-shell-chips .status-badge:first-child {
            background:#ffedd5 !important;
            color:#c2410c !important;
        }
        .fieldops-task-shell--completed .fieldops-task-shell-chips .status-badge:first-child {
            background:#dcfce7 !important;
            color:#166534 !important;
        }
        .fieldops-mobile-back-inline {
            display:inline-flex;
            align-items:center;
            justify-content:center;
            gap:5px;
            justify-self:start;
            min-height:30px;
            padding:0 10px;
            border-radius:999px;
            border:1px solid #dbe3ef;
            background:#ffffff;
            color:#334155;
            font-size:11px;
            font-weight:900;
            text-decoration:none;
            box-shadow:0 8px 16px rgba(15,23,42,.06);
        }
        .fieldops-task-shell-top {
            grid-template-columns:48px minmax(0, 1fr);
            gap:9px;
        }
        .fieldops-task-shell-ill {
            width:48px;
            height:48px;
            border-radius:14px;
        }
        .fieldops-task-shell-copy strong {
            font-size:17px;
        }
        .fieldops-task-shell-copy p {
            display:none;
        }
        .fieldops-task-meta-grid {
            gap:6px;
        }
        .fieldops-task-meta {
            padding:7px 8px;
            border-radius:11px;
            background:rgba(255,255,255,.86);
            border-color:rgba(203,213,225,.82);
            box-shadow:inset 0 1px 0 rgba(255,255,255,.75);
        }
        .fieldops-task-meta strong {
            font-size:12px;
        }
        .mobile-inline-actions { display:none; }
        .item-progress-form { display:none; }
        .detail-card {
            padding:10px;
            border-radius:15px;
        }
        .fieldops-overview-card {
            gap:10px;
        }
        .fieldops-overview-head h2 {
            font-size:15px;
        }
        .fieldops-overview-head p,
        .fieldops-kv-value,
        .detail-summary-note {
            font-size:11.5px;
        }
        .fieldops-kv-grid,
        .workflow-mini-summary {
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:8px;
        }
        .fieldops-primary-bar {
            display:none;
        }
        .fieldops-icon-actions {
            grid-template-columns:repeat(4, minmax(0, 1fr));
            gap:6px;
            align-items:stretch;
        }
        .fieldops-icon-action,
        .fieldops-icon-actions .mobile-actions-menu summary {
            min-height:38px;
            width:100%;
            padding:5px 4px;
            border-radius:11px;
            font-size:9.5px;
            border:1px solid #dbe3ef;
            background:#fff;
            color:#0f172a;
            display:grid;
            place-items:center;
            gap:3px;
            text-align:center;
        }
        .fieldops-icon-action svg,
        .fieldops-icon-actions .mobile-actions-menu summary svg {
            width:14px;
            height:14px;
        }
        .fieldops-icon-action:nth-child(1) {
            background:#ecfdf5;
            border-color:#bbf7d0;
            color:#047857;
        }
        .fieldops-icon-action:nth-child(2) {
            background:#ecfdf5;
            border-color:#bbf7d0;
            color:#047857;
        }
        .fieldops-icon-action:nth-child(3) {
            background:#eff6ff;
            border-color:#bfdbfe;
            color:#1d4ed8;
        }
        .fieldops-icon-actions .mobile-actions-menu {
            min-width:0;
            width:100%;
            position:relative;
        }
        .fieldops-icon-actions .mobile-actions-menu summary {
            list-style:none;
            min-height:38px;
            color:#334155;
            background:#f8fafc;
            border-color:#dbe3ef;
        }
        .fieldops-icon-actions .mobile-actions-menu summary::-webkit-details-marker {
            display:none;
        }
        .fieldops-icon-actions .mobile-actions-menu summary::before {
            content:"...";
            font-size:15px;
            line-height:1;
            color:#2563eb;
        }
        .fieldops-icon-actions .mobile-actions-menu summary {
            font-size:0;
        }
        .fieldops-icon-actions .mobile-actions-menu summary::after {
            content:"More";
            display:block;
            font-size:9.5px;
            line-height:1.1;
            font-weight:800;
            color:#0f172a;
        }
        .fieldops-icon-actions .mobile-actions-menu[open]::before {
            content:"";
            position:fixed;
            inset:0;
            z-index:39;
            background:rgba(15,23,42,.28);
        }
        .fieldops-icon-actions .mobile-actions-menu .mobile-actions-panel {
            position:fixed;
            left:12px;
            right:12px;
            bottom:calc(136px + env(safe-area-inset-bottom, 0px));
            width:auto;
            max-height:min(52vh, 360px);
            overflow-y:auto;
            z-index:40;
            border-radius:18px;
            box-shadow:0 24px 48px rgba(15,23,42,.24);
        }
        .fieldops-overview-card {
            display:none;
        }
        .workflow-start-hero {
            grid-template-columns:56px minmax(0, 1fr);
            padding:12px;
        }
        .workflow-start-illustration {
            width:56px;
            height:56px;
            border-radius:16px;
            font-size:22px;
        }
        .workflow-start-copy strong,
        .workflow-step-copy strong {
            font-size:16px;
        }
        .workflow-step-hero {
            grid-template-columns:48px minmax(0, 1fr);
            padding:9px 10px;
        }
        .workflow-step-hero-icon {
            width:48px;
            height:48px;
            border-radius:14px;
        }
        .workflow-step-hero-copy strong {
            font-size:12px;
        }
        .workflow-step-hero-copy span {
            font-size:10.5px;
        }
        .progress-metric-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); gap:6px; }
        .progress-metric { padding:7px 6px; }
        .progress-metric span { font-size:9px; }
        .progress-metric strong { font-size:12px; }
        .workflow-camera-grid {
            grid-template-columns:1fr;
            gap:8px;
        }
        .workflow-camera-card {
            min-height:112px;
            grid-template-columns:44px minmax(0, 1fr);
            align-items:center;
            justify-items:start;
            text-align:left;
        }
        .workflow-camera-preview {
            grid-template-columns:repeat(2, minmax(0, 1fr));
        }
        .workflow-camera-preview img {
            height:90px;
        }
        .workflow-camera-icon {
            width:40px;
            height:40px;
            border-radius:14px;
            font-size:18px;
        }
        .workflow-condition-grid {
            grid-template-columns:1fr;
            gap:7px;
        }
        .workflow-mobile-stepper-tab {
            min-height:32px;
            padding:0 9px;
            font-size:10px;
        }
        .workflow-review-summary-grid {
            display:grid !important;
            grid-template-columns:minmax(0, 1fr) !important;
            gap:8px;
        }
        .workflow-step {
            padding:11px;
            gap:9px;
            min-width:0;
            width:100%;
            max-width:100%;
            overflow:hidden;
        }
        .workflow-step-head strong { font-size:12px; }
        .workflow-step-head span,
        .workflow-step-helper,
        .workflow-proof-help { font-size:11px; }
        .workflow-proof-badges { gap:6px; }
        .workflow-proof-badge {
            padding:5px 8px;
            font-size:10px;
        }
        .workflow-proof-status {
            min-height:34px;
            padding:9px 10px;
            font-size:11.5px;
        }
        .workflow-camera-grid {
            grid-template-columns:minmax(0, 1fr);
            gap:12px;
        }
        .workflow-camera-card {
            padding:13px;
        }
        .workflow-camera-footer {
            grid-template-columns:minmax(0, 1fr);
        }
        .workflow-camera-trigger {
            width:100%;
            min-height:36px;
        }
        .workflow-camera-preview {
            grid-template-columns:repeat(2, minmax(0, 1fr));
        }
        .workflow-camera-preview img {
            height:112px;
        }
        .workflow-proof-signature-pad { height:168px; }
        .workflow-proof-signature-actions .workflow-proof-trigger {
            width:100%;
        }
        .workflow-step-actions {
            padding-top:8px;
            background:linear-gradient(180deg, rgba(255,255,255,0) 0%, #fbfdff 28%, #fbfdff 100%);
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            align-items:stretch;
        }
        .workflow-step-actions .detail-btn,
        .workflow-step-actions .detail-btn-secondary {
            flex:1 1 0;
            min-width:0;
            width:100%;
        }
        .workflow-step-actions--stacked {
            grid-template-columns:minmax(0, 1fr);
        }
        .workflow-step-actions .workflow-step-helper {
            grid-column:1 / -1;
        }
        .workflow-review-item {
            grid-template-columns:minmax(0, 1fr) !important;
            gap:6px;
            padding:11px 12px;
        }
        .workflow-review-status {
            justify-self:start;
            white-space:normal;
        }
        .workflow-review-summary-card {
            display:grid;
            grid-template-columns:minmax(0, 1fr);
            gap:4px;
            padding:11px 12px;
        }
        .workflow-review-summary-card span,
        .workflow-review-summary-card strong,
        .workflow-review-item strong,
        .workflow-review-item span,
        .workflow-step-head strong,
        .workflow-step-head span,
        .workflow-step-hero-copy strong,
        .workflow-step-hero-copy span {
            max-width:100%;
            min-width:0;
            white-space:normal;
            overflow-wrap:anywhere;
            word-break:break-word;
        }
        .workflow-review-summary-card strong {
            text-align:left;
        }
        .workflow-proof-field > label[style*="display:flex"] {
            width:100%;
            min-width:0;
            flex-wrap:nowrap;
        }
        .workflow-proof-field > label[style*="display:flex"] span {
            min-width:0;
            overflow-wrap:anywhere;
            word-break:break-word;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-review-summary-grid,
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-review-list {
            display:flex !important;
            flex-direction:column !important;
            gap:8px;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-review-step {
            display:grid;
            grid-template-columns:minmax(0, 1fr);
            align-content:start;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-review-step .workflow-review-summary-grid,
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-review-step .workflow-review-list {
            display:flex !important;
            flex-direction:column !important;
            width:100% !important;
            max-width:100% !important;
            min-width:0 !important;
            gap:8px;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-review-summary-card,
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-review-item {
            display:flex !important;
            flex-direction:column !important;
            align-items:flex-start !important;
            justify-content:flex-start !important;
            width:100%;
            max-width:100%;
            overflow:hidden;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-review-item strong,
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-review-item span,
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-review-summary-card strong,
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-review-summary-card span {
            writing-mode:horizontal-tb;
        }
        .workflow-mobile-stepper[data-mobile-workflow-active="true"] [data-workflow-step-panel] {
            display:none;
        }
        .workflow-mobile-stepper[data-mobile-workflow-active="true"] [data-workflow-step-panel].is-current {
            display:grid;
        }
        .workflow-mobile-stepper[data-mobile-workflow-active="true"] [data-workflow-desktop-actions] {
            display:none;
        }
        .workflow-mobile-shell {
            position:fixed;
            inset:0;
            height:100dvh;
            max-height:100dvh;
            z-index:1095;
            display:none;
            grid-template-rows:auto minmax(0, 1fr);
            gap:0;
            padding:0;
            border:none;
            border-radius:0;
            box-shadow:none;
            background:#f8fbff;
            overflow:hidden;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] {
            display:grid;
        }
        .workflow-mobile-shell[data-mobile-shell-mode="proof-history"] .workflow-mobile-stepper {
            display:none !important;
        }
        .workflow-mobile-shell[data-mobile-shell-mode="proof-history"] [data-workflow-shell-intro],
        .workflow-mobile-shell[data-mobile-shell-mode="proof-history"] [data-workflow-error-summary] {
            display:none !important;
        }
        .workflow-mobile-shell[data-mobile-shell-mode="proof-history"] form[data-workflow-form],
        .workflow-mobile-shell[data-mobile-shell-mode="proof-history"] details[id="{{ $cancellationSectionId }}"],
        .workflow-mobile-shell[data-mobile-shell-mode="proof-history"] .workflow-proof-divider {
            display:none !important;
        }
        .workflow-mobile-shell[data-mobile-shell-mode="proof-history"] .proof-history-shell {
            display:block !important;
            margin-top:0;
            overflow:visible;
        }
        .workflow-mobile-shell[data-mobile-shell-mode="proof-history"] .workflow-mobile-shell-body {
            overflow-y:auto !important;
            -webkit-overflow-scrolling:touch;
            overscroll-behavior:contain;
            touch-action:pan-y;
        }
        .workflow-mobile-shell[data-mobile-shell-mode="proof-history"] .proof-history-body {
            padding:0 14px calc(118px + env(safe-area-inset-bottom, 0px));
            overflow:visible;
        }
        .workflow-mobile-shell-head {
            display:flex;
            align-items:center;
            justify-content:space-between;
            gap:12px;
            padding:calc(12px + env(safe-area-inset-top, 0px)) 12px 10px;
            border-bottom:1px solid #dbe3ef;
            background:rgba(255,255,255,.96);
            backdrop-filter:blur(16px);
        }
        .workflow-mobile-shell-head strong {
            display:block;
            color:#0f172a;
            font-size:15px;
            line-height:1.2;
        }
        .workflow-mobile-shell-head span {
            display:block;
            margin-top:3px;
            color:#64748b;
            font-size:11px;
            line-height:1.4;
        }
        .workflow-mobile-shell-close {
            width:36px;
            height:36px;
            flex:0 0 36px;
            display:grid;
            place-items:center;
            border-radius:12px;
            border:1px solid #cbd5e1;
            background:#fff;
            color:#0f172a;
            font-size:20px;
            font-weight:700;
            cursor:pointer;
        }
        .workflow-mobile-shell-body {
            min-height:0;
            min-width:0;
            overflow-y:auto;
            padding:10px 10px calc(96px + env(safe-area-inset-bottom, 0px));
            -webkit-overflow-scrolling:touch;
            overscroll-behavior:contain;
        }
        .workflow-mobile-shell .workflow-proof-badges,
        .workflow-mobile-shell [data-workflow-desktop-actions] {
            display:none;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-mobile-stepper[data-mobile-workflow-active="true"] [data-workflow-step-panel] {
            padding-bottom:112px;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-mobile-stepper[data-mobile-workflow-active="true"] [data-workflow-step-panel].is-current .workflow-step-actions {
            position:fixed;
            left:12px;
            right:12px;
            bottom:calc(12px + env(safe-area-inset-bottom, 0px));
            z-index:1120;
            padding:8px 10px;
            border-radius:18px;
            border:1px solid #dbe3ef;
            background:rgba(255,255,255,.96);
            box-shadow:0 16px 36px rgba(15,23,42,.14);
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-mobile-stepper[data-mobile-workflow-active="true"] [data-workflow-step-panel].is-current .workflow-step-helper {
            width:100%;
            order:3;
        }
        .detail-support-grid {
            display:none;
        }
        .desktop-secondary-shell[open] .detail-support-grid {
            display:grid;
            grid-template-columns:1fr;
            gap:8px;
            padding:0 10px 10px;
        }
        .desktop-secondary-shell[open] .detail-support-grid .detail-card {
            padding:9px;
            border-radius:12px;
        }
        .delivery-mobile-sticky-cta {
            position:fixed;
            left:10px;
            right:10px;
            bottom:calc(78px + env(safe-area-inset-bottom, 0px));
            z-index:34;
            display:block;
        }
        .delivery-mobile-sticky-cta.is-pending a {
            background:#dc2626;
            box-shadow:0 18px 36px rgba(220,38,38,.28);
        }
        .delivery-mobile-sticky-cta.is-progress a {
            background:#f97316;
            box-shadow:0 18px 36px rgba(249,115,22,.26);
        }
        .delivery-mobile-sticky-cta.is-completed a {
            background:#16a34a;
            box-shadow:0 18px 36px rgba(22,163,74,.24);
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-mobile-stepper[data-mobile-workflow-active="true"] {
            position:sticky;
            top:0;
            z-index:18;
            margin:-8px -8px 8px;
            padding:8px 8px 7px;
            border-bottom:1px solid #e8eef7;
            background:rgba(255,255,255,.98);
            box-shadow:0 8px 22px rgba(15,23,42,.06);
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-mobile-stepper[data-mobile-workflow-active="true"] .workflow-mobile-stepper-bar {
            display:grid;
            grid-auto-flow:column;
            grid-auto-columns:minmax(58px, 1fr);
            gap:5px;
            overflow-x:auto;
            padding:0 2px 1px;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-mobile-stepper[data-mobile-workflow-active="true"] .workflow-mobile-stepper-tab {
            min-height:44px;
            display:grid;
            justify-items:center;
            align-content:center;
            gap:3px;
            padding:4px 6px;
            border:0;
            border-radius:12px;
            background:transparent;
            color:#64748b;
            box-shadow:none;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-mobile-stepper[data-mobile-workflow-active="true"] .workflow-mobile-stepper-tab-index {
            width:23px;
            height:23px;
            flex-basis:23px;
            border:1px solid #cbd5e1;
            background:#fff;
            color:#64748b;
            box-shadow:0 4px 12px rgba(15,23,42,.06);
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-mobile-stepper[data-mobile-workflow-active="true"] .workflow-mobile-stepper-tab span:last-child {
            max-width:70px;
            color:inherit;
            font-size:9.5px;
            font-weight:850;
            text-align:center;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-mobile-stepper[data-mobile-workflow-active="true"] .workflow-mobile-stepper-tab.is-active {
            color:#1d4ed8;
            background:#eff6ff;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-mobile-stepper[data-mobile-workflow-active="true"] .workflow-mobile-stepper-tab.is-active .workflow-mobile-stepper-tab-index {
            border-color:#2563eb;
            background:#2563eb;
            color:#fff;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-mobile-stepper[data-mobile-workflow-active="true"] .workflow-mobile-stepper-tab.is-complete {
            color:#15803d;
            background:#f0fdf4;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-mobile-stepper[data-mobile-workflow-active="true"] .workflow-mobile-stepper-tab.is-complete .workflow-mobile-stepper-tab-index {
            border-color:#16a34a;
            background:#16a34a;
            color:#fff;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-step {
            border-radius:18px;
            border-color:#e4ebf5;
            background:#fff;
            box-shadow:0 14px 36px rgba(15,23,42,.08);
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-step-head {
            align-items:center;
            padding-bottom:2px;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-step-index {
            width:24px;
            height:24px;
            flex-basis:24px;
            background:#2563eb;
            color:#fff;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-step-counter {
            display:none;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-step-copy strong {
            margin-top:0;
            font-size:15px;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-step-copy p {
            margin-top:2px;
            font-size:11px;
            line-height:1.35;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-start-hero,
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-step-hero {
            border-color:#e4ebf5;
            background:#fbfdff;
            box-shadow:none;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-proof-status {
            border-radius:14px;
            border-style:solid;
            font-size:11px;
            line-height:1.4;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-proof-status.is-success::before,
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-review-item[data-review-ready="true"]::before {
            content:"✓";
            display:inline-grid;
            place-items:center;
            width:18px;
            height:18px;
            margin-right:6px;
            border-radius:999px;
            background:#16a34a;
            color:#fff;
            font-size:11px;
            font-weight:900;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-camera-card {
            min-height:0;
            padding:10px;
            border-radius:16px;
            border-color:#e4ebf5;
            box-shadow:0 8px 20px rgba(15,23,42,.05);
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-camera-card .workflow-camera-chip {
            min-height:20px;
            padding:0 7px;
            font-size:9px;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-camera-trigger {
            min-height:34px;
            border-radius:12px;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-review-item,
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-review-summary-card {
            border-radius:14px;
            padding:9px 10px;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-coordinate-details {
            grid-column:span 12;
            border:1px solid #e2e8f0;
            border-radius:14px;
            padding:8px 10px;
            background:#f8fafc;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-coordinate-details summary {
            cursor:pointer;
            color:#475569;
            font-size:11px;
            font-weight:800;
            letter-spacing:.04em;
            text-transform:uppercase;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-coordinate-details .workflow-proof-status {
            margin-top:8px;
            display:grid;
            grid-template-columns:repeat(2, minmax(0, 1fr));
            gap:5px;
            padding:8px;
            background:#fff;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-coordinate-details .workflow-proof-status div {
            min-width:0;
            overflow:hidden;
            text-overflow:ellipsis;
            white-space:nowrap;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-proof-field textarea {
            min-height:68px;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-proof-signature-pad {
            height:126px;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-proof-signature-preview.is-visible img {
            max-width:100%;
            height:92px;
            object-fit:contain;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-proof-signature-preview.is-visible ~ .workflow-signature-unavailable-block,
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-proof-signature-wrap.has-signature-capture .workflow-signature-unavailable-block {
            display:none;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-proof-signature-wrap.has-signature-capture .workflow-proof-help[data-signature-hint] {
            display:none;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-review-step .workflow-proof-field.span-12 {
            border:1px solid #e2e8f0;
            border-radius:14px;
            padding:9px 10px;
            background:#fff;
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-step-actions .detail-btn {
            background:#2563eb;
            border-color:#2563eb;
            box-shadow:0 12px 24px rgba(37,99,235,.22);
        }
        .workflow-mobile-shell[data-mobile-shell-state="open"] .workflow-step-actions .detail-btn-secondary {
            border-color:#d5deea;
            color:#334155;
            background:#fff;
        }
        .delivery-page-spacer {
            display:block;
            height:calc(132px + env(safe-area-inset-bottom, 0px));
            pointer-events:none;
        }
        .workflow-success-screen {
            position:fixed;
            inset:0;
            z-index:1300;
            display:grid;
            place-items:center;
            padding:28px;
            background:linear-gradient(160deg, #16a34a 0%, #15803d 55%, #047857 100%);
            color:#fff;
            text-align:center;
        }
        .workflow-success-panel {
            width:min(100%, 330px);
            display:grid;
            justify-items:center;
            gap:14px;
        }
        .workflow-success-check {
            width:112px;
            height:112px;
            border-radius:999px;
            display:grid;
            place-items:center;
            background:#fff;
            color:#16a34a;
            font-size:68px;
            font-weight:900;
            box-shadow:0 22px 60px rgba(0,0,0,.18);
        }
        .workflow-success-title {
            margin:8px 0 0;
            font-size:25px;
            line-height:1.15;
            font-weight:900;
        }
        .workflow-success-meta {
            display:grid;
            gap:5px;
            color:rgba(255,255,255,.9);
            font-size:14px;
            line-height:1.35;
        }
        .workflow-success-progress {
            width:100%;
            height:5px;
            margin-top:10px;
            border-radius:999px;
            overflow:hidden;
            background:rgba(255,255,255,.25);
        }
        .workflow-success-progress span {
            display:block;
            height:100%;
            width:100%;
            background:#fff;
            transform-origin:left;
            animation:workflow-success-return 2.2s linear forwards;
        }
        @keyframes workflow-success-return {
            from { transform:scaleX(0); }
            to { transform:scaleX(1); }
        }
    }

    @media (max-width: 480px) {
        .progress-metric-grid { grid-template-columns:repeat(2, minmax(0, 1fr)); }
        .mobile-inline-actions {
            grid-template-columns:repeat(4, minmax(0, 1fr));
        }
    }
</style>

<div class="delivery-detail">
    <div class="delivery-mobile-titlebar is-{{ $mobileTaskTone }}" aria-label="{{ ucfirst($delivery->type) }} task header">
        <a href="{{ route('deliveries.index') }}" aria-label="Back to deliveries">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m15 18-6-6 6-6"/></svg>
        </a>
        <strong>{{ ucfirst($delivery->type) }} #{{ $delivery->id }}</strong>
        <span class="status-badge" style="{{ $statusStyle }}">{{ $displayStatusLabel }}</span>
    </div>
    <div class="delivery-detail-header">
        <div>
            <h1>{{ ucfirst($delivery->type) }} #{{ $delivery->id }}</h1>
            <p>Compact field summary, guided proof, and one clear action path.</p>
            <div class="desktop-task-header-meta" aria-label="Task summary">
                <span class="status-badge" style="{{ $statusStyle }}">{{ $displayStatusLabel }}</span>
                <span class="status-badge" style="background:#fff7ed;color:#9a3412;">
                    {{ $delivery->scheduled_at && $delivery->scheduled_at->isPast() && !in_array($delivery->status, ['completed', 'cancelled'], true) ? 'High Priority' : 'Normal Priority' }}
                </span>
                <span>Assigned: <strong>{{ $assigneeName }}</strong></span>
                <span>Customer: <strong>{{ $linkedCustomerName ?: 'Customer' }}</strong></span>
                <span>Date: <strong>{{ $delivery->scheduled_at ? $delivery->scheduled_at->format('d M h:i A') : 'Not scheduled' }}</strong></span>
            </div>
        </div>
        <div class="detail-actions">
            <a href="{{ route('deliveries.index') }}" class="detail-btn-secondary">Back</a>
            @if($canUpdateTask && !$workflowCompleted && $delivery->status === 'pending')
                <a href="#{{ $workflowProofSectionId }}" class="detail-btn">{{ $primaryWorkflowCtaLabel }}</a>
            @elseif($canUpdateTask && !$workflowCompleted && $delivery->status === 'in_progress')
                <a href="#{{ $workflowProofSectionId }}" class="detail-btn">{{ $primaryWorkflowCtaLabel }}</a>
            @elseif($hasProofHistory)
                <a href="#delivery-proof-history" class="detail-btn">View Proof</a>
            @endif
            @if($canUpdateTask)
                <a href="{{ route('deliveries.edit', $delivery) }}" class="detail-btn-secondary">Edit</a>
            @endif
        </div>
    </div>

    <div class="fieldops-task-shell fieldops-task-shell--{{ $mobileTaskTone }}">
        <a href="{{ route('deliveries.index') }}" class="fieldops-mobile-back-inline" aria-label="Back to task list">
            <span aria-hidden="true">←</span>
            <span>Tasks</span>
        </a>
        <div class="fieldops-task-shell-top">
            <div class="fieldops-task-shell-ill" aria-hidden="true">{!! $workflowIllustration('start') !!}</div>
            <div class="fieldops-task-shell-copy">
                <div class="fieldops-task-shell-chips">
                    <span class="status-badge" style="{{ $statusStyle }}">{{ $displayStatusLabel }}</span>
                    <span class="status-badge" style="background:#eff6ff;color:#1d4ed8;">{{ ucfirst($delivery->type) }}</span>
                </div>
                <strong>{{ ucfirst($delivery->type) }} #{{ $delivery->id }}</strong>
                <p>{{ $delivery->type === 'pickup' ? 'Pickup proof flow with GPS, photos, condition, sign-off, and final review.' : 'Delivery proof flow with GPS, photos, sign-off, and final review.' }}</p>
            </div>
        </div>

        <div class="fieldops-task-meta-grid">
            <div class="fieldops-task-meta">
                <span>Customer</span>
                <strong>{{ $linkedCustomerName ?: 'Customer' }}</strong>
            </div>
            <div class="fieldops-task-meta">
                <span>Scheduled</span>
                <strong>{{ $delivery->scheduled_at ? $delivery->scheduled_at->format('d M h:i A') : 'Not scheduled' }}</strong>
            </div>
            <div class="fieldops-task-meta">
                <span>Product</span>
                <strong>{{ $taskProductLabel }}</strong>
            </div>
            <div class="fieldops-task-meta">
                <span>Warehouse</span>
                <strong>{{ $taskWarehouseLabel }}</strong>
            </div>
            <div class="fieldops-task-meta is-wide">
                <span>Service Address</span>
                <strong>{{ collect([$linkedAddress, $linkedCity])->filter()->join(', ') ?: 'No service address captured.' }}</strong>
            </div>
        </div>

        <div class="fieldops-icon-actions" aria-label="Delivery quick actions">
            @if($linkedPhone)
                <a href="tel:{{ preg_replace('/\D+/', '', $linkedPhone) }}" class="fieldops-icon-action" aria-label="Call contact">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.2 19.2 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7l.5 3a2 2 0 0 1-.6 1.8l-1.3 1.3a16 16 0 0 0 6 6l1.3-1.3a2 2 0 0 1 1.8-.6l3 .5A2 2 0 0 1 22 16.9Z"/></svg>
                    <span>Call</span>
                </a>
            @endif
            @if($linkedWhatsapp)
                <a href="https://wa.me/{{ preg_replace('/\D+/', '', $linkedWhatsapp) }}" target="_blank" rel="noopener noreferrer" class="fieldops-icon-action" aria-label="WhatsApp contact">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20 11.4c0 4.7-3.9 8.6-8.8 8.6-1.5 0-3-.4-4.2-1.1L3 20l1.2-3.7A8.4 8.4 0 0 1 2.4 11.4C2.4 6.7 6.3 3 11.2 3 16.1 3 20 6.7 20 11.4Zm-4.8 2.2c-.2-.1-1.2-.6-1.4-.7s-.3-.1-.4.1-.5.7-.7.9-.3.2-.5.1a5.9 5.9 0 0 1-1.7-1c-.6-.5-1-1.2-1.1-1.4-.1-.2 0-.3.1-.4l.3-.4.2-.3v-.4c0-.1-.4-1.1-.6-1.6-.2-.4-.3-.4-.4-.4h-.4c-.1 0-.4 0-.6.3-.2.2-.8.8-.8 1.9s.8 2.1 1 2.3c.1.1 1.5 2.3 3.8 3.2.5.2 1 .4 1.3.5.6.2 1.2.2 1.7.1.5-.1 1.2-.5 1.4-1 .2-.5.2-1 .1-1Z"/></svg>
                    <span>WhatsApp</span>
                </a>
            @endif
            @if($linkedMapUrl)
                <a href="{{ $linkedMapUrl }}" target="_blank" rel="noopener" class="fieldops-icon-action" aria-label="Open map">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-5.1 7-11a7 7 0 1 0-14 0c0 5.9 7 11 7 11Z"/><circle cx="12" cy="10" r="2.5"/></svg>
                    <span>Navigate</span>
                </a>
            @endif
            <a href="#activity-timeline" class="fieldops-icon-action desktop-note-action" aria-label="Add note">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.85" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg>
                <span>Note</span>
            </a>
            <details class="mobile-actions-menu">
                <summary aria-label="More task actions">More</summary>
                <div class="mobile-actions-panel">
                    <a href="{{ route('deliveries.index') }}">Back to Tasks</a>
                    @if($delivery->rental_id)
                        <a href="{{ route('rentals.show', $delivery->rental_id) }}">View Rental</a>
                    @elseif($delivery->sale_id)
                        <a href="{{ route('sales.show', $delivery->sale_id) }}">View Sale</a>
                    @endif
                    @if($hasProofHistory)
                        <a href="{{ route('deliveries.show', $delivery) }}#delivery-proof-history" data-open-proof-history>Proof History</a>
                    @endif
                    <a href="#activity-timeline">Operations History</a>
                    @if($canUpdateTask)
                        <a href="{{ route('deliveries.edit', $delivery) }}">Edit Assignment</a>
                    @endif
                    @if($canCancelTask)
                        <a href="#{{ $cancellationSectionId }}" class="is-danger">Unable to complete</a>
                    @endif
                </div>
            </details>
        </div>

        <div class="fieldops-primary-bar">
            @if((!$workflowCompleted && $canUpdateTask && in_array($delivery->status, ['pending', 'in_progress'], true)) || $hasProofHistory)
                <a
                    href="{{ (!$workflowCompleted && $canUpdateTask) ? '#' . $workflowProofSectionId : route('deliveries.show', $delivery) . '#delivery-proof-history' }}"
                    class="detail-btn"
                    @if($workflowCompleted || !(!$workflowCompleted && $canUpdateTask)) data-open-proof-history @endif
                >
                    {{ (!$workflowCompleted && $canUpdateTask) ? $primaryWorkflowCtaLabel : 'View Proof History' }}
                </a>
            @endif
            <span class="fieldops-primary-note">{{ $delivery->type === 'pickup' ? 'Proof must be complete before pickup can close.' : 'Proof must be complete before delivery can close.' }}</span>
        </div>
    </div>

    <div class="detail-card fieldops-overview-card">
        <div class="fieldops-overview-head">
            <div>
                <span class="status-badge" style="{{ $statusStyle }}">{{ $displayStatusLabel }}</span>
                <h2>{{ ucfirst($delivery->type) }} overview</h2>
                <p>{{ $delivery->type === 'pickup' ? 'Review contact, route, and proof steps before pickup.' : 'Review contact, route, and proof steps before delivery.' }}</p>
            </div>
            @if($referencePartnerName)
                <span class="fieldops-reference-note">Reminder contact: {{ $referencePartnerName }}</span>
            @endif
        </div>
        <div class="fieldops-kv-grid">
            <div class="fieldops-kv">
                <span class="fieldops-kv-label">Status</span>
                <span class="fieldops-kv-value">{{ $displayStatusLabel }}</span>
            </div>
            <div class="fieldops-kv">
                <span class="fieldops-kv-label">Type</span>
                <span class="fieldops-kv-value">{{ ucfirst($delivery->type) }}</span>
            </div>
            <div class="fieldops-kv">
                <span class="fieldops-kv-label">Scheduled</span>
                <span class="fieldops-kv-value">{{ $delivery->scheduled_at ? $delivery->scheduled_at->format('d M h:i A') : 'Not scheduled' }}</span>
            </div>
            <div class="fieldops-kv">
                <span class="fieldops-kv-label">{{ $orderReferenceLabel }}</span>
                <span class="fieldops-kv-value">{{ $orderReferenceId ? '#' . $orderReferenceId : 'Not linked' }}</span>
            </div>
            <div class="fieldops-kv">
                <span class="fieldops-kv-label">{{ $delivery->type === 'pickup' ? 'Pickup From' : 'Customer' }}</span>
                <span class="fieldops-kv-value">{{ $linkedCustomerName ?: 'Customer' }}</span>
            </div>
            <div class="fieldops-kv">
                <span class="fieldops-kv-label">Phone</span>
                <span class="fieldops-kv-value">{{ $linkedPhone ?: 'No phone saved' }}</span>
            </div>
            <div class="fieldops-kv">
                <span class="fieldops-kv-label">Product</span>
                <span class="fieldops-kv-value">{{ $taskProductLabel }}</span>
            </div>
            <div class="fieldops-kv">
                <span class="fieldops-kv-label">Warehouse</span>
                <span class="fieldops-kv-value">{{ $taskWarehouseLabel }}</span>
            </div>
            <div class="fieldops-kv is-wide">
                <span class="fieldops-kv-label">Service Address</span>
                <span class="fieldops-kv-value">{{ collect([$linkedAddress, $linkedCity])->filter()->join(', ') ?: 'No service address captured.' }}</span>
            </div>
        </div>
    </div>

    @if(!$isSaleTask)
    <div class="detail-card">
        <div data-workflow-shell-intro style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
            <div>
                <h2 style="margin:0;">Item Progress</h2>
                <div style="margin-top:6px; color:#64748b; font-size:12px;">Clear counts for each rental item.</div>
            </div>
        </div>
        <div class="progress-card-grid" style="margin-top:14px;">
            @foreach($rentalItems as $item)
                @php
                    $orderedQty = (int) ($item->ordered_quantity ?? $item->quantity ?? 0);
                    $deliveredQty = (int) ($item->delivered_quantity_value ?? min(max((int) ($item->delivered_quantity ?? 0), 0), $orderedQty));
                    $deliveryReopenedDisplayFallback = $delivery->type === 'delivery'
                        && $delivery->status === 'pending'
                        && is_null($delivery->completed_at)
                        && in_array($delivery->rental?->deliveryStatus(), ['completed', 'delivered'], true)
                        && $orderedQty > 0
                        && $deliveredQty >= $orderedQty;

                    if ($deliveryReopenedDisplayFallback) {
                        $deliveredQty = 0;
                    }

                    $deliveryCompletionFallback = $delivery->type === 'delivery'
                        && in_array($displayStatus, ['delivered', 'completed'], true)
                        && $orderedQty > 0
                        && $deliveredQty <= 0;

                    if ($deliveryCompletionFallback) {
                        $deliveredQty = $orderedQty;
                    }

                    $pendingDeliveryQty = max($orderedQty - $deliveredQty, 0);
                    $returnedQty = (int) ($item->returned_quantity_value ?? min(max((int) ($item->returned_quantity ?? 0), 0), $deliveredQty));
                    $pickupCompletionFallback = $delivery->type === 'pickup'
                        && in_array($displayStatus, ['picked_up', 'completed'], true)
                        && $deliveredQty > 0
                        && $returnedQty <= 0;

                    if ($pickupCompletionFallback) {
                        $returnedQty = $deliveredQty;
                    }

                    if ($deliveryReopenedDisplayFallback) {
                        $returnedQty = 0;
                    }

                    $pendingPickupQty = max($deliveredQty - $returnedQty, 0);
                    $linkedAssetIds = collect($item->asset_ids ?? [])
                        ->filter(fn ($assetId) => filled($assetId))
                        ->map(fn ($assetId) => (int) $assetId)
                        ->filter(fn ($assetId) => $assetId > 0)
                        ->values();
                    $hasAwaitingVerificationAsset = $linkedAssetIds->isNotEmpty()
                        && \App\Models\Asset::query()
                            ->where('organization_id', $delivery->organization_id)
                            ->whereIn('id', $linkedAssetIds->all())
                            ->where('asset_status', \App\Models\Asset::STATUS_AWAITING_VERIFICATION)
                            ->exists();
                    $itemPrimaryStatus = $delivery->type === 'pickup'
                        ? ($pendingPickupQty > 0
                            ? ($returnedQty > 0 ? 'partially_returned' : 'pickup_pending')
                            : ($returnedQty > 0 ? 'picked_up' : 'pickup_pending'))
                        : ($pendingDeliveryQty > 0
                            ? ($deliveredQty > 0 ? 'partially_delivered' : 'delivery_pending')
                            : ($deliveredQty > 0 ? 'delivered' : 'delivery_pending'));
                    $itemLifecycleStatuses = collect();

                    if ($delivery->type === 'delivery') {
                        if ($deliveredQty > $returnedQty && $deliveredQty > 0) {
                            $itemLifecycleStatuses->push('with_customer');
                        }

                        if ($pendingPickupQty > 0) {
                            $itemLifecycleStatuses->push(!$pickupAssigned ? 'pickup_not_assigned' : 'pickup_pending');
                        } elseif ($deliveredQty > 0 && $returnedQty === $deliveredQty) {
                            $itemLifecycleStatuses->push($hasAwaitingVerificationAsset ? 'awaiting_verification' : 'returned');
                        }
                    } elseif ($pendingPickupQty <= 0 && $returnedQty > 0 && $hasAwaitingVerificationAsset) {
                        $itemLifecycleStatuses->push('awaiting_verification');
                    }

                    $itemLifecycleStatuses = $itemLifecycleStatuses
                        ->reject(fn ($status) => $status === $itemPrimaryStatus)
                        ->unique()
                        ->values();
                    $itemActionCopy = $delivery->type === 'pickup'
                        ? ($pendingPickupQty > 0
                            ? (!$pickupAssigned ? 'Pickup not assigned yet' : ($returnedQty > 0 ? 'Pending pickup' : 'Pending pickup'))
                            : ($hasAwaitingVerificationAsset ? 'Awaiting verification' : 'Pickup completed'))
                        : ($pendingDeliveryQty > 0
                            ? ($deliveredQty > 0 ? 'Pending delivery' : 'Pending delivery')
                            : 'Delivery completed');
                @endphp
                <div class="progress-card">
                    <div class="progress-card-head">
                        <strong>{{ $item->product->name ?? $delivery->rental?->product?->name ?? 'Rental item' }}</strong>
                        @if(!empty($item->notes))
                            <small>{{ $item->notes }}</small>
                        @endif
                    </div>
                    <div class="progress-metric-grid">
                        <div class="progress-metric"><span>{{ $delivery->type === 'pickup' ? 'Issued' : 'Ordered' }}</span><strong>{{ $orderedQty }}</strong></div>
                        <div class="progress-metric"><span>Delivered</span><strong>{{ $deliveredQty }}</strong></div>
                        <div class="progress-metric"><span>{{ $delivery->type === 'pickup' ? 'Returned' : 'Picked Up' }}</span><strong>{{ $returnedQty }}</strong></div>
                        <div class="progress-metric"><span>Pending</span><strong>{{ $delivery->type === 'pickup' ? $pendingPickupQty : $pendingDeliveryQty }}</strong></div>
                    </div>
                    <div class="progress-status-row">
                        <span class="status-badge" style="{{ $itemProgressBadge($itemPrimaryStatus) }}">{{ $itemProgressLabel($itemPrimaryStatus) }}</span>
                        @foreach($itemLifecycleStatuses as $itemLifecycleStatus)
                            <span class="status-badge" style="{{ $itemProgressBadge($itemLifecycleStatus) }}">{{ $itemProgressLabel($itemLifecycleStatus) }}</span>
                        @endforeach
                    </div>
                    @if($canUpdateTask && $delivery->type === 'delivery' && in_array($delivery->status, ['pending', 'in_progress'], true) && $pendingDeliveryQty > 0)
                        <form method="POST" action="{{ route('deliveries.partial_delivery', $delivery) }}" class="item-progress-form">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="rental_item_id" value="{{ $item->id }}">
                            <input type="number" name="quantity" min="1" max="{{ $pendingDeliveryQty }}" value="1" aria-label="Deliver quantity">
                            <button type="submit" class="detail-btn">Deliver</button>
                        </form>
                    @elseif($canUpdateTask && $delivery->type === 'pickup' && in_array($delivery->status, ['pending', 'in_progress'], true) && $pendingPickupQty > 0)
                        <form method="POST" action="{{ route('deliveries.partial_pickup', $delivery) }}" class="item-progress-form">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="rental_item_id" value="{{ $item->id }}">
                            <input type="number" name="quantity" min="1" max="{{ $pendingPickupQty }}" value="1" aria-label="Pickup quantity">
                            <button type="submit" class="detail-btn">Pickup</button>
                        </form>
                    @else
                        <span class="progress-action-copy">{{ $itemActionCopy }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
    @endif

    <div class="detail-card workflow-proof-card workflow-mobile-shell" id="{{ $workflowProofSectionId }}" data-mobile-workflow-shell data-mobile-shell-state="closed">
        <div class="workflow-mobile-shell-head">
            <div>
                <strong>{{ $delivery->type === 'pickup' ? 'Pickup Workflow' : 'Delivery Workflow' }}</strong>
                <span>One step at a time. Finish proof, then complete.</span>
            </div>
            <button type="button" class="workflow-mobile-shell-close" data-close-mobile-workflow aria-label="Close workflow">×</button>
        </div>
        <div class="workflow-mobile-shell-body">
        <div style="display:flex; justify-content:space-between; gap:12px; flex-wrap:wrap; align-items:flex-start;">
            <div>
                <h2 style="margin:0;">{{ $delivery->type === 'pickup' ? 'Pickup Workflow' : 'Delivery Workflow' }}</h2>
                <div style="margin-top:6px; color:#64748b; font-size:12px;">Finish each step before completion.</div>
            </div>
            <div class="workflow-proof-badges">
                <span class="workflow-proof-badge">Target image size {{ $proofConfig['target_kb'] }} KB</span>
                <span class="workflow-proof-badge">Max image size {{ $proofConfig['max_kb'] }} KB</span>
                <span class="workflow-proof-badge">Max dimension {{ $proofConfig['max_dimension'] }} px</span>
            </div>
        </div>
        <div class="desktop-workflow-progress" aria-label="Workflow progress">
            <span>Item</span>
            <span>Photo</span>
            <span>Location</span>
            <span>Signature</span>
            <span>Review</span>
        </div>
        @if($hasWorkflowErrors)
            <div class="workflow-proof-status is-warning" data-workflow-error-summary tabindex="-1">
                Please complete the required proof fields marked below before continuing this {{ $delivery->type }} task.
            </div>
        @endif

        @if($canUpdateTask && $delivery->status === 'pending')
            <form action="{{ route('deliveries.in_progress', $delivery) }}" method="POST" class="workflow-proof-card workflow-start-shell" data-workflow-form="start">
                @csrf
                @method('PUT')
                <input type="hidden" name="workflow_capture_form" value="1">
                <div class="workflow-mobile-stepper" data-mobile-workflow-stepper data-workflow-initial-step="{{ $hasWorkflowErrors ? 2 : 1 }}" data-workflow-step-count="2">
                    <div class="workflow-mobile-stepper-bar" aria-label="Start workflow steps">
                        <button type="button" class="workflow-mobile-stepper-tab" data-workflow-step-tab="1">
                            <span class="workflow-mobile-stepper-tab-index">1</span>
                            <span>Start</span>
                        </button>
                        <button type="button" class="workflow-mobile-stepper-tab" data-workflow-step-tab="2">
                            <span class="workflow-mobile-stepper-tab-index">2</span>
                            <span>GPS</span>
                        </button>
                    </div>
                    <div class="workflow-step-list">
                        <section class="workflow-step" data-workflow-step-panel data-workflow-step-index="1">
                            <div class="workflow-step-head">
                                <span class="workflow-step-index">1</span>
                                <div class="workflow-step-copy">
                                    <span class="workflow-step-counter">Step 1 of 2</span>
                                    <strong>{{ $delivery->type === 'pickup' ? 'Ready to start pickup?' : 'Ready to start delivery?' }}</strong>
                                    <p>{{ $delivery->type === 'pickup' ? 'Confirm the pickup, then move into proof capture.' : 'Confirm the delivery, then move into proof capture.' }}</p>
                                </div>
                            </div>
                            <section class="workflow-start-hero">
                                <div class="workflow-start-illustration" aria-hidden="true">{!! $workflowIllustration('start') !!}</div>
                                <div class="workflow-start-copy">
                                    <span class="workflow-step-counter">{{ ucfirst($delivery->type) }} task</span>
                                    <strong>{{ $delivery->type === 'pickup' ? 'Pickup is assigned and ready.' : 'Delivery is assigned and ready.' }}</strong>
                                    <p>Review the task, then continue to location capture.</p>
                                </div>
                            </section>
                            <div class="workflow-mini-summary">
                                <div class="workflow-mini-summary-item">
                                    <span>Contact</span>
                                    <strong>{{ $linkedCustomerName ?: 'Customer' }}</strong>
                                </div>
                                <div class="workflow-mini-summary-item">
                                    <span>Phone</span>
                                    <strong>{{ $linkedPhone ?: 'No phone saved' }}</strong>
                                </div>
                                <div class="workflow-mini-summary-item">
                                    <span>Product</span>
                                    <strong>{{ $taskProductLabel }}</strong>
                                </div>
                                <div class="workflow-mini-summary-item">
                                    <span>Scheduled</span>
                                    <strong>{{ $delivery->scheduled_at ? $delivery->scheduled_at->format('d M h:i A') : 'Not scheduled' }}</strong>
                                </div>
                            </div>
                            <div class="workflow-step-actions workflow-step-actions--stacked">
                                <span class="workflow-step-helper">Start first, then capture GPS.</span>
                                <button type="button" class="detail-btn" data-workflow-next>{{ $delivery->type === 'pickup' ? 'Start Pickup' : 'Start Delivery' }}</button>
                            </div>
                        </section>

                        <section class="workflow-step" data-workflow-step-panel data-workflow-step-index="2">
                            <div class="workflow-step-head">
                                <span class="workflow-step-index">2</span>
                                <div class="workflow-step-copy">
                                    <span class="workflow-step-counter">Step 2 of 2</span>
                                    <strong>Capture GPS</strong>
                                    <p>GPS required or reason needed.</p>
                                </div>
                            </div>
                            <div class="workflow-step-hero">
                                <div class="workflow-step-hero-icon" aria-hidden="true">{!! $workflowIllustration('gps') !!}</div>
                                <div class="workflow-step-hero-copy">
                                    <strong>Capture current location</strong>
                                    <span>Tap once, then continue when GPS is ready.</span>
                                </div>
                            </div>
                            <div class="workflow-proof-grid">
                                <div class="workflow-proof-field span-12">
                                    <label>Location Proof</label>
                                    <div class="workflow-proof-actions">
                                        <button type="button" class="workflow-proof-trigger is-primary" data-capture-location>Capture Current Location</button>
                                        <button type="button" class="workflow-proof-trigger" data-recapture-location hidden>Re-capture Location</button>
                                        <button type="button" class="workflow-proof-trigger" data-clear-location hidden>Clear Location</button>
                                    </div>
                                    <div class="workflow-proof-status" data-location-status>Location not captured yet.</div>
                                </div>
                                <div class="workflow-proof-field">
                                    <label for="location_missing_reason_start">Location unavailable reason</label>
                                    <textarea id="location_missing_reason_start" name="location_missing_reason" placeholder="Add reason if GPS is unavailable.">{{ $defaultWorkflowLocationReason }}</textarea>
                                    @error('location_missing_reason')
                                        <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                    @enderror
                                </div>
                                <details class="workflow-proof-field workflow-coordinate-details">
                                    <summary>Technical GPS details</summary>
                                    <div class="workflow-proof-status">
                                        <div>Lat: <span data-location-lat-preview>{{ $defaultWorkflowLatitude !== '' ? $defaultWorkflowLatitude : '-' }}</span></div>
                                        <div>Lng: <span data-location-lng-preview>{{ $defaultWorkflowLongitude !== '' ? $defaultWorkflowLongitude : '-' }}</span></div>
                                        <div>Accuracy: <span data-location-accuracy-preview>{{ $defaultWorkflowAccuracy !== '' ? $defaultWorkflowAccuracy : '-' }}</span></div>
                                        <div>Captured: <span data-location-captured-preview>{{ $defaultWorkflowCapturedAt ?: '-' }}</span></div>
                                    </div>
                                </details>
                            </div>
                            <div class="workflow-step-actions">
                                <button type="button" class="detail-btn-secondary" data-workflow-back>Back</button>
                                <button type="button" class="detail-btn" data-workflow-next data-workflow-submit-on-next>Save GPS</button>
                            </div>
                        </section>
                    </div>
                </div>
                <input type="hidden" name="location_latitude" value="{{ $defaultWorkflowLatitude }}" data-location-latitude>
                <input type="hidden" name="location_longitude" value="{{ $defaultWorkflowLongitude }}" data-location-longitude>
                <input type="hidden" name="location_accuracy" value="{{ $defaultWorkflowAccuracy }}" data-location-accuracy>
                <input type="hidden" name="location_captured_at" value="{{ $defaultWorkflowCapturedAt }}" data-location-captured-at>
            </form>
        @elseif($canUpdateTask && $delivery->status === 'in_progress')
            <form action="{{ route('deliveries.complete', $delivery) }}" method="POST" enctype="multipart/form-data" class="workflow-proof-card" data-workflow-form="complete" data-workflow-review-form data-workflow-type="{{ $delivery->type }}">
                @csrf
                @method('PUT')
                <input type="hidden" name="workflow_capture_form" value="1">
                @if((!$isSaleTask && $delivery->type === 'delivery' && $delivery->rental?->pendingDeliveryQuantityTotal() > 0) || (!$isSaleTask && $delivery->type === 'pickup' && $delivery->rental?->pendingPickupQuantityTotal() > 0))
                    <input type="hidden" name="confirm_partial" value="1">
                @endif
                <div class="workflow-mobile-stepper" data-mobile-workflow-stepper data-workflow-initial-step="{{ $initialCompletionStep }}" data-workflow-step-count="{{ $workflowStepCount }}">
                    <div class="workflow-mobile-stepper-bar" aria-label="Workflow steps">
                        @foreach($completionSteps as $step)
                            <button type="button" class="workflow-mobile-stepper-tab" data-workflow-step-tab="{{ $step['index'] }}">
                                <span class="workflow-mobile-stepper-tab-index">{{ $step['index'] + $workflowDisplayOffset }}</span>
                                <span>{{ $step['label'] }}</span>
                            </button>
                        @endforeach
                    </div>
                    <div class="workflow-step-list">
                    <section class="workflow-step" data-workflow-step-panel data-workflow-step-index="1">
                        <div class="workflow-step-head">
                            <span class="workflow-step-index">{{ 1 + $workflowDisplayOffset }}</span>
                            <div>
                                <div class="workflow-step-copy">
                                    <span class="workflow-step-counter">Step {{ 1 + $workflowDisplayOffset }} of {{ $workflowDisplayStepCount }}</span>
                                    <strong>Capture GPS</strong>
                                    <p>Capture GPS or add a reason.</p>
                                </div>
                            </div>
                        </div>
                        <div class="workflow-step-hero">
                            <div class="workflow-step-hero-icon" aria-hidden="true">{!! $workflowIllustration('gps') !!}</div>
                            <div class="workflow-step-hero-copy">
                                <strong>Location first</strong>
                                <span>GPS is required, or add a short reason if it is unavailable.</span>
                            </div>
                        </div>
                        <div class="workflow-proof-grid">
                            <div class="workflow-proof-field span-12">
                                <label>{{ ucfirst($delivery->type) }} location</label>
                                <div class="workflow-proof-actions">
                                    <button type="button" class="workflow-proof-trigger is-primary" data-capture-location>Capture Current Location</button>
                                    <button type="button" class="workflow-proof-trigger" data-recapture-location hidden>Re-capture Location</button>
                                    <button type="button" class="workflow-proof-trigger" data-clear-location hidden>Clear Location</button>
                                </div>
                                <div class="workflow-proof-status" data-location-status>Location not captured yet.</div>
                            </div>
                            <div class="workflow-proof-field">
                                <label for="location_missing_reason_complete">Location unavailable reason</label>
                                <textarea id="location_missing_reason_complete" name="location_missing_reason" placeholder="Add reason if GPS is unavailable.">{{ $defaultWorkflowLocationReason }}</textarea>
                                @error('location_missing_reason')
                                    <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                @enderror
                            </div>
                            <details class="workflow-proof-field workflow-coordinate-details">
                                <summary>Technical GPS details</summary>
                                <div class="workflow-proof-status">
                                    <div>Lat: <span data-location-lat-preview>{{ $defaultWorkflowLatitude !== '' ? $defaultWorkflowLatitude : '-' }}</span></div>
                                    <div>Lng: <span data-location-lng-preview>{{ $defaultWorkflowLongitude !== '' ? $defaultWorkflowLongitude : '-' }}</span></div>
                                    <div>Accuracy: <span data-location-accuracy-preview>{{ $defaultWorkflowAccuracy !== '' ? $defaultWorkflowAccuracy : '-' }}</span></div>
                                    <div>Captured: <span data-location-captured-preview>{{ $defaultWorkflowCapturedAt ?: '-' }}</span></div>
                                </div>
                            </details>
                        </div>
                        <div class="workflow-step-actions">
                            <span class="workflow-step-helper">GPS required or reason needed.</span>
                            <button type="button" class="detail-btn" data-workflow-next>Continue to Photos</button>
                        </div>
                    </section>

                    <section class="workflow-step" data-workflow-step-panel data-workflow-step-index="2">
                        <div class="workflow-step-head">
                            <span class="workflow-step-index">{{ 2 + $workflowDisplayOffset }}</span>
                            <div>
                                <div class="workflow-step-copy">
                                    <span class="workflow-step-counter">Step {{ 2 + $workflowDisplayOffset }} of {{ $workflowDisplayStepCount }}</span>
                                    <strong>Capture Photos</strong>
                                    <p>{{ $delivery->type === 'pickup' ? 'Capture item and accessory proof.' : 'Capture device and location proof.' }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="workflow-step-hero">
                            <div class="workflow-step-hero-icon" aria-hidden="true">{!! $workflowIllustration('photos') !!}</div>
                            <div class="workflow-step-hero-copy">
                                <strong>Camera-first proof</strong>
                                <span>{{ $delivery->type === 'pickup' ? 'Take clear pickup photos before you move on.' : 'Add the item and on-site proof photos.' }}</span>
                            </div>
                        </div>
                        <div class="workflow-camera-grid">
                    @if($delivery->type === 'delivery')
                        <label class="workflow-camera-card" for="delivery_device_photos">
                            <span class="workflow-camera-icon" aria-hidden="true">CAM</span>
                            <div class="workflow-camera-meta">
                                <strong>Product Photo</strong>
                                <span>Add at least one product photo.</span>
                            </div>
                            <div class="workflow-camera-footer">
                                <span class="workflow-camera-chip">Required</span>
                                <span class="workflow-camera-trigger">Capture / Upload</span>
                            </div>
                            <div class="workflow-camera-status" data-file-status>Waiting for upload</div>
                            <div class="workflow-camera-preview" id="delivery-device-preview"></div>
                            <input id="delivery_device_photos" type="file" name="delivery_device_photos[]" accept="image/*" capture="environment" multiple data-compress-images data-review-source="delivery_device_photos" data-preview-target="delivery-device-preview">
                        </label>
                        <label class="workflow-camera-card" for="premises_photo">
                            <span class="workflow-camera-icon" aria-hidden="true">LOC</span>
                            <div class="workflow-camera-meta">
                                <strong>Delivery / Installation</strong>
                                <span>Add one on-site proof photo.</span>
                            </div>
                            <div class="workflow-camera-footer">
                                <span class="workflow-camera-chip">Required</span>
                                <span class="workflow-camera-trigger">Capture / Upload</span>
                            </div>
                            <div class="workflow-camera-status" data-file-status>Waiting for upload</div>
                            <div class="workflow-camera-preview" id="premises-preview"></div>
                            <input id="premises_photo" type="file" name="premises_photo" accept="image/*" capture="environment" data-compress-images data-review-source="premises_photo" data-preview-target="premises-preview">
                        </label>
                        <label class="workflow-camera-card" for="delivery_extra_photos">
                            <span class="workflow-camera-icon" aria-hidden="true">ADD</span>
                            <div class="workflow-camera-meta">
                                <strong>Extra Photo</strong>
                                <span>Add one more proof image if needed.</span>
                            </div>
                            <div class="workflow-camera-footer">
                                <span class="workflow-camera-chip is-optional">Optional</span>
                                <span class="workflow-camera-trigger">Capture / Upload</span>
                            </div>
                            <div class="workflow-camera-status" data-file-status>Optional</div>
                            <div class="workflow-camera-preview" id="delivery-extra-preview"></div>
                            <input id="delivery_extra_photos" type="file" name="delivery_extra_photos[]" accept="image/*" capture="environment" multiple data-compress-images data-preview-target="delivery-extra-preview">
                        </label>
                    @else
                        <label class="workflow-camera-card" for="pickup_device_photos">
                            <span class="workflow-camera-icon" aria-hidden="true">CAM</span>
                            <div class="workflow-camera-meta">
                                <strong>Product Photo</strong>
                                <span>Add at least one pickup photo.</span>
                            </div>
                            <div class="workflow-camera-footer">
                                <span class="workflow-camera-chip">Required</span>
                                <span class="workflow-camera-trigger">Capture / Upload</span>
                            </div>
                            <div class="workflow-camera-status" data-file-status>Waiting for upload</div>
                            <div class="workflow-camera-preview" id="pickup-device-preview"></div>
                            <input id="pickup_device_photos" type="file" name="pickup_device_photos[]" accept="image/*" capture="environment" multiple data-compress-images data-review-source="pickup_device_photos" data-preview-target="pickup-device-preview">
                        </label>
                        <label class="workflow-camera-card" for="damage_photos">
                            <span class="workflow-camera-icon" aria-hidden="true">PRF</span>
                            <div class="workflow-camera-meta">
                                <strong>Accessories / Parts</strong>
                                <span>Add if accessories are missing or damage is visible.</span>
                            </div>
                            <div class="workflow-camera-footer">
                                <span class="workflow-camera-chip is-optional">Optional</span>
                                <span class="workflow-camera-trigger">Capture / Upload</span>
                            </div>
                            <div class="workflow-camera-status" data-file-status>Optional</div>
                            <div class="workflow-camera-preview" id="pickup-damage-preview"></div>
                            <input id="damage_photos" type="file" name="damage_photos[]" accept="image/*" capture="environment" multiple data-compress-images data-review-source="damage_photos" data-preview-target="pickup-damage-preview">
                        </label>
                        <label class="workflow-camera-card" for="pickup_extra_photos">
                            <span class="workflow-camera-icon" aria-hidden="true">ADD</span>
                            <div class="workflow-camera-meta">
                                <strong>Extra Photo</strong>
                                <span>Add one more proof image if needed.</span>
                            </div>
                            <div class="workflow-camera-footer">
                                <span class="workflow-camera-chip is-optional">Optional</span>
                                <span class="workflow-camera-trigger">Capture / Upload</span>
                            </div>
                            <div class="workflow-camera-status" data-file-status>Optional</div>
                            <div class="workflow-camera-preview" id="pickup-extra-preview"></div>
                            <input id="pickup_extra_photos" type="file" name="pickup_extra_photos[]" accept="image/*" capture="environment" multiple data-compress-images data-preview-target="pickup-extra-preview">
                        </label>
                    @endif
                        </div>
                        <div class="workflow-proof-grid">
                            <div class="workflow-proof-field span-12">
                                @error('delivery_device_photos')
                                    <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                @enderror
                                @error('delivery_device_photos.*')
                                    <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                @enderror
                                @error('premises_photo')
                                    <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                @enderror
                                @error('pickup_device_photos')
                                    <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                @enderror
                                @error('pickup_device_photos.*')
                                    <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                @enderror
                                @error('damage_photos')
                                    <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                @enderror
                                @error('damage_photos.*')
                                    <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="workflow-step-actions">
                            <button type="button" class="detail-btn-secondary" data-workflow-back>Back</button>
                            <button type="button" class="detail-btn" data-workflow-next>{{ $delivery->type === 'pickup' ? 'Continue to Check' : 'Continue to Notes' }}</button>
                        </div>
                    </section>

                    @if($delivery->type === 'pickup')
                        <section class="workflow-step" data-workflow-step-panel data-workflow-step-index="3">
                            <div class="workflow-step-head">
                                <span class="workflow-step-index">{{ 3 + $workflowDisplayOffset }}</span>
                                <div>
                                    <div class="workflow-step-copy">
                                        <span class="workflow-step-counter">Step {{ 3 + $workflowDisplayOffset }} of {{ $workflowDisplayStepCount }}</span>
                                        <strong>Condition Check</strong>
                                        <p>Select product condition and accessory status.</p>
                                    </div>
                                </div>
                            </div>
                            <div class="workflow-step-hero">
                                <div class="workflow-step-hero-icon" aria-hidden="true">{!! $workflowIllustration('condition') !!}</div>
                                <div class="workflow-step-hero-copy">
                                    <strong>Mark the item condition</strong>
                                    <span>Choose one clear condition before continuing.</span>
                                </div>
                            </div>
                            <div class="workflow-proof-grid">
                                <div class="workflow-proof-field span-12">
                                    <label for="pickup_condition_choice">Condition status</label>
                                    <input type="hidden" name="pickup_condition_choice" id="pickup_condition_choice" value="{{ $selectedPickupCondition }}" data-pickup-condition-input>
                                    <input type="checkbox" name="damage_reported" value="1" {{ old('damage_reported') ? 'checked' : '' }} hidden data-pickup-damage-toggle>
                                    <div class="workflow-condition-grid" data-pickup-condition-grid>
                                        <button type="button" class="workflow-condition-card" data-pickup-condition="good">
                                            <strong>Good</strong>
                                            <span>No visible issue found.</span>
                                        </button>
                                        <button type="button" class="workflow-condition-card" data-pickup-condition="needs_inspection">
                                            <strong>Needs Inspection</strong>
                                            <span>Return item needs warehouse check.</span>
                                        </button>
                                        <button type="button" class="workflow-condition-card" data-pickup-condition="damaged">
                                            <strong>Damaged</strong>
                                            <span>Damage notes and photos required.</span>
                                        </button>
                                        <button type="button" class="workflow-condition-card" data-pickup-condition="missing_accessories">
                                            <strong>Missing Accessories</strong>
                                            <span>Add short missing item notes.</span>
                                        </button>
                                    </div>
                                    <div class="workflow-proof-help">Pick one condition before moving forward.</div>
                                    @error('damage_reported')
                                        <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                    @enderror
                                </div>
                            </div>
                            <div class="workflow-step-actions">
                                <button type="button" class="detail-btn-secondary" data-workflow-back>Back</button>
                                <button type="button" class="detail-btn" data-workflow-next>Continue to Notes</button>
                            </div>
                        </section>
                    @endif

                    <section class="workflow-step" data-workflow-step-panel data-workflow-step-index="{{ $delivery->type === 'pickup' ? 4 : 3 }}">
                        <div class="workflow-step-head">
                            <span class="workflow-step-index">{{ ($delivery->type === 'pickup' ? 4 : 3) + $workflowDisplayOffset }}</span>
                            <div>
                                <div class="workflow-step-copy">
                                    <span class="workflow-step-counter">Step {{ ($delivery->type === 'pickup' ? 4 : 3) + $workflowDisplayOffset }} of {{ $workflowDisplayStepCount }}</span>
                                    <strong>Notes / Damage</strong>
                                    <p>{{ $delivery->type === 'pickup' ? 'Add damage or missing item notes.' : 'Add short field notes only if needed.' }}</p>
                                </div>
                            </div>
                        </div>
                        <div class="workflow-step-hero">
                            <div class="workflow-step-hero-icon" aria-hidden="true">{!! $workflowIllustration('condition') !!}</div>
                            <div class="workflow-step-hero-copy">
                                <strong>Keep notes short</strong>
                                <span>{{ $delivery->type === 'pickup' ? 'Only add damage or missing item details that matter in the field.' : 'Only add a short operational note when needed.' }}</span>
                            </div>
                        </div>
                        <div class="workflow-proof-grid">
                            @if($delivery->type === 'pickup')
                                <div class="workflow-proof-field">
                                    <label for="damage_notes">Damage notes</label>
                                    <textarea id="damage_notes" name="damage_notes" placeholder="Describe damage or issue.">{{ old('damage_notes') }}</textarea>
                                    @error('damage_notes')
                                        <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="workflow-proof-field">
                                    <label for="missing_accessories_notes">Missing accessories notes</label>
                                    <textarea id="missing_accessories_notes" name="missing_accessories_notes" placeholder="List missing accessories.">{{ old('missing_accessories_notes') }}</textarea>
                                    @error('missing_accessories_notes')
                                        <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                    @enderror
                                </div>
                                <div class="workflow-proof-field span-12">
                                    <label for="proof_notes_pickup">Pickup notes</label>
                                    <textarea id="proof_notes_pickup" name="proof_notes" placeholder="Add short pickup notes only if needed.">{{ old('proof_notes') }}</textarea>
                                    @error('proof_notes')
                                        <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                    @enderror
                                </div>
                            @else
                                <div class="workflow-proof-field span-12">
                                    <label for="proof_notes">Workflow notes</label>
                                    <textarea id="proof_notes" name="proof_notes" placeholder="Add short delivery notes.">{{ old('proof_notes') }}</textarea>
                                    @error('proof_notes')
                                        <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                    @enderror
                                </div>
                            @endif
                        </div>
                        <div class="workflow-step-actions">
                            <button type="button" class="detail-btn-secondary" data-workflow-back>Back</button>
                            <button type="button" class="detail-btn" data-workflow-next>Continue to Signature</button>
                        </div>
                    </section>

                    <section class="workflow-step" data-workflow-step-panel data-workflow-step-index="{{ $delivery->type === 'pickup' ? 5 : 4 }}">
                        <div class="workflow-step-head">
                            <span class="workflow-step-index">{{ ($delivery->type === 'pickup' ? 5 : 4) + $workflowDisplayOffset }}</span>
                            <div>
                                <div class="workflow-step-copy">
                                    <span class="workflow-step-counter">Step {{ ($delivery->type === 'pickup' ? 5 : 4) + $workflowDisplayOffset }} of {{ $workflowDisplayStepCount }}</span>
                                    <strong>Customer Signature</strong>
                                    <p>Capture customer acknowledgement.</p>
                                </div>
                            </div>
                        </div>
                        <div class="workflow-step-hero">
                            <div class="workflow-step-hero-icon" aria-hidden="true">{!! $workflowIllustration('signature') !!}</div>
                            <div class="workflow-step-hero-copy">
                                <strong>Capture acknowledgement</strong>
                                <span>Ask the customer to sign before you finish the task.</span>
                            </div>
                        </div>
                        <div class="workflow-proof-grid">
                            <div class="workflow-proof-field span-12">
                                <label>Acknowledgement</label>
                                <div class="workflow-proof-signature-wrap">
                                    <div class="workflow-proof-help">{{ $acknowledgementText }}</div>
                                    <div class="workflow-proof-help" data-signature-hint>Signature is preferred. If not possible, add a short reason below.</div>
                                    <canvas class="workflow-proof-signature-pad" data-signature-pad data-target-input="signature_data"></canvas>
                                    <input type="hidden" name="signature_data" value="{{ old('signature_data') }}">
                                    <div class="workflow-proof-signature-preview" data-signature-preview-wrap>
                                        <div class="workflow-proof-help"><strong>Saved preview</strong></div>
                                        <img src="" alt="Signature preview" data-signature-preview-image>
                                    </div>
                                    <div class="workflow-proof-actions">
                                        <button type="button" class="workflow-proof-trigger" data-signature-clear>Clear Signature</button>
                                        <div class="workflow-proof-help">Sign with finger or stylus.</div>
                                    </div>
                                    <div class="workflow-signature-unavailable-block">
                                        <label for="signature_unavailable_reason">Unable to sign reason</label>
                                        <textarea id="signature_unavailable_reason" name="signature_unavailable_reason" placeholder="Add reason if the customer could not sign.">{{ old('signature_unavailable_reason') }}</textarea>
                                        @error('signature_data')
                                            <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                        @enderror
                                        @error('signature_unavailable_reason')
                                            <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                        @enderror
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="workflow-step-actions">
                            <button type="button" class="detail-btn-secondary" data-workflow-back>Back</button>
                            <button type="button" class="detail-btn" data-workflow-next>{{ $supportsCollectionStep ? 'Continue to Collection' : ($delivery->type === 'pickup' ? 'Review Pickup' : 'Review Delivery') }}</button>
                        </div>
                    </section>

                    @if($supportsCollectionStep)
                    <section class="workflow-step" data-workflow-step-panel data-workflow-step-index="{{ $delivery->type === 'pickup' ? 6 : 5 }}">
                        <div class="workflow-step-head">
                            <span class="workflow-step-index">{{ ($delivery->type === 'pickup' ? 6 : 5) + $workflowDisplayOffset }}</span>
                            <div>
                                <div class="workflow-step-copy">
                                    <span class="workflow-step-counter">Step {{ ($delivery->type === 'pickup' ? 6 : 5) + $workflowDisplayOffset }} of {{ $workflowDisplayStepCount }}</span>
                                    <strong>Collection</strong>
                                    <p>Collect payment if this task was assigned with collection.</p>
                                </div>
                            </div>
                        </div>
                        <div class="workflow-step-hero">
                            <div class="workflow-step-hero-icon" aria-hidden="true">{!! $workflowIllustration('payment') !!}</div>
                            <div class="workflow-step-hero-copy">
                                <strong>Record collection</strong>
                                <span>Enter the received amount or choose why payment could not be collected.</span>
                            </div>
                        </div>
                        <div class="workflow-proof-grid">
                            <div class="workflow-proof-field">
                                <label>Amount to collect</label>
                                <input type="text" value="{{ $collectionAmountToCollect > 0 ? '₹ ' . number_format($collectionAmountToCollect, 2) : 'As instructed' }}" readonly>
                            </div>
                            <div class="workflow-proof-field">
                                <label for="collection_amount_collected">Amount collected</label>
                                <input id="collection_amount_collected" type="text" name="collection_amount_collected" value="{{ old('collection_amount_collected', $delivery->collection_amount_collected) }}" placeholder="0.00">
                                @error('collection_amount_collected')
                                    <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="workflow-proof-field">
                                <label for="collection_payment_mode">Payment mode</label>
                                <select id="collection_payment_mode" name="collection_payment_mode" style="width:100%; border:1px solid #cbd5e1; border-radius:12px; padding:10px 12px; font-size:13px; color:#0f172a; background:#fff;">
                                    <option value="">Select mode</option>
                                    @foreach($collectionModes as $collectionModeKey => $collectionModeLabel)
                                        <option value="{{ $collectionModeKey }}" @selected(old('collection_payment_mode', $delivery->collection_payment_mode) === $collectionModeKey)>{{ $collectionModeLabel }}</option>
                                    @endforeach
                                </select>
                                @error('collection_payment_mode')
                                    <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="workflow-proof-field">
                                <label for="collection_transaction_reference">Transaction reference</label>
                                <input id="collection_transaction_reference" type="text" name="collection_transaction_reference" value="{{ old('collection_transaction_reference', $delivery->collection_transaction_reference) }}" placeholder="UPI / bank reference">
                                @error('collection_transaction_reference')
                                    <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="workflow-proof-field span-12">
                                <label for="collection_payment_proof">Payment proof</label>
                                <input id="collection_payment_proof" type="file" name="collection_payment_proof" accept="image/*" capture="environment" data-compress-images>
                                @error('collection_payment_proof')
                                    <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="workflow-proof-field">
                                <label for="collection_not_collected_reason">If not collected</label>
                                <select id="collection_not_collected_reason" name="collection_not_collected_reason" style="width:100%; border:1px solid #cbd5e1; border-radius:12px; padding:10px 12px; font-size:13px; color:#0f172a; background:#fff;">
                                    <option value="">Select reason</option>
                                    @foreach($collectionReasonOptions as $reasonKey => $reasonLabel)
                                        <option value="{{ $reasonKey }}" @selected(old('collection_not_collected_reason', $delivery->collection_not_collected_reason) === $reasonKey)>{{ $reasonLabel }}</option>
                                    @endforeach
                                </select>
                                @error('collection_not_collected_reason')
                                    <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="workflow-proof-field">
                                <label for="collection_note">Collection note</label>
                                <textarea id="collection_note" name="collection_note" placeholder="Add a short collection note if needed.">{{ old('collection_note', $delivery->collection_note) }}</textarea>
                                @error('collection_note')
                                    <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>
                        <div class="workflow-step-actions">
                            <button type="button" class="detail-btn-secondary" data-workflow-back>Back</button>
                            <button type="button" class="detail-btn" data-workflow-next>{{ $delivery->type === 'pickup' ? 'Review Pickup' : 'Review Delivery' }}</button>
                        </div>
                    </section>
                    @endif

                    <section class="workflow-step workflow-review-step" data-workflow-step-panel data-workflow-step-index="{{ $delivery->type === 'pickup' ? ($supportsCollectionStep ? 7 : 6) : ($supportsCollectionStep ? 6 : 5) }}">
                        <div class="workflow-step-head">
                            <span class="workflow-step-index">{{ ($delivery->type === 'pickup' ? ($supportsCollectionStep ? 7 : 6) : ($supportsCollectionStep ? 6 : 5)) + $workflowDisplayOffset }}</span>
                            <div>
                                <div class="workflow-step-copy">
                                    <span class="workflow-step-counter">Step {{ ($delivery->type === 'pickup' ? ($supportsCollectionStep ? 7 : 6) : ($supportsCollectionStep ? 6 : 5)) + $workflowDisplayOffset }} of {{ $workflowDisplayStepCount }}</span>
                                    <strong>Review &amp; Complete</strong>
                                    <p>Review required proofs before completion.</p>
                                </div>
                            </div>
                        </div>
                        <div class="workflow-step-hero">
                            <div class="workflow-step-hero-icon" aria-hidden="true">{!! $workflowIllustration('complete') !!}</div>
                            <div class="workflow-step-hero-copy">
                                <strong>Review and finish</strong>
                                <span>Complete only when every required proof is ready.</span>
                            </div>
                        </div>
                        <div class="workflow-review-summary-grid workflow-review-summary-grid--stacked" style="display:block;width:100%;max-width:100%;min-width:0;">
                            <div class="workflow-review-summary-card workflow-review-summary-card--stacked" style="display:block;width:100%;max-width:100%;min-width:0;overflow:hidden;margin-bottom:8px;">
                                <span style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;">Customer</span>
                                <strong style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;text-align:left;">{{ $linkedCustomerName ?: 'Customer' }}</strong>
                            </div>
                            <div class="workflow-review-summary-card workflow-review-summary-card--stacked" style="display:block;width:100%;max-width:100%;min-width:0;overflow:hidden;margin-bottom:8px;">
                                <span style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;">Product</span>
                                <strong style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;text-align:left;">{{ $taskProductLabel }}</strong>
                            </div>
                            @if($delivery->type === 'pickup')
                                <div class="workflow-review-summary-card workflow-review-summary-card--stacked" style="display:block;width:100%;max-width:100%;min-width:0;overflow:hidden;margin-bottom:8px;">
                                    <span style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;">Condition</span>
                                    <strong data-review-summary="condition" style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;text-align:left;">Pending</strong>
                                </div>
                            @endif
                            @if($supportsCollectionStep)
                                <div class="workflow-review-summary-card workflow-review-summary-card--stacked" style="display:block;width:100%;max-width:100%;min-width:0;overflow:hidden;">
                                    <span style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;">Collection</span>
                                    <strong data-review-summary="collection" style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;text-align:left;">Pending</strong>
                                </div>
                            @endif
                        </div>
                        <div class="workflow-review-list workflow-review-list--stacked" style="display:block;width:100%;max-width:100%;min-width:0;">
                            <div class="workflow-review-item workflow-review-item--stacked" data-review-item="location" data-review-ready="false" style="display:block;width:100%;max-width:100%;min-width:0;overflow:hidden;margin-bottom:8px;"><strong style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;">Location</strong><span style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;">GPS captured or reason added.</span><span class="workflow-review-status" style="display:block;writing-mode:horizontal-tb;white-space:normal;">Missing</span></div>
                            <div class="workflow-review-item workflow-review-item--stacked" data-review-item="photos" data-review-ready="false" style="display:block;width:100%;max-width:100%;min-width:0;overflow:hidden;margin-bottom:8px;"><strong style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;">Photos</strong><span style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;">{{ $delivery->type === 'pickup' ? 'Pickup photos ready.' : 'Delivery proof photos ready.' }}</span><span class="workflow-review-status" style="display:block;writing-mode:horizontal-tb;white-space:normal;">Missing</span></div>
                            @if($delivery->type === 'pickup')
                                <div class="workflow-review-item workflow-review-item--stacked" data-review-item="condition" data-review-ready="false" style="display:block;width:100%;max-width:100%;min-width:0;overflow:hidden;margin-bottom:8px;"><strong style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;">Condition</strong><span style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;">Damage and accessories reviewed.</span><span class="workflow-review-status" style="display:block;writing-mode:horizontal-tb;white-space:normal;">Missing</span></div>
                            @endif
                            <div class="workflow-review-item workflow-review-item--stacked" data-review-item="signature" data-review-ready="false" style="display:block;width:100%;max-width:100%;min-width:0;overflow:hidden;margin-bottom:8px;"><strong style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;">Signature</strong><span style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;">Customer acknowledgement captured.</span><span class="workflow-review-status" style="display:block;writing-mode:horizontal-tb;white-space:normal;">Missing</span></div>
                            @if($supportsCollectionStep)
                                <div class="workflow-review-item workflow-review-item--stacked" data-review-item="collection" data-review-ready="false" style="display:block;width:100%;max-width:100%;min-width:0;overflow:hidden;margin-bottom:8px;"><strong style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;">Collection</strong><span style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;">Amount recorded or reason added.</span><span class="workflow-review-status" style="display:block;writing-mode:horizontal-tb;white-space:normal;">Missing</span></div>
                            @endif
                            <div class="workflow-review-item workflow-review-item--stacked" data-review-item="notes" data-review-ready="true" style="display:block;width:100%;max-width:100%;min-width:0;overflow:hidden;"><strong style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;">Notes</strong><span style="display:block;writing-mode:horizontal-tb;white-space:normal;overflow-wrap:anywhere;word-break:break-word;">Only operational notes, no extra narrative.</span><span class="workflow-review-status" style="display:block;writing-mode:horizontal-tb;white-space:normal;">Optional</span></div>
                        </div>
                        <div class="workflow-proof-field span-12">
                            <label style="display:flex; align-items:flex-start; gap:10px; text-transform:none; letter-spacing:0; font-size:12px; color:#0f172a;">
                                <input type="checkbox" name="completion_confirmed" value="1" {{ old('completion_confirmed') ? 'checked' : '' }} style="margin-top:2px;">
                                <span>I confirm the above details are correct and proof has been captured.</span>
                            </label>
                            @error('completion_confirmed')
                                <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                            @enderror
                        </div>
                        <div class="workflow-step-actions">
                            <button type="button" class="detail-btn-secondary" data-workflow-back>Back</button>
                            <button type="submit" class="detail-btn" data-workflow-complete>{{ $delivery->type === 'pickup' ? 'Complete Pickup' : 'Complete Delivery' }}</button>
                        </div>
                        @if($delivery->type === 'pickup')
                            <div class="workflow-proof-help">Returned assets will stay in verification flow until warehouse checks are complete.</div>
                        @endif
                    </section>
                </div>
                </div>
                <input type="hidden" name="location_latitude" value="{{ $defaultWorkflowLatitude }}" data-location-latitude>
                <input type="hidden" name="location_longitude" value="{{ $defaultWorkflowLongitude }}" data-location-longitude>
                <input type="hidden" name="location_accuracy" value="{{ $defaultWorkflowAccuracy }}" data-location-accuracy>
                <input type="hidden" name="location_captured_at" value="{{ $defaultWorkflowCapturedAt }}" data-location-captured-at>
                <div class="workflow-proof-actions" data-workflow-desktop-actions>
                    <button type="submit" class="detail-btn">{{ $delivery->type === 'pickup' ? 'Complete Pickup' : 'Complete Delivery' }}</button>
                    <div class="workflow-proof-help">Required steps only.</div>
                </div>
            </form>
        @elseif(!$canUpdateTask && in_array($delivery->status, ['pending', 'in_progress'], true))
            <div class="workflow-proof-status">You can review proof history, but only the assigned workflow owner can capture proof.</div>
        @endif

        @if($canCancelTask)
            <div class="workflow-proof-divider"></div>

            <details id="{{ $cancellationSectionId }}" class="proof-history-shell" @if($hasCancellationErrors) open @endif>
                <summary>
                    <div class="proof-history-summary">
                        <div>
                            <strong style="display:block; color:#0f172a; font-size:16px;">Unable to complete</strong>
                            <span class="workflow-proof-help">Use this only when the task cannot be finished in the field.</span>
                        </div>
                        <span class="workflow-proof-badge" style="background:#fff7ed; color:#9a3412;">Reason required</span>
                    </div>
                </summary>
                <div class="proof-history-body">
                    <form action="{{ route('deliveries.cancel', $delivery) }}" method="POST" class="workflow-proof-card">
                        @csrf
                        @method('PUT')

                        @if($hasCancellationErrors)
                            <div class="workflow-proof-status is-warning">
                                Select a cancellation reason before cancelling this {{ $delivery->type }} task.
                            </div>
                        @endif

                        <div class="workflow-proof-grid">
                            <div class="workflow-proof-field">
                                <label for="cancellation_reason">Cancellation reason</label>
                                <select id="cancellation_reason" name="cancellation_reason" style="width:100%; border:1px solid #cbd5e1; border-radius:12px; padding:10px 12px; font-size:13px; color:#0f172a; background:#fff;">
                                    <option value="">Select reason</option>
                                    @foreach($cancellationReasonOptions as $reasonValue => $reasonLabel)
                                        <option value="{{ $reasonValue }}" {{ $selectedCancellationReason === $reasonValue ? 'selected' : '' }}>{{ $reasonLabel }}</option>
                                    @endforeach
                                </select>
                                @error('cancellation_reason')
                                    <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                @enderror
                            </div>
                            <div class="workflow-proof-field">
                                <label for="cancellation_notes">Additional remarks</label>
                                <textarea id="cancellation_notes" name="cancellation_notes" placeholder="Add any extra context, especially for Other or reschedule scenarios.">{{ $selectedCancellationNotes }}</textarea>
                                @error('cancellation_notes')
                                    <div class="workflow-proof-help" style="color:#b91c1c;">{{ $message }}</div>
                                @enderror
                            </div>
                        </div>

                        <div class="workflow-proof-actions">
                            <button type="submit" class="detail-btn-secondary" style="background:#fff1f2;border-color:#fecaca;color:#991b1b;">Cancel {{ ucfirst($delivery->type) }}</button>
                            <div class="workflow-proof-help">Cancelled tasks stay in history.</div>
                        </div>
                    </form>
                </div>
            </details>
        @endif

        <div class="workflow-proof-divider"></div>

        <details id="delivery-proof-history" class="proof-history-shell">
            <summary>
                <div class="proof-history-summary">
                    <div>
                        <strong style="display:block; color:#0f172a; font-size:16px;">Proof History</strong>
                        <span class="workflow-proof-help">Latest: {{ $latestProofPreview }}</span>
                    </div>
                    <span class="workflow-proof-badge">{{ $proofHistoryItems->count() }} item{{ $proofHistoryItems->count() === 1 ? '' : 's' }}</span>
                </div>
            </summary>
            <div class="proof-history-body">
            <div class="workflow-proof-history">

            @forelse($proofHistoryItems as $proof)
                @php
                    $proofLabel = \App\Models\DeliveryProof::historyLabelFor($proof);
                    $proofWhen = $proof->captured_at ?: $proof->created_at;
                    $proofMeta = collect($proof->meta ?? [])->filter(fn ($value) => filled($value));
                    $proofUrl = $proof->file_path ? route('deliveries.proofs.view', [$delivery, $proof]) : null;
                    $hasCoordinates = filled($proof->latitude) && filled($proof->longitude);
                    $proofMapUrl = $hasCoordinates
                        ? 'https://www.google.com/maps/search/?api=1&query=' . $proof->latitude . ',' . $proof->longitude
                        : null;
                @endphp
                <article class="workflow-proof-history-item">
                    <div>
                        @if($proofUrl)
                            <img src="{{ $proofUrl }}" alt="{{ $proofLabel }}" class="workflow-proof-history-thumb">
                        @else
                            <div class="workflow-proof-status {{ $hasCoordinates ? 'is-success' : 'is-warning' }}" style="min-height:120px; display:flex; align-items:center; justify-content:center;">
                                {{ $hasCoordinates ? 'GPS' : 'No file' }}
                            </div>
                        @endif
                    </div>
                    <div class="workflow-proof-history-meta">
                        <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap;">
                            <strong>{{ $proofLabel }}</strong>
                            <small>{{ optional($proofWhen)->format('d M Y h:i A') }}</small>
                        </div>
                        <small>{{ ucfirst($proof->workflow_stage) }} {{ $proof->capture_moment ? ucfirst($proof->capture_moment) : 'Record' }} · Uploaded by {{ $proof->creator->name ?? 'System' }}</small>
                        @if($proof->acknowledgement_text)
                            <div class="workflow-proof-help"><strong>Acknowledgement:</strong> {{ $proof->acknowledgement_text }}</div>
                        @endif
                        @if($hasCoordinates)
                            <div class="workflow-proof-help"><strong>Coordinates:</strong> {{ number_format((float) $proof->latitude, 6) }}, {{ number_format((float) $proof->longitude, 6) }}</div>
                            @if(filled($proof->accuracy))
                                <div class="workflow-proof-help"><strong>Accuracy:</strong> {{ number_format((float) $proof->accuracy, 1) }} m</div>
                            @endif
                            <div class="workflow-proof-help"><strong>Captured:</strong> {{ optional($proofWhen)->format('d M Y h:i A') }}</div>
                            <div class="workflow-proof-actions">
                                <a href="{{ $proofMapUrl }}" target="_blank" rel="noopener" class="workflow-proof-trigger">Open Map</a>
                            </div>
                        @elseif($proof->proof_type === \App\Models\DeliveryProof::TYPE_LOCATION)
                            <div class="workflow-proof-help"><strong>Location missing reason:</strong> {{ $proof->notes ?: 'No reason provided' }}</div>
                        @endif
                        @if($proof->notes && $proof->proof_type !== \App\Models\DeliveryProof::TYPE_LOCATION)
                            <div class="workflow-proof-help"><strong>Notes:</strong> {{ $proof->notes }}</div>
                        @endif
                        @if($proofMeta->isNotEmpty())
                            <div class="workflow-proof-help">
                                @foreach($proofMeta as $metaKey => $metaValue)
                                    <div><strong>{{ str($metaKey)->replace('_', ' ')->title() }}:</strong> {{ is_bool($metaValue) ? ($metaValue ? 'Yes' : 'No') : $metaValue }}</div>
                                @endforeach
                            </div>
                        @endif
                        @if($proofUrl)
                            <div class="workflow-proof-actions">
                                <a href="{{ $proofUrl }}" target="_blank" class="workflow-proof-trigger">View Full Size</a>
                            </div>
                        @endif
                    </div>
                </article>
            @empty
                <div class="workflow-proof-status">No proof has been captured for this task yet.</div>
            @endforelse
        </div>
            </div>
        </details>
        </div>
    </div>

    @include('partials.activity-timeline', [
        'logs' => $activityLogs ?? collect(),
        'title' => 'Operations History',
        'subtitle' => 'Assignment, start, completion, and linked order updates for this task.',
    ])

    <details class="desktop-secondary-shell">
        <summary>
            <span>Secondary Information</span>
            <strong>Order, assignee, notes, and linked assets</strong>
        </summary>
    <div class="detail-grid detail-support-grid">
        <div class="detail-card span-6">
            <h2 style="margin-top:0;">{{ $isSaleTask ? 'Sale Order' : 'Rental' }}</h2>
            <div class="detail-grid">
                <div class="span-6">
                    <span class="label">{{ $isSaleTask ? 'Sale' : 'Rental' }}</span>
                    <div class="value">#{{ $isSaleTask ? ($delivery->sale->id ?? '-') : ($delivery->rental->id ?? '-') }}</div>
                </div>
                <div class="span-6">
                    <span class="label">Customer</span>
                    <div class="value">{{ $linkedCustomerName ?: 'N/A' }}</div>
                </div>
                <div class="span-6">
                    <span class="label">Contact Phone</span>
                    <div class="value">{{ $linkedPhone ?: 'N/A' }}</div>
                </div>
                <div class="span-6">
                    <span class="label">Product</span>
                    <div class="value">{{ $isSaleTask ? ($delivery->sale?->product?->name ?? 'N/A') : ($delivery->rental?->product?->name ?? 'N/A') }}</div>
                </div>
                <div class="span-6">
                    <span class="label">Warehouse</span>
                    <div class="value">{{ $isSaleTask ? ($delivery->sale?->asset?->warehouse?->name ?? 'Sale dispatch') : ($delivery->rental?->dispatchWarehouse?->name ?? 'Any warehouse') }}</div>
                </div>
                <div class="span-12">
                    <span class="label">Delivery Address</span>
                    <div class="value">
                        {{ collect([$linkedAddress, $linkedCity])->filter()->join(', ') ?: 'No delivery address captured.' }}
                        @if($linkedMapUrl)
                            <div style="margin-top:8px;"><a href="{{ $linkedMapUrl }}" target="_blank" rel="noopener" class="detail-btn-secondary">Open Map</a></div>
                        @endif
                    </div>
                </div>
                @if($linkedContactNotes)
                    <div class="span-12">
                        <span class="label">Delivery Notes</span>
                        <div class="value">{{ $linkedContactNotes }}</div>
                    </div>
                @endif
            </div>
        </div>

        <div class="detail-card span-6">
            <h2 style="margin-top:0;">Assignee</h2>
            <div class="detail-grid">
                <div class="span-6">
                    <span class="label">{{ $assigneeSecondaryLabel }}</span>
                    <div class="value">{{ $assigneeName }}</div>
                </div>
                <div class="span-6">
                    <span class="label">Role</span>
                    <div class="value">{{ $assigneeRole }}</div>
                </div>
                @if($showThirdPartyDetails)
                    <div class="span-6">
                        <span class="label">Third Party</span>
                        <div class="value">{{ $delivery->third_party_name ?: 'Assigned' }}</div>
                    </div>
                @endif
                <div class="span-6">
                    <span class="label">Contact</span>
                    <div class="value">{{ $assigneeContact }}</div>
                </div>
                <div class="span-12">
                    <span class="label">Notes</span>
                    <div class="value">{{ $delivery->notes ?: 'No notes added.' }}</div>
                </div>
                @if($delivery->status === 'cancelled' && $delivery->cancellation_reason)
                    <div class="span-6">
                        <span class="label">Cancellation Reason</span>
                        <div class="value">{{ \App\Models\Delivery::cancellationReasonLabel($delivery->cancellation_reason) }}</div>
                    </div>
                @endif
                @if($delivery->status === 'cancelled')
                    <div class="span-6">
                        <span class="label">Cancellation Notes</span>
                        <div class="value">{{ $delivery->cancellation_notes ?: 'No additional cancellation notes.' }}</div>
                    </div>
                @endif
            </div>
        </div>

        @if(!$isSaleTask)
        <div class="detail-card span-12">
            <h2 style="margin-top:0;">Linked Assets</h2>
            <div class="asset-grid">
                @forelse($rentalAssets as $assignment)
                    <div class="asset-box">
                        <span class="label">Category</span>
                        <div class="value">Rental Asset</div>
                        <span class="label">Serial</span>
                        <div class="value">{{ $assignment->asset->serial_number ?? 'N/A' }}</div>
                        <span class="label" style="margin-top:10px;">Barcode</span>
                        <div class="value">{{ $assignment->asset->barcode_value ?? '-' }}</div>
                    </div>
                @empty
                @endforelse

                @if($delivery->type === 'delivery')
                    @foreach($deliverySaleAssets as $saleItem)
                        <div class="asset-box">
                            <span class="label">Category</span>
                            <div class="value">New Product With Rental</div>
                            <span class="label" style="margin-top:10px;">Product</span>
                            <div class="value">{{ $saleItem->product->name ?? 'New product' }}</div>
                            <span class="label" style="margin-top:10px;">Serial</span>
                            <div class="value">{{ $saleItem->asset->serial_number ?? 'N/A' }}</div>
                            <span class="label" style="margin-top:10px;">Warehouse</span>
                            <div class="value">{{ $saleItem->asset->warehouse->name ?? '-' }}</div>
                        </div>
                    @endforeach
                @endif

                @if($rentalAssets->isEmpty() && $deliverySaleAssets->isEmpty())
                    <div class="asset-box">
                        <div class="value">No specific assets linked to this task.</div>
                    </div>
                @endif
            </div>
        </div>
        @endif
    </div>
    </details>
</div>
@if((!$workflowCompleted && $canUpdateTask && in_array($delivery->status, ['pending', 'in_progress'], true)) || $hasProofHistory)
    <div class="delivery-mobile-sticky-cta is-{{ $mobileTaskTone }}">
        <a
            href="{{ (!$workflowCompleted && $canUpdateTask) ? '#' . $workflowProofSectionId : route('deliveries.show', $delivery) . '#delivery-proof-history' }}"
            @if($workflowCompleted || !(!$workflowCompleted && $canUpdateTask)) data-open-proof-history @endif
        >
            {{ (!$workflowCompleted && $canUpdateTask) ? $primaryWorkflowCtaLabel : 'View Proof History' }}
        </a>
    </div>
@endif
@php
    $workflowSuccessFlash = (string) session('success', '');
    $showWorkflowSuccessScreen = $workflowCompleted
        && $workflowSuccessFlash !== ''
        && str_contains(strtolower($workflowSuccessFlash), 'marked as completed');
@endphp
@if($showWorkflowSuccessScreen)
    <div
        class="workflow-success-screen"
        data-workflow-success-screen
        data-redirect-url="{{ route('deliveries.index') }}"
        role="status"
        aria-live="polite"
    >
        <div class="workflow-success-panel">
            <div class="workflow-success-check" aria-hidden="true">✓</div>
            <h2 class="workflow-success-title">{{ $delivery->type === 'pickup' ? 'Pickup Completed!' : 'Delivery Completed!' }}</h2>
            <div class="workflow-success-meta">
                <strong>{{ ucfirst($delivery->type) }} #{{ $delivery->id }}</strong>
                <span>{{ $delivery->linkedCustomerName() ?: 'Task' }}</span>
                <span>{{ $delivery->rental?->product?->name ?? $delivery->sale?->product?->name ?? 'Completed successfully' }}</span>
                <span>Returning to Taskboard in 2 seconds...</span>
            </div>
            <div class="workflow-success-progress" aria-hidden="true"><span></span></div>
        </div>
    </div>
@endif
<div class="delivery-page-spacer" aria-hidden="true"></div>
@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', () => {
    const proofConfig = {
        targetBytes: {{ (int) ($proofConfig['target_kb'] ?? 50) * 1024 }},
        maxBytes: {{ (int) ($proofConfig['max_kb'] ?? 100) * 1024 }},
        maxDimension: {{ (int) ($proofConfig['max_dimension'] ?? 1024) }},
    };
    const workflowSection = document.getElementById(@json($workflowProofSectionId));
    const cancellationSection = document.getElementById(@json($cancellationSectionId));
    const proofHistorySection = document.getElementById('delivery-proof-history');
    const workflowErrorSummary = document.querySelector('[data-workflow-error-summary]');
    const mobileWorkflowMedia = window.matchMedia('(max-width: 767px)');
    const mobileWorkflowShell = document.querySelector('[data-mobile-workflow-shell]');
    const mobileWorkflowShellBody = mobileWorkflowShell?.querySelector('.workflow-mobile-shell-body');
    const mobileWorkflowCloseButtons = Array.from(document.querySelectorAll('[data-close-mobile-workflow]'));
    const mobileActionMenus = Array.from(document.querySelectorAll('.fieldops-icon-actions .mobile-actions-menu'));
    const workflowSuccessScreen = document.querySelector('[data-workflow-success-screen]');

    if (workflowSuccessScreen instanceof HTMLElement) {
        const redirectUrl = workflowSuccessScreen.dataset.redirectUrl || '/deliveries';
        window.setTimeout(() => {
            window.location.assign(redirectUrl);
        }, 2300);
    }

    const closeMobileActionMenus = (exceptMenu = null) => {
        mobileActionMenus.forEach((menu) => {
            if (menu !== exceptMenu) {
                menu.removeAttribute('open');
            }
        });
    };

    document.addEventListener('click', (event) => {
        const activeMenu = event.target.closest?.('.fieldops-icon-actions .mobile-actions-menu');
        closeMobileActionMenus(activeMenu || null);
    });

    mobileActionMenus.forEach((menu) => {
        menu.addEventListener('click', (event) => {
            if (event.target === menu && menu.open) {
                event.preventDefault();
                menu.removeAttribute('open');
            }
        });

        menu.addEventListener('toggle', () => {
            if (menu.open) {
                closeMobileActionMenus(menu);
            }
        });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMobileActionMenus();
        }
    });

    const closeMobileWorkflowShell = ({ preserveHash = false } = {}) => {
        if (!(mobileWorkflowShell instanceof HTMLElement)) {
            return;
        }

        mobileWorkflowShell.dataset.mobileShellState = 'closed';
        mobileWorkflowShell.dataset.mobileShellMode = 'workflow';
        document.body.classList.remove('workflow-mobile-open');

        if (!preserveHash && window.location.hash === '#{{ $workflowProofSectionId }}') {
            history.replaceState(null, '', window.location.pathname + window.location.search);
        }
    };

    const openMobileWorkflowShell = (mode = 'workflow') => {
        if (!(mobileWorkflowShell instanceof HTMLElement) || !mobileWorkflowMedia.matches) {
            return;
        }

        mobileWorkflowShell.dataset.mobileShellState = 'open';
        mobileWorkflowShell.dataset.mobileShellMode = mode;
        document.body.classList.add('workflow-mobile-open');
    };

    const scrollWithinWorkflowShell = (target, { behavior = 'smooth' } = {}) => {
        if (!(target instanceof HTMLElement)) {
            return;
        }

        if (mobileWorkflowMedia.matches && mobileWorkflowShellBody instanceof HTMLElement && mobileWorkflowShell instanceof HTMLElement) {
            const bodyRect = mobileWorkflowShellBody.getBoundingClientRect();
            const targetRect = target.getBoundingClientRect();
            const offsetTop = targetRect.top - bodyRect.top + mobileWorkflowShellBody.scrollTop - 12;

            mobileWorkflowShellBody.scrollTo({
                top: Math.max(offsetTop, 0),
                behavior,
            });

            return;
        }

        target.scrollIntoView({ behavior, block: 'start' });
    };

    document.querySelectorAll('a[href]').forEach((anchor) => {
        try {
            const url = new URL(anchor.href, window.location.origin);

            if (url.hash !== '#{{ $workflowProofSectionId }}' || url.pathname !== window.location.pathname) {
                return;
            }

            anchor.addEventListener('click', (event) => {
                if (!mobileWorkflowMedia.matches) {
                    return;
                }

                event.preventDefault();
                history.replaceState(null, '', '#{{ $workflowProofSectionId }}');
                openMobileWorkflowShell('workflow');
            });
        } catch (error) {
            // Ignore malformed or external links.
        }
    });

    mobileWorkflowCloseButtons.forEach((button) => {
        button.addEventListener('click', () => closeMobileWorkflowShell());
    });

    window.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') {
            closeMobileWorkflowShell();
        }
    });

    const openProofHistoryFromHash = () => {
        if (!(proofHistorySection instanceof HTMLDetailsElement)) {
            return;
        }

        if (window.location.hash !== '#delivery-proof-history') {
            return;
        }

        if (mobileWorkflowMedia.matches && mobileWorkflowShell instanceof HTMLElement) {
            openMobileWorkflowShell('proof-history');
        }

        proofHistorySection.open = true;
        window.requestAnimationFrame(() => {
            scrollWithinWorkflowShell(proofHistorySection);
        });
    };

    const openProofHistoryPanel = (updateHash = true) => {
        if (!(proofHistorySection instanceof HTMLDetailsElement)) {
            return;
        }

        if (updateHash) {
            history.replaceState(null, '', '#delivery-proof-history');
        }

        openProofHistoryFromHash();
    };

    if (workflowSection && (window.location.hash === '#{{ $workflowProofSectionId }}' || {{ $hasWorkflowErrors ? 'true' : 'false' }})) {
        openMobileWorkflowShell('workflow');
        window.requestAnimationFrame(() => {
            scrollWithinWorkflowShell(workflowSection);
        });

        if (workflowErrorSummary instanceof HTMLElement) {
            window.setTimeout(() => workflowErrorSummary.focus(), 160);
        }
    }

    if (cancellationSection && (window.location.hash === '#{{ $cancellationSectionId }}' || {{ $hasCancellationErrors ? 'true' : 'false' }})) {
        if (cancellationSection instanceof HTMLDetailsElement) {
            cancellationSection.open = true;
        }
        window.requestAnimationFrame(() => {
            scrollWithinWorkflowShell(cancellationSection);
        });
    }

    openProofHistoryFromHash();
    window.addEventListener('hashchange', openProofHistoryFromHash);

    document.querySelectorAll('[data-open-proof-history]').forEach((anchor) => {
        anchor.addEventListener('click', (event) => {
            event.preventDefault();
            openProofHistoryPanel(true);
        });
    });

    const mobileWorkflowSteppers = Array.from(document.querySelectorAll('[data-mobile-workflow-stepper]'));

    const initializeMobileWorkflowStepper = (stepper) => {
        const form = stepper.closest('form');
        const stepPanels = Array.from(stepper.querySelectorAll('[data-workflow-step-panel]'));
        const stepTabs = Array.from(stepper.querySelectorAll('[data-workflow-step-tab]'));
        const initialStep = Math.max(parseInt(stepper.dataset.workflowInitialStep || '1', 10), 1);
        let currentStep = Math.min(initialStep, stepPanels.length || 1);

        const sync = () => {
            const mobileActive = mobileWorkflowMedia.matches;
            stepper.dataset.mobileWorkflowActive = mobileActive ? 'true' : 'false';

            stepPanels.forEach((panel, index) => {
                const stepIndex = index + 1;
                panel.classList.toggle('is-current', stepIndex === currentStep);

                if (!mobileActive) {
                    panel.style.display = '';
                    panel.style.flexDirection = '';
                    panel.style.alignItems = '';
                    return;
                }

                if (stepIndex === currentStep) {
                    panel.style.display = 'flex';
                    panel.style.flexDirection = 'column';
                    panel.style.alignItems = 'stretch';
                } else {
                    panel.style.display = 'none';
                    panel.style.flexDirection = '';
                    panel.style.alignItems = '';
                }
            });

            stepTabs.forEach((tab, index) => {
                const stepIndex = index + 1;
                tab.classList.toggle('is-active', stepIndex === currentStep);
                tab.classList.toggle('is-complete', stepIndex < currentStep);
            });
        };

        stepper.addEventListener('click', (event) => {
            const nextButton = event.target.closest('[data-workflow-next]');
            const backButton = event.target.closest('[data-workflow-back]');
            const stepTab = event.target.closest('[data-workflow-step-tab]');

            if (nextButton) {
                event.preventDefault();
                if (typeof form?.__canAdvanceFromStep === 'function' && !form.__canAdvanceFromStep(currentStep)) {
                    return;
                }
                if (nextButton.hasAttribute('data-workflow-submit-on-next')) {
                    form?.requestSubmit();
                    return;
                }
                currentStep = Math.min(currentStep + 1, stepPanels.length);
                sync();
                stepPanels[currentStep - 1]?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                return;
            }

            if (backButton) {
                event.preventDefault();
                currentStep = Math.max(currentStep - 1, 1);
                sync();
                stepPanels[currentStep - 1]?.scrollIntoView({ behavior: 'smooth', block: 'start' });
                return;
            }

            if (stepTab) {
                event.preventDefault();
                const targetStep = Math.max(parseInt(stepTab.dataset.workflowStepTab || '1', 10), 1);
                currentStep = Math.min(targetStep, stepPanels.length);
                sync();
                stepPanels[currentStep - 1]?.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        });

        mobileWorkflowMedia.addEventListener('change', () => {
            sync();

            if (!mobileWorkflowMedia.matches) {
                closeMobileWorkflowShell({ preserveHash: true });
            }
        });
        sync();
    };

    mobileWorkflowSteppers.forEach(initializeMobileWorkflowStepper);

    const canvasToBlob = (canvas, type, quality) => new Promise((resolve) => {
        canvas.toBlob((blob) => resolve(blob), type, quality);
    });

    const loadImageFromFile = (file) => new Promise((resolve, reject) => {
        const reader = new FileReader();
        const image = new Image();

        reader.onerror = () => reject(new Error('Unable to read the selected image.'));
        image.onerror = () => reject(new Error('Unable to process the selected image.'));
        image.onload = () => resolve(image);
        reader.onload = () => {
            image.src = reader.result;
        };
        reader.readAsDataURL(file);
    });

    const formatCapturedAt = (value) => {
        if (!value) {
            return '-';
        }

        const parsed = new Date(value);

        if (Number.isNaN(parsed.getTime())) {
            return value;
        }

        return new Intl.DateTimeFormat('en-IN', {
            timeZone: 'Asia/Kolkata',
            day: '2-digit',
            month: 'short',
            year: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
            hour12: true,
        }).format(parsed);
    };

    const compressImageFile = async (file) => {
        if (!(file instanceof File) || !file.type.startsWith('image/')) {
            return file;
        }

        const image = await loadImageFromFile(file);
        const canvas = document.createElement('canvas');
        const context = canvas.getContext('2d', { alpha: false });
        const dimensions = [proofConfig.maxDimension, 800, 640, 480]
            .filter((dimension, index, items) => dimension > 0 && items.indexOf(dimension) === index);
        const qualities = [0.75, 0.6, 0.45, 0.35];
        let blob = null;

        for (const dimension of dimensions) {
            const scale = Math.min(1, dimension / Math.max(image.width, image.height));
            const width = Math.max(Math.round(image.width * scale), 1);
            const height = Math.max(Math.round(image.height * scale), 1);

            canvas.width = width;
            canvas.height = height;
            context.fillStyle = '#ffffff';
            context.fillRect(0, 0, width, height);
            context.drawImage(image, 0, 0, width, height);

            for (const quality of qualities) {
                blob = await canvasToBlob(canvas, 'image/jpeg', quality);

                if (!blob) {
                    continue;
                }

                if (blob.size <= proofConfig.targetBytes) {
                    break;
                }
            }

            if (blob && blob.size <= proofConfig.maxBytes) {
                break;
            }
        }

        if (!blob) {
            throw new Error('Unable to compress the selected image.');
        }

        if (blob.size > proofConfig.maxBytes) {
            throw new Error('Image is still too large. Try taking a closer photo with less background, or use retake/choose another photo.');
        }

        const baseName = (file.name || 'proof-image').replace(/\.[^/.]+$/, '');

        return new File([blob], `${baseName}.jpg`, {
            type: 'image/jpeg',
            lastModified: Date.now(),
        });
    };

    document.querySelectorAll('[data-compress-images]').forEach((input) => {
        input.addEventListener('change', async () => {
            if (!(input instanceof HTMLInputElement) || !input.files || input.files.length === 0) {
                return;
            }

            const files = Array.from(input.files);
            const dataTransfer = new DataTransfer();

            try {
                for (const file of files) {
                    const compressed = await compressImageFile(file);
                    dataTransfer.items.add(compressed);
                }

                input.files = dataTransfer.files;
                input.setCustomValidity('');
                const parentForm = input.closest('form');
                if (parentForm?.hasAttribute('data-workflow-review-form')) {
                    syncWorkflowReview(parentForm);
                }
            } catch (error) {
                input.value = '';
                input.setCustomValidity(error.message || 'Unable to compress the selected image.');
                input.reportValidity();
            }
        });
    });

    const syncFilePreview = (input) => {
        if (!(input instanceof HTMLInputElement)) {
            return;
        }

        const previewId = input.dataset.previewTarget || '';
        const preview = previewId ? document.getElementById(previewId) : null;
        const status = input.closest('.workflow-camera-card')?.querySelector('[data-file-status]');

        if (!(preview instanceof HTMLElement)) {
            return;
        }

        preview.innerHTML = '';

        const files = input.files ? Array.from(input.files) : [];

        if (files.length === 0) {
            preview.classList.remove('is-visible');
            if (status instanceof HTMLElement && !status.textContent?.trim()) {
                status.classList.remove('is-visible');
            }
            return;
        }

        files.slice(0, 3).forEach((file) => {
            const image = document.createElement('img');
            image.alt = file.name || 'Proof preview';
            image.src = URL.createObjectURL(file);
            image.addEventListener('load', () => URL.revokeObjectURL(image.src), { once: true });
            preview.appendChild(image);
        });

        preview.classList.add('is-visible');

        if (status instanceof HTMLElement) {
            status.textContent = files.length === 1 ? '1 file ready' : `${files.length} files ready`;
            status.classList.add('is-visible');
        }
    };

    document.querySelectorAll('[data-preview-target]').forEach((input) => {
        input.addEventListener('change', () => syncFilePreview(input));
        syncFilePreview(input);
    });

    const updateLocationPreview = (form) => {
        const status = form.querySelector('[data-location-status]');
        const latitudeInput = form.querySelector('[data-location-latitude]');
        const longitudeInput = form.querySelector('[data-location-longitude]');
        const accuracyInput = form.querySelector('[data-location-accuracy]');
        const capturedAtInput = form.querySelector('[data-location-captured-at]');
        const reasonField = form.querySelector('textarea[name="location_missing_reason"]');
        const latPreview = form.querySelector('[data-location-lat-preview]');
        const lngPreview = form.querySelector('[data-location-lng-preview]');
        const accuracyPreview = form.querySelector('[data-location-accuracy-preview]');
        const capturedPreview = form.querySelector('[data-location-captured-preview]');
        const captureButton = form.querySelector('[data-capture-location]');
        const recaptureButton = form.querySelector('[data-recapture-location]');
        const clearButton = form.querySelector('[data-clear-location]');
        const hasCoordinates = latitudeInput?.value && longitudeInput?.value;
        const reason = reasonField?.value?.trim() || '';

        if (latPreview) latPreview.textContent = latitudeInput?.value || '-';
        if (lngPreview) lngPreview.textContent = longitudeInput?.value || '-';
        if (accuracyPreview) accuracyPreview.textContent = accuracyInput?.value || '-';
        if (capturedPreview) capturedPreview.textContent = formatCapturedAt(capturedAtInput?.value || '');

        if (captureButton instanceof HTMLElement) {
            captureButton.hidden = !!hasCoordinates;
        }
        if (recaptureButton instanceof HTMLElement) {
            recaptureButton.hidden = !hasCoordinates;
        }
        if (clearButton instanceof HTMLElement) {
            clearButton.hidden = !hasCoordinates;
        }

        if (!status) {
            return;
        }

        status.classList.remove('is-success', 'is-warning');

        if (hasCoordinates) {
            status.classList.add('is-success');
            status.textContent = `Location captured${capturedAtInput?.value ? ` at ${formatCapturedAt(capturedAtInput.value)}` : ''}.`;
            return;
        }

        if (reason !== '') {
            status.classList.add('is-warning');
            status.textContent = `Location marked as unavailable: ${reason}`;
            return;
        }

        status.textContent = 'Location not captured yet.';
    };

    const hasSelectedFiles = (input) => input instanceof HTMLInputElement && input.files && input.files.length > 0;

    const setReviewItemState = (form, key, ready, text) => {
        const item = form.querySelector(`[data-review-item="${key}"]`);
        if (!item) {
            const summaryOnly = form.querySelector(`[data-review-summary="${key}"]`);

            if (summaryOnly) {
                summaryOnly.textContent = text;
            }

            return;
        }

        item.dataset.reviewReady = ready ? 'true' : 'false';

        const status = item.querySelector('.workflow-review-status');

        if (status) {
            status.textContent = text;
        }

        const summary = form.querySelector(`[data-review-summary="${key}"]`);
        if (summary) {
            summary.textContent = text;
        }
    };

    const syncPickupConditionSelection = (form) => {
        const hiddenInput = form.querySelector('[data-pickup-condition-input]');
        const damageToggle = form.querySelector('[data-pickup-damage-toggle]');
        const selected = hiddenInput?.value || '';

        form.querySelectorAll('[data-pickup-condition]').forEach((card) => {
            card.classList.toggle('is-selected', card.dataset.pickupCondition === selected);
        });

        if (damageToggle instanceof HTMLInputElement) {
            damageToggle.checked = selected === 'damaged';
        }
    };

    const workflowRequirementState = (form) => {
        const workflowType = form.dataset.workflowType || 'delivery';
        const hasCoordinates = Boolean(
            form.querySelector('[data-location-latitude]')?.value
            && form.querySelector('[data-location-longitude]')?.value
        );
        const hasLocationReason = Boolean(form.querySelector('textarea[name="location_missing_reason"]')?.value?.trim());
        const locationReady = hasCoordinates || hasLocationReason;
        const photoReady = workflowType === 'pickup'
            ? hasSelectedFiles(form.querySelector('input[name="pickup_device_photos[]"]'))
            : (hasSelectedFiles(form.querySelector('input[name="delivery_device_photos[]"]'))
                && hasSelectedFiles(form.querySelector('input[name="premises_photo"]')));
        const pickupCondition = form.querySelector('[data-pickup-condition-input]')?.value || '';
        const conditionReady = workflowType !== 'pickup' || pickupCondition !== '';
        const damageNotes = form.querySelector('textarea[name="damage_notes"]')?.value?.trim() || '';
        const missingAccessoryNotes = form.querySelector('textarea[name="missing_accessories_notes"]')?.value?.trim() || '';
        const damagePhotoReady = hasSelectedFiles(form.querySelector('input[name="damage_photos[]"]'));
        const notesReady = workflowType !== 'pickup'
            ? true
            : (
                (pickupCondition === 'damaged' && damageNotes !== '' && damagePhotoReady)
                || (pickupCondition === 'missing_accessories' && missingAccessoryNotes !== '')
                || ['good', 'needs_inspection', ''].includes(pickupCondition)
            );
        const signatureReady = Boolean(form.querySelector('input[name="signature_data"]')?.value)
            || Boolean(form.querySelector('textarea[name="signature_unavailable_reason"]')?.value?.trim());
        const collectionRequired = {{ $supportsCollectionStep ? 'true' : 'false' }};
        const hasCollectedAmount = (() => {
            const raw = form.querySelector('input[name="collection_amount_collected"]')?.value?.trim() || '';
            return raw !== '' && !Number.isNaN(Number(raw)) && Number(raw) > 0;
        })();
        const collectionReason = form.querySelector('select[name="collection_not_collected_reason"]')?.value || '';
        const collectionMode = form.querySelector('select[name="collection_payment_mode"]')?.value || '';
        const collectionReady = !collectionRequired || ((hasCollectedAmount && collectionMode !== '') || (!hasCollectedAmount && collectionReason !== ''));
        const consentReady = Boolean(form.querySelector('input[name="completion_confirmed"]')?.checked);

        return {
            workflowType,
            locationReady,
            photoReady,
            conditionReady,
            notesReady,
            signatureReady,
            pickupCondition,
            damagePhotoReady,
            collectionRequired,
            collectionReady,
            consentReady,
        };
    };

    const syncWorkflowReview = (form) => {
        const state = workflowRequirementState(form);

        setReviewItemState(form, 'location', state.locationReady, state.locationReady ? 'Ready' : 'Missing');
        setReviewItemState(form, 'photos', state.photoReady, state.photoReady ? 'Ready' : 'Missing');

        if (state.workflowType === 'pickup') {
            setReviewItemState(form, 'condition', state.conditionReady, state.conditionReady ? 'Ready' : 'Missing');
        }

        if (state.collectionRequired) {
            setReviewItemState(form, 'collection', state.collectionReady, state.collectionReady ? 'Ready' : 'Missing');
        }

        const notesStateLabel = state.workflowType === 'pickup'
            ? (
                state.pickupCondition === 'damaged'
                    ? (state.notesReady ? 'Ready' : 'Need Notes')
                    : (state.pickupCondition === 'missing_accessories'
                        ? (state.notesReady ? 'Ready' : 'Need Notes')
                        : 'Optional')
            )
            : 'Optional';

        setReviewItemState(form, 'notes', state.workflowType === 'pickup' ? state.notesReady : true, notesStateLabel);
        setReviewItemState(form, 'signature', state.signatureReady, state.signatureReady ? 'Ready' : 'Missing');

        const completeButton = form.querySelector('[data-workflow-complete]');
        if (completeButton) {
            const canComplete = state.locationReady
                && state.photoReady
                && state.signatureReady
                && (state.workflowType !== 'pickup' || (state.conditionReady && state.notesReady))
                && (!state.collectionRequired || state.collectionReady)
                && state.consentReady;

            setReviewItemState(form, 'complete', canComplete, canComplete ? 'Ready to finish' : 'Review pending');

            completeButton.disabled = !canComplete;
            completeButton.setAttribute('aria-disabled', canComplete ? 'false' : 'true');
            completeButton.style.opacity = '1';
            completeButton.style.pointerEvents = canComplete ? 'auto' : 'none';
        }
    };

    const focusStepRequirement = (form, currentStep) => {
        const workflowType = form.dataset.workflowType || 'delivery';
        const state = workflowRequirementState(form);

        if (currentStep === 1 && !state.locationReady) {
            form.querySelector('[data-capture-location]')?.focus();
            return false;
        }

        if (currentStep === 2 && !state.photoReady) {
            (workflowType === 'pickup'
                ? form.querySelector('input[name="pickup_device_photos[]"]')
                : form.querySelector('input[name="delivery_device_photos[]"]'))?.click();
            return false;
        }

        if (workflowType === 'pickup' && currentStep === 3 && !state.conditionReady) {
            form.querySelector('[data-pickup-condition]')?.focus();
            return false;
        }

        if (workflowType === 'pickup' && currentStep === 4 && !state.notesReady) {
            if (state.pickupCondition === 'damaged') {
                form.querySelector('textarea[name="damage_notes"]')?.focus();
            } else {
                form.querySelector('textarea[name="missing_accessories_notes"]')?.focus();
            }
            return false;
        }

        if (((workflowType === 'pickup' && currentStep === 5) || (workflowType === 'delivery' && currentStep === 4)) && !state.signatureReady) {
            form.querySelector('[data-signature-pad]')?.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return false;
        }

        if (state.collectionRequired && ((workflowType === 'pickup' && currentStep === 6) || (workflowType === 'delivery' && currentStep === 5)) && !state.collectionReady) {
            form.querySelector('input[name="collection_amount_collected"]')?.focus();
            return false;
        }

        return true;
    };

    document.querySelectorAll('[data-workflow-form]').forEach((form) => {
        const captureButton = form.querySelector('[data-capture-location]');
        const recaptureButton = form.querySelector('[data-recapture-location]');
        const clearButton = form.querySelector('[data-clear-location]');
        const latitudeInput = form.querySelector('[data-location-latitude]');
        const longitudeInput = form.querySelector('[data-location-longitude]');
        const accuracyInput = form.querySelector('[data-location-accuracy]');
        const capturedAtInput = form.querySelector('[data-location-captured-at]');
        const reasonField = form.querySelector('textarea[name="location_missing_reason"]');
        const refreshWorkflowState = () => {
            if (form.hasAttribute('data-workflow-review-form')) {
                syncPickupConditionSelection(form);
                syncWorkflowReview(form);
            }
        };
        const resetCoordinates = () => {
            latitudeInput.value = '';
            longitudeInput.value = '';
            accuracyInput.value = '';
            capturedAtInput.value = '';
        };

        const handleLocationCapture = () => {
            if (!navigator.geolocation) {
                if (reasonField) {
                    reasonField.focus();
                }
                const status = form.querySelector('[data-location-status]');
                if (status) {
                    status.classList.add('is-warning');
                    status.textContent = 'Geolocation is not supported on this device. Add a missing-location reason to continue.';
                }
                return;
            }

            navigator.geolocation.getCurrentPosition((position) => {
                latitudeInput.value = position.coords.latitude.toFixed(6);
                longitudeInput.value = position.coords.longitude.toFixed(6);
                accuracyInput.value = Math.round(position.coords.accuracy * 10) / 10;
                capturedAtInput.value = new Date().toISOString();
                if (reasonField) {
                    reasonField.value = '';
                }
                updateLocationPreview(form);
                refreshWorkflowState();
            }, (error) => {
                const status = form.querySelector('[data-location-status]');
                if (status) {
                    status.classList.add('is-warning');
                    status.textContent = `${error.message || 'Location permission was denied.'} Add a missing-location reason to continue.`;
                }
                if (reasonField) {
                    reasonField.focus();
                }
                refreshWorkflowState();
            }, {
                enableHighAccuracy: true,
                timeout: 10000,
                maximumAge: 0,
            });
        };

        updateLocationPreview(form);
        form.__canAdvanceFromStep = (currentStep) => {
            if (form.dataset.workflowForm === 'start') {
                return currentStep !== 2 || focusStepRequirement(form, 1);
            }

            return focusStepRequirement(form, currentStep);
        };

        reasonField?.addEventListener('input', () => {
            if (reasonField.value.trim() !== '') {
                resetCoordinates();
            }

            updateLocationPreview(form);
            refreshWorkflowState();
        });

        captureButton?.addEventListener('click', handleLocationCapture);
        recaptureButton?.addEventListener('click', handleLocationCapture);
        clearButton?.addEventListener('click', () => {
            resetCoordinates();
            updateLocationPreview(form);
            refreshWorkflowState();
            captureButton?.focus();
        });

        form.querySelectorAll('[data-pickup-condition]').forEach((button) => {
            button.addEventListener('click', () => {
                const hiddenInput = form.querySelector('[data-pickup-condition-input]');

                if (hiddenInput) {
                    hiddenInput.value = button.dataset.pickupCondition || '';
                }

                refreshWorkflowState();
            });
        });

        form.querySelectorAll('input[type="file"], textarea, input[type="checkbox"]').forEach((field) => {
            field.addEventListener('change', refreshWorkflowState);
            field.addEventListener('input', refreshWorkflowState);
        });

        refreshWorkflowState();
    });

    document.querySelectorAll('[data-signature-pad]').forEach((canvas) => {
        const form = canvas.closest('form');
        const hiddenInputName = canvas.dataset.targetInput;
        const hiddenInput = form?.querySelector(`input[name="${hiddenInputName}"]`);
        const clearButton = form?.querySelector('[data-signature-clear]');
        const previewWrap = form?.querySelector('[data-signature-preview-wrap]');
        const previewImage = form?.querySelector('[data-signature-preview-image]');
        const signatureWrap = canvas.closest('.workflow-proof-signature-wrap');
        const context = canvas.getContext('2d');
        let drawing = false;
        let hasSignature = false;
        let currentSignatureData = hiddenInput?.value || '';
        let pixelRatio = 1;
        const refreshWorkflowState = () => {
            if (form?.hasAttribute('data-workflow-review-form')) {
                syncWorkflowReview(form);
            }
        };
        const syncSignatureUi = (hasCapturedSignature) => {
            if (signatureWrap instanceof HTMLElement) {
                signatureWrap.classList.toggle('has-signature-capture', Boolean(hasCapturedSignature));
            }
        };

        const drawSignaturePreview = (dataUrl) => {
            if (!(previewWrap instanceof HTMLElement) || !(previewImage instanceof HTMLImageElement)) {
                syncSignatureUi(Boolean(dataUrl));
                return;
            }

            if (!dataUrl) {
                previewWrap.classList.remove('is-visible');
                previewImage.removeAttribute('src');
                syncSignatureUi(false);
                return;
            }

            previewImage.src = dataUrl;
            previewWrap.classList.add('is-visible');
            syncSignatureUi(true);
        };

        const applyCanvasStyles = () => {
            context.lineWidth = 2.4;
            context.lineCap = 'round';
            context.lineJoin = 'round';
            context.strokeStyle = '#0f172a';
        };

        const redrawSignature = (dataUrl) => {
            if (!dataUrl) {
                context.clearRect(0, 0, canvas.width, canvas.height);
                return;
            }

            const image = new Image();
            image.onload = () => {
                context.clearRect(0, 0, canvas.width, canvas.height);
                context.drawImage(image, 0, 0, canvas.width / pixelRatio, canvas.height / pixelRatio);
            };
            image.src = dataUrl;
        };

        const resizeCanvas = () => {
            pixelRatio = window.devicePixelRatio || 1;
            const bounds = canvas.getBoundingClientRect();
            const width = Math.max(Math.floor(bounds.width), 280);
            const height = Math.max(Math.floor(bounds.height), 118);
            const preservedSignature = currentSignatureData || hiddenInput?.value || '';

            canvas.width = Math.max(Math.floor(width * pixelRatio), 1);
            canvas.height = Math.max(Math.floor(height * pixelRatio), 1);
            canvas.style.width = `${width}px`;
            canvas.style.height = `${height}px`;

            context.setTransform(pixelRatio, 0, 0, pixelRatio, 0, 0);
            applyCanvasStyles();
            redrawSignature(preservedSignature);
        };

        const positionForEvent = (event) => {
            const bounds = canvas.getBoundingClientRect();
            const point = event.touches ? event.touches[0] : event;

            return {
                x: point.clientX - bounds.left,
                y: point.clientY - bounds.top,
            };
        };

        const startStroke = (event) => {
            drawing = true;
            const point = positionForEvent(event);
            context.beginPath();
            context.moveTo(point.x, point.y);
            event.preventDefault();
        };

        const continueStroke = (event) => {
            if (!drawing) {
                return;
            }

            const point = positionForEvent(event);
            context.lineTo(point.x, point.y);
            context.stroke();
            hasSignature = true;
            event.preventDefault();
        };

        const stopStroke = () => {
            drawing = false;

            if (hasSignature && hiddenInput) {
                currentSignatureData = canvas.toDataURL('image/png');
                hiddenInput.value = currentSignatureData;
                drawSignaturePreview(currentSignatureData);
            }

            refreshWorkflowState();
        };

        resizeCanvas();
        window.addEventListener('resize', resizeCanvas);
        drawSignaturePreview(currentSignatureData);
        if (currentSignatureData) {
            hasSignature = true;
        }

        canvas.addEventListener('mousedown', startStroke);
        canvas.addEventListener('mousemove', continueStroke);
        canvas.addEventListener('mouseup', stopStroke);
        canvas.addEventListener('mouseleave', stopStroke);
        canvas.addEventListener('touchstart', startStroke, { passive: false });
        canvas.addEventListener('touchmove', continueStroke, { passive: false });
        canvas.addEventListener('touchend', stopStroke);
        canvas.addEventListener('touchcancel', stopStroke);

        clearButton?.addEventListener('click', () => {
            context.clearRect(0, 0, canvas.width, canvas.height);
            hasSignature = false;
            currentSignatureData = '';
            if (hiddenInput) {
                hiddenInput.value = '';
            }
            drawSignaturePreview('');
            refreshWorkflowState();
        });

        form?.addEventListener('submit', () => {
            if (!hiddenInput) {
                return;
            }

            if (hasSignature) {
                currentSignatureData = canvas.toDataURL('image/png');
                hiddenInput.value = currentSignatureData;
            }
        });
    });
});
</script>
@endpush
@endsection
