<?php

namespace Tests\Unit;

use App\Support\LocalDay;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Uygulama UTC'de calisiyor ama kafe Istanbul'da. Gun ve hafta sinirlari
 * YEREL saate gore kurulmali; whereDate/whereMonth UTC gunune gore calistigi
 * icin yerel 00:00-03:00 arasi bir onceki gune dusuyor.
 */
class LocalDayTest extends TestCase
{
    public function test_a_local_day_starts_and_ends_in_utc_offsets(): void
    {
        [$bas, $bit] = LocalDay::bounds('2026-03-05');

        // Istanbul UTC+3: yerel 05 Mart 00:00 = UTC 04 Mart 21:00
        $this->assertSame('2026-03-04 21:00:00', $bas->utc()->toDateTimeString());
        $this->assertSame('2026-03-05 20:59:59', $bit->utc()->toDateTimeString());
    }

    public function test_an_after_midnight_moment_belongs_to_the_local_day(): void
    {
        // UTC 04 Mart 22:30 = Istanbul 05 Mart 01:30 -> 5 Mart'a ait olmali
        $an = Carbon::parse('2026-03-04 22:30:00', 'UTC');

        $this->assertSame('2026-03-05', LocalDay::of($an));
    }

    public function test_a_moment_just_before_local_midnight_stays_on_the_earlier_day(): void
    {
        // UTC 04 Mart 20:30 = Istanbul 04 Mart 23:30
        $an = Carbon::parse('2026-03-04 20:30:00', 'UTC');

        $this->assertSame('2026-03-04', LocalDay::of($an));
    }

    public function test_a_week_starts_on_monday_in_local_time(): void
    {
        // 2026-03-05 persembe; haftasi 02 Mart pazartesi baslar
        [$bas, $bit] = LocalDay::weekBounds('2026-03-05');

        $this->assertSame('2026-03-01 21:00:00', $bas->utc()->toDateTimeString());
        $this->assertSame('2026-03-08 20:59:59', $bit->utc()->toDateTimeString());
    }

    public function test_a_month_is_bounded_in_local_time(): void
    {
        [$bas, $bit] = LocalDay::monthBounds(2026, 3);

        $this->assertSame('2026-02-28 21:00:00', $bas->utc()->toDateTimeString());
        $this->assertSame('2026-03-31 20:59:59', $bit->utc()->toDateTimeString());
    }

    public function test_today_follows_the_cafe_timezone_not_utc(): void
    {
        // UTC'de hala 4 Mart, Istanbul'da 5 Mart olmus an
        Carbon::setTestNow(Carbon::parse('2026-03-04 22:00:00', 'UTC'));

        $this->assertSame('2026-03-05', LocalDay::today());
        $this->assertNotSame(now()->toDateString(), LocalDay::today());

        Carbon::setTestNow();
    }
    /**
     * Sinirlar UTC dondurulmeli.
     *
     * Sebep Eloquent'in davranisi: bir Carbon'u sorgu baglamasina koyarken UTC'ye
     * CEVIRMEZ, kendi saat diliminin duvar saatini bicimler. Kafe saatindeki
     * "2026-09-14 00:00+03:00" sorguya "2026-09-14 00:00:00" diye gider ve UTC
     * sutunuyla karsilastirilir - uc saatlik sessiz kayma.
     *
     * An ayni kaliyor; yalnizca tasidigi saat dilimi UTC. Gosterim gerektiginde
     * ->timezone(config('kafe.timezone')) ile geri cevrilir.
     */
    public function test_bounds_are_returned_in_utc_so_queries_are_not_shifted(): void
    {
        [$bas, $son] = LocalDay::bounds('2026-09-14');

        $this->assertSame('UTC', $bas->timezoneName);
        $this->assertSame('UTC', $son->timezoneName);

        // Istanbul UTC+3: yerel gun 21:00 UTC'de baslar.
        $this->assertSame('2026-09-13 21:00:00', $bas->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-14 20:59:59', $son->format('Y-m-d H:i:s'));
    }

    public function test_week_and_month_bounds_are_also_utc(): void
    {
        [$haftaBas, $haftaSon] = LocalDay::weekBounds('2026-09-16');
        [$ayBas, $aySon] = LocalDay::monthBounds(2026, 9);

        foreach ([$haftaBas, $haftaSon, $ayBas, $aySon] as $an) {
            $this->assertSame('UTC', $an->timezoneName);
        }

        // Hafta pazartesi baslar: 14 Eylul 00:00 yerel = 13 Eylul 21:00 UTC.
        $this->assertSame('2026-09-13 21:00:00', $haftaBas->format('Y-m-d H:i:s'));
        // Ay: 1 Eylul 00:00 yerel = 31 Agustos 21:00 UTC.
        $this->assertSame('2026-08-31 21:00:00', $ayBas->format('Y-m-d H:i:s'));
    }

    /**
     * monthStart YEREL ayin ilk gununu vermeli.
     *
     * monthBounds() UTC Carbon donuyor; ondan dogrudan toDateString() almak
     * bir gun geri kayardi: yerel 1 Eylul 00:00, UTC'de 31 AGUSTOS 21:00 -
     * yani "Eylul plani" Agustos'a dusrdu. weekStart ile ayni tuzak
     * (bkz. README SS10.1, ayni tuzagin besinci bicimi).
     */
    public function test_the_month_starts_on_the_local_first_day(): void
    {
        $this->assertSame('2026-09-01', LocalDay::monthStart('2026-09-16'));
        $this->assertSame('2026-09-01', LocalDay::monthStart('2026-09-01'));
        $this->assertSame('2026-09-01', LocalDay::monthStart('2026-09-30'));

        // Tuzagin kendisi: UTC sinirindan okumak Agustos verirdi.
        $this->assertSame('2026-08-31', LocalDay::monthBounds(2026, 9)[0]->toDateString());
    }
}
