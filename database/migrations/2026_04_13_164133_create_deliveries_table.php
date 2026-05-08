<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('deliveries', function (Blueprint $table) {
    $table->id();
    $table->foreignId('rental_id')->nullable()->constrained()->nullOnDelete();

    $table->string('type'); // delivery or pickup
    $table->string('assigned_to')->nullable();

    $table->dateTime('scheduled_at')->nullable();
    $table->dateTime('completed_at')->nullable();

    $table->string('status')->default('pending'); // pending, in_progress, completed

    $table->timestamps();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('deliveries');
    }
};
