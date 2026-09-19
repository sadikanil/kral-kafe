<?php

namespace Tests\Unit;

use App\Support\Period;
use Tests\TestCase;

class PeriodTest extends TestCase
{
    public function test_it_keeps_a_valid_period(): void
    {
        $this->assertSame([2026, 3], Period::normalize('2026', '3'));
    }

    public function test_it_falls_back_when_the_input_is_not_a_number(): void
    {
        [$year, $month] = Period::normalize('abc', 'xyz');

        $this->assertSame(now()->year, $year);
        $this->assertSame(now()->month, $month);
    }

    public function test_it_rejects_a_month_outside_one_to_twelve(): void
    {
        $this->assertSame(now()->month, Period::normalize(2026, 13)[1]);
        $this->assertSame(now()->month, Period::normalize(2026, 0)[1]);
    }

    public function test_it_rejects_an_implausible_year(): void
    {
        $this->assertSame(now()->year, Period::normalize(1200, 5)[0]);
        $this->assertSame(now()->year, Period::normalize(999999, 5)[0]);
    }

    public function test_it_accepts_arrays_and_nulls_without_crashing(): void
    {
        [$year, $month] = Period::normalize(['dizi'], null);

        $this->assertSame(now()->year, $year);
        $this->assertSame(now()->month, $month);
    }
}
