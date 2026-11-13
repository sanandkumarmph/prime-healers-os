<?php

declare(strict_types=1);

use App\Models\{Customer, Delivery, DeliveryProof, Organization, Product, Rental, User};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$directory = realpath((string) getenv('PHOS_MOBILE_VENDOR_FIXTURE_DIR'));
$database = DB::connection();
if (!$app->environment('testing') || !$directory || $database->getDriverName() !== 'sqlite'
    || realpath($database->getDatabaseName()) !== $directory.DIRECTORY_SEPARATOR.'database.sqlite') {
    throw new RuntimeException('Disposable browser test database required.');
}

if (($argv[1] ?? '') === 'verify') {
    $results = [];
    foreach (Delivery::with('rental.rentalItems')->get() as $task) {
        $item = $task->rental->rentalItems->sole();
        $proof = $task->proofs();
        $results[$task->type] = [
            'status' => $task->status,
            'pickup_status' => $task->pickup_status,
            'completed_at_saved' => $task->completed_at !== null,
            'rental_status' => $task->rental->status,
            'delivered_quantity' => $item->delivered_quantity_value,
            'returned_quantity' => $item->returned_quantity_value,
            'start_proof' => (clone $proof)->where('capture_moment', 'start')->where('proof_type', DeliveryProof::TYPE_LOCATION)->exists(),
            'completion_proof' => (clone $proof)->where('capture_moment', 'complete')->where('proof_type', DeliveryProof::TYPE_LOCATION)->exists(),
            'proof_images' => (clone $proof)->whereNotNull('file_path')->count(),
            'proof_files_exist' => (clone $proof)->whereNotNull('file_path')->pluck('file_path')
                ->every(fn ($file) => is_file($directory.DIRECTORY_SEPARATOR.'storage/app/private/'.$file)),
        ];
    }
    echo json_encode($results, JSON_THROW_ON_ERROR), PHP_EOL;
    exit;
}

if (($argv[1] ?? '') !== 'seed') {
    throw new RuntimeException('Specify seed or verify.');
}

$org = Organization::create(['name' => 'Prime Healers', 'plan' => 'internal', 'is_internal' => true, 'is_active' => true]);
$vendor = User::factory()->create(['organization_id' => $org->id, 'name' => 'Synthetic Browser Vendor',
    'email' => 'vendor@example.test', 'password' => getenv('PHOS_MOBILE_VENDOR_FIXTURE_PASSWORD'),
    'role' => User::ROLE_VENDOR, 'is_internal' => true, 'is_active' => true, 'email_verified_at' => now()]);
$customer = Customer::create(['organization_id' => $org->id, 'name' => 'Synthetic Customer', 'address' => 'Synthetic address']);
$product = Product::create(['organization_id' => $org->id, 'name' => 'Synthetic Equipment',
    'product_type' => Product::TYPE_RENTABLE, 'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
    'price_per_day' => 0, 'total_quantity' => 0, 'available_quantity' => 0]);
$tasks = [];
foreach (['delivery', 'pickup'] as $type) {
    $rental = Rental::create(['organization_id' => $org->id, 'customer_id' => $customer->id,
        'customer_name' => $customer->name, 'phone' => '', 'product_id' => $product->id, 'quantity' => 1,
        'start_date' => today()->subDay(), 'end_date' => today()->addDay(), 'rental_amount' => 0, 'status' => 'active']);
    $rental->rentalItems()->create(['organization_id' => $org->id, 'product_id' => $product->id,
        'quantity' => 1, 'ordered_quantity' => 1, 'delivered_quantity' => (int) ($type === 'pickup'), 'returned_quantity' => 0]);
    $task = Delivery::create(['organization_id' => $org->id, 'rental_id' => $rental->id, 'type' => $type,
        'scheduled_at' => now(), 'status' => 'pending', 'pickup_status' => $type === 'pickup' ? 'assigned' : null,
        'assignment_type' => 'vendor', 'assigned_user_id' => $vendor->id]);
    $tasks[$type] = $task->id;
}
echo json_encode($tasks, JSON_THROW_ON_ERROR), PHP_EOL;
