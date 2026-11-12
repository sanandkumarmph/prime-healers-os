<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB,Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::table('cylinder_refills',function(Blueprint $table){
            $table->unsignedBigInteger('assigned_user_id')->nullable()->change();
            $table->string('assignment_type',20)->nullable();
            $table->foreignId('assigned_staff_id')->nullable()->constrained('staff')->restrictOnDelete();
            $table->foreignId('assigned_vendor_id')->nullable()->constrained('vendors')->restrictOnDelete();
            $table->string('assignment_name')->nullable();$table->string('assignment_contact')->nullable();
            $table->string('assignment_phone',40)->nullable();
        });
    }
    public function down(): void
    {
        if(DB::table('cylinder_refills')->whereNotNull('assignment_type')->orWhereNull('assigned_user_id')->exists())
            throw new RuntimeException('Delivery assignments are in use. Preserve this schema and its history.');
        Schema::table('cylinder_refills',function(Blueprint $table){
            $table->dropForeign(['assigned_staff_id']);$table->dropForeign(['assigned_vendor_id']);
            $table->dropColumn(['assignment_type','assigned_staff_id','assigned_vendor_id','assignment_name','assignment_contact','assignment_phone']);
            $table->unsignedBigInteger('assigned_user_id')->nullable(false)->change();
        });
    }
};
