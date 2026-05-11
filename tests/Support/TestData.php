<?php

namespace Tests\Support;

use App\Models\Organization;
use App\Models\User;

class TestData
{
    public static function organization(array $attributes = []): Organization
    {
        return Organization::create(array_merge([
            'name' => 'Prime Healers',
            'is_internal' => true,
            'is_active' => true,
            'plan' => 'internal',
        ], $attributes));
    }

    public static function user(?Organization $organization = null, array $attributes = []): User
    {
        $organization ??= self::organization();

        return User::factory()->create(array_merge([
            'organization_id' => $organization->id,
            'role' => User::ROLE_SUPER_ADMIN,
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ], $attributes));
    }
}
