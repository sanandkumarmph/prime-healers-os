<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('name')->after('id');
            $table->string('phone')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('id_proof_type')->nullable();
            $table->string('id_proof_number')->nullable();
            $table->text('notes')->nullable();
        });
    }

    public function down()
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn([
                'name',
                'phone',
                'address',
                'city',
                'id_proof_type',
                'id_proof_number',
                'notes'
            ]);
        });
    }
};