<?php

namespace Tests\Feature\Regression;

use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class DashboardHeaderControlsRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_compact_filter_form_keeps_every_existing_field_and_current_tab(): void
    {
        $this->actingAs(TestData::user());
        $filters = ['dashboard_tab' => 'inventory', 'from_date' => '2026-10-01', 'to_date' => '2026-10-29',
            'city' => 'Bengaluru', 'fulfilment_source' => 'vendor_supplied', 'payment_status' => 'partial', 'search' => 'test'];
        $response = $this->get(route('dashboard', $filters))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $form = $xpath->query('//header[contains(@class,"phos-dashboard-header")]//form[@data-dashboard-filter-form]');
        $this->assertCount(1, $form);
        $this->assertSame('GET', $form->item(0)->getAttribute('method'));
        $this->assertSame(0, $xpath->query('//*[@data-executive-dashboard]/*[contains(@class,"control-room-filter-dock")]')->length);
        foreach ($filters as $name => $value) {
            $field = $xpath->query('.//*[@name="'.$name.'"]', $form->item(0))->item(0);
            $this->assertNotNull($field, "Existing field {$name} must be retained.");
            $actual = $field->tagName === 'select'
                ? $xpath->query('.//option[@selected]', $field)->item(0)?->getAttribute('value')
                : $field->getAttribute('value');
            // City options depend on records; validate its requested scope below.
            if ($name !== 'city') $this->assertSame($value, $actual);
        }
        $this->assertStringContainsString('City: Bengaluru', $response->getContent());
        $reset = $xpath->query('//*[@data-dashboard-filter-reset]')->item(0);
        $this->assertSame(route('dashboard'), $reset->getAttribute('href'));
        $reports = $xpath->query('//details[@data-dashboard-filters]/following-sibling::a[1]')->item(0);
        $this->assertSame('Detailed reports', trim($reports->textContent));
    }

    public function test_period_is_visible_and_presets_use_existing_date_filters_without_changing_defaults(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 30)->setTime(12, 0));
        $this->actingAs(TestData::user());
        $response = $this->get(route('dashboard', ['dashboard_tab' => 'revenue', 'city' => 'Bengaluru']))->assertOk();
        $xpath = $this->xpath($response->getContent());
        $toggle = $xpath->query('//*[@data-dashboard-period-toggle]')->item(0);
        $this->assertStringContainsString('All dates', $toggle->textContent);
        $expected = [
            'Today' => ['2026-10-30', '2026-10-30'],
            'Last 7 days' => ['2026-10-24', '2026-10-30'],
            'This month' => ['2026-10-01', '2026-10-30'],
            'Last month' => ['2026-09-01', '2026-09-30'],
            'All dates' => [null, null],
        ];
        $links = $xpath->query('//*[@data-dashboard-period-link]');
        $this->assertCount(5, $links);
        foreach ($links as $link) {
            $label = trim($xpath->query('.//strong', $link)->item(0)->textContent);
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            $this->assertSame('revenue', $query['dashboard_tab']);
            $this->assertSame('Bengaluru', $query['city']);
            $this->assertSame($expected[$label][0], $query['from_date'] ?? null);
            $this->assertSame($expected[$label][1], $query['to_date'] ?? null);
        }
        $month = $this->get(route('dashboard', ['dashboard_tab' => 'revenue', 'from_date' => '2026-10-01', 'to_date' => '2026-10-30']))->assertOk();
        $this->assertSame('2026-10-01', $month->viewData('fromDate'));
        $this->assertSame('2026-10-30', $month->viewData('toDate'));
        $monthToggle = $this->xpath($month->getContent())->query('//*[@data-dashboard-period-toggle]')->item(0);
        $this->assertStringContainsString('This month', $monthToggle->textContent);
        $this->assertStringContainsString('01 Oct 2026', $monthToggle->textContent);
        $this->assertStringContainsString('30 Oct 2026', $monthToggle->textContent);

        $legacy = $this->get(route('dashboard', ['dashboard_tab' => 'team', 'date' => '2026-10-25']))->assertOk();
        $allDates = $this->xpath($legacy->getContent())->query('//*[@data-dashboard-period-link][strong="All dates"]')->item(0);
        parse_str((string) parse_url($allDates->getAttribute('href'), PHP_URL_QUERY), $allDateQuery);
        $this->assertArrayNotHasKey('date', $allDateQuery);
        $this->assertArrayNotHasKey('from_date', $allDateQuery);
        $this->assertArrayNotHasKey('to_date', $allDateQuery);
        $cleared = $this->get($allDates->getAttribute('href'))->assertOk();
        $this->assertSame('', $cleared->viewData('fromDate'));
        $this->assertSame('', $cleared->viewData('toDate'));
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return new DOMXPath($document);
    }
}
