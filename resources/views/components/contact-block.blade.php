@props([
    'title' => null,
    'name' => null,
    'role' => null,
    'phone' => null,
    'whatsapp' => null,
    'email' => null,
    'address' => null,
    'city' => null,
    'state' => null,
    'pincode' => null,
    'mapUrl' => null,
    'notes' => null,
    'viewLabel' => null,
    'viewUrl' => null,
    'chips' => [],
])

@php
    $phoneHref = $phone ? 'tel:' . preg_replace('/\D+/', '', $phone) : null;
    $whatsAppHref = $whatsapp
        ? \App\Support\WhatsAppHelper::chatUrl(\App\Support\WhatsAppHelper::normalizeNumber($whatsapp))
        : null;
    $chipItems = collect($chips)->filter()->values();
@endphp

<div {{ $attributes->class(['ph-contact-block']) }}>
    <div class="ph-contact-block__head">
        <div class="ph-contact-block__heading">
            @if(filled($title))
                <span class="ph-contact-block__label">{{ $title }}</span>
            @endif
            <strong class="ph-contact-block__name">{{ $name ?: 'Not available' }}</strong>
            @if(filled($role))
                <span class="ph-contact-block__role">{{ $role }}</span>
            @endif
        </div>
        @if($chipItems->isNotEmpty())
            <div class="ph-contact-block__chips">
                @foreach($chipItems as $chip)
                    <x-status-chip :label="$chip['label'] ?? $chip" :tone="$chip['tone'] ?? 'neutral'" size="sm" />
                @endforeach
            </div>
        @endif
    </div>

    <div class="ph-contact-block__body">
        @if(filled($phone))
            <div class="ph-contact-block__row">
                <span>Phone</span>
                @if($phoneHref)
                    <a href="{{ $phoneHref }}">{{ $phone }}</a>
                @else
                    <strong>{{ $phone }}</strong>
                @endif
            </div>
        @endif

        @if(filled($email))
            <div class="ph-contact-block__row">
                <span>Email</span>
                <strong>{{ $email }}</strong>
            </div>
        @endif

        @if(filled($address) || filled($city) || filled($state) || filled($pincode) || filled($mapUrl))
            <x-address-block
                title="Address"
                :address="$address"
                :city="$city"
                :state="$state"
                :pincode="$pincode"
                :map-url="$mapUrl"
                :notes="$notes"
            />
        @elseif(filled($notes))
            <div class="ph-contact-block__note">{{ $notes }}</div>
        @endif
    </div>

    @if($phoneHref || $whatsAppHref || (filled($viewLabel) && filled($viewUrl)))
        <div class="ph-contact-block__actions">
            @if($phoneHref)
                <a href="{{ $phoneHref }}" class="ph-contact-block__action">Call</a>
            @endif
            @if($whatsAppHref)
                <a href="{{ $whatsAppHref }}" target="_blank" rel="noopener" class="ph-contact-block__action ph-contact-block__action--accent">WhatsApp</a>
            @endif
            @if(filled($viewLabel) && filled($viewUrl))
                <a href="{{ $viewUrl }}" class="ph-contact-block__action">{{ $viewLabel }}</a>
            @endif
        </div>
    @endif
</div>

@once
    <style>
        .ph-contact-block {
            display: grid;
            gap: 16px;
            min-width: 0;
            padding: 18px;
            border: 1px solid #e2e8f0;
            border-radius: 20px;
            background: #fff;
        }
        .ph-contact-block__head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .ph-contact-block__heading {
            display: grid;
            gap: 5px;
            min-width: 0;
        }
        .ph-contact-block__label {
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .ph-contact-block__name {
            color: #0f172a;
            font-size: 17px;
            line-height: 1.3;
            overflow-wrap: anywhere;
        }
        .ph-contact-block__role {
            color: #475569;
            font-size: 12px;
            line-height: 1.5;
        }
        .ph-contact-block__chips {
            display: flex;
            align-items: center;
            gap: 6px;
            flex-wrap: wrap;
        }
        .ph-contact-block__body {
            display: grid;
            gap: 12px;
            min-width: 0;
        }
        .ph-contact-block__row {
            display: grid;
            gap: 4px;
        }
        .ph-contact-block__row span {
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .ph-contact-block__row strong,
        .ph-contact-block__row a {
            color: #0f172a;
            font-size: 14px;
            font-weight: 700;
            line-height: 1.5;
            text-decoration: none;
            overflow-wrap: anywhere;
        }
        .ph-contact-block__row a:hover {
            text-decoration: underline;
        }
        .ph-contact-block__note {
            color: #64748b;
            font-size: 12px;
            line-height: 1.6;
        }
        .ph-contact-block__actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .ph-contact-block__action {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 36px;
            padding: 8px 12px;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #0f172a;
            font-size: 12px;
            font-weight: 800;
            text-decoration: none;
        }
        .ph-contact-block__action--accent {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }
    </style>
@endonce
