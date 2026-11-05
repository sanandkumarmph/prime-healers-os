<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('rental_agreements', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id');
            $table->unsignedBigInteger('quotation_id')->nullable()->unique();
            $table->unsignedBigInteger('rental_id')->nullable();
            $table->index(['organization_id', 'rental_id', 'id']);
            $table->text('terms');
            $table->char('rental_fingerprint', 64)->nullable();
            $table->dateTime('signed_at')->nullable();
            $table->longText('proof')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        if (\Illuminate\Support\Facades\DB::table('rental_agreements')->whereNotNull('signed_at')->exists()) {
            throw new \RuntimeException('Preserve signed rental agreements.');
        }
        Schema::dropIfExists('rental_agreements');
    }
};
