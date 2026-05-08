<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            if (!Schema::hasColumn('products', 'category')) {
                $table->string('category')->nullable()->after('name');
            }

            if (!Schema::hasColumn('products', 'brand')) {
                $table->string('brand')->nullable()->after('category');
            }

            if (!Schema::hasColumn('products', 'model_name')) {
                $table->string('model_name')->nullable()->after('brand');
            }
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $columnsToDrop = [];

            if (Schema::hasColumn('products', 'model_name')) {
                $columnsToDrop[] = 'model_name';
            }

            if (Schema::hasColumn('products', 'brand')) {
                $columnsToDrop[] = 'brand';
            }

            if (Schema::hasColumn('products', 'category')) {
                $columnsToDrop[] = 'category';
            }

            if (!empty($columnsToDrop)) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
