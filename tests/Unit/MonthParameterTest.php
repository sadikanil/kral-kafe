<?php

namespace Tests\Unit;

use App\Support\MonthParameter;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * ?ay= adres parametresi kullanicinin elinde: ?ay[]=... bir DIZI getirir.
 * Metin olmayan her deger bozuk sayilip kafe ayina dusmeli, 500 vermemeli.
 */
class MonthParameterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
    }

    public function test_a_valid_month_is_kept(): void
    {
        $this->assertSame([2026, 8], MonthParameter::resolve('2026-08'));
    }

    public function test_an_array_falls_back_to_the_cafe_month(): void
    {
        $this->assertSame([2026, 9], MonthParameter::resolve(['2026-08']));
        $this->assertSame([2026, 9], MonthParameter::resolve(['x' => ['2026-08']]));
    }

    public function test_missing_and_broken_values_fall_back_to_the_cafe_month(): void
    {
        $this->assertSame([2026, 9], MonthParameter::resolve(null));
        $this->assertSame([2026, 9], MonthParameter::resolve('2026-13'));
        $this->assertSame([2026, 9], MonthParameter::resolve(202608));
    }

    public function test_the_fallback_is_the_local_month_right_after_midnight_on_the_first(): void
    {
        // Yerel 1 Ekim 00:30 = UTC 30 Eylul 21:30
        $this->travelTo(Carbon::parse('2026-10-01 00:30', config('kafe.timezone')));

        $this->assertSame([2026, 10], MonthParameter::resolve(['2026-08']));
    }
}
