<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('accessory_templates')) {
            Schema::create('accessory_templates', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('category')->nullable();
                $table->string('equipment_type')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('accessory_template_items')) {
            Schema::create('accessory_template_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('accessory_template_id')->constrained('accessory_templates')->cascadeOnDelete();
                $table->string('name');
                $table->boolean('is_required')->default(true);
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestamps();
            });
        }

        if (Schema::hasTable('products') && !Schema::hasColumn('products', 'accessory_template_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->foreignId('accessory_template_id')->nullable()->after('stock_mode')->constrained('accessory_templates')->nullOnDelete();
            });
        }

        if (!Schema::hasTable('return_verifications')) {
            Schema::create('return_verifications', function (Blueprint $table) {
                $table->id();
                $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();
                $table->foreignId('rental_id')->nullable()->constrained('rentals')->nullOnDelete();
                $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('condition_before')->nullable();
                $table->string('condition_after');
                $table->string('outcome');
                $table->text('remarks')->nullable();
                $table->timestamp('verified_at')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('return_verification_accessories')) {
            Schema::create('return_verification_accessories', function (Blueprint $table) {
                $table->id();
                $table->foreignId('return_verification_id')->constrained('return_verifications')->cascadeOnDelete();
                $table->string('accessory_name');
                $table->string('status', 40);
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        if (!Schema::hasTable('return_verification_photos')) {
            Schema::create('return_verification_photos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('return_verification_id')->constrained('return_verifications')->cascadeOnDelete();
                $table->string('photo_type', 40);
                $table->string('path');
                $table->timestamps();
            });
        }

        $this->seedDefaultAccessoryTemplates();
    }

    public function down(): void
    {
        Schema::dropIfExists('return_verification_photos');
        Schema::dropIfExists('return_verification_accessories');
        Schema::dropIfExists('return_verifications');

        if (Schema::hasTable('products') && Schema::hasColumn('products', 'accessory_template_id')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropConstrainedForeignId('accessory_template_id');
            });
        }

        Schema::dropIfExists('accessory_template_items');
        Schema::dropIfExists('accessory_templates');
    }

    private function seedDefaultAccessoryTemplates(): void
    {
        $templates = [
            'Oxygen Concentrator' => ['Power cable', 'Humidifier bottle', 'Nasal cannula', 'Oxygen tube', 'Filter', 'User manual'],
            'BiPAP / CPAP' => ['Machine unit', 'Power adapter', 'Mask', 'Tubing', 'Humidifier chamber', 'Filter', 'Carry bag'],
            'Hospital Bed' => ['Mattress', 'Side rails', 'Remote', 'Power cable', 'Wheels/castors', 'IV pole if included'],
            'Wheelchair' => ['Footrests', 'Armrests', 'Seat cushion', 'Brakes', 'Seat belt if included'],
            'Suction Machine' => ['Power cable', 'Suction jar', 'Suction tube', 'Filter', 'Manual/documents'],
            'DVT Pump' => ['Power adapter', 'Cuffs', 'Tubes', 'Carry bag'],
            'Nebulizer' => ['Machine unit', 'Power adapter', 'Nebulizer cup', 'Mask', 'Tubing'],
            'ICU Monitor' => ['Power cable', 'ECG leads', 'SpO2 probe', 'NIBP cuff', 'Temperature probe'],
            'Ventilator' => ['Power cable', 'Breathing circuit', 'Humidifier chamber', 'Filters', 'Oxygen hose', 'Manual/documents'],
        ];

        foreach ($templates as $name => $items) {
            $templateId = DB::table('accessory_templates')->where('name', $name)->value('id');

            if (!$templateId) {
                $templateId = DB::table('accessory_templates')->insertGetId([
                    'name' => $name,
                    'category' => $name,
                    'equipment_type' => strtolower(str_replace([' / ', ' '], ['_', '_'], $name)),
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach (array_values($items) as $index => $item) {
                DB::table('accessory_template_items')->updateOrInsert(
                    ['accessory_template_id' => $templateId, 'name' => $item],
                    ['is_required' => true, 'sort_order' => $index + 1, 'updated_at' => now(), 'created_at' => now()]
                );
            }
        }
    }
};
