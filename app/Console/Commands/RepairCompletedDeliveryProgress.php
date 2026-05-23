<?php

namespace App\Console\Commands;

use App\Models\Delivery;
use App\Models\Rental;
use App\Services\Deliveries\DeliveryWorkflowService;
use Illuminate\Console\Command;

class RepairCompletedDeliveryProgress extends Command
{
    protected $signature = 'phos:repair-completed-delivery-progress
        {--organization= : Limit repairs to one organization id}
        {--apply : Apply the safe delivery-item progress repair}';

    protected $description = 'Dry-run or repair completed delivery tasks whose rental item delivered quantities were never finalized.';

    public function handle(DeliveryWorkflowService $workflowService): int
    {
        $organizationId = $this->option('organization');
        $apply = (bool) $this->option('apply');
        $scanned = 0;
        $repaired = 0;

        $query = Delivery::query()
            ->where('type', 'delivery')
            ->where('status', 'completed')
            ->whereNotNull('rental_id')
            ->when($organizationId, fn ($builder) => $builder->where('organization_id', (int) $organizationId))
            ->with('rental');

        if (!Rental::hasRentalItemsTable()) {
            $this->warn('Rental items table is not available in this environment. No repairs were needed.');

            return self::SUCCESS;
        }

        $processedRentals = [];

        $query->chunkById(100, function ($deliveries) use ($workflowService, $apply, &$scanned, &$repaired, &$processedRentals) {
            foreach ($deliveries as $delivery) {
                $rental = $delivery->rental;

                if (!$rental || isset($processedRentals[$rental->id])) {
                    continue;
                }

                $processedRentals[$rental->id] = true;
                $scanned++;

                $workflowService->ensureRentalItemsExist($delivery->organization_id, $rental);
                $rental->unsetRelation('rentalItems');

                if ($rental->pendingDeliveryQuantityTotal() === 0) {
                    continue;
                }

                $this->line(sprintf(
                    'Rental #%d (delivery #%d) has stale delivery progress: delivered=%d pending=%d',
                    $rental->id,
                    $delivery->id,
                    $rental->deliveredQuantityTotal(),
                    $rental->pendingDeliveryQuantityTotal()
                ));

                if (!$apply) {
                    continue;
                }

                $workflowService->finalizeDeliveryCompletionForRental($delivery->organization_id, $rental);
                $repaired++;
            }
        });

        $this->info(sprintf(
            'Completed delivery progress audit finished. Scanned %d rental%s, repaired %d.',
            $scanned,
            $scanned === 1 ? '' : 's',
            $repaired
        ));

        if (!$apply) {
            $this->line('Run again with --apply to persist the safe repairs.');
        }

        return self::SUCCESS;
    }
}
