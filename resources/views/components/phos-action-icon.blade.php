@props(['name' => 'view', 'label' => 'View'])
<svg class="phos-action-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    @switch($name)
        @case('edit')<path d="m15 4 5 5M4 20l4.5-1L20 7.5a2.1 2.1 0 0 0-3-3L5.5 16 4 20Z"/>@break
        @case('download')<path d="M12 3v12m-4-4 4 4 4-4M4 17v4h16v-4"/>@break
        @case('print')<path d="M6 8V3h12v5M6 17H3V9h18v8h-3M6 14h12v7H6zM17 11h.01"/>@break
        @case('phone')<path d="M22 16.9v3a2 2 0 0 1-2.2 2A19.8 19.8 0 0 1 3.1 5.2 2 2 0 0 1 5.1 3h3l1.4 5.2-2 2a16 16 0 0 0 6.3 6.3l2-2L22 16.9Z"/>@break
        @case('close')<path d="m6 6 12 12M18 6 6 18"/>@break
        @default<path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>
    @endswitch
</svg>
<span class="sr-only">{{ $label }}</span>
