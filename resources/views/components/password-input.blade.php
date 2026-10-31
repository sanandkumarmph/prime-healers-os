@props(['id', 'name', 'autocomplete' => 'current-password'])

<div x-data="{ visible: false }" x-on:keydown.escape="visible = false" style="position: relative;">
    <x-text-input :id="$id" :name="$name" :autocomplete="$autocomplete"
        type="password" x-bind:type="visible ? 'text' : 'password'"
        class="ph-input block mt-1 w-full" style="padding-right: 3.5rem;" {{ $attributes }} />
    <button type="button" x-on:click="visible = !visible"
        aria-label="Show password" aria-pressed="false" aria-controls="{{ $id }}"
        x-bind:aria-label="visible ? 'Hide password' : 'Show password'"
        x-bind:aria-pressed="visible"
        style="position: absolute; right: 6px; top: 50%; transform: translateY(-50%); width: 44px; height: 44px; display: inline-flex; align-items: center; justify-content: center; border: 0; border-radius: 8px; background: transparent; color: #536789; cursor: pointer;">
        <svg x-show="!visible" aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/>
        </svg>
        <svg x-show="visible" style="display: none;" aria-hidden="true" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <path d="m3 3 18 18M10.6 5.1A12 12 0 0 1 12 5c6.5 0 10 7 10 7a17 17 0 0 1-3.1 4M6.5 6.5A21 21 0 0 0 2 12s3.5 7 10 7a12 12 0 0 0 5.5-1.5M10 10a3 3 0 0 0 4 4"/>
        </svg>
    </button>
</div>
