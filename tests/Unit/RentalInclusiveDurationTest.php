<?php

namespace Tests\Unit;

use App\Models\Delivery;
use App\Models\Rental;
use Carbon\Carbon;
use Tests\TestCase;

class RentalInclusiveDurationTest extends TestCase
{
    public function test_same_day_rental_returns_one_day_remaining(): void
    {
        $rental = new Rental([
            'start_date' => '2026-06-02',
            'end_date' => '2026-06-02',
            'status' => 'active',
        ]);

        $this->assertSame(1, $rental->remainingDaysInclusive(Carbon::parse('2026-06-02')));
        $this->assertSame('1 day remaining', $rental->customerFacingRemainingLabel(Carbon::parse('2026-06-02')));
    }

    public function test_june_second_to_july_first_returns_thirty_days_inclusive(): void
    {
        $rental = new Rental([
            'start_date' => '2026-06-02',
            'end_date' => '2026-07-01',
            'status' => 'active',
        ]);

        $this->assertSame(30, $rental->baseDurationDays());
        $this->assertSame(30, $rental->remainingDaysInclusive(Carbon::parse('2026-06-02')));
        $this->assertSame('30 days remaining', $rental->customerFacingRemainingLabel(Carbon::parse('2026-06-02')));
    }

    public function test_june_first_to_june_thirtieth_returns_thirty_days_inclusive(): void
    {
        $rental = new Rental([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-30',
            'status' => 'active',
        ]);

        $this->assertSame(30, $rental->baseDurationDays());
        $this->assertSame(30, $rental->remainingDaysInclusive(Carbon::parse('2026-06-01')));
        $this->assertSame('30 days remaining', $rental->customerFacingRemainingLabel(Carbon::parse('2026-06-01')));
    }

    public function test_existing_overdue_and_ending_soon_status_logic_still_works(): void
    {
        $overdueRental = new Rental([
            'start_date' => '2026-05-20',
            'end_date' => '2026-06-01',
            'status' => 'active',
        ]);
        $overdueRental->setRelation('deliveryRecord', new Delivery(['status' => 'completed']));

        $endingSoonRental = new Rental([
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-03',
            'status' => 'active',
        ]);
        $endingSoonRental->setRelation('deliveryRecord', new Delivery(['status' => 'completed']));

        $today = Carbon::parse('2026-06-02')->startOfDay();

        $this->assertTrue($overdueRental->isOverdue($today));
        $this->assertSame('1 day overdue', $overdueRental->customerFacingRemainingLabel($today));
        $this->assertTrue($endingSoonRental->isEndingSoon($today, 2));
        $this->assertSame('2 days remaining', $endingSoonRental->customerFacingRemainingLabel($today));
    }
}
