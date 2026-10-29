<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_collections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('invoice_id')->constrained()->restrictOnDelete();
            $table->foreignId('collector_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->foreignId('proof_id')->nullable()->constrained('delivery_proofs')->restrictOnDelete();
            $table->uuid('submission_token');
            $table->string('request_hash', 64);
            $table->decimal('amount', 12, 2);
            $table->string('method', 30);
            $table->string('status', 30);
            $table->string('reference')->nullable();
            $table->text('note')->nullable();
            $table->dateTime('received_at');
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            $table->text('review_reason')->nullable();
            $table->foreignId('reversal_payment_id')->nullable()->unique()->constrained('payments')->restrictOnDelete();
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('reversed_at')->nullable();
            $table->text('reversal_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'submission_token'], 'phos_collection_token_unique');
            $table->index(['organization_id', 'delivery_id', 'status']);
        });

        Schema::create('delivery_cash_handovers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('collection_id')->constrained('delivery_collections')->restrictOnDelete();
            $table->foreignId('collector_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('receiver_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('submission_token');
            $table->uuid('confirmation_token')->nullable();
            $table->decimal('submitted_amount', 12, 2);
            $table->decimal('received_amount', 12, 2)->nullable();
            $table->string('status', 20)->default('pending');
            $table->dateTime('submitted_at');
            $table->dateTime('confirmed_at')->nullable();
            $table->text('discrepancy_reason')->nullable();
            $table->foreignId('corrected_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->dateTime('corrected_at')->nullable();
            $table->text('correction_reason')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'submission_token'], 'phos_handover_token_unique');
            $table->unique(['organization_id', 'confirmation_token'], 'phos_handover_confirm_unique');
        });
        // Existing task reports, proofs, Payments and collection flags remain untouched.
    }

    public function down(): void
    {
        if (DB::table('delivery_collections')->exists() || DB::table('delivery_cash_handovers')->exists()) {
            throw new RuntimeException('Collection or cash history exists. Roll forward or use a coordinated recovery plan.');
        }
        Schema::dropIfExists('delivery_cash_handovers');
        Schema::dropIfExists('delivery_collections');
    }
};
