<?php

namespace App\Support;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\Schema;

class InternalOrganization
{
    /**
     * Request-scoped memoization. PHP statics reset between requests.
     *
     * @var array<string, ?\App\Models\Organization>
     */
    private static array $resolvedOrganizations = [];

    public static function enabled(): bool
    {
        return (bool) config('primehealers.internal_single_org_mode', true);
    }

    public static function companyName(): string
    {
        return (string) config('primehealers.internal_organization_name', 'Prime Healers');
    }

    public static function resolve(?User $user = null): ?Organization
    {
        $cacheKey = self::cacheKey($user);

        if (array_key_exists($cacheKey, self::$resolvedOrganizations)) {
            $cachedOrganization = self::$resolvedOrganizations[$cacheKey];

            if ($cachedOrganization === null) {
                unset(self::$resolvedOrganizations[$cacheKey]);
            } elseif (Schema::hasTable('organizations') && Organization::query()->whereKey($cachedOrganization->getKey())->exists()) {
                return $cachedOrganization;
            } else {
                unset(self::$resolvedOrganizations[$cacheKey]);
            }
        }

        if ($user && $user->relationLoaded('organization')) {
            $loadedOrganization = $user->getRelation('organization');

            if ($loadedOrganization instanceof Organization) {
                return self::$resolvedOrganizations[$cacheKey] = $loadedOrganization;
            }
        }

        if ($user && (int) ($user->organization_id ?? 0) > 0) {
            $userOrganization = Organization::find((int) $user->organization_id);

            if ($userOrganization) {
                if ($user) {
                    $user->setRelation('organization', $userOrganization);
                }

                return self::$resolvedOrganizations[$cacheKey] = $userOrganization;
            }
        }

        if (!Schema::hasTable('organizations')) {
            return self::$resolvedOrganizations[$cacheKey] = null;
        }

        $configuredId = (int) (config('primehealers.internal_organization_id') ?? 0);
        if ($configuredId > 0) {
            $configuredOrganization = Organization::find($configuredId);

            if ($configuredOrganization) {
                return self::$resolvedOrganizations[$cacheKey] = $configuredOrganization;
            }
        }

        $configuredName = trim((string) config('primehealers.internal_organization_name', 'Prime Healers'));
        if ($configuredName !== '') {
            $namedOrganization = Organization::query()
                ->whereRaw('LOWER(name) = ?', [mb_strtolower($configuredName)])
                ->orderByDesc('is_internal')
                ->orderBy('id')
                ->first();

            if ($namedOrganization) {
                return self::$resolvedOrganizations[$cacheKey] = $namedOrganization;
            }
        }

        $internalOrganization = Organization::query()
            ->where('is_internal', true)
            ->orderBy('id')
            ->first();

        if ($internalOrganization) {
            return self::$resolvedOrganizations[$cacheKey] = $internalOrganization;
        }

        $existingOrganization = Organization::query()->orderBy('id')->first();

        if ($existingOrganization) {
            return self::$resolvedOrganizations[$cacheKey] = $existingOrganization;
        }

        if (!self::enabled()) {
            return self::$resolvedOrganizations[$cacheKey] = null;
        }

        return self::$resolvedOrganizations[$cacheKey] = Organization::query()->create([
            'name' => self::companyName(),
            'plan' => 'internal',
            'is_internal' => true,
            'is_active' => true,
        ]);
    }

    public static function id(?User $user = null): ?int
    {
        return self::resolve($user)?->id;
    }

    public static function ensureUserAssigned(User $user): ?Organization
    {
        $organization = self::resolve($user);

        if (!$organization) {
            return null;
        }

        if ((int) ($user->organization_id ?? 0) !== (int) $organization->id) {
            $user->forceFill([
                'organization_id' => $organization->id,
            ])->save();
        }

        $user->setRelation('organization', $organization);

        return $organization;
    }

    private static function cacheKey(?User $user = null): string
    {
        return implode(':', [
            (string) ($user?->id ?? 'guest'),
            (string) ($user?->organization_id ?? 0),
            (string) (config('primehealers.internal_organization_id') ?? 0),
            mb_strtolower(trim((string) config('primehealers.internal_organization_name', 'Prime Healers'))),
        ]);
    }
}
