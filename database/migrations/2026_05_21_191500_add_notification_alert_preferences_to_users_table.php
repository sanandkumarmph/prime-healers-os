<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'notification_sound_enabled')) {
                $table->boolean('notification_sound_enabled')->default(false)->after('is_active');
            }

            if (!Schema::hasColumn('users', 'notification_voice_enabled')) {
                $table->boolean('notification_voice_enabled')->default(false)->after('notification_sound_enabled');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'notification_voice_enabled')) {
                $table->dropColumn('notification_voice_enabled');
            }

            if (Schema::hasColumn('users', 'notification_sound_enabled')) {
                $table->dropColumn('notification_sound_enabled');
            }
        });
    }
};
