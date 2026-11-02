<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('cylinder_refills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('rental_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->string('capacity_label');
            $table->foreignId('assigned_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('invoice_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->uuid('request_id');$table->char('request_fingerprint',64);
            $table->string('mode',20)->default('exchange');$table->string('stock_mode',20);
            $table->string('status',20)->default('open');$table->unsignedInteger('lock_version')->default(0);
            $table->unsignedInteger('quantity')->default(1);
            foreach(['delivered_quantity','collected_quantity','received_quantity','refilled_quantity'] as $name)$table->unsignedInteger($name)->default(0);
            $table->json('incoming_asset_ids')->nullable();$table->json('outgoing_asset_ids')->nullable();
            $table->string('serial_reference',500)->nullable();
            $table->dateTime('scheduled_at');$table->dateTime('completed_at')->nullable();
            $table->decimal('refill_charge',12,2)->default(0);$table->decimal('transport_charge',12,2)->default(0);
            $table->decimal('tax_rate',5,2)->default(0);$table->string('tax_type',20)->default('cgst_sgst');
            $table->text('notes')->nullable();$table->timestamps();
            $table->unique(['organization_id','request_id'],'cylinder_refills_request');
            $table->index(['organization_id','assigned_user_id','status'],'cylinder_refills_tasks');
        });
        Schema::create('cylinder_refill_events', function (Blueprint $table) {
            $table->id();$table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('cylinder_refill_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('request_id');$table->char('request_fingerprint',64);$table->string('action',40);
            $table->unsignedInteger('quantity')->default(0);$table->json('details');$table->timestamps();
            $table->unique(['organization_id','request_id'],'cylinder_refill_events_request');
        });
    }
    public function down(): void
    {
        foreach(['cylinder_refill_events','cylinder_refills']as$name)if(Schema::hasTable($name)&&DB::table($name)->exists())throw new RuntimeException('Refill history is in use. Retain the additive schema.');
        Schema::dropIfExists('cylinder_refill_events');Schema::dropIfExists('cylinder_refills');
    }
};
