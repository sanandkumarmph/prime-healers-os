<?php

use App\Models\Delivery;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            if (!Schema::hasColumn('deliveries', 'pickup_status')) {
                $table->string('pickup_status')->nullable()->after('status');
            }

            if (!Schema::hasColumn('deliveries', 'pickup_time_slot')) {
                $table->string('pickup_time_slot')->nullable()->after('pickup_status');
            }

            if (!Schema::hasColumn('deliveries', 'failed_attempt_reason')) {
                $table->string('failed_attempt_reason')->nullable()->after('pickup_time_slot');
            }

            if (!Schema::hasColumn('deliveries', 'failed_attempt_note')) {
                $table->text('failed_attempt_note')->nullable()->after('failed_attempt_reason');
            }

            if (!Schema::hasColumn('deliveries', 'failed_attempt_at')) {
                $table->dateTime('failed_attempt_at')->nullable()->after('failed_attempt_note');
            }

            if (!Schema::hasColumn('deliveries', 'last_pickup_note')) {
                $table->text('last_pickup_note')->nullable()->after('failed_attempt_at');
            }

            if (!Schema::hasColumn('deliveries', 'last_pickup_note_at')) {
                $table->dateTime('last_pickup_note_at')->nullable()->after('last_pickup_note');
            }

            if (!Schema::hasColumn('deliveries', 'rescheduled_from_at')) {
                $table->dateTime('rescheduled_from_at')->nullable()->after('last_pickup_note_at');
            }
        });

        Delivery::query()
            ->where('type', 'pickup')
            ->where(function ($query): void {
                $query->whereNull('pickup_status')
                    ->orWhereNull('pickup_time_slot');
            })
            ->orderBy('id')
            ->chunkById(200, function ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    $delivery->forceFill([
                        'pickup_status' => $delivery->pickup_status ?: match ($delivery->status) {
                            'completed' => 'picked_up',
                            'cancelled' => 'cancelled',
                            'in_progress' => 'in_progress',
                            default => ($delivery->assigned_user_id || $delivery->assigned_staff_id || filled($delivery->third_party_name))
                                ? 'assigned'
                                : 'requested',
                        },
                        'pickup_time_slot' => $delivery->pickup_time_slot ?: $delivery->scheduled_at?->format('H:i'),
                    ])->saveQuietly();
                }
            });
    }

    public function down(): void
    {
        Schema::table('deliveries', function (Blueprint $table) {
            foreach ([
                'pickup_status',
                'pickup_time_slot',
                'failed_attempt_reason',
                'failed_attempt_note',
                'failed_attempt_at',
                'last_pickup_note',
                'last_pickup_note_at',
                'rescheduled_from_at',
            ] as $column) {
                if (Schema::hasColumn('deliveries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
