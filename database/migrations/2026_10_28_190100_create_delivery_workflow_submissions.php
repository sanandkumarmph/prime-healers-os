<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('delivery_workflow_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->foreignId('delivery_id')->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->uuid('submission_token');
            $table->string('action', 50);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->text('redirect_url');
            $table->timestamps();
            $table->unique(['organization_id','submission_token'], 'phos_delivery_submission_unique');
        });
    }

    public function down(): void
    {
        if (DB::table('delivery_workflow_submissions')->exists()) throw new RuntimeException('Saved delivery submissions exist. Preserve their replay protection and history.');
        Schema::dropIfExists('delivery_workflow_submissions');
    }
};
