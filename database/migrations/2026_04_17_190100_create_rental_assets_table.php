<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rental_assets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rental_id')->constrained()->cascadeOnDelete();
            $table->foreignId('asset_id')->constrained()->restrictOnDelete();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->string('return_condition')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['rental_id', 'asset_id']);
            $table->index(['organization_id', 'asset_id']);
            $table->index(['organization_id', 'rental_id']);
            $table->index(['returned_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_assets');
    }
};
