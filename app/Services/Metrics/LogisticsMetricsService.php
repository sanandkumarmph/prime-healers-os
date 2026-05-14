<?php

namespace App\Services\Metrics;

use App\Models\Delivery;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LogisticsMetricsService
{
    public function dedupe(Collection $deliveries): Collection
    {
        return $deliveries
            ->sortByDesc('id')
            ->unique(function (Delivery $delivery) {
                return $delivery->sale_id
                    ? 'sale:' . $delivery->sale_id . ':' . $delivery->type
                    : 'rental:' . ($delivery->rental_id ?? 'none') . ':' . $delivery->type;
            })
            ->values();
    }

    public function summary(Collection $deliveries, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $todayKey = $today->toDateString();
        $tasks = $deliveries->values();

        $deliveryTasks = $tasks->where('type', 'delivery')->values();
        $pickupTasks = $tasks->where('type', 'pickup')->values();

        $pendingDeliveryTasks = $deliveryTasks->filter(fn (Delivery $delivery) => $delivery->status === 'pending' && $this->taskNeedsAction($delivery))->values();
        $outForDeliveryTasks = $deliveryTasks->filter(fn (Delivery $delivery) => $delivery->status === 'in_progress' && $this->taskNeedsAction($delivery))->values();
        $completedDeliveryTasks = $deliveryTasks->filter(fn (Delivery $delivery) => $this->isEffectivelyCompleted($delivery))->values();
        $deliveredTodayTasks = $completedDeliveryTasks->filter(fn (Delivery $delivery) => $this->completionDate($delivery) === $todayKey)->values();

        $pendingPickupTasks = $pickupTasks->filter(fn (Delivery $delivery) => $delivery->status === 'pending' && $this->taskNeedsAction($delivery))->values();
        $outForPickupTasks = $pickupTasks->filter(fn (Delivery $delivery) => $delivery->status === 'in_progress' && $this->taskNeedsAction($delivery))->values();
        $completedPickupTasks = $pickupTasks->filter(fn (Delivery $delivery) => $this->isEffectivelyCompleted($delivery))->values();
        $completedPickupTodayTasks = $completedPickupTasks->filter(fn (Delivery $delivery) => $this->completionDate($delivery) === $todayKey)->values();

        $completedTodayTasks = $tasks->filter(fn (Delivery $delivery) => $this->isEffectivelyCompleted($delivery) && $this->completionDate($delivery) === $todayKey)->values();
        $overdueTasks = $tasks->filter(fn (Delivery $delivery) => $this->isOverdueTask($delivery, $todayKey))->values();
        $todayTasks = $tasks->filter(fn (Delivery $delivery) => $this->scheduledDate($delivery) === $todayKey)->values();
        $todayDeliveryTasks = $todayTasks->where('type', 'delivery')->values();
        $todayPickupTasks = $todayTasks->where('type', 'pickup')->values();

        return [
            'totalTasksCount' => (int) $tasks->count(),
            'deliveryTasksCount' => (int) $deliveryTasks->count(),
            'pickupTasksCount' => (int) $pickupTasks->count(),
            'overdueTasksCount' => (int) $overdueTasks->count(),
            'completedTodayCount' => (int) $completedTodayTasks->count(),
            'pendingDeliveryCount' => (int) $pendingDeliveryTasks->count(),
            'outForDeliveryCount' => (int) $outForDeliveryTasks->count(),
            'deliveredTodayCount' => (int) $deliveredTodayTasks->count(),
            'pendingPickupCount' => (int) $pendingPickupTasks->count(),
            'outForPickupCount' => (int) $outForPickupTasks->count(),
            'completedPickupCount' => (int) $completedPickupTasks->count(),
            'pickedUpTodayCount' => (int) $completedPickupTodayTasks->count(),
            'todayTaskCount' => (int) $todayTasks->count(),
            'todayDeliveryCount' => (int) $todayDeliveryTasks->count(),
            'todayPickupCount' => (int) $todayPickupTasks->count(),
            'pendingCollectionsCount' => (int) $pendingPickupTasks->count(),
            'todayTasks' => $todayTasks,
            'overdueTasks' => $overdueTasks,
            'pendingPickupTasks' => $pendingPickupTasks,
        ];
    }

    private function scheduledDate(Delivery $delivery): ?string
    {
        return optional($delivery->scheduled_at)->toDateString();
    }

    private function completionDate(Delivery $delivery): ?string
    {
        return optional($delivery->completed_at ?? $delivery->updated_at)->toDateString();
    }

    private function isOverdueTask(Delivery $delivery, string $todayKey): bool
    {
        return in_array($delivery->status, ['pending', 'in_progress'], true)
            && $this->taskNeedsAction($delivery)
            && filled($this->scheduledDate($delivery))
            && $this->scheduledDate($delivery) < $todayKey;
    }

    private function taskNeedsAction(Delivery $delivery): bool
    {
        if ($delivery->status === 'cancelled') {
            return false;
        }

        if ($this->isEffectivelyCompleted($delivery)) {
            return false;
        }

        if ($delivery->sale_id || !$delivery->rental) {
            return in_array($delivery->status, ['pending', 'in_progress'], true);
        }

        if ($delivery->type === 'delivery') {
            return $delivery->rental->pendingDeliveryQuantityTotal() > 0;
        }

        return $delivery->rental->pendingPickupQuantityTotal() > 0;
    }

    private function isEffectivelyCompleted(Delivery $delivery): bool
    {
        if ($delivery->status === 'completed') {
            return true;
        }

        if ($delivery->sale_id || !$delivery->rental) {
            return false;
        }

        if ($delivery->type === 'delivery') {
            return $delivery->rental->pendingDeliveryQuantityTotal() <= 0
                && $delivery->rental->deliveredQuantityTotal() > 0;
        }

        return $delivery->rental->pendingPickupQuantityTotal() <= 0
            && $delivery->rental->returnedQuantityTotal() > 0;
    }
}
