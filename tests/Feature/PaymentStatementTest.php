<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Consumption;
use App\Models\Location;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use App\Services\PaymentStatement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dalga 22: ogrenci ve velinin Odemeler sayfasi. Ay ay: paket bedeli,
 * odenen, kalan + o ayin adisyon dokumu (pakete dahil olanlar ayri).
 * Salt okunur; odeme kaydini yalnizca yonetici yazar.
 */
class PaymentStatementTest extends TestCase
{
    use RefreshDatabase;

    private function ogrenci(): User
    {
        return User::factory()->student()->create();
    }

    private function tuket(User $u, string $an, int $adet = 1, int $kapsanan = 0, int $fiyat = 10, bool $geriAlindi = false): Consumption
    {
        $urun = Product::firstOrCreate(['name' => 'Tost'], ['unit_price' => $fiyat, 'unit_type' => 'adet']);
        $raf = Location::firstOrCreate(['qr_code' => 'LOC-T'], ['name' => 'Raf', 'type' => 'shelf']);

        return Consumption::create([
            'user_id' => $u->id, 'product_id' => $urun->id, 'location_id' => $raf->id,
            'quantity' => $adet, 'covered_quantity' => $kapsanan, 'unit_price' => $fiyat,
            'consumed_at' => $an, 'is_undone' => $geriAlindi,
        ]);
    }

    private function abone(User $u, string $bas, int $fiyat = 12000): Subscription
    {
        return Subscription::factory()->create([
            'student_id' => $u->id,
            'package_id' => Package::factory()->tier3()->create()->id,
            'starts_on' => $bas,
            'ends_on' => \Illuminate\Support\Carbon::parse($bas)->addMonthNoOverflow()->subDay()->toDateString(),
            'price' => $fiyat,
        ]);
    }

    // --- Hesap ---------------------------------------------------------------

    public function test_the_month_adds_the_package_and_the_spending(): void
    {
        $ogrenci = $this->ogrenci();
        $this->abone($ogrenci, '2026-09-01', 12000);
        $this->tuket($ogrenci, '2026-09-05 10:00', adet: 2, fiyat: 45); // 90

        $ay = PaymentStatement::for($ogrenci, 2026, 9);

        $this->assertEquals(12000, $ay->packageTotal());
        $this->assertEquals(90, $ay->spendingTotal());
        $this->assertEquals(12090, $ay->monthTotal());
    }

    public function test_covered_items_are_listed_but_not_charged(): void
    {
        $ogrenci = $this->ogrenci();
        $this->tuket($ogrenci, '2026-09-03 10:00', adet: 2, kapsanan: 2, fiyat: 15);

        $ay = PaymentStatement::for($ogrenci, 2026, 9);

        $this->assertCount(1, $ay->consumptions());
        $this->assertEquals(0, $ay->spendingTotal());
    }

    public function test_undone_items_are_left_out(): void
    {
        $ogrenci = $this->ogrenci();
        $this->tuket($ogrenci, '2026-09-03 10:00', geriAlindi: true);

        $this->assertCount(0, PaymentStatement::for($ogrenci, 2026, 9)->consumptions());
    }

    public function test_other_months_are_left_out(): void
    {
        $ogrenci = $this->ogrenci();
        $this->abone($ogrenci, '2026-08-01');
        $this->tuket($ogrenci, '2026-08-31 10:00');

        $ay = PaymentStatement::for($ogrenci, 2026, 9);

        $this->assertEquals(0, $ay->monthTotal());
    }

    /** Fatura ile ayni kural: paket bedeli BASLADIGI aya yazilir. */
    public function test_the_package_belongs_to_the_month_it_starts_in(): void
    {
        $ogrenci = $this->ogrenci();
        $this->abone($ogrenci, '2026-09-15');

        $this->assertEquals(12000, PaymentStatement::for($ogrenci, 2026, 9)->packageTotal());
        $this->assertEquals(0, PaymentStatement::for($ogrenci, 2026, 10)->packageTotal());
    }

    public function test_payments_reduce_the_balance(): void
    {
        $ogrenci = $this->ogrenci();
        $abonelik = $this->abone($ogrenci, '2026-09-01', 12000);
        Payment::create(['subscription_id' => $abonelik->id, 'amount' => 8000, 'paid_at' => '2026-09-02', 'method' => 'cash']);

        $ay = PaymentStatement::for($ogrenci, 2026, 9);

        $this->assertEquals(8000, $ay->paidTotal());
        $this->assertEquals(4000, $ay->packageBalance());
    }

    public function test_a_cancelled_package_is_not_owed(): void
    {
        $ogrenci = $this->ogrenci();
        $this->abone($ogrenci, '2026-09-01')->forceFill(['payment_status' => PaymentStatus::Cancelled])->save();

        $this->assertEquals(0, PaymentStatement::for($ogrenci, 2026, 9)->packageTotal());
    }

    // --- Sayfalar ------------------------------------------------------------

    public function test_the_student_sees_their_statement(): void
    {
        $ogrenci = $this->ogrenci();
        $this->abone($ogrenci, now()->startOfMonth()->toDateString(), 12000);

        $this->actingAs($ogrenci)->get(route('user.payments'))
            ->assertOk()
            ->assertSee('12.000,00 ₺');
    }

    public function test_the_student_can_open_another_month(): void
    {
        $ogrenci = $this->ogrenci();
        $this->tuket($ogrenci, '2026-03-02 10:00', fiyat: 45);

        $this->actingAs($ogrenci)->get(route('user.payments', ['ay' => '2026-03']))
            ->assertOk()
            ->assertSee('Mart 2026')
            ->assertSee('Tost');
    }

    public function test_the_parent_sees_the_childs_statement(): void
    {
        $veli = User::factory()->parent()->create();
        $ogrenci = $this->ogrenci();
        $veli->students()->attach($ogrenci);
        $this->abone($ogrenci, now()->startOfMonth()->toDateString(), 12000);

        $this->actingAs($veli)->get(route('parent.payments', $ogrenci))
            ->assertOk()
            ->assertSee('12.000,00 ₺');
    }

    public function test_a_parent_cannot_see_another_childs_statement(): void
    {
        $veli = User::factory()->parent()->create();

        $this->actingAs($veli)->get(route('parent.payments', $this->ogrenci()))->assertForbidden();
    }

    public function test_the_old_history_address_leads_to_payments(): void
    {
        $this->actingAs($this->ogrenci())->get('/kullanici/gecmis')->assertRedirect(route('user.payments'));
    }
}
