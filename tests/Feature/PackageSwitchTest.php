<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 30a - Kayitli ogrencinin paketini degistirmek.
 *
 * Karar (23 Eyl): TARIHTEN ITIBAREN. Eski paket bir gun once biter, yenisi
 * o gun baslar ve eskinin bitis gununu devralir (odenmis donem yeni paketle
 * surer). Gecmis ve odemeler bozulmaz; yerinde duzenleme yok.
 */
class PackageSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-23 12:00', config('kafe.timezone')));
    }

    private function yonetici(): User
    {
        return User::factory()->admin()->create();
    }

    private function ogrenciPaketli(Package $paket, string $bas = '2026-09-01', string $bit = '2026-09-30'): array
    {
        $ogrenci = User::factory()->student()->create();
        $abonelik = Subscription::factory()->create([
            'student_id' => $ogrenci->id, 'package_id' => $paket->id,
            'starts_on' => $bas, 'ends_on' => $bit, 'price' => 5000,
        ]);

        return [$ogrenci, $abonelik];
    }

    private function degistir(User $ogrenci, Package $paket, string $tarih, array $ek = [])
    {
        return $this->actingAs($this->yonetici())->post(route('admin.subscriptions.switch', $ogrenci), array_merge([
            'package_id' => $paket->id, 'switch_on' => $tarih,
        ], $ek));
    }

    public function test_the_old_package_ends_the_day_before_and_the_new_one_starts(): void
    {
        $standart = Package::factory()->tier1()->create();
        $kral = Package::factory()->tier3()->create(['monthly_price' => 12000]);
        [$ogrenci, $eski] = $this->ogrenciPaketli($standart);

        $this->degistir($ogrenci, $kral, '2026-09-23')->assertRedirect();

        $this->assertSame('2026-09-22', $eski->fresh()->ends_on->toDateString());

        $yeni = Subscription::where('package_id', $kral->id)->sole();
        $this->assertSame('2026-09-23', $yeni->starts_on->toDateString());
        $this->assertSame('2026-09-30', $yeni->ends_on->toDateString(), 'Eskinin bitisini devralir');
        $this->assertSame('12000.00', $yeni->price);
    }

    public function test_the_new_package_grants_its_rights_from_the_switch_day(): void
    {
        [$ogrenci] = $this->ogrenciPaketli(Package::factory()->tier1()->create());

        $this->degistir($ogrenci, Package::factory()->tier3()->create(), '2026-09-23');

        $this->assertTrue($ogrenci->fresh()->entitlements()->privateLessons);
    }

    /** Hic kullanilmamis paket (degisim baslangic gunu) iptal edilir, kisaltilmaz. */
    public function test_switching_on_the_start_day_cancels_the_old_package(): void
    {
        [$ogrenci, $eski] = $this->ogrenciPaketli(Package::factory()->tier1()->create(), '2026-09-23', '2026-10-22');

        $this->degistir($ogrenci, Package::factory()->tier2()->create(), '2026-09-23');

        $this->assertSame(PaymentStatus::Cancelled, $eski->fresh()->payment_status);
    }

    public function test_a_custom_price_is_kept(): void
    {
        [$ogrenci] = $this->ogrenciPaketli(Package::factory()->tier1()->create());
        $kral = Package::factory()->tier3()->create();

        $this->degistir($ogrenci, $kral, '2026-09-23', ['price' => 4000]);

        $this->assertSame('4000.00', Subscription::where('package_id', $kral->id)->sole()->price);
    }

    /** Paketi yoksa degistirme, yeni bir donem acmaktir (bir aylik). */
    public function test_without_a_package_it_opens_a_new_one(): void
    {
        $ogrenci = User::factory()->student()->create();

        $this->degistir($ogrenci, Package::factory()->tier1()->create(), '2026-09-23');

        $yeni = Subscription::sole();
        $this->assertSame('2026-10-22', $yeni->ends_on->toDateString());
    }

    /** Ek paket (deneme kulubu eki) degisimden etkilenmez. */
    public function test_an_addon_is_left_alone(): void
    {
        [$ogrenci] = $this->ogrenciPaketli(Package::factory()->tier2()->create());
        $ek = Subscription::factory()->create([
            'student_id' => $ogrenci->id, 'package_id' => Package::factory()->examClubAddon()->create()->id,
            'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30',
        ]);

        $this->degistir($ogrenci, Package::factory()->tier3()->create(), '2026-09-23');

        $this->assertSame('2026-09-30', $ek->fresh()->ends_on->toDateString());
    }

    public function test_an_addon_cannot_be_chosen_as_the_new_package(): void
    {
        [$ogrenci] = $this->ogrenciPaketli(Package::factory()->tier2()->create());

        $this->actingAs($this->yonetici())->from(route('admin.users.edit', $ogrenci))
            ->post(route('admin.subscriptions.switch', $ogrenci), [
                'package_id' => Package::factory()->examClubAddon()->create()->id, 'switch_on' => '2026-09-23',
            ])
            ->assertSessionHasErrors('package_id');
    }

    public function test_the_user_page_offers_the_switch(): void
    {
        [$ogrenci] = $this->ogrenciPaketli(Package::factory()->tier1()->create());

        $this->actingAs($this->yonetici())->get(route('admin.users.edit', $ogrenci))
            ->assertOk()
            ->assertSee('Paketi değiştir')
            ->assertSee('Standart')
            ->assertSee(route('admin.subscriptions.switch', $ogrenci), false);
    }

    public function test_only_students_have_packages(): void
    {
        $veli = User::factory()->parent()->create();

        $this->degistir($veli, Package::factory()->tier1()->create(), '2026-09-23')->assertNotFound();
    }
}
