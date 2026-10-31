<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\User;

uses(TestCase::class, RefreshDatabase::class);

it('redirects guests to login from home', function () {
    $response = $this->get('/');

    $response->assertRedirect(route('login'));
});

it('redirects authenticated users to their permitted home page', function (string $role, string $route) {
    $user = User::factory()->create(['role' => $role]);

    $response = $this->actingAs($user)->get('/');

    $response->assertRedirect(route($route));
})->with([
    'executive' => ['super_admin', 'dashboard'],
    'sales' => ['sales', 'enquiries.index'],
    'unassigned staff' => ['staff', 'profile.edit'],
]);
