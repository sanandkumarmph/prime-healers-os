<?php

namespace App\Console\Commands;

use App\Models\StockMovement;
use App\Models\User;
use App\Services\Inventory\StockMovementRecorder;
use Illuminate\Console\Command;

class CreateStockMovementCorrection extends Command
{
    protected $signature = 'phos:stock-history-correct
        {movement_id : The stock movement to compensate}
        {--performed-by-user= : Super admin user id authorizing the correction}
        {--correction-type= : correction_add, correction_remove, or correction_transfer}
        {--quantity= : Override compensating quantity; defaults to the original quantity}
        {--reason= : Mandatory correction reason}
        {--remarks= : Mandatory correction remarks}
        {--dry-run : Show the compensating payload without writing it}';

    protected $description = 'Create an immutable compensating stock movement entry for emergency corrections.';

    public function handle(StockMovementRecorder $recorder): int
    {
        $actorId = (int) $this->option('performed-by-user');

        if ($actorId <= 0) {
            $this->error('A super admin user id is required via --performed-by-user.');

            return self::FAILURE;
        }

        $actor = User::query()->find($actorId);

        if (!$actor || !$actor->isSuperAdmin()) {
            $this->error('Only a super admin can create emergency stock history corrections.');

            return self::FAILURE;
        }

        /** @var StockMovement $movement */
        $movement = StockMovement::query()->findOrFail((int) $this->argument('movement_id'));
        $quantity = max((int) ($this->option('quantity') ?: $movement->quantity), 0);

        if ($quantity <= 0) {
            $this->error('Correction quantity must be greater than zero.');

            return self::FAILURE;
        }

        $correctionType = strtolower(trim((string) $this->option('correction-type')));
        $reason = trim((string) $this->option('reason'));
        $remarks = trim((string) $this->option('remarks'));

        if (!in_array($correctionType, [
            StockMovement::TYPE_CORRECTION_ADD,
            StockMovement::TYPE_CORRECTION_REMOVE,
            StockMovement::TYPE_CORRECTION_TRANSFER,
        ], true)) {
            $this->error('A valid --correction-type is required: correction_add, correction_remove, or correction_transfer.');

            return self::FAILURE;
        }

        if ($reason === '') {
            $this->error('A correction reason is required via --reason.');

            return self::FAILURE;
        }

        if ($remarks === '') {
            $this->error('Correction remarks are required via --remarks.');

            return self::FAILURE;
        }

        $payload = [
            'organization_id' => $movement->organization_id,
            'product_id' => $movement->product_id,
            'asset_id' => $movement->asset_id,
            'movement_type' => $correctionType,
            'quantity' => $quantity,
            'from_status' => $movement->to_status,
            'to_status' => $movement->from_status,
            'from_warehouse_id' => $movement->to_warehouse_id,
            'to_warehouse_id' => $movement->from_warehouse_id,
            'rental_id' => $movement->rental_id,
            'sale_id' => $movement->sale_id,
            'delivery_id' => $movement->delivery_id,
            'invoice_id' => $movement->invoice_id,
            'payment_id' => $movement->payment_id,
            'performed_by_user_id' => $actor->id,
            'movement_at' => now(),
            'notes' => trim(collect([
                'Compensating entry for stock movement #' . $movement->id . '.',
                'Reason: ' . $reason,
                $remarks !== '' ? 'Remarks: ' . $remarks : null,
            ])->filter()->implode(' ')),
        ];

        if ($this->option('dry-run')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $correction = $recorder->record($payload);

        $this->info(sprintf(
            'Created compensating stock movement #%d for original movement #%d.',
            $correction?->id ?? 0,
            $movement->id
        ));

        return self::SUCCESS;
    }
}
