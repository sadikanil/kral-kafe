<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Kayitli uyenin paketini degistirmek.
 *
 * 18 Kas 2026: eski "tarihten itibaren" akisi yeni paketi TAM fiyatli yeni
 * bir donem olarak aciyordu ve ay sonunu uzatmak icin de kullanildigindan
 * canlida birkac gunluk tam fiyatli parcalar birikti. Artik:
 * - "sonraki donemden" (varsayilan): bu donem aynen kalir, yenileme yeni
 *   paketle acilir;
 * - "bu donemden": bu donemin paketi ve tutari yerinde degisir.
 * Ikisi de yeni satir acmaz (paketi yoksa bugunden bir aylik donem acilir).
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

    private function degistir(User $ogrenci, Package $paket, string $ne = 'sonraki', array $ek = [])
    {
        return $this->actingAs($this->yonetici())->post(route('admin.subscriptions.switch', $ogrenci), array_merge([
            'package_id' => $paket->id, 'when' => $ne,
        ], $ek));
    }

    public function test_next_period_keeps_this_period_and_renews_with_the_new_package(): void
    {
        $standart = Package::factory()->tier1()->create();
        $kral = Package::factory()->tier3()->create(['monthly_price' => 12000]);
        [$ogrenci, $donem] = $this->ogrenciPaketli($standart);

        $this->degistir($ogrenci, $kral)->assertSessionHas('success', 'Kral, 01.10.2026 tarihli yeni dönemden itibaren geçerli.');

        $donem->refresh();
        $this->assertSame(1, Subscription::count(), 'yeni satir acilmaz');
        $this->assertSame($standart->id, $donem->package_id);
        $this->assertSame('5000.00', $donem->price);
        $this->assertSame($kral->id, $donem->next_package_id);
        $this->assertTrue($donem->auto_renew, 'degisiklik paketi yenilemeye alir');

        $this->travelTo(Carbon::parse('2026-10-01 09:00', config('kafe.timezone')));
        $this->assertSame($kral->id, $ogrenci->fresh()->currentSubscription()->package_id);
        $this->assertSame('12000.00', $ogrenci->fresh()->currentSubscription()->price);
    }

    public function test_this_period_changes_the_package_and_price_in_place(): void
    {
        [$ogrenci, $donem] = $this->ogrenciPaketli(Package::factory()->tier1()->create());
        $donem->payments()->create(['amount' => 5000, 'paid_at' => '2026-09-02', 'method' => 'cash']);
        $kral = Package::factory()->tier3()->create(['monthly_price' => 12000]);

        $this->degistir($ogrenci, $kral, 'simdi')->assertSessionHas('success', 'Bu dönemin paketi Kral oldu. Kalan: 7.000,00 ₺');

        $donem->refresh();
        $this->assertSame(1, Subscription::count());
        $this->assertSame($kral->id, $donem->package_id);
        $this->assertSame('12000.00', $donem->price);
        $this->assertSame(7000.0, $donem->balance(), 'odenen korunur, fark kalan olur');
        $this->assertStringContainsString('Paket 23.09 tarihinde değişti', $donem->note);
        $this->assertTrue($ogrenci->fresh()->entitlements()->privateLessons, 'yeni paketin haklari hemen gelir');
    }

    public function test_a_custom_price_is_kept(): void
    {
        [$ogrenci, $donem] = $this->ogrenciPaketli(Package::factory()->tier1()->create());
        $kral = Package::factory()->tier3()->create();

        $this->degistir($ogrenci, $kral, 'sonraki', ['price' => 4000]);
        $this->assertSame('4000.00', $donem->fresh()->next_price);

        $this->degistir($ogrenci, $kral, 'simdi', ['price' => 4500]);
        $this->assertSame('4500.00', $donem->fresh()->price);
        $this->assertNull($donem->fresh()->next_package_id, 'bu donemden degisince bekleyen secim duser');
    }

    public function test_choosing_the_same_package_for_next_period_clears_a_pending_change(): void
    {
        $standart = Package::factory()->tier1()->create();
        [$ogrenci, $donem] = $this->ogrenciPaketli($standart);
        $donem->update(['next_package_id' => Package::factory()->tier3()->create()->id]);

        $this->degistir($ogrenci, $standart);

        $this->assertNull($donem->fresh()->next_package_id);
    }

    /** Ileri tarihli donem atanmissa "sonraki donem" secimi zincirin ucuna yazilir. */
    public function test_next_period_change_goes_to_an_already_assigned_future_period(): void
    {
        $standart = Package::factory()->tier1()->create();
        [$ogrenci, $simdiki] = $this->ogrenciPaketli($standart);
        $ileri = Subscription::factory()->create([
            'student_id' => $ogrenci->id, 'package_id' => $standart->id,
            'starts_on' => '2026-10-01', 'ends_on' => '2026-10-31', 'price' => 5000,
        ]);
        $kral = Package::factory()->tier3()->create();

        $this->degistir($ogrenci, $kral)->assertSessionHas('success', 'Kral, 01.11.2026 tarihli yeni dönemden itibaren geçerli.');

        $this->assertNull($simdiki->fresh()->next_package_id);
        $this->assertSame($kral->id, $ileri->fresh()->next_package_id);
    }

    /** Paketi yoksa degistirme, bugunden ay sonuna yeni donem acmaktir. */
    public function test_without_a_package_it_opens_a_new_renewing_one(): void
    {
        $ogrenci = User::factory()->student()->create();

        $this->degistir($ogrenci, Package::factory()->tier1()->create(), 'simdi');

        // Takvim ayi: ay sonuna kadar kist (8/30), ayin 1'inde yenilenir.
        $yeni = Subscription::sole();
        $this->assertSame('2026-09-23', $yeni->starts_on->toDateString());
        $this->assertSame('2026-09-30', $yeni->ends_on->toDateString());
        $this->assertSame('2000.00', $yeni->price);
        $this->assertTrue($yeni->auto_renew);
    }

    /** Ek paket (deneme kulubu eki) degisimden etkilenmez. */
    public function test_an_addon_is_left_alone(): void
    {
        [$ogrenci] = $this->ogrenciPaketli(Package::factory()->tier2()->create());
        $ekPaket = Package::factory()->examClubAddon()->create();
        $ek = Subscription::factory()->create([
            'student_id' => $ogrenci->id, 'package_id' => $ekPaket->id,
            'starts_on' => '2026-09-01', 'ends_on' => '2026-09-30',
        ]);

        $this->degistir($ogrenci, Package::factory()->tier3()->create(), 'simdi');

        $this->assertSame($ekPaket->id, $ek->fresh()->package_id);
        $this->assertSame('2026-09-30', $ek->fresh()->ends_on->toDateString());
    }

    public function test_an_addon_cannot_be_chosen_as_the_new_package(): void
    {
        [$ogrenci] = $this->ogrenciPaketli(Package::factory()->tier2()->create());

        $this->actingAs($this->yonetici())->from(route('admin.users.edit', $ogrenci))
            ->post(route('admin.subscriptions.switch', $ogrenci), [
                'package_id' => Package::factory()->examClubAddon()->create()->id, 'when' => 'sonraki',
            ])
            ->assertSessionHasErrors('package_id');
    }

    public function test_when_must_be_one_of_the_two_options(): void
    {
        [$ogrenci] = $this->ogrenciPaketli(Package::factory()->tier1()->create());

        $this->actingAs($this->yonetici())->from(route('admin.users.edit', $ogrenci))
            ->post(route('admin.subscriptions.switch', $ogrenci), [
                'package_id' => Package::factory()->tier2()->create()->id, 'switch_on' => '2026-09-23',
            ])
            ->assertSessionHasErrors('when');
    }

    public function test_the_user_page_offers_the_switch_and_shows_renewal(): void
    {
        [$ogrenci, $donem] = $this->ogrenciPaketli(Package::factory()->tier1()->create());
        $donem->update(['auto_renew' => true]);

        $this->actingAs($this->yonetici())->get(route('admin.users.edit', $ogrenci))
            ->assertOk()
            ->assertSee('Paketi değiştir')
            ->assertSee('Standart')
            ->assertSee('Sonraki dönemden (01.10.2026)')
            ->assertSee('Her ay yenilenir')
            ->assertSee('Yenilemeyi durdur')
            ->assertSee(route('admin.subscriptions.switch', $ogrenci), false);
    }

    public function test_only_students_have_packages(): void
    {
        $veli = User::factory()->parent()->create();

        $this->degistir($veli, Package::factory()->tier1()->create())->assertNotFound();
    }
}
