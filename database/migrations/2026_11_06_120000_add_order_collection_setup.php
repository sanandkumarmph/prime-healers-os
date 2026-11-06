<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            $table->string('collection_allowed_methods', 12)->nullable();
            $table->text('collection_instructions')->nullable();
        });

        Schema::table('delivery_collections', function (Blueprint $table) {
            $table->string('outcome', 12)->nullable();
        });

        Schema::create('order_collection_submissions', function (Blueprint $table) {
            $table->foreignId('organization_id')->constrained()->restrictOnDelete();
            $table->uuid('token');
            $table->timestamp('created_at');
            $table->primary(['organization_id', 'token']);
        });
    }

    public function down(): void
    {
        if (DB::table('order_collection_submissions')->exists()
            || DB::table('deliveries')->whereNotNull('collection_allowed_methods')->exists()
            || DB::table('delivery_collections')->whereNotNull('outcome')->exists()) {
            throw new RuntimeException('Order collection history exists. Use a coordinated recovery plan.');
        }
        Schema::dropIfExists('order_collection_submissions');
        Schema::table('delivery_collections', fn (Blueprint $table) => $table->dropColumn('outcome'));
        Schema::table('deliveries', fn (Blueprint $table) => $table->dropColumn(['collection_allowed_methods', 'collection_instructions']));
    }
};
