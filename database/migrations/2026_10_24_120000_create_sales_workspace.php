<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\{DB, Schema};

return new class extends Migration {
    public function up(): void
    {
        Schema::create('enquiries', function (Blueprint $t) {
            $t->id();
            $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $t->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('name'); $t->string('phone', 40)->nullable(); $t->string('email')->nullable();
            $t->string('subject'); $t->text('requirements')->nullable(); $t->string('source', 80)->nullable();
            $t->string('stage', 24)->default('new'); $t->text('lost_reason')->nullable();
            $t->unsignedInteger('lock_version')->default(1); $t->timestamps();
            $t->index(['organization_id', 'owner_id', 'stage']);
        });
        Schema::create('quotations', function (Blueprint $t) {
            $t->id(); $t->foreignId('organization_id')->constrained()->restrictOnDelete();
            $t->foreignId('enquiry_id')->constrained()->restrictOnDelete();
            $t->unsignedInteger('revision'); $t->unsignedInteger('lock_version')->default(1);
            $t->string('kind', 12); $t->string('status', 24)->default('draft');
            $t->date('valid_until'); $t->json('snapshot');
            $t->timestamp('sent_at')->nullable(); $t->timestamp('viewed_at')->nullable();
            $t->timestamp('accepted_at')->nullable(); $t->string('order_type', 12)->nullable();
            $t->unsignedBigInteger('order_id')->nullable(); $t->timestamps();
            $t->unique(['enquiry_id', 'revision']);
            $t->unique(['order_type', 'order_id']);
            $t->index(['organization_id', 'status']);
        });
        Schema::create('enquiry_follow_ups', function (Blueprint $t) {
            $t->id(); $t->foreignId('enquiry_id')->constrained()->restrictOnDelete();
            $t->string('channel', 20); $t->string('subject'); $t->timestamp('due_at');
            $t->timestamp('completed_at')->nullable(); $t->string('outcome', 40)->nullable();
            $t->text('completion_note')->nullable(); $t->timestamps();
            $t->index(['enquiry_id', 'completed_at', 'due_at']);
        });
        Schema::create('enquiry_activities', function (Blueprint $t) {
            $t->id(); $t->foreignId('enquiry_id')->constrained()->restrictOnDelete();
            $t->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('type', 40); $t->text('body'); $t->timestamp('created_at');
            $t->index(['enquiry_id', 'id']);
        });
        // Add only the new modules to system templates. Custom grants remain untouched.
        DB::table('roles')->where('is_system', true)->whereIn('slug', ['super_admin', 'admin_operations', 'sales'])->orderBy('id')->eachById(function ($role) {
            $permissions = json_decode($role->permissions ?: '{}', true) ?: [];
            foreach (['enquiries', 'quotations'] as $module) {
                $permissions[$module] = array_values(array_unique(array_merge($permissions[$module] ?? [], ['read', 'create', 'update'])));
            }
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
        });
    }

    public function down(): void
    {
        if (DB::table('enquiries')->exists() || DB::table('quotations')->exists()) {
            throw new RuntimeException('Sales workspace history exists. Use a coordinated recovery plan instead of dropping it.');
        }
        Schema::dropIfExists('enquiry_activities'); Schema::dropIfExists('enquiry_follow_ups');
        Schema::dropIfExists('quotations'); Schema::dropIfExists('enquiries');
    }
};
