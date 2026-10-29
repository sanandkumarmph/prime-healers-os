<?php

namespace App\Services\Deliveries;

use App\Models\Delivery;
use App\Models\DeliveryProof;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeliveryProofService
{
    private function trackNewFile(string $path): void
    {
        $files = request()->attributes->get('phos_new_proof_files', []);
        $files[] = ['disk' => $this->disk(), 'path' => $path];
        request()->attributes->set('phos_new_proof_files', $files);
    }

    public function cleanupFailedRequestFiles(\Illuminate\Http\Request $request): void
    {
        foreach ($request->attributes->get('phos_new_proof_files', []) as $file) {
            try {
                $prefix = 'delivery-proofs/org-'.(int)$request->user()?->organization_id.'/delivery-';
                if (str_starts_with($file['path'], $prefix)
                    && !DeliveryProof::where('file_path', $file['path'])->exists()) {
                    Storage::disk($file['disk'])->delete($file['path']);
                }
            } catch (\Throwable $exception) { report($exception); }
        }
        $request->attributes->remove('phos_new_proof_files');
    }

    public function disk(): string
    {
        return (string) config('proof.storage_disk', 'local');
    }

    public function maxKilobytes(): int
    {
        return max((int) config('proof.image_max_kb', 100), 1);
    }

    public function targetKilobytes(): int
    {
        return max((int) config('proof.image_target_kb', 50), 1);
    }

    public function maxBytes(): int
    {
        return $this->maxKilobytes() * 1024;
    }

    public function targetBytes(): int
    {
        return $this->targetKilobytes() * 1024;
    }

    public function storeUploadedFiles(
        Delivery $delivery,
        User $user,
        string $workflowStage,
        string $captureMoment,
        string $proofType,
        array $files,
        ?string $notes = null,
        array $meta = []
    ): array {
        $stored = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $path = $file->storeAs(
                $this->directory($delivery),
                $this->filename($workflowStage, $captureMoment, $proofType, $file->extension() ?: 'jpg'),
                $this->disk()
            );
            if (!$path) throw new \RuntimeException('Private proof storage could not save the image.');
            $this->trackNewFile($path);

            $stored[] = DeliveryProof::create([
                'organization_id' => $delivery->organization_id,
                'delivery_id' => $delivery->id,
                'rental_id' => $delivery->rental_id,
                'workflow_stage' => $workflowStage,
                'capture_moment' => $captureMoment,
                'proof_type' => $proofType,
                'file_path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size_bytes' => $file->getSize(),
                'captured_at' => now(),
                'notes' => $notes,
                'meta' => $meta,
                'created_by_user_id' => $user->id,
            ]);
        }

        return $stored;
    }

    public function storeSignature(
        Delivery $delivery,
        User $user,
        string $workflowStage,
        string $captureMoment,
        string $signatureData,
        string $acknowledgementText,
        ?string $notes = null,
        array $meta = []
    ): DeliveryProof {
        if (! preg_match('#^data:(image/(png|webp));base64,(.+)$#', $signatureData, $matches)) {
            throw ValidationException::withMessages([
                'signature_data' => 'Signature capture is invalid. Please sign again before submitting.',
            ]);
        }

        $mimeType = 'image/png';
        $extension = 'png';
        $binary = SignatureCapture::clean($signatureData, $this->targetBytes());

        if ($binary === false || $binary === '') {
            throw ValidationException::withMessages([
                'signature_data' => 'Signature capture could not be processed. Please sign again.',
            ]);
        }

        if (strlen($binary) > $this->targetBytes()) {
            throw ValidationException::withMessages([
                'signature_data' => 'Signature is too large. Please clear it and sign again with a shorter stroke area.',
            ]);
        }

        $path = $this->directory($delivery) . '/' . $this->filename($workflowStage, $captureMoment, DeliveryProof::TYPE_SIGNATURE, $extension);
        if (!Storage::disk($this->disk())->put($path, $binary)) throw new \RuntimeException('Private signature storage could not save the capture.');
        $this->trackNewFile($path);

        return DeliveryProof::create([
            'organization_id' => $delivery->organization_id,
            'delivery_id' => $delivery->id,
            'rental_id' => $delivery->rental_id,
            'workflow_stage' => $workflowStage,
            'capture_moment' => $captureMoment,
            'proof_type' => DeliveryProof::TYPE_SIGNATURE,
            'file_path' => $path,
            'original_name' => 'signature-' . $workflowStage . '.' . $extension,
            'mime_type' => $mimeType,
            'size_bytes' => strlen($binary),
            'captured_at' => now(),
            'acknowledgement_text' => $acknowledgementText,
            'notes' => $notes,
            'meta' => $meta,
            'created_by_user_id' => $user->id,
        ]);
    }

    public function storeLocationCapture(
        Delivery $delivery,
        User $user,
        string $workflowStage,
        string $captureMoment,
        ?float $latitude,
        ?float $longitude,
        ?float $accuracy,
        ?string $capturedAt,
        ?string $missingReason = null
    ): DeliveryProof {
        $resolvedCapturedAt = $capturedAt
            ? Carbon::parse($capturedAt, config('app.timezone'))->setTimezone(config('app.timezone'))
            : now();

        return DeliveryProof::create([
            'organization_id' => $delivery->organization_id,
            'delivery_id' => $delivery->id,
            'rental_id' => $delivery->rental_id,
            'workflow_stage' => $workflowStage,
            'capture_moment' => $captureMoment,
            'proof_type' => DeliveryProof::TYPE_LOCATION,
            'latitude' => $latitude,
            'longitude' => $longitude,
            'accuracy' => $accuracy,
            'captured_at' => $resolvedCapturedAt,
            'notes' => $missingReason,
            'meta' => [
                'location_missing' => blank($latitude) || blank($longitude),
            ],
            'created_by_user_id' => $user->id,
        ]);
    }

    public function streamInline(DeliveryProof $proof)
    {
        abort_unless($proof->hasFile() && $proof->existsOnDisk(), 404);

        return Storage::disk($this->disk())->response(
            $proof->file_path,
            $proof->original_name ?: basename((string) $proof->file_path),
            [
                'Content-Type' => $proof->mime_type ?: 'application/octet-stream',
                'Cache-Control' => 'private, max-age=300',
            ],
            'inline'
        );
    }

    public function warnIfOversized(?string $htmlOrPdfLabel, int $sizeBytes, int $deliveryId): void
    {
        $thresholdKb = max((int) config('proof.size_warning_threshold_kb', 500), 1);

        if ($sizeBytes <= ($thresholdKb * 1024)) {
            return;
        }

        Log::warning('delivery_proof_size_warning', [
            'delivery_id' => $deliveryId,
            'payload' => $htmlOrPdfLabel,
            'size_bytes' => $sizeBytes,
            'threshold_kb' => $thresholdKb,
        ]);
    }

    private function directory(Delivery $delivery): string
    {
        return 'delivery-proofs/org-' . $delivery->organization_id . '/delivery-' . $delivery->id;
    }

    private function filename(string $workflowStage, string $captureMoment, string $proofType, string $extension): string
    {
        return $workflowStage . '-' . $captureMoment . '-' . $proofType . '-' . Str::uuid() . '.' . ltrim($extension, '.');
    }
}
