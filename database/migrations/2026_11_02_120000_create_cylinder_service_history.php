<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cylinders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('asset_id')->unique()->constrained()->restrictOnDelete();
            $table->string('contents', 20)->default('unknown');
            $table->string('custody', 20)->default('warehouse');
            $table->foreignId('reserved_for_rental_id')->nullable()->constrained('rentals')->restrictOnDelete();
            $table->timestamps();
            $table->index(['organization_id', 'contents', 'custody'], 'cylinders_stock_state');
        });
        Schema::create('cylinder_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('rental_id')->constrained()->restrictOnDelete();
            $table->foreignId('incoming_cylinder_id')->constrained('cylinders')->restrictOnDelete();
            $table->foreignId('outgoing_cylinder_id')->constrained('cylinders')->restrictOnDelete();
            $table->foreignId('assigned_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->uuid('request_id');
            $table->char('request_fingerprint', 64);
            $table->string('mode', 20);
            $table->string('status', 20)->default('open');
            $table->unsignedInteger('lock_version')->default(0);
            $table->dateTime('scheduled_at');
            $table->dateTime('collected_at')->nullable();
            $table->dateTime('warehouse_received_at')->nullable();
            $table->dateTime('refill_sent_at')->nullable();
            $table->dateTime('refilled_at')->nullable();
            $table->dateTime('delivered_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->decimal('refill_charge', 12, 2)->default(0);
            $table->decimal('transport_charge', 12, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->string('tax_type', 20)->default('cgst_sgst');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'request_id'], 'cylinder_jobs_request');
            $table->index(['organization_id', 'status', 'assigned_user_id'], 'cylinder_jobs_queue');
        });
        Schema::create('cylinder_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('cylinder_id')->constrained()->restrictOnDelete();
            $table->foreignId('cylinder_job_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id');
            $table->char('request_fingerprint', 64);
            $table->string('action', 40);
            $table->json('details');
            $table->timestamps();
            $table->unique(['organization_id', 'request_id'], 'cylinder_events_request');
            $table->unique(['cylinder_job_id', 'action'], 'cylinder_job_stage');
        });
    }

    public function down(): void
    {
        foreach (['cylinder_events', 'cylinder_jobs', 'cylinders'] as $name) {
            if (Schema::hasTable($name) && DB::table($name)->exists()) {
                throw new RuntimeException('Cylinder history is in use. Keep the additive schema when rolling back application code.');
            }
        }
        Schema::dropIfExists('cylinder_events');
        Schema::dropIfExists('cylinder_jobs');
        Schema::dropIfExists('cylinders');
    }
};
