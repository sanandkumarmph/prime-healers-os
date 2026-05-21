@props([
    'actions' => [],
    'moreActions' => [],
    'infoItems' => [],
    'label' => 'Quick actions',
    'description' => null,
])

@php
    $primaryActions = collect($actions)->filter(fn ($action) => filled($action['label'] ?? null))->values();
    $secondaryActions = collect($moreActions)->filter(fn ($action) => filled($action['label'] ?? null))->values();
    $infoCollection = collect($infoItems)->filter(fn ($item) => filled($item['label'] ?? null) && filled($item['value'] ?? null))->values();

    $renderAttributes = function (array $attributes = []): string {
        return collect($attributes)
            ->map(function ($value, $key) {
                if (is_bool($value)) {
                    return $value ? $key : null;
                }
                if ($value === null) {
                    return null;
                }

                return sprintf('%s="%s"', $key, e((string) $value));
            })
            ->filter()
            ->implode(' ');
    };
@endphp

@if($primaryActions->isNotEmpty() || $secondaryActions->isNotEmpty() || $infoCollection->isNotEmpty())
    <section {{ $attributes->class(['ph-operational-actions']) }} aria-label="{{ $label }}">
        <div class="ph-operational-actions__header">
            <div class="ph-operational-actions__title">
                <strong>{{ $label }}</strong>
                @if(filled($description))
                    <span>{{ $description }}</span>
                @endif
            </div>

            <div class="ph-operational-actions__buttons">
                @foreach($primaryActions as $action)
                    @php($accentClass = !empty($action['accent']) ? ' is-accent' : '')
                    @php($primaryClass = empty($action['accent']) && $loop->first ? ' is-primary' : '')
                    @php($dangerClass = !empty($action['danger']) ? ' is-danger' : '')
                    @if(($action['type'] ?? 'link') === 'form')
                        <form action="{{ $action['action'] }}" method="{{ strtoupper($action['method'] ?? 'POST') === 'GET' ? 'GET' : 'POST' }}">
                            @if(strtoupper($action['method'] ?? 'POST') !== 'GET')
                                @csrf
                                @if(!in_array(strtoupper($action['method'] ?? 'POST'), ['POST'], true))
                                    @method($action['method'])
                                @endif
                            @endif
                            <button type="submit" class="ph-operational-actions__button{{ $primaryClass }}{{ $accentClass }}{{ $dangerClass }}">
                                {{ $action['label'] }}
                            </button>
                        </form>
                    @elseif(($action['type'] ?? 'link') === 'button')
                        <button type="button" class="ph-operational-actions__button{{ $primaryClass }}{{ $accentClass }}{{ $dangerClass }}" {!! $renderAttributes($action['attributes'] ?? []) !!}>
                            {{ $action['label'] }}
                        </button>
                    @else
                        <a
                            href="{{ $action['href'] ?? '#' }}"
                            class="ph-operational-actions__button{{ $primaryClass }}{{ $accentClass }}{{ $dangerClass }}"
                            @if(!empty($action['target'])) target="{{ $action['target'] }}" @endif
                            @if(!empty($action['rel'])) rel="{{ $action['rel'] }}" @endif
                        >
                            {{ $action['label'] }}
                        </a>
                    @endif
                @endforeach

                <x-quick-actions-dropdown :actions="$secondaryActions" label="More" />
            </div>
        </div>

        @if($infoCollection->isNotEmpty())
            <div class="ph-operational-actions__info">
                @foreach($infoCollection as $item)
                    <div class="ph-operational-actions__info-card">
                        <span>{{ $item['label'] }}</span>
                        <strong>{{ $item['value'] }}</strong>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
@endif

@once
    <style>
        .ph-operational-actions {
            display: grid;
            gap: 14px;
            padding: 16px 18px;
            border: 1px solid #dbe3ef;
            border-radius: 22px;
            background: linear-gradient(180deg, #ffffff 0%, #f8fbff 100%);
            box-shadow: 0 16px 36px rgba(15, 23, 42, 0.05);
        }
        .ph-operational-actions__header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 14px;
            flex-wrap: wrap;
        }
        .ph-operational-actions__title {
            display: grid;
            gap: 5px;
        }
        .ph-operational-actions__title strong {
            color: #0f172a;
            font-size: 16px;
            line-height: 1.2;
        }
        .ph-operational-actions__title span {
            color: #64748b;
            font-size: 12px;
            line-height: 1.5;
        }
        .ph-operational-actions__buttons {
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 8px;
            flex-wrap: wrap;
        }
        .ph-operational-actions__buttons form {
            margin: 0;
        }
        .ph-operational-actions__button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 9px 13px;
            border: 1px solid #cbd5e1;
            border-radius: 12px;
            background: #fff;
            color: #0f172a;
            font-size: 13px;
            font-weight: 800;
            text-decoration: none;
            font-family: inherit;
            cursor: pointer;
            white-space: nowrap;
        }
        .ph-operational-actions__button.is-primary {
            background: #0f172a;
            border-color: #0f172a;
            color: #fff;
            box-shadow: 0 12px 24px rgba(15, 23, 42, 0.12);
        }
        .ph-operational-actions__button.is-accent {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }
        .ph-operational-actions__button.is-danger {
            background: #fff1f2;
            border-color: #fecaca;
            color: #b91c1c;
        }
        .ph-operational-actions__info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 10px;
        }
        .ph-operational-actions__info-card {
            display: grid;
            gap: 4px;
            min-width: 0;
            padding: 12px 14px;
            border: 1px solid #e2e8f0;
            border-radius: 16px;
            background: #fff;
        }
        .ph-operational-actions__info-card span {
            color: #64748b;
            font-size: 11px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: .05em;
        }
        .ph-operational-actions__info-card strong {
            color: #0f172a;
            font-size: 13px;
            line-height: 1.55;
            overflow-wrap: anywhere;
        }
        @media (max-width: 768px) {
            .ph-operational-actions {
                display: none;
            }
        }
    </style>
@endonce
