<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_payment_accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->uuid('webhook_token')->unique();
            $t->string('mode', 10);
            $t->string('key_fingerprint', 64)->unique();
            $t->text('key_id');
            $t->text('key_secret');
            $t->text('webhook_secret');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
            $t->index(['organization_id', 'is_active']);
        });
        Schema::create('customer_payment_links', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $t->foreignId('account_id')->constrained('customer_payment_accounts')->restrictOnDelete();
            $t->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->uuid('reference')->unique();
            $t->string('provider_id')->nullable()->unique();
            $t->string('status', 30)->default('creating');
            $t->unsignedBigInteger('amount_minor');
            $t->string('currency', 3)->default('INR');
            $t->string('short_url')->nullable();
            $t->boolean('reminders')->default(false);
            $t->timestamp('expires_at');
            $t->timestamp('checked_at')->nullable();
            $t->timestamps();
            $t->index(['organization_id', 'invoice_id', 'status'], 'customer_links_invoice_status');
        });
        Schema::create('customer_payment_receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('link_id')->constrained('customer_payment_links')->restrictOnDelete();
            $t->foreignId('account_id')->constrained('customer_payment_accounts')->restrictOnDelete();
            $t->string('provider_payment_id');
            $t->string('event_id')->nullable();
            $t->unsignedBigInteger('amount_minor');
            $t->string('currency', 3);
            $t->string('method', 30);
            $t->timestamp('paid_at');
            $t->string('status', 30)->default('received');
            $t->string('review_reason')->nullable();
            $t->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $t->timestamps();
            $t->unique(['account_id', 'provider_payment_id'], 'customer_receipts_provider_payment');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_payment_receipts');
        Schema::dropIfExists('customer_payment_links');
        Schema::dropIfExists('customer_payment_accounts');
    }
};
