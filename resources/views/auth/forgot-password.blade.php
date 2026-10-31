<x-guest-layout>
    <div>
        <h1 class="auth-form-title">Reset your password</h1>
        <p class="auth-form-copy">Enter your work email to request a password reset link.</p>
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />
    @if(!$passwordRecoveryAvailable)
        <p class="auth-form-copy">{{ \App\Services\Auth\PasswordRecovery::UNAVAILABLE }}</p>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
        @csrf

        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input id="email" class="ph-input block mt-1 w-full" type="email" name="email" :value="old('email')" required autofocus />
            <x-input-error :messages="$errors->get('email')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-4">
            <button type="submit" class="ph-btn auth-submit" @disabled(!$passwordRecoveryAvailable)>
                {{ __('Send reset link') }}
            </button>
        </div>
        <p class="auth-form-copy"><a href="{{ route('login') }}">Back to sign in</a></p>
    </form>
</x-guest-layout>
