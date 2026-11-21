<?php

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use App\Services\SubscriptionRenewer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Paketlerin aylik yenilenmesi (18-21 Kasim 2026). Isletme karari: paket
 * iptal edilmedikce ya da degistirilmedikce her ay tekrar eder ve ayin
 * BASINDA yenilenir; yonetici her ay yeni paket tanimlamaz.
 */
class PackageRenewalTest extends TestCase
{
    use RefreshDatabase;

    private Package $standart;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18 12:00', config('kafe.timezone')));
        $this->standart = Package::factory()->tier1()->create(['monthly_price' => 7500]);
    }

    private function gun(string $tarih, string $saat = '09:00'): void
    {
        $this->travelTo(Carbon::parse("{$tarih} {$saat}", config('kafe.timezone')));
    }

    /** Kasim donemi, indirimli aylik tutar 6000. */
    private function donem(array $ek = [], ?User $uye = null): Subscription
    {
        return Subscription::factory()->create(array_merge([
            'student_id' => ($uye ?? User::factory()->student()->create())->id,
            'package_id' => $this->standart->id,
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30',
            'price' => 6000, 'base_price' => 6000, 'auto_renew' => true,
        ], $ek));
    }

    private function gece(): void
    {
        config(['kafe.cron_anahtari' => 'gizli']);
        $this->withToken('gizli')->get(route('cron.nightly'))->assertOk();
    }

    private function yenile(): int
    {
        return app(SubscriptionRenewer::class)->renewDue();
    }

    // --- Yenileme -------------------------------------------------------------

    public function test_on_the_first_of_the_month_the_same_package_renews_for_the_whole_month(): void
    {
        $eski = $this->donem();
        $eski->payments()->create(['amount' => 6000, 'paid_at' => '2026-11-03', 'method' => 'cash']);
        $eski->syncPaymentStatus();

        $this->gun('2026-11-30', '23:30');
        $this->assertSame(0, $this->yenile(), 'ay bitmeden yenilenmez');

        $this->gun('2026-12-01', '00:05');
        $this->gece();

        $yeni = $eski->fresh()->renewal;
        $this->assertNotNull($yeni);
        $this->assertSame(['2026-12-01', '2026-12-31'], [$yeni->starts_on->toDateString(), $yeni->ends_on->toDateString()]);
        $this->assertSame($this->standart->id, $yeni->package_id);
        $this->assertSame(['6000.00', '6000.00'], [$yeni->price, $yeni->base_price], 'indirimli aylik tutar surer');
        $this->assertSame('Otomatik yenileme', $yeni->note);
        $this->assertNull($yeni->created_by);
        $this->assertTrue($yeni->auto_renew);
        $this->assertSame(PaymentStatus::Pending, $yeni->payment_status);
        $this->assertSame(PaymentStatus::Paid, $eski->fresh()->payment_status, 'onceki ayin odemesi kendinde kalir');
        $this->assertSame('2026-12-31', $yeni->student->subscription_end->toDateString());
    }

    public function test_the_cron_reports_how_many_renewed_and_never_twice(): void
    {
        $this->donem();
        $this->gun('2026-12-01', '00:05');
        config(['kafe.cron_anahtari' => 'gizli']);

        $this->withToken('gizli')->get(route('cron.nightly'))->assertOk()->assertJson(['yenilenen_paket' => 1]);
        $this->withToken('gizli')->get(route('cron.nightly'))->assertOk()->assertJson(['yenilenen_paket' => 0]);
        $this->assertSame(2, Subscription::count());
    }

    public function test_missed_months_are_caught_up_in_order(): void
    {
        $eski = $this->donem();

        $this->gun('2027-02-20');
        $this->yenile();

        $this->assertSame(
            ['2026-11-01', '2026-12-01', '2027-01-01', '2027-02-01'],
            Subscription::orderBy('starts_on')->pluck('starts_on')->map->toDateString()->all(),
        );
        $this->assertSame('2027-02-28', Subscription::whereDate('starts_on', '2027-02-01')->sole()->ends_on->toDateString());
        $this->assertSame($eski->id, Subscription::whereDate('starts_on', '2026-12-01')->sole()->renewed_from_id);
    }

    /** Eski (ay ortasinda biten) donem: ay sonuna kist kopru, sonra ayin 1'i. */
    public function test_an_old_mid_month_period_bridges_to_the_month_end_then_renews_on_the_first(): void
    {
        $eski = $this->donem(['starts_on' => '2026-11-13', 'ends_on' => '2026-12-12', 'base_price' => null]);

        $this->gun('2027-01-02');
        $this->yenile();

        $kopru = $eski->fresh()->renewal;
        $this->assertSame(['2026-12-13', '2026-12-31'], [$kopru->starts_on->toDateString(), $kopru->ends_on->toDateString()]);
        $this->assertSame('3677.00', $kopru->price, '6000 x 19/31 gun');
        $this->assertSame('6000.00', $kopru->base_price, 'aylik tutar eski donemin tutari');
        $this->assertTrue($kopru->isPartialMonth());

        $ocak = $kopru->renewal;
        $this->assertSame(['2027-01-01', '2027-01-31', '6000.00'], [$ocak->starts_on->toDateString(), $ocak->ends_on->toDateString(), $ocak->price]);
    }

    public function test_the_member_keeps_table_access_on_the_first_morning_even_without_the_cron(): void
    {
        $uye = User::factory()->student()->create();
        $this->donem([], $uye);

        $this->gun('2026-12-01', '08:30');

        $this->assertTrue($uye->fresh()->entitlements()->table);
        $this->assertSame('2026-12-01', $uye->fresh()->currentSubscription()->starts_on->toDateString());
    }

    public function test_a_next_period_package_change_is_applied_on_renewal(): void
    {
        $kral = Package::factory()->tier3()->create(['monthly_price' => 17500]);
        $eski = $this->donem(['next_package_id' => $kral->id]);
        $ikinci = $this->donem(['next_package_id' => $kral->id, 'next_price' => 15000]);

        $this->gun('2026-12-01');
        $this->yenile();

        $yeni = $eski->fresh()->renewal;
        $this->assertSame([$kral->id, '17500.00', '17500.00'], [$yeni->package_id, $yeni->price, $yeni->base_price]);
        $this->assertSame('15000.00', $ikinci->fresh()->renewal->price);
        $this->assertNull($yeni->next_package_id, 'yeni donem kendi paketiyle surer');
    }

    public function test_an_addon_renews_on_its_own(): void
    {
        $uye = User::factory()->student()->create();
        $this->donem(['ends_on' => '2026-12-31', 'starts_on' => '2026-12-01'], $uye);
        $ek = $this->donem(['package_id' => Package::factory()->examClubAddon()->create(['monthly_price' => 1500])->id,
            'price' => 1500, 'base_price' => 1500], $uye);

        $this->gun('2026-12-01');
        $this->yenile();

        $this->assertSame('2026-12-01', $ek->fresh()->renewal->starts_on->toDateString());
        $this->assertSame(3, $uye->subscriptions()->count(), 'Aralik ana paketi zaten vardi');
    }

    // --- Yenilenmeyenler --------------------------------------------------------

    public function test_old_rows_and_stopped_or_cancelled_periods_do_not_renew(): void
    {
        $eskiSatir = Subscription::factory()->create(['package_id' => $this->standart->id,
            'starts_on' => '2026-11-01', 'ends_on' => '2026-11-30']);
        $durdurulan = $this->donem(['auto_renew' => false]);
        $iptal = $this->donem(['payment_status' => PaymentStatus::Cancelled->value]);

        $this->gun('2026-12-01');
        $this->assertSame(0, $this->yenile());

        $this->assertFalse($eskiSatir->fresh()->auto_renew, 'migration oncesi satirlar kendiliginden yenilenmez');
        $this->assertNull($durdurulan->fresh()->renewal);
        $this->assertNull($iptal->fresh()->renewal);
    }

    public function test_a_suspended_member_is_not_charged_for_the_months_away(): void
    {
        $uye = User::factory()->student()->create(['subscription_status' => 'suspended']);
        $donem = $this->donem([], $uye);

        $this->gun('2027-03-01');
        $this->yenile();

        $this->assertSame(1, Subscription::count());
        $this->assertFalse($donem->fresh()->auto_renew, 'zincir durur; geri gelince paket yeniden atanir');
    }

    public function test_a_newer_period_in_the_same_slot_takes_over_the_chain(): void
    {
        $uye = User::factory()->student()->create();
        $eski = $this->donem(['starts_on' => '2026-10-01', 'ends_on' => '2026-10-31'], $uye);
        $this->donem(['auto_renew' => false], $uye);

        $this->yenile();

        $this->assertSame(2, Subscription::count());
        $this->assertFalse($eski->fresh()->auto_renew);
    }

    /** Canlida ayni gun baslayan iki ana paket var: ikisi birden yenilenmemeli. */
    public function test_of_two_main_packages_starting_the_same_day_only_the_later_one_renews(): void
    {
        $uye = User::factory()->student()->create();
        $ilk = $this->donem([], $uye);
        $sonraki = $this->donem(['package_id' => Package::factory()->examOnly()->create()->id], $uye);

        $this->gun('2026-12-01');
        $this->yenile();

        $this->assertNull($ilk->fresh()->renewal);
        $this->assertFalse($ilk->fresh()->auto_renew);
        $this->assertNotNull($sonraki->fresh()->renewal);
        $this->assertSame(3, Subscription::count());
    }

    // --- Yonetim ----------------------------------------------------------------

    public function test_the_admin_can_stop_and_resume_renewal(): void
    {
        $yonetici = User::factory()->admin()->create();
        $donem = $this->donem(['next_package_id' => Package::factory()->tier3()->create()->id]);

        $this->actingAs($yonetici)->post(route('admin.subscriptions.renewal', $donem), ['auto_renew' => 0])
            ->assertSessionHas('success', 'Yenileme durduruldu; paket 30.11.2026 tarihinde bitecek.');
        $this->assertFalse($donem->fresh()->auto_renew);
        $this->assertNull($donem->fresh()->next_package_id, 'durdurulan paketin bekleyen degisikligi de duser');

        $this->actingAs($yonetici)->post(route('admin.subscriptions.renewal', $donem), ['auto_renew' => 1])
            ->assertSessionHas('success', 'Paket her ay yenilenecek; yeni dönem 01.12.2026 tarihinde başlar.');
        $this->assertTrue($donem->fresh()->auto_renew);
    }

    public function test_renewal_cannot_be_switched_on_for_a_period_that_already_ended(): void
    {
        $bitmis = $this->donem(['starts_on' => '2026-09-01', 'ends_on' => '2026-09-30', 'auto_renew' => false]);

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.subscriptions.renewal', $bitmis), ['auto_renew' => 1])
            ->assertSessionHas('error');

        $this->assertFalse($bitmis->fresh()->auto_renew, 'acilsaydi gecen aylar borc yazilirdi');
    }

    public function test_cancelling_a_period_also_stops_its_renewal(): void
    {
        $donem = $this->donem();

        $this->actingAs(User::factory()->admin()->create())->post(route('admin.subscriptions.cancel', $donem));

        $this->assertFalse($donem->fresh()->auto_renew);
        $this->gun('2026-12-01');
        $this->assertSame(0, $this->yenile());
    }

    /** Ay ortasinda atanan paket ay sonuna kadar kist; ayin 1'inde tam ay. */
    public function test_assigning_mid_month_is_prorated_and_renews_by_default_or_can_be_one_off(): void
    {
        $yonetici = User::factory()->admin()->create();
        $a = User::factory()->student()->create();
        $b = User::factory()->student()->create();

        $this->actingAs($yonetici)->post(route('admin.subscriptions.store', $a), [
            'package_id' => $this->standart->id, 'starts_on' => '2026-11-18', 'auto_renew' => '1',
        ])->assertSessionHas('success', "Paket atandı: 18.11–30.11.2026 için 3.250,00 ₺ (kıst). Her ayın 1'inde yenilenecek.");
        $this->actingAs($yonetici)->post(route('admin.subscriptions.store', $b), [
            'package_id' => $this->standart->id, 'starts_on' => '2026-11-18', 'auto_renew' => '0', 'price' => 6000,
        ])->assertSessionHas('success', 'Paket atandı: 18.11–30.11.2026 için 2.600,00 ₺ (kıst). Dönem sonunda bitecek.');

        $this->assertSame(['3250.00', '7500.00', true], [$a->subscriptions()->sole()->price, $a->subscriptions()->sole()->base_price, $a->subscriptions()->sole()->auto_renew]);
        $this->assertFalse($b->subscriptions()->sole()->auto_renew);

        $this->gun('2026-12-01');
        $this->yenile();
        $this->assertSame('7500.00', $a->subscriptions()->whereDate('starts_on', '2026-12-01')->sole()->price);
        $this->assertSame(1, $b->subscriptions()->count());
    }

    /** Canlida ayni gun baslayan iki ana paket vardi: uye iki kez borclaniyordu. */
    public function test_a_second_main_package_over_the_same_dates_is_refused_but_an_addon_is_fine(): void
    {
        $yonetici = User::factory()->admin()->create();
        $uye = User::factory()->student()->create();
        $this->donem([], $uye);

        $this->actingAs($yonetici)->from(route('admin.subscriptions.index', $uye))
            ->post(route('admin.subscriptions.store', $uye), [
                'package_id' => Package::factory()->tier3()->create()->id, 'starts_on' => '2026-11-20',
            ])
            ->assertRedirect(route('admin.subscriptions.index', $uye))
            ->assertSessionHas('error');

        $this->actingAs($yonetici)->post(route('admin.subscriptions.store', $uye), [
            'package_id' => Package::factory()->examClubAddon()->create()->id, 'starts_on' => '2026-11-20',
        ])->assertSessionHas('success');

        $this->assertSame(2, $uye->subscriptions()->count());

        // Son gune basliyorsa da cakisir; ertesi gun serbest.
        $this->actingAs($yonetici)->post(route('admin.subscriptions.store', $uye), [
            'package_id' => $this->standart->id, 'starts_on' => '2026-11-30',
        ])->assertSessionHas('error');
        $this->actingAs($yonetici)->post(route('admin.subscriptions.store', $uye), [
            'package_id' => $this->standart->id, 'starts_on' => '2026-12-01',
        ])->assertSessionHas('success');
    }

    public function test_the_payments_page_shows_the_renewal_state(): void
    {
        $uye = User::factory()->student()->create();
        $donem = $this->donem([], $uye);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.subscriptions.index', $uye))
            ->assertOk()
            ->assertSee('Her ay kendiliğinden yenilensin')
            ->assertSee('Her ay yenilenir')
            ->assertSee('yeni dönem 01.12.2026')
            ->assertSee('Yenilemeyi durdur');

        $this->gun('2026-12-01');
        $this->actingAs(User::factory()->admin()->create())->get(route('admin.subscriptions.index', $uye))
            ->assertOk()
            ->assertSee('Yenilendi → 01.12.2026 – 31.12.2026');
        $this->assertNotNull($donem->fresh()->renewal, 'sayfa acilisi da yeniler');
    }

    public function test_the_member_sees_that_the_package_renews(): void
    {
        $uye = User::factory()->student()->create();
        $this->donem([], $uye);

        $this->actingAs($uye)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('her ay yenilenir, yeni dönem 1 Aralık');
    }
}
