<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;

uses(TestCase::class, RefreshDatabase::class);

it('redirects guests to login from home', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});

it('redirects authenticated users to dashboard from home', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/');

    $response->assertRedirect(route('dashboard'));
});
