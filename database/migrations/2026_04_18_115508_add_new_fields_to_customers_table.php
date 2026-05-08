<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'customer_type')) {
                $table->string('customer_type')->nullable()->after('name');
            }

            if (!Schema::hasColumn('customers', 'salutation')) {
                $table->string('salutation')->nullable()->after('customer_type');
            }

            if (!Schema::hasColumn('customers', 'first_name')) {
                $table->string('first_name')->nullable()->after('salutation');
            }

            if (!Schema::hasColumn('customers', 'last_name')) {
                $table->string('last_name')->nullable()->after('first_name');
            }

            if (!Schema::hasColumn('customers', 'company_name')) {
                $table->string('company_name')->nullable()->after('last_name');
            }

            if (!Schema::hasColumn('customers', 'contact_name')) {
                $table->string('contact_name')->nullable()->after('company_name');
            }

            if (!Schema::hasColumn('customers', 'whatsapp_number')) {
                $table->string('whatsapp_number')->nullable()->after('phone');
            }

            if (!Schema::hasColumn('customers', 'email')) {
                $table->string('email')->nullable()->after('whatsapp_number');
            }

            if (!Schema::hasColumn('customers', 'gst_treatment')) {
                $table->string('gst_treatment')->nullable()->after('email');
            }

            if (!Schema::hasColumn('customers', 'place_of_supply')) {
                $table->string('place_of_supply')->nullable()->after('gst_treatment');
            }

            if (!Schema::hasColumn('customers', 'gst_number')) {
                $table->string('gst_number')->nullable()->after('place_of_supply');
            }

            if (!Schema::hasColumn('customers', 'state')) {
                $table->string('state')->nullable()->after('city');
            }

            if (!Schema::hasColumn('customers', 'pincode')) {
                $table->string('pincode')->nullable()->after('state');
            }

            if (!Schema::hasColumn('customers', 'patient_name')) {
                $table->string('patient_name')->nullable()->after('pincode');
            }

            if (!Schema::hasColumn('customers', 'map_location_text')) {
                $table->string('map_location_text')->nullable()->after('pincode');
            }

            if (!Schema::hasColumn('customers', 'map_location_url')) {
                $table->text('map_location_url')->nullable()->after('map_location_text');
            }

            if (!Schema::hasColumn('customers', 'id_proof_file_path')) {
                $table->string('id_proof_file_path')->nullable()->after('id_proof_number');
            }

            if (!Schema::hasColumn('customers', 'id_proof_original_name')) {
                $table->string('id_proof_original_name')->nullable()->after('id_proof_file_path');
            }
        });

        Customer::query()->chunkById(100, function ($customers) {
            foreach ($customers as $customer) {
                $updates = [];

                if (empty($customer->customer_type)) {
                    $updates['customer_type'] = !empty($customer->company_name) ? 'Business' : 'Individual';
                }

                if (!empty($customer->company_name) && empty($customer->contact_name) && !empty($customer->name) && $customer->name !== $customer->company_name) {
                    $updates['contact_name'] = $customer->name;
                }

                if (empty($customer->place_of_supply) && !empty($customer->state)) {
                    $updates['place_of_supply'] = $customer->state;
                }

                if (empty($customer->state) && !empty($customer->place_of_supply)) {
                    $updates['state'] = $customer->place_of_supply;
                }

                $resolvedType = $updates['customer_type'] ?? $customer->customer_type;

                if (
                    $resolvedType === 'Individual' &&
                    empty($customer->first_name) &&
                    empty($customer->company_name) &&
                    !empty($customer->name)
                ) {
                    $nameParts = preg_split('/\s+/', trim((string) $customer->name), 2);
                    $updates['first_name'] = $nameParts[0] ?? null;
                    $updates['last_name'] = $nameParts[1] ?? null;
                }

                if (!empty($updates)) {
                    $customer->forceFill($updates)->save();
                }
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive to protect existing customer data.
    }
};
