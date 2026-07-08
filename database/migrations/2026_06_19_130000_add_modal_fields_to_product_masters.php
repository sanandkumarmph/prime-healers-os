<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('product_categories')) {
            Schema::table('product_categories', function (Blueprint $table) {
                if (! Schema::hasColumn('product_categories', 'slug')) {
                    $table->string('slug')->nullable()->after('normalized_name');
                }

                if (! Schema::hasColumn('product_categories', 'parent_id')) {
                    $table->foreignId('parent_id')->nullable()->after('slug')->constrained('product_categories')->nullOnDelete();
                }

                if (! Schema::hasColumn('product_categories', 'description')) {
                    $table->text('description')->nullable()->after('parent_id');
                }
            });
        }

        if (Schema::hasTable('product_brands')) {
            Schema::table('product_brands', function (Blueprint $table) {
                if (! Schema::hasColumn('product_brands', 'slug')) {
                    $table->string('slug')->nullable()->after('normalized_name');
                }

                if (! Schema::hasColumn('product_brands', 'manufacturer')) {
                    $table->string('manufacturer')->nullable()->after('slug');
                }

                if (! Schema::hasColumn('product_brands', 'description')) {
                    $table->text('description')->nullable()->after('manufacturer');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('product_brands')) {
            Schema::table('product_brands', function (Blueprint $table) {
                if (Schema::hasColumn('product_brands', 'description')) {
                    $table->dropColumn('description');
                }

                if (Schema::hasColumn('product_brands', 'manufacturer')) {
                    $table->dropColumn('manufacturer');
                }

                if (Schema::hasColumn('product_brands', 'slug')) {
                    $table->dropColumn('slug');
                }
            });
        }

        if (Schema::hasTable('product_categories')) {
            Schema::table('product_categories', function (Blueprint $table) {
                if (Schema::hasColumn('product_categories', 'description')) {
                    $table->dropColumn('description');
                }

                if (Schema::hasColumn('product_categories', 'parent_id')) {
                    $table->dropConstrainedForeignId('parent_id');
                }

                if (Schema::hasColumn('product_categories', 'slug')) {
                    $table->dropColumn('slug');
                }
            });
        }
    }
};
