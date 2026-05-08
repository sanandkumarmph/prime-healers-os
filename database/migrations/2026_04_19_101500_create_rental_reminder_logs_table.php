<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_reminder_logs')) {
            return;
        }

        Schema::create('rental_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('rental_id');
            $table->unsignedBigInteger('customer_id')->nullable();
            $table->string('reminder_type', 50)->default('renewal');
            $table->string('sent_via', 30)->default('whatsapp');
            $table->string('sent_to_number', 30)->nullable();
            $table->text('message_preview')->nullable();
            $table->unsignedBigInteger('sent_by_user_id')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'rental_id']);
            $table->index(['rental_id', 'sent_at']);
            $table->index(['customer_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_reminder_logs');
    }
};
