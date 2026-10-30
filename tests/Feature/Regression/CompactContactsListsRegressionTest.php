<?php

namespace Tests\Feature\Regression;

use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\PartnerClient;
use App\Models\Role;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class CompactContactsListsRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_compact_contact_filters_keep_record_scope_query_links_and_existing_actions(): void
    {
        [$organization, $customers, $partners] = $this->records();
        $this->actingAs(TestData::user($organization));
        $customerBefore = $customers[0]->fresh()->getRawOriginal();
        $partnerBefore = $partners[0]->fresh()->getRawOriginal();
        $customerQuery = [
            'search' => 'Compact contact', 'city' => 'Bengaluru', 'state' => 'Karnataka',
            'status' => 'active', 'created_date' => now()->toDateString(),
            'from_date' => now()->toDateString(), 'to_date' => now()->toDateString(), 'sort_by' => 'name_asc',
        ];
        $response = $this->get(route('customers.index', $customerQuery))->assertOk();
        $dom = $this->dom($response->getContent());
        $this->assertSame(1, $dom->query('//details[@id="customers-list-filters" and @data-phos-list-filter and not(@open)]')->length);
        $this->assertSame(1, $dom->query('//details[@id="customers-list-filters"]/following-sibling::a[1][@href="'.route('customers.create').'"]')->length);
        foreach (array_keys($customerQuery) as $field) {
            $this->assertGreaterThan(0, $dom->query('//details[@id="customers-list-filters"]//form//*[@name="'.$field.'"]')->length, $field);
        }
        $this->assertSame($customerQuery['search'], $dom->evaluate('string(//*[@id="customers-list-filters"]//*[@id="search"]/@value)'));
        $this->assertSame($customerQuery['created_date'], $dom->evaluate('string(//*[@id="customers-list-filters"]//*[@name="created_date"]/@value)'));
        $this->assertSame('name_asc', $dom->evaluate('string(//*[@id="customers-list-filters"]//*[@name="sort_by"]/option[@selected]/@value)'));
        $this->assertSame(10, $response->viewData('customers')->total());
        $response->assertDontSee('Outside compact contact')->assertDontSee('Other filter contact');
        foreach (['customers.show', 'customers.edit'] as $route) {
            $this->assertLink($dom, route($route, $customers[0]));
        }
        $this->assertLink($dom, route('customers.export.csv', $customerQuery));
        $this->assertSame(1, $dom->query('//form[@id="customerBulkExportForm"]//input[@name="_token"]')->length);
        $this->assertSame(1, $dom->query('//form[@id="customerBulkDeleteForm"]//input[@name="_method" and @value="DELETE"]')->length);
        $this->assertGreaterThan(0, $dom->query('//*[@class="customer-bulk-check"]')->length);
        $this->export('customer.html', $response->getContent(), 'customers', 'super_admin', [
            'records' => 10, 'active_filters' => $customerQuery,
        ]);

        $partnerQuery = ['search' => 'Compact partner', 'partner_type' => 'general', 'status' => 'active', 'city' => 'Bengaluru'];
        $response = $this->get(route('business-partners.index', $partnerQuery))->assertOk();
        $dom = $this->dom($response->getContent());
        $this->assertSame(1, $dom->query('//details[@id="business-partners-list-filters" and @data-phos-list-filter and not(@open)]')->length);
        $this->assertSame(1, $dom->query('//details[@id="business-partners-list-filters"]/following-sibling::a[1][@href="'.route('business-partners.create').'"]')->length);
        foreach (array_keys($partnerQuery) as $field) {
            $this->assertSame(1, $dom->query('//details[@id="business-partners-list-filters"]//form//*[@name="'.$field.'"]')->length, $field);
        }
        $this->assertSame('general', $dom->evaluate('string(//*[@id="bp-type"]/option[@selected]/@value)'));
        $this->assertSame(8, $response->viewData('businessPartners')->total());
        $response->assertDontSee('Outside compact partner')->assertDontSee('Other filter partner');
        foreach (['business-partners.show', 'business-partners.edit', 'business-partners.clients.create'] as $route) {
            $this->assertLink($dom, route($route, $partners[0]));
        }
        $this->assertGreaterThan(0, $dom->query('//details[contains(@class,"bp-clients")]')->length);
        $this->export('business-partners.html', $response->getContent(), 'business-partners', 'super_admin', [
            'records' => 8, 'active_filters' => $partnerQuery,
        ]);
        $this->assertSame($customerBefore, $customers[0]->fresh()->getRawOriginal());
        $this->assertSame($partnerBefore, $partners[0]->fresh()->getRawOriginal());
    }

    public function test_reader_keeps_contact_lists_and_profile_links_without_edit_or_delete_controls(): void
    {
        [$organization, $customers, $partners] = $this->records();
        $role = Role::create([
            'organization_id' => $organization->id, 'name' => 'Contact list reader', 'slug' => 'compact_contact_reader',
            'permissions' => Role::normalizePermissions(['customers' => ['read']]), 'is_system' => false, 'is_active' => true,
        ]);
        $this->actingAs(TestData::user($organization, ['role' => $role->slug, 'role_id' => $role->id]));
        foreach ([['customers', $customers[0], 'customer-reader.html'], ['business-partners', $partners[0], 'business-partners-reader.html']] as [$page, $record, $filename]) {
            $response = $this->get(route($page.'.index'))->assertOk();
            $dom = $this->dom($response->getContent());
            $this->assertLink($dom, route($page.'.show', $record));
            $this->assertSame(0, $dom->query('//a[@href="'.route($page.'.edit', $record).'"]')->length);
            $this->assertSame(0, $dom->query('//form[@action="'.route($page.'.destroy', $record).'"]')->length);
            $this->assertSame(0, $dom->query('//*[contains(@class,"phos-page-header")]//a[@href="'.route($page.'.create').'"]')->length);
            if ($page === 'customers') {
                $this->assertSame(0, $dom->query('//form[@id="customerBulkDeleteForm"]')->length);
            }
            $this->export($filename, $response->getContent(), $page, $role->slug, [
                'records' => $response->viewData($page === 'customers' ? 'customers' : 'businessPartners')->total(), 'active_filters' => [],
            ]);
        }
    }

    private function records(): array
    {
        $organization = TestData::organization();
        $customers = [];
        foreach (range(1, 10) as $number) {
            $customers[] = Customer::create([
                'organization_id' => $organization->id, 'name' => 'Compact contact '.sprintf('%02d', $number),
                'company_name' => 'Home care contact', 'phone' => '987650'.sprintf('%04d', $number),
                'whatsapp_number' => '987651'.sprintf('%04d', $number), 'city' => 'Bengaluru', 'state' => 'Karnataka',
                'pincode' => '560001', 'status' => 'active',
            ]);
        }
        Customer::create(['organization_id' => $organization->id, 'name' => 'Other filter contact', 'phone' => '9876599999', 'city' => 'Mysuru', 'status' => 'inactive']);
        $partners = [];
        foreach (range(1, 8) as $number) {
            $partner = BusinessPartner::create([
                'organization_id' => $organization->id, 'business_name' => 'Compact partner '.sprintf('%02d', $number),
                'contact_person' => 'Care coordinator '.$number, 'phone' => '+91987652'.sprintf('%04d', $number),
                'email' => 'partner'.$number.'@example.test', 'city' => 'Bengaluru', 'state' => 'Karnataka',
                'pincode' => '560001', 'gst_registered' => false, 'status' => 'active',
            ]);
            PartnerClient::create([
                'organization_id' => $organization->id, 'business_partner_id' => $partner->id,
                'client_name' => 'Compact client '.$number, 'phone' => '+91987653'.sprintf('%04d', $number),
                'city' => 'Bengaluru', 'state' => 'Karnataka', 'status' => 'active',
            ]);
            $partners[] = $partner;
        }
        BusinessPartner::create(['organization_id' => $organization->id, 'business_name' => 'Other filter partner', 'phone' => '+919876599999', 'city' => 'Mysuru', 'gst_registered' => true, 'status' => 'inactive']);
        $other = TestData::organization(['name' => 'Other compact contacts organization']);
        Customer::create(['organization_id' => $other->id, 'name' => 'Outside compact contact', 'phone' => '9876599998', 'city' => 'Bengaluru', 'status' => 'active']);
        BusinessPartner::create(['organization_id' => $other->id, 'business_name' => 'Outside compact partner', 'phone' => '+919876599998', 'city' => 'Bengaluru', 'status' => 'active']);

        return [$organization, $customers, $partners];
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }

    private function assertLink(DOMXPath $dom, string $url): void
    {
        $this->assertGreaterThan(0, $dom->query('//a[@href="'.$url.'"]')->length, $url);
    }

    private function export(string $filename, string $html, string $page, string $role, array $metrics): void
    {
        $directory = getenv('PHOS_COMPACT_PREVIEW_DIR');
        if (! is_string($directory) || $directory === '') return;
        if (! is_dir($directory)) $this->assertTrue(mkdir($directory, 0777, true));
        $this->assertNotFalse(file_put_contents($directory.DIRECTORY_SEPARATOR.$filename, $html));
        $path = $directory.DIRECTORY_SEPARATOR.'contacts-fixtures.json';
        $manifest = is_file($path) ? json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
        $manifest = array_values(array_filter($manifest, fn (array $entry) => $entry['html'] !== $filename));
        $manifest[] = array_merge(['synthetic' => true, 'html' => $filename, 'page' => $page, 'role' => $role, 'route' => $page.'.index'], $metrics);
        $this->assertNotFalse(file_put_contents($path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
    }
}
