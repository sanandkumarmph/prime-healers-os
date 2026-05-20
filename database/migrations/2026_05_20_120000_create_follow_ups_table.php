<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('follow_ups')) {
            return;
        }

        Schema::create('follow_ups', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('customer_id')->nullable()->index();
            $table->unsignedBigInteger('business_partner_id')->nullable()->index();
            $table->unsignedBigInteger('partner_client_id')->nullable()->index();
            $table->unsignedBigInteger('rental_id')->nullable()->index();
            $table->unsignedBigInteger('sale_id')->nullable()->index();
            $table->unsignedBigInteger('invoice_id')->nullable()->index();
            $table->unsignedBigInteger('delivery_id')->nullable()->index();
            $table->unsignedBigInteger('assigned_user_id')->nullable()->index();
            $table->unsignedBigInteger('created_by_user_id')->nullable()->index();
            $table->string('followup_type', 40)->index();
            $table->string('title', 180);
            $table->text('note')->nullable();
            $table->dateTime('due_at')->index();
            $table->string('status', 20)->default('pending')->index();
            $table->string('priority', 20)->default('medium')->index();
            $table->dateTime('completed_at')->nullable()->index();
            $table->boolean('is_system_generated')->default(false)->index();
            $table->string('source', 40)->nullable()->index();
            $table->timestamps();

            $table->index(['organization_id', 'status', 'due_at']);
            $table->index(['organization_id', 'assigned_user_id', 'status', 'due_at']);
            $table->index(['organization_id', 'followup_type', 'status', 'due_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('follow_ups');
    }
};
