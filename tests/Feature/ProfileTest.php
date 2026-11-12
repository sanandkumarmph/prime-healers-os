<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;

uses(TestCase::class, RefreshDatabase::class);

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get('/profile');

    $response->assertOk();
});

test('a super admin can update their name and email', function () {
    $user = \Tests\Support\TestData::user();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'current_password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $user->refresh();

    $this->assertSame('Test User', $user->name);
    $this->assertSame('test@example.com', $user->email);
    $this->assertNull($user->email_verified_at);
});

test('delivery can update their name but cannot change their login email', function () {
    $user = \Tests\Support\TestData::user(null, ['role' => User::ROLE_DELIVERY]);
    $email = $user->email;
    $this->actingAs($user)->get('/profile')->assertOk()->assertSee('readonly')
        ->assertDontSee('id="profile_current_password"', false);
    $this->patchJson('/profile', ['name' => 'Attempted edit', 'email' => 'other@example.test', 'current_password' => 'password'])
        ->assertForbidden();
    $this->patch('/profile', ['name' => 'Updated delivery name'])->assertRedirect('/profile');
    $this->assertSame('Updated delivery name', $user->fresh()->name);
    $this->assertSame($email, $user->fresh()->email);
    $this->assertNotNull($user->fresh()->email_verified_at);
});

test('email verification status is unchanged when the email address is unchanged', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->patch('/profile', [
            'name' => 'Test User',
            'email' => $user->email,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/profile');

    $this->assertNotNull($user->refresh()->email_verified_at);
});

test('super admin can delete their account when another owner remains', function () {
    $user = \Tests\Support\TestData::user();
    \Tests\Support\TestData::user($user->organization);

    $response = $this
        ->actingAs($user)
        ->delete('/profile', [
            'password' => 'password',
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/');

    $this->assertGuest();
    $this->assertNull($user->fresh());
});

test('correct password must be provided to delete account', function () {
    $user = \Tests\Support\TestData::user();

    $response = $this
        ->actingAs($user)
        ->from('/profile')
        ->delete('/profile', [
            'password' => 'wrong-password',
        ]);

    $response
        ->assertSessionHasErrorsIn('userDeletion', 'password')
        ->assertRedirect('/profile');

    $this->assertNotNull($user->fresh());
});
