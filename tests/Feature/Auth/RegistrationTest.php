<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use App\Models\User;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

test('public registration screen is not available', function () {
    expect(config('auth.allow_public_registration'))->toBeFalse();
    expect(Route::has('register'))->toBeFalse();

    $response = $this->get('/register');

    $response->assertNotFound();
});

test('public self registration is blocked', function () {
    $response = $this->post('/register', [
        'name' => 'Test User',
        'email' => 'test@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $response->assertNotFound();
    $this->assertGuest();
    expect(User::query()->where('email', 'test@example.com')->exists())->toBeFalse();
});
