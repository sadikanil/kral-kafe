<?php

namespace Tests\Unit;

use App\Support\Duration;
use PHPUnit\Framework\TestCase;

/**
 * UX turu (23 Eyl): "1s 35dk" saniye gibi okunuyordu. Saat "sa", sifir
 * kisim yazilmaz: panelde dar kartta iki satira kirilmasin.
 */
class DurationTest extends TestCase
{
    public function test_hours_and_minutes(): void
    {
        $this->assertSame('1 sa 35 dk', Duration::human(95));
    }

    public function test_whole_hours_drop_the_minutes(): void
    {
        $this->assertSame('10 sa', Duration::human(600));
    }

    public function test_under_an_hour_is_minutes_only(): void
    {
        $this->assertSame('35 dk', Duration::human(35));
    }

    public function test_zero_is_zero_minutes(): void
    {
        $this->assertSame('0 dk', Duration::human(0));
    }

    public function test_negative_is_treated_as_zero(): void
    {
        $this->assertSame('0 dk', Duration::human(-5));
    }
}
