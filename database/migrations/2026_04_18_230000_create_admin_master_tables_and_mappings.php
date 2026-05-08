<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('slug');
                $table->text('description')->nullable();
                $table->json('permissions')->nullable();
                $table->boolean('is_system')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['organization_id', 'slug']);
                $table->unique(['organization_id', 'name']);
            });
        }

        if (!Schema::hasTable('cities')) {
            Schema::create('cities', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('state')->nullable();
                $table->string('country')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['organization_id', 'name', 'state']);
                $table->index(['organization_id', 'is_active']);
            });
        }

        if (!Schema::hasTable('vendors')) {
            Schema::create('vendors', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->foreignId('city_id')->nullable()->constrained('cities')->nullOnDelete();
                $table->string('name');
                $table->string('contact_person')->nullable();
                $table->string('phone')->nullable();
                $table->string('email')->nullable();
                $table->text('address')->nullable();
                $table->string('city')->nullable();
                $table->boolean('is_active')->default(true);
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['organization_id', 'name']);
                $table->index(['organization_id', 'is_active']);
            });
        }

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'role_id')) {
                $table->foreignId('role_id')->nullable()->after('organization_id')->constrained('roles')->nullOnDelete();
            }

            if (!Schema::hasColumn('users', 'city_id')) {
                $table->foreignId('city_id')->nullable()->after('role_id')->constrained('cities')->nullOnDelete();
            }

            if (!Schema::hasColumn('users', 'phone')) {
                $table->string('phone')->nullable()->after('email');
            }

            if (!Schema::hasColumn('users', 'address')) {
                $table->text('address')->nullable()->after('phone');
            }

            if (!Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('address');
            }
        });

        Schema::table('warehouses', function (Blueprint $table) {
            if (!Schema::hasColumn('warehouses', 'city_id')) {
                $table->foreignId('city_id')->nullable()->after('address')->constrained('cities')->nullOnDelete();
            }
        });

        $this->seedDefaultRoles();
        $this->seedCitiesAndMapWarehouses();
        $this->seedVendorsFromStaff();
    }

    public function down(): void
    {
        Schema::table('warehouses', function (Blueprint $table) {
            if (Schema::hasColumn('warehouses', 'city_id')) {
                $table->dropConstrainedForeignId('city_id');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'role_id')) {
                $table->dropConstrainedForeignId('role_id');
            }

            if (Schema::hasColumn('users', 'city_id')) {
                $table->dropConstrainedForeignId('city_id');
            }

            foreach (['phone', 'address', 'is_active'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::dropIfExists('vendors');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('roles');
    }

    private function seedDefaultRoles(): void
    {
        $modulePermissions = [
            'users' => ['read', 'create', 'update', 'delete'],
            'roles' => ['read', 'create', 'update', 'delete'],
            'cities' => ['read', 'create', 'update', 'delete'],
            'warehouses' => ['read', 'create', 'update', 'delete'],
            'vendors' => ['read', 'create', 'update', 'delete'],
            'customers' => ['read', 'create', 'update', 'delete'],
            'products' => ['read', 'create', 'update', 'delete'],
            'assets' => ['read', 'create', 'update', 'delete'],
            'rentals' => ['read', 'create', 'update', 'delete'],
            'sales' => ['read', 'create', 'update', 'delete'],
            'invoices' => ['read', 'create', 'update', 'delete'],
            'payments' => ['read', 'create', 'update', 'delete'],
            'deliveries' => ['read', 'create', 'update', 'delete'],
            'reports' => ['read'],
            'settings' => ['read', 'update'],
        ];

        $defaultRoles = [
            'super_admin' => [
                'name' => 'Super Admin',
                'permissions' => $modulePermissions,
            ],
            'admin_operations' => [
                'name' => 'Operations Admin',
                'permissions' => array_merge($modulePermissions, [
                    'roles' => ['read'],
                    'users' => ['read', 'update'],
                    'settings' => ['read'],
                ]),
            ],
            'sales' => [
                'name' => 'Sales',
                'permissions' => [
                    'customers' => ['read', 'create', 'update'],
                    'products' => ['read'],
                    'rentals' => ['read', 'create', 'update'],
                    'sales' => ['read', 'create', 'update'],
                    'invoices' => ['read', 'create', 'update'],
                    'payments' => ['read', 'create'],
                    'deliveries' => ['read'],
                    'reports' => ['read'],
                ],
            ],
            'sales_renewals' => [
                'name' => 'Sales Renewals',
                'permissions' => [
                    'customers' => ['read', 'update'],
                    'products' => ['read'],
                    'rentals' => ['read', 'update'],
                    'sales' => ['read'],
                    'invoices' => ['read', 'create'],
                    'payments' => ['read'],
                    'deliveries' => ['read'],
                    'reports' => ['read'],
                ],
            ],
            'delivery' => [
                'name' => 'Delivery Staff',
                'permissions' => [
                    'deliveries' => ['read', 'update'],
                    'rentals' => ['read'],
                ],
            ],
            'vendor' => [
                'name' => 'Vendor',
                'permissions' => [
                    'deliveries' => ['read', 'update'],
                    'rentals' => ['read'],
                ],
            ],
            'staff' => [
                'name' => 'Staff',
                'permissions' => [
                    'dashboard' => ['read'],
                ],
            ],
        ];

        $organizations = DB::table('organizations')->select('id')->get();

        foreach ($organizations as $organization) {
            foreach ($defaultRoles as $slug => $roleData) {
                DB::table('roles')->updateOrInsert(
                    [
                        'organization_id' => $organization->id,
                        'slug' => $slug,
                    ],
                    [
                        'name' => $roleData['name'],
                        'description' => $roleData['name'] . ' role',
                        'permissions' => json_encode($roleData['permissions']),
                        'is_system' => true,
                        'is_active' => true,
                        'updated_at' => now(),
                        'created_at' => now(),
                    ]
                );
            }

            $rolesBySlug = DB::table('roles')
                ->where('organization_id', $organization->id)
                ->pluck('id', 'slug');

            $users = DB::table('users')
                ->where('organization_id', $organization->id)
                ->select('id', 'role')
                ->get();

            foreach ($users as $user) {
                $legacyRole = filled($user->role) ? $user->role : 'staff';
                $roleId = $rolesBySlug[$legacyRole] ?? $rolesBySlug['staff'] ?? null;

                DB::table('users')
                    ->where('id', $user->id)
                    ->update([
                        'role_id' => $roleId,
                        'role' => $legacyRole,
                        'is_active' => true,
                    ]);
            }
        }
    }

    private function seedCitiesAndMapWarehouses(): void
    {
        $organizations = DB::table('organizations')
            ->select('id', 'city', 'state', 'country')
            ->get();

        foreach ($organizations as $organization) {
            $cityRecords = collect();

            if (Schema::hasTable('customers')) {
                $customerCities = DB::table('customers')
                    ->where('organization_id', $organization->id)
                    ->whereNotNull('city')
                    ->where('city', '!=', '')
                    ->select('city as name', 'state')
                    ->distinct()
                    ->get();

                $cityRecords = $cityRecords->concat($customerCities);
            }

            if (Schema::hasTable('warehouses')) {
                $warehouseCities = DB::table('warehouses')
                    ->where('organization_id', $organization->id)
                    ->whereNotNull('city')
                    ->where('city', '!=', '')
                    ->select('city as name', 'state')
                    ->distinct()
                    ->get();

                $cityRecords = $cityRecords->concat($warehouseCities);
            }

            if (Schema::hasTable('staff') && Schema::hasColumn('staff', 'city')) {
                $staffCities = DB::table('staff')
                    ->where('organization_id', $organization->id)
                    ->whereNotNull('city')
                    ->where('city', '!=', '')
                    ->select('city as name', DB::raw('NULL as state'))
                    ->distinct()
                    ->get();

                $cityRecords = $cityRecords->concat($staffCities);
            }

            if (filled($organization->city)) {
                $cityRecords->push((object) [
                    'name' => $organization->city,
                    'state' => $organization->state,
                ]);
            }

            $cityRecords
                ->filter(fn ($city) => filled($city->name))
                ->unique(fn ($city) => strtolower(trim($city->name)) . '|' . strtolower(trim((string) $city->state)))
                ->each(function ($city) use ($organization) {
                    DB::table('cities')->updateOrInsert(
                        [
                            'organization_id' => $organization->id,
                            'name' => trim($city->name),
                            'state' => filled($city->state) ? trim($city->state) : null,
                        ],
                        [
                            'country' => $organization->country ?: 'India',
                            'is_active' => true,
                            'updated_at' => now(),
                            'created_at' => now(),
                        ]
                    );
                });

            $cities = DB::table('cities')
                ->where('organization_id', $organization->id)
                ->get()
                ->keyBy(fn ($city) => strtolower(trim($city->name)));

            $warehouses = DB::table('warehouses')
                ->where('organization_id', $organization->id)
                ->select('id', 'city')
                ->get();

            foreach ($warehouses as $warehouse) {
                if (!filled($warehouse->city)) {
                    continue;
                }

                $match = $cities->get(strtolower(trim($warehouse->city)));

                if ($match) {
                    DB::table('warehouses')
                        ->where('id', $warehouse->id)
                        ->update(['city_id' => $match->id]);
                }
            }
        }
    }

    private function seedVendorsFromStaff(): void
    {
        if (!Schema::hasTable('staff')) {
            return;
        }

        $vendorStaff = DB::table('staff')
            ->whereIn('role', ['vendor', 'third_party'])
            ->select('id', 'organization_id', 'name', 'phone', 'email', 'address', 'city', 'notes', 'status')
            ->get();

        foreach ($vendorStaff as $staff) {
            $cityId = null;

            if (filled($staff->city) && Schema::hasTable('cities')) {
                $cityId = DB::table('cities')
                    ->where('organization_id', $staff->organization_id)
                    ->whereRaw('LOWER(name) = ?', [strtolower(trim($staff->city))])
                    ->value('id');
            }

            DB::table('vendors')->updateOrInsert(
                [
                    'organization_id' => $staff->organization_id,
                    'name' => $staff->name,
                ],
                [
                    'city_id' => $cityId,
                    'contact_person' => $staff->name,
                    'phone' => $staff->phone,
                    'email' => $staff->email,
                    'address' => $staff->address,
                    'city' => $staff->city,
                    'is_active' => $staff->status === 'active',
                    'notes' => $staff->notes,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }
    }
};
