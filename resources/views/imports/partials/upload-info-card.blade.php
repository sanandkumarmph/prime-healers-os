<div class="ph-import-card">
    @isset($kicker)
        <span class="ph-import-kicker">{{ $kicker }}</span>
    @endisset
    <h2>{{ $title }}</h2>
    @isset($copy)
        <p>{{ $copy }}</p>
    @endisset
    {{ $slot }}
</div>
