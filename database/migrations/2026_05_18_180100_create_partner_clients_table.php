<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('partner_clients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('business_partner_id')->constrained('business_partners')->cascadeOnDelete();
            $table->string('client_name');
            $table->string('phone', 30)->nullable();
            $table->string('alternate_phone', 30)->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('pincode', 20)->nullable();
            $table->string('location')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->text('delivery_notes')->nullable();
            $table->string('status')->default('active');
            $table->timestamps();

            $table->index(['organization_id', 'business_partner_id', 'status']);
            $table->index(['organization_id', 'client_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('partner_clients');
    }
};
