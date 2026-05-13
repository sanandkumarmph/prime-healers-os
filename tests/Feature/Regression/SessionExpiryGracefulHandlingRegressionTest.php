<?php

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    Route::middleware('web')->post('/_test/session-expired-form', function () {
        throw new TokenMismatchException('Expired session.');
    })->name('test.session-expired.form');

    Route::middleware(['web', 'auth'])->post('/_test/session-expired-logout', function () {
        throw new TokenMismatchException('Expired session.');
    })->name('test.session-expired.logout');
});

test('expired logout request redirects to login with a friendly session expiry message', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->post('/_test/session-expired-logout');

    $response
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your session expired. Please login again.');

    $this->assertGuest();
});

test('expired form submission redirects to login instead of showing a raw 419 page', function () {
    $response = $this->post('/_test/session-expired-form');

    $response
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', 'Your session expired. Please login again.');
});

test('expired json requests still receive a csrf-protected 419 response', function () {
    $response = $this->postJson('/_test/session-expired-form');

    $response
        ->assertStatus(419)
        ->assertJson([
            'message' => 'Your session expired. Please login again.',
        ]);
});

test('csrf middleware remains enabled for the web middleware group', function () {
    $webMiddleware = app(\Illuminate\Contracts\Http\Kernel::class)->getMiddlewareGroups()['web'];

    expect($webMiddleware)->toContain(ValidateCsrfToken::class);
});
