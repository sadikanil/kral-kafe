<?php

namespace Tests\Unit;

use App\Enums\PlanPeriod;
use Tests\TestCase;

/**
 * Dalga 14 - Plan donemi: haftalik ya da aylik.
 *
 * Dalga 13'te plan yalnizca HAFTALIKTI. Koca aylik hedef de verdirmek
 * icin donem bir boyut haline geldi; hangi tarihin hangi donem kovasina
 * dustugu tek yerden cevaplansin diye burada toplandi.
 */
class PlanPeriodTest extends TestCase
{
    // --- Donem baslangici ---------------------------------------------------

    public function test_a_weekly_period_starts_on_the_local_monday(): void
    {
        // 2026-09-16 carsamba; haftanin pazartesisi 14'u.
        $this->assertSame('2026-09-14', PlanPeriod::Week->startFor('2026-09-16'));
    }

    public function test_a_monthly_period_starts_on_the_first_of_the_month(): void
    {
        $this->assertSame('2026-09-01', PlanPeriod::Month->startFor('2026-09-16'));
    }

    /**
     * Ayin ilk gunleri bir ONCEKI ayin haftasina dusebilir.
     *
     * 1 Ekim 2026 persembe; o haftanin pazartesisi 28 EYLUL. Haftalik plan
     * eylulun son haftasina, ayni tarihteki aylik plan EKIM'e gitmeli.
     * Ikisini tek bir "donem baslangici" sutununda tutmak ancak bu ayrim
     * dogru kurulursa calisir.
     */
    public function test_the_two_periods_can_disagree_at_a_month_boundary(): void
    {
        $this->assertSame('2026-09-28', PlanPeriod::Week->startFor('2026-10-01'));
        $this->assertSame('2026-10-01', PlanPeriod::Month->startFor('2026-10-01'));
    }

    // --- Istekten okuma -----------------------------------------------------

    /**
     * Adres cubugundaki ?donem= degeri kullanicinin elinde.
     *
     * Taninmayan deger 500 vermemeli, sessizce haftaliga dusmeli: plan
     * sayfasi bir raporlama ekrani, girdi dogrulama kapisi degil.
     */
    public function test_an_unknown_value_falls_back_to_the_week(): void
    {
        $this->assertSame(PlanPeriod::Week, PlanPeriod::fromRequest('yil'));
        $this->assertSame(PlanPeriod::Week, PlanPeriod::fromRequest(null));
        $this->assertSame(PlanPeriod::Week, PlanPeriod::fromRequest(['dizi']));
    }

    public function test_a_known_value_is_kept(): void
    {
        $this->assertSame(PlanPeriod::Month, PlanPeriod::fromRequest('month'));
        $this->assertSame(PlanPeriod::Week, PlanPeriod::fromRequest('week'));
    }

    // --- Baslik -------------------------------------------------------------

    public function test_a_week_is_titled_with_both_ends(): void
    {
        $this->assertSame('14 - 20 Eylül 2026', PlanPeriod::Week->titleFor('2026-09-14'));
    }

    /** Iki aya yayilan hafta her iki ayi da yazmali. */
    public function test_a_week_spanning_two_months_names_both(): void
    {
        $this->assertSame('28 Eylül - 4 Ekim 2026', PlanPeriod::Week->titleFor('2026-09-28'));
    }

    public function test_a_month_is_titled_with_the_month_name(): void
    {
        $this->assertSame('Ekim 2026', PlanPeriod::Month->titleFor('2026-10-01'));
    }

    // --- Kaydirma -----------------------------------------------------------

    /**
     * Koc gecmis ve gelecek donemlere bakabilmeli; kaydirma adimi doneme
     * gore degisir - haftalikta 7 gun, aylikta bir ay (28-31 gun).
     */
    public function test_shifting_moves_by_one_period(): void
    {
        $this->assertSame('2026-09-21', PlanPeriod::Week->shift('2026-09-14', 1));
        $this->assertSame('2026-09-07', PlanPeriod::Week->shift('2026-09-14', -1));
        $this->assertSame('2026-10-01', PlanPeriod::Month->shift('2026-09-01', 1));
        $this->assertSame('2026-08-01', PlanPeriod::Month->shift('2026-09-01', -1));
    }

    /** Ay kaydirmasi gun tasirmamali: 31 Ocak + 1 ay, 3 Mart olmamali. */
    public function test_shifting_a_month_does_not_overflow(): void
    {
        $this->assertSame('2026-02-01', PlanPeriod::Month->shift('2026-01-01', 1));
        $this->assertSame('2026-03-01', PlanPeriod::Month->shift('2026-01-01', 2));
    }
}
