<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestData;
use Tests\TestCase;

class CustomerUpdateValidationRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_update_address_without_changing_own_phone_email_or_whatsapp(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'customer_type' => 'Individual',
            'name' => 'Anika Sharma',
            'first_name' => 'Anika',
            'phone' => PhoneNumber::normalize('9876500001', '+91'),
            'whatsapp_number' => PhoneNumber::normalize('9876500002', '+91'),
            'email' => 'anika@example.com',
            'address' => 'Old address',
        ]);

        $response = $this->from(route('customers.edit', $customer->id))
            ->put(route('customers.update', $customer->id), [
                'customer_type' => 'Individual',
                'salutation' => null,
                'first_name' => 'Anika',
                'last_name' => 'Sharma',
                'phone_country_code' => '+91',
                'phone' => '9876500001',
                'whatsapp_number_country_code' => '+91',
                'whatsapp_number' => '9876500002',
                'email' => 'anika@example.com',
                'address' => 'New address only',
            ]);

        $response->assertRedirect(route('customers.index'));
        $response->assertSessionHasNoErrors();

        $customer->refresh();

        $this->assertSame('New address only', $customer->address);
        $this->assertSame(PhoneNumber::normalize('9876500001', '+91'), $customer->phone);
        $this->assertSame(PhoneNumber::normalize('9876500002', '+91'), $customer->whatsapp_number);
        $this->assertSame('anika@example.com', $customer->email);
    }

    public function test_customer_cannot_update_to_another_customers_phone_email_or_whatsapp(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $customerA = Customer::create([
            'organization_id' => $organization->id,
            'customer_type' => 'Individual',
            'name' => 'Customer One',
            'first_name' => 'Customer',
            'phone' => PhoneNumber::normalize('9876500010', '+91'),
            'whatsapp_number' => PhoneNumber::normalize('9876500011', '+91'),
            'email' => 'one@example.com',
        ]);

        $customerB = Customer::create([
            'organization_id' => $organization->id,
            'customer_type' => 'Individual',
            'name' => 'Customer Two',
            'first_name' => 'Second',
            'phone' => PhoneNumber::normalize('9876500020', '+91'),
            'whatsapp_number' => PhoneNumber::normalize('9876500021', '+91'),
            'email' => 'two@example.com',
        ]);

        $response = $this->from(route('customers.edit', $customerB->id))
            ->put(route('customers.update', $customerB->id), [
                'customer_type' => 'Individual',
                'first_name' => 'Second',
                'phone_country_code' => '+91',
                'phone' => '9876500010',
                'whatsapp_number_country_code' => '+91',
                'whatsapp_number' => '9876500011',
                'email' => 'one@example.com',
                'address' => 'Attempted duplicate values',
            ]);

        $response->assertRedirect(route('customers.edit', $customerB->id));
        $response->assertSessionHasErrors([
            'phone',
            'whatsapp_number',
            'email',
        ]);
    }

    public function test_customer_create_still_blocks_duplicate_phone_email_and_whatsapp(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        Customer::create([
            'organization_id' => $organization->id,
            'customer_type' => 'Individual',
            'name' => 'Existing Customer',
            'first_name' => 'Existing',
            'phone' => PhoneNumber::normalize('9876500030', '+91'),
            'whatsapp_number' => PhoneNumber::normalize('9876500031', '+91'),
            'email' => 'existing@example.com',
        ]);

        $response = $this->from(route('customers.create'))
            ->post(route('customers.store'), [
                'customer_type' => 'Individual',
                'first_name' => 'Fresh',
                'phone_country_code' => '+91',
                'phone' => '9876500030',
                'whatsapp_number_country_code' => '+91',
                'whatsapp_number' => '9876500031',
                'email' => 'existing@example.com',
                'address' => 'Duplicate attempt',
            ]);

        $response->assertRedirect(route('customers.create'));
        $response->assertSessionHasErrors([
            'phone',
            'whatsapp_number',
            'email',
        ]);
    }

    public function test_customer_update_keeps_the_current_proof_until_a_replacement_is_saved(): void
    {
        Storage::fake('local');
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));
        $oldPath = 'customer-id-proofs/existing-proof.pdf';
        Storage::disk('local')->put($oldPath, 'Synthetic proof fixture');
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'customer_type' => 'Individual',
            'name' => 'Proof test customer',
            'first_name' => 'Proof test customer',
            'id_proof_file_path' => $oldPath,
            'id_proof_original_name' => 'existing-proof.pdf',
        ]);
        $url = route('customers.update', $customer);
        $fields = [
            'customer_type' => 'Individual',
            'first_name' => 'Proof test customer',
            'address' => 'Synthetic updated address',
        ];

        $this->put($url, $fields)->assertRedirect(route('customers.index'))->assertSessionHasNoErrors();
        $this->assertSame($oldPath, $customer->fresh()->id_proof_file_path);
        $this->get(route('customers.id-proof.download', $customer))->assertDownload('existing-proof.pdf');

        Customer::saving(static function (Customer $record) use ($customer) {
            if ($record->is($customer) && $record->isDirty('id_proof_file_path')
                && $record->id_proof_original_name === 'refused-proof.pdf') {
                return false;
            }
        });
        $this->put($url, $fields + [
            'id_proof_file' => UploadedFile::fake()->create('refused-proof.pdf', 1, 'application/pdf'),
        ])->assertServerError();
        $this->assertSame($oldPath, $customer->fresh()->id_proof_file_path);
        $this->assertSame([$oldPath], Storage::disk('local')->allFiles('customer-id-proofs'));
        $this->get(route('customers.id-proof.download', $customer))->assertDownload('existing-proof.pdf');

        $this->put($url, $fields + [
            'id_proof_file' => UploadedFile::fake()->create('replacement-proof.pdf', 1, 'application/pdf'),
        ])->assertRedirect(route('customers.index'))->assertSessionHasNoErrors();
        $customer->refresh();
        $this->assertNotSame($oldPath, $customer->id_proof_file_path);
        Storage::disk('local')->assertExists($customer->id_proof_file_path);
        Storage::disk('local')->assertMissing($oldPath);
        $this->get(route('customers.id-proof.download', $customer))->assertDownload('replacement-proof.pdf');
    }
}
