<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('rental_renewals')) {
            return;
        }

        Schema::create('rental_renewals', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->index();
            $table->unsignedBigInteger('rental_id')->index();
            $table->date('previous_end_date')->nullable();
            $table->date('renewed_end_date');
            $table->unsignedInteger('renewal_days')->default(0);
            $table->string('renewal_type', 30)->default('custom');
            $table->decimal('rental_amount_added', 10, 2)->default(0);
            $table->decimal('deposit_amount_added', 10, 2)->default(0);
            $table->decimal('transport_amount_added', 10, 2)->default(0);
            $table->decimal('other_amount_added', 10, 2)->default(0);
            $table->unsignedBigInteger('payment_id')->nullable()->index();
            $table->decimal('payment_amount', 10, 2)->default(0);
            $table->string('payment_method', 50)->nullable();
            $table->timestamp('reminder_sent_at')->nullable();
            $table->string('reminder_channel', 30)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('renewed_by_user_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rental_renewals');
    }
};
