<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['deliveries', 'delivery_collections', 'payments'] as $name) {
            foreach (['collection_recipient' => 16, 'collection_vendor_key' => 40, 'collection_vendor_name' => 255] as $column => $length) {
                if (!Schema::hasColumn($name, $column)) {
                    Schema::table($name, fn (Blueprint $table) => $table->string($column, $length)->nullable());
                }
            }
            $index = $name.'_vendor_collection_lookup';
            if (!Schema::hasIndex($name, $index)) {
                Schema::table($name, fn (Blueprint $table) => $table->index(['organization_id', 'collection_vendor_key'], $index));
            }
        }
    }

    public function down(): void
    {
        foreach (['deliveries', 'delivery_collections', 'payments'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name) {
                $table->dropIndex($name.'_vendor_collection_lookup');
                $table->dropColumn(['collection_recipient', 'collection_vendor_key', 'collection_vendor_name']);
            });
        }
    }
};
