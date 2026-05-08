@extends('layouts.app')

@section('content')
<style>
    .profile-page {
        display: grid;
        gap: 16px;
        width: 100%;
        max-width: 1080px;
        min-width: 0;
        margin: 0 auto;
        padding: 10px 0 24px;
        box-sizing: border-box;
    }

    .profile-hero,
    .profile-card {
        background: #ffffff;
        border: 1px solid #dbe3ef;
        border-radius: 18px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.04);
    }

    .profile-hero {
        padding: 20px 22px;
        background: linear-gradient(135deg, #f8fbff 0%, #eef6ff 100%);
    }

    .profile-hero h1 {
        margin: 0;
        font-size: 30px;
        color: #0f172a;
    }

    .profile-hero p {
        margin: 8px 0 0;
        color: #64748b;
        font-size: 14px;
        line-height: 1.6;
        max-width: 720px;
    }

    .profile-card-head {
        padding: 14px 16px;
        border-bottom: 1px solid #e2e8f0;
    }

    .profile-card-head h2 {
        margin: 0;
        font-size: 18px;
        color: #0f172a;
    }

    .profile-card-head p {
        margin: 4px 0 0;
        color: #64748b;
        font-size: 12px;
    }

    .profile-card-body {
        padding: 16px;
        min-width: 0;
    }

    .profile-grid {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 12px;
    }

    .profile-field {
        display: grid;
        gap: 6px;
    }

    .profile-field.full {
        grid-column: 1 / -1;
    }

    .profile-field label {
        font-size: 11px;
        font-weight: 700;
        color: #64748b;
        text-transform: uppercase;
        letter-spacing: 0.05em;
    }

    .profile-input {
        width: 100%;
        min-height: 38px;
        padding: 9px 11px;
        border: 1px solid #cbd5e1;
        border-radius: 10px;
        font-size: 13px;
        color: #0f172a;
        background: #ffffff;
        box-sizing: border-box;
    }

    .profile-input:focus {
        outline: none;
        border-color: #2563eb;
        box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
    }

    .profile-actions {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
        margin-top: 14px;
    }

    .profile-btn,
    .profile-btn-light,
    .profile-btn-danger {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 34px;
        padding: 7px 12px;
        border-radius: 10px;
        font-size: 12px;
        font-weight: 700;
        text-decoration: none;
        cursor: pointer;
    }

    .profile-btn {
        background: #0f172a;
        border: 1px solid #0f172a;
        color: #ffffff;
    }

    .profile-btn-light {
        background: #ffffff;
        border: 1px solid #cbd5e1;
        color: #334155;
    }

    .profile-btn-danger {
        background: #dc2626;
        border: 1px solid #dc2626;
        color: #ffffff;
    }

    .profile-help,
    .profile-error,
    .profile-success,
    .profile-warning {
        font-size: 12px;
        line-height: 1.5;
    }

    .profile-help {
        color: #64748b;
    }

    .profile-error {
        color: #b91c1c;
    }

    .profile-success {
        color: #166534;
    }

    .profile-warning {
        color: #9a3412;
    }

    .profile-flash {
        padding: 12px 14px;
        border-radius: 12px;
        border: 1px solid #bbf7d0;
        background: #dcfce7;
        color: #166534;
        font-size: 13px;
    }

    @media (max-width: 720px) {
        .profile-page {
            padding: 8px 0 18px;
        }

        .profile-grid {
            grid-template-columns: 1fr;
        }

        .profile-actions {
            flex-direction: column;
            align-items: stretch;
        }

        .profile-btn,
        .profile-btn-light,
        .profile-btn-danger {
            width: 100%;
        }
    }
</style>

<div class="container profile-page">
    <div class="profile-hero">
        <h1>Profile</h1>
        <p>Update your account details, change your password, and manage account access from one clean workspace.</p>
    </div>

    @if (session('status') === 'profile-updated')
        <div class="profile-flash">Profile updated successfully.</div>
    @endif

    @if (session('status') === 'password-updated')
        <div class="profile-flash">Password updated successfully.</div>
    @endif

    <div class="profile-card">
        <div class="profile-card-head">
            <h2>Profile Information</h2>
            <p>Keep your name and email current so account and notification details stay accurate.</p>
        </div>
        <div class="profile-card-body">
            <form method="POST" action="{{ route('profile.update') }}">
                @csrf
                @method('PATCH')

                <div class="profile-grid">
                    <div class="profile-field">
                        <label for="name">Name</label>
                        <input id="name" class="profile-input" name="name" type="text" value="{{ old('name', $user->name) }}" required autofocus autocomplete="name">
                        @error('name')
                            <div class="profile-error">{{ $message }}</div>
                        @enderror
                    </div>

                    <div class="profile-field">
                        <label for="email">Email</label>
                        <input id="email" class="profile-input" name="email" type="email" value="{{ old('email', $user->email) }}" required autocomplete="username">
                        @error('email')
                            <div class="profile-error">{{ $message }}</div>
                        @enderror
                    </div>
                </div>

                @if ($user instanceof \Illuminate\Contracts\Auth\MustVerifyEmail && ! $user->hasVerifiedEmail())
                    <div class="profile-actions" style="margin-top:12px;">
                        <div class="profile-warning">Your email address is unverified.</div>
                        <form method="POST" action="{{ route('verification.send') }}">
                            @csrf
                            <button type="submit" class="profile-btn-light">Resend Verification Email</button>
                        </form>
                    </div>
                    @if (session('status') === 'verification-link-sent')
                        <div class="profile-success" style="margin-top:8px;">A new verification link has been sent to your email address.</div>
                    @endif
                @endif

                <div class="profile-actions">
                    <button type="submit" class="profile-btn">Save Profile</button>
                </div>
            </form>
        </div>
    </div>

    <div class="profile-card">
        <div class="profile-card-head">
            <h2>Update Password</h2>
            <p>Use a strong password to keep your account secure.</p>
        </div>
        <div class="profile-card-body">
            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                @method('PUT')

                <div class="profile-grid">
                    <div class="profile-field">
                        <label for="current_password">Current Password</label>
                        <input id="current_password" class="profile-input" name="current_password" type="password" autocomplete="current-password">
                        @if ($errors->updatePassword->has('current_password'))
                            <div class="profile-error">{{ $errors->updatePassword->first('current_password') }}</div>
                        @endif
                    </div>

                    <div class="profile-field">
                        <label for="password">New Password</label>
                        <input id="password" class="profile-input" name="password" type="password" autocomplete="new-password">
                        @if ($errors->updatePassword->has('password'))
                            <div class="profile-error">{{ $errors->updatePassword->first('password') }}</div>
                        @endif
                    </div>

                    <div class="profile-field full">
                        <label for="password_confirmation">Confirm Password</label>
                        <input id="password_confirmation" class="profile-input" name="password_confirmation" type="password" autocomplete="new-password">
                        @if ($errors->updatePassword->has('password_confirmation'))
                            <div class="profile-error">{{ $errors->updatePassword->first('password_confirmation') }}</div>
                        @endif
                    </div>
                </div>

                <div class="profile-actions">
                    <button type="submit" class="profile-btn">Save Password</button>
                </div>
            </form>
        </div>
    </div>

    <div class="profile-card">
        <div class="profile-card-head">
            <h2>Delete Account</h2>
            <p>This action is permanent. Enter your password to confirm account deletion.</p>
        </div>
        <div class="profile-card-body">
            <form method="POST" action="{{ route('profile.destroy') }}">
                @csrf
                @method('DELETE')

                <div class="profile-grid">
                    <div class="profile-field full">
                        <label for="delete_password">Password</label>
                        <input id="delete_password" class="profile-input" name="password" type="password" placeholder="Enter password to confirm">
                        @if ($errors->userDeletion->has('password'))
                            <div class="profile-error">{{ $errors->userDeletion->first('password') }}</div>
                        @endif
                    </div>
                </div>

                <div class="profile-actions">
                    <button type="submit" class="profile-btn-danger" onclick="return confirm('Are you sure you want to permanently delete your account?')">Delete Account</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection
