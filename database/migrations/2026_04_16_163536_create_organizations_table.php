<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('logo')->nullable();

            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('state_code')->nullable();
            $table->string('pincode')->nullable();
            $table->string('country')->default('India');

            $table->string('gst_number')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();

            $table->text('default_terms')->nullable();

            // Multi-tenant / SaaS fields
            $table->string('plan')->default('internal');
            $table->boolean('is_internal')->default(true);
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organizations');
    }
};