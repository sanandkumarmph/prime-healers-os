<?php

namespace App\Services\Metrics;

use App\Models\Delivery;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LogisticsMetricsService
{
    private const WORKFLOW_BUCKETS = [
        'pending_delivery',
        'scheduled_delivery',
        'out_delivery',
        'overdue_delivery',
        'completed_delivery',
        'completed_today',
        'delivery_workload',
        'pending_pickup',
        'scheduled_pickup',
        'out_pickup',
        'overdue_pickup',
        'completed_pickup',
        'pickup_workload',
        'live',
        'overdue',
        'failed',
    ];

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

        $pendingDeliveryTasks = $this->applyWorkflowFilter($deliveryTasks, 'pending_delivery', $today);
        $scheduledDeliveryTasks = $this->applyWorkflowFilter($deliveryTasks, 'scheduled_delivery', $today);
        $outForDeliveryTasks = $this->applyWorkflowFilter($deliveryTasks, 'out_delivery', $today);
        $overdueDeliveryTasks = $this->applyWorkflowFilter($deliveryTasks, 'overdue_delivery', $today);
        $completedDeliveryTasks = $deliveryTasks->filter(fn (Delivery $delivery) => $this->isEffectivelyCompleted($delivery))->values();
        $deliveredTodayTasks = $completedDeliveryTasks->filter(fn (Delivery $delivery) => $this->completionDate($delivery) === $todayKey)->values();

        $pendingPickupTasks = $this->applyWorkflowFilter($pickupTasks, 'pending_pickup', $today);
        $scheduledPickupTasks = $this->applyWorkflowFilter($pickupTasks, 'scheduled_pickup', $today);
        $outForPickupTasks = $this->applyWorkflowFilter($pickupTasks, 'out_pickup', $today);
        $overduePickupTasks = $this->applyWorkflowFilter($pickupTasks, 'overdue_pickup', $today);
        $completedPickupTasks = $pickupTasks->filter(fn (Delivery $delivery) => $this->isEffectivelyCompleted($delivery))->values();
        $completedPickupTodayTasks = $completedPickupTasks->filter(fn (Delivery $delivery) => $this->completionDate($delivery) === $todayKey)->values();

        $completedTodayTasks = $tasks->filter(fn (Delivery $delivery) => $this->isEffectivelyCompleted($delivery) && $this->completionDate($delivery) === $todayKey)->values();
        $overdueTasks = $overdueDeliveryTasks->concat($overduePickupTasks)->values();
        $failedTasks = $this->applyWorkflowFilter($tasks, 'failed', $today);
        $deliveryWorkloadTasks = $pendingDeliveryTasks
            ->concat($scheduledDeliveryTasks)
            ->concat($outForDeliveryTasks)
            ->concat($overdueDeliveryTasks)
            ->values();
        $pickupWorkloadTasks = $pendingPickupTasks
            ->concat($scheduledPickupTasks)
            ->concat($outForPickupTasks)
            ->concat($overduePickupTasks)
            ->values();
        $todayTasks = $tasks->filter(fn (Delivery $delivery) => $this->scheduledDate($delivery) === $todayKey)->values();
        $todayDeliveryTasks = $todayTasks->where('type', 'delivery')->values();
        $todayPickupTasks = $todayTasks->where('type', 'pickup')->values();

        return [
            'totalTasksCount' => (int) $tasks->count(),
            'deliveryTasksCount' => (int) $deliveryWorkloadTasks->count(),
            'pickupTasksCount' => (int) $pickupWorkloadTasks->count(),
            'overdueTasksCount' => (int) $overdueTasks->count(),
            'failedTasksCount' => (int) $failedTasks->count(),
            'completedTodayCount' => (int) $completedTodayTasks->count(),
            'pendingDeliveryCount' => (int) $pendingDeliveryTasks->count(),
            'scheduledDeliveryCount' => (int) $scheduledDeliveryTasks->count(),
            'outForDeliveryCount' => (int) $outForDeliveryTasks->count(),
            'overdueDeliveryCount' => (int) $overdueDeliveryTasks->count(),
            'completedDeliveryCount' => (int) $completedDeliveryTasks->count(),
            'deliveredTodayCount' => (int) $deliveredTodayTasks->count(),
            'pendingPickupCount' => (int) $pendingPickupTasks->count(),
            'scheduledPickupCount' => (int) $scheduledPickupTasks->count(),
            'outForPickupCount' => (int) $outForPickupTasks->count(),
            'overduePickupCount' => (int) $overduePickupTasks->count(),
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

    public function applyWorkflowFilter(Collection $deliveries, string $workflow, ?Carbon $today = null): Collection
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $todayKey = $today->toDateString();
        $tasks = $deliveries->values();

        return match ($workflow) {
            'pending_delivery' => $tasks->filter(fn (Delivery $delivery) => $delivery->type === 'delivery'
                && $delivery->status === 'pending'
                && $this->taskNeedsAction($delivery)
                && !$this->isOverdueTask($delivery, $todayKey)
                && !$this->isFutureScheduledTask($delivery, $todayKey))->values(),
            'scheduled_delivery' => $tasks->filter(fn (Delivery $delivery) => $delivery->type === 'delivery'
                && $delivery->status === 'pending'
                && $this->taskNeedsAction($delivery)
                && $this->isFutureScheduledTask($delivery, $todayKey))->values(),
            'out_delivery' => $tasks->filter(fn (Delivery $delivery) => $delivery->type === 'delivery'
                && $delivery->status === 'in_progress'
                && $this->taskNeedsAction($delivery))->values(),
            'overdue_delivery' => $tasks->filter(fn (Delivery $delivery) => $delivery->type === 'delivery'
                && $this->isOverdueTask($delivery, $todayKey))->values(),
            'completed_delivery' => $tasks->filter(fn (Delivery $delivery) => $delivery->type === 'delivery'
                && $this->isEffectivelyCompleted($delivery))->values(),
            'completed_today' => $tasks->filter(fn (Delivery $delivery) => $this->isEffectivelyCompleted($delivery)
                && $this->completionDate($delivery) === $todayKey)->values(),
            'delivery_workload' => $this->applyWorkflowFilter($tasks, 'pending_delivery', $today)
                ->concat($this->applyWorkflowFilter($tasks, 'scheduled_delivery', $today))
                ->concat($this->applyWorkflowFilter($tasks, 'out_delivery', $today))
                ->concat($this->applyWorkflowFilter($tasks, 'overdue_delivery', $today))
                ->values(),
            'pending_pickup' => $tasks->filter(fn (Delivery $delivery) => $delivery->type === 'pickup'
                && $delivery->status === 'pending'
                && $this->taskNeedsAction($delivery)
                && !$this->isOverdueTask($delivery, $todayKey)
                && !$this->isFutureScheduledTask($delivery, $todayKey))->values(),
            'scheduled_pickup' => $tasks->filter(fn (Delivery $delivery) => $delivery->type === 'pickup'
                && $delivery->status === 'pending'
                && $this->taskNeedsAction($delivery)
                && $this->isFutureScheduledTask($delivery, $todayKey))->values(),
            'out_pickup' => $tasks->filter(fn (Delivery $delivery) => $delivery->type === 'pickup'
                && $delivery->status === 'in_progress'
                && $this->taskNeedsAction($delivery))->values(),
            'overdue_pickup' => $tasks->filter(fn (Delivery $delivery) => $delivery->type === 'pickup'
                && $this->isOverdueTask($delivery, $todayKey))->values(),
            'completed_pickup' => $tasks->filter(fn (Delivery $delivery) => $delivery->type === 'pickup'
                && $this->isEffectivelyCompleted($delivery))->values(),
            'pickup_workload' => $this->applyWorkflowFilter($tasks, 'pending_pickup', $today)
                ->concat($this->applyWorkflowFilter($tasks, 'scheduled_pickup', $today))
                ->concat($this->applyWorkflowFilter($tasks, 'out_pickup', $today))
                ->concat($this->applyWorkflowFilter($tasks, 'overdue_pickup', $today))
                ->values(),
            'live' => $this->applyWorkflowFilter($tasks, 'delivery_workload', $today)
                ->concat($this->applyWorkflowFilter($tasks, 'pickup_workload', $today))
                ->values(),
            'overdue' => $tasks->filter(fn (Delivery $delivery) => $this->isOverdueTask($delivery, $todayKey))->values(),
            'failed' => $tasks->filter(fn (Delivery $delivery) => $this->isFailedTask($delivery))->values(),
            default => $tasks,
        };
    }

    public function isSupportedWorkflow(?string $workflow): bool
    {
        return filled($workflow) && in_array($workflow, self::WORKFLOW_BUCKETS, true);
    }

    private function scheduledDate(Delivery $delivery): ?string
    {
        return optional($delivery->scheduled_at)->toDateString();
    }

    private function completionDate(Delivery $delivery): ?string
    {
        return optional($delivery->completed_at ?? $delivery->updated_at)->toDateString();
    }

    private function isFailedTask(Delivery $delivery): bool
    {
        if ($delivery->status === 'cancelled') {
            return true;
        }

        if (($delivery->pickup_status ?? null) === 'failed_attempt') {
            return true;
        }

        return filled($delivery->failed_attempt_reason ?? null);
    }

    private function isOverdueTask(Delivery $delivery, string $todayKey): bool
    {
        return in_array($delivery->status, ['pending', 'in_progress'], true)
            && $this->taskNeedsAction($delivery)
            && filled($this->scheduledDate($delivery))
            && $this->scheduledDate($delivery) < $todayKey;
    }

    private function isFutureScheduledTask(Delivery $delivery, string $todayKey): bool
    {
        return filled($this->scheduledDate($delivery))
            && $this->scheduledDate($delivery) > $todayKey;
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
