<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_categories')) {
            Schema::create('product_categories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('normalized_name');
                $table->string('slug')->nullable();
                $table->foreignId('parent_id')->nullable()->constrained('product_categories')->nullOnDelete();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['organization_id', 'normalized_name'], 'product_categories_org_name_unique');
                $table->index(['organization_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('product_brands')) {
            Schema::create('product_brands', function (Blueprint $table) {
                $table->id();
                $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('normalized_name');
                $table->string('slug')->nullable();
                $table->string('manufacturer')->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['organization_id', 'normalized_name'], 'product_brands_org_name_unique');
                $table->index(['organization_id', 'is_active']);
            });
        }

        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (! Schema::hasColumn('products', 'category_id')) {
                    $table->foreignId('category_id')->nullable()->after('category')->constrained('product_categories')->nullOnDelete();
                }

                if (! Schema::hasColumn('products', 'brand_id')) {
                    $table->foreignId('brand_id')->nullable()->after('brand')->constrained('product_brands')->nullOnDelete();
                }
            });

            DB::table('products')
                ->select('organization_id', 'category')
                ->whereNotNull('organization_id')
                ->whereNotNull('category')
                ->where('category', '!=', '')
                ->distinct()
                ->orderBy('organization_id')
                ->chunk(100, function ($rows) {
                    foreach ($rows as $row) {
                        $name = trim((string) $row->category);
                        $normalized = Str::lower($name);

                        if ($name === '' || $normalized === '') {
                            continue;
                        }

                        DB::table('product_categories')->updateOrInsert(
                            [
                                'organization_id' => (int) $row->organization_id,
                                'normalized_name' => $normalized,
                            ],
                            [
                                'name' => $name,
                                'is_active' => true,
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                    }
                });

            DB::table('products')
                ->select('organization_id', 'brand')
                ->whereNotNull('organization_id')
                ->whereNotNull('brand')
                ->where('brand', '!=', '')
                ->distinct()
                ->orderBy('organization_id')
                ->chunk(100, function ($rows) {
                    foreach ($rows as $row) {
                        $name = trim((string) $row->brand);
                        $normalized = Str::lower($name);

                        if ($name === '' || $normalized === '') {
                            continue;
                        }

                        DB::table('product_brands')->updateOrInsert(
                            [
                                'organization_id' => (int) $row->organization_id,
                                'normalized_name' => $normalized,
                            ],
                            [
                                'name' => $name,
                                'is_active' => true,
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                    }
                });

            DB::table('products')
                ->whereNotNull('category')
                ->where('category', '!=', '')
                ->orderBy('id')
                ->chunkById(100, function ($products) {
                    foreach ($products as $product) {
                        $categoryId = DB::table('product_categories')
                            ->where('organization_id', $product->organization_id)
                            ->where('normalized_name', Str::lower(trim((string) $product->category)))
                            ->value('id');

                        if ($categoryId) {
                            DB::table('products')->where('id', $product->id)->update(['category_id' => $categoryId]);
                        }
                    }
                });

            DB::table('products')
                ->whereNotNull('brand')
                ->where('brand', '!=', '')
                ->orderBy('id')
                ->chunkById(100, function ($products) {
                    foreach ($products as $product) {
                        $brandId = DB::table('product_brands')
                            ->where('organization_id', $product->organization_id)
                            ->where('normalized_name', Str::lower(trim((string) $product->brand)))
                            ->value('id');

                        if ($brandId) {
                            DB::table('products')->where('id', $product->id)->update(['brand_id' => $brandId]);
                        }
                    }
                });
        }

        $this->seedDefaultMasters();
    }

    private function defaultCategories(): array
    {
        return [
            'Respiratory Care',
            'Sleep Therapy',
            'Mobility Aids',
            'Patient Care',
            'Monitoring Equipment',
            'ICU Equipment',
            'Rehabilitation',
            'Consumables',
            'Accessories',
            'Furniture',
        ];
    }

    private function defaultBrands(): array
    {
        return [
            'Philips',
            'ResMed',
            'BMC',
            'Yuwell',
            'Omron',
            'Dr Trust',
            'KareMed',
            'Prime Healers',
            'Generic',
        ];
    }

    private function seedDefaultMasters(): void
    {
        if (! Schema::hasTable('organizations')) {
            return;
        }

        DB::table('organizations')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($organizations) {
                foreach ($organizations as $organization) {
                    foreach ($this->defaultCategories() as $name) {
                        DB::table('product_categories')->updateOrInsert(
                            [
                                'organization_id' => (int) $organization->id,
                                'normalized_name' => Str::lower($name),
                            ],
                            [
                                'name' => $name,
                                'is_active' => true,
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                    }

                    foreach ($this->defaultBrands() as $name) {
                        DB::table('product_brands')->updateOrInsert(
                            [
                                'organization_id' => (int) $organization->id,
                                'normalized_name' => Str::lower($name),
                            ],
                            [
                                'name' => $name,
                                'is_active' => true,
                                'updated_at' => now(),
                                'created_at' => now(),
                            ]
                        );
                    }
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasTable('products')) {
            Schema::table('products', function (Blueprint $table) {
                if (Schema::hasColumn('products', 'brand_id')) {
                    $table->dropConstrainedForeignId('brand_id');
                }

                if (Schema::hasColumn('products', 'category_id')) {
                    $table->dropConstrainedForeignId('category_id');
                }
            });
        }

        Schema::dropIfExists('product_brands');
        Schema::dropIfExists('product_categories');
    }
};
