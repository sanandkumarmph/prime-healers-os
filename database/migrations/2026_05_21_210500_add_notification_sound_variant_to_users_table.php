<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'notification_sound_variant')) {
                $table->string('notification_sound_variant', 32)
                    ->default('default')
                    ->after('notification_voice_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'notification_sound_variant')) {
                $table->dropColumn('notification_sound_variant');
            }
        });
    }
};
