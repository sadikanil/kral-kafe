<?php

namespace Tests\Feature;

use App\Models\Consumption;
use App\Models\Product;
use App\Models\StudyTable;
use App\Models\User;
use App\Services\StudySessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * DEV-506 (26 Ekim 2026): masa QR'i once Adisyonum'u acar.
 *
 * Masadaki QR'lar DEGISMEZ (ayni /masa/{kod} adresi). Okutan uye once bu
 * ayin adisyonunu gorur: toplam, kalemler, son eklenenler, odeme durumu
 * alani. "Urun ekle" mevcut urun secme ekranina gider (masa bilgisi
 * korunur); calisma oturumuna gecmek isteyene ikinci adim.
 */
class AdisyonumTest extends TestCase
{
    use RefreshDatabase;

    private User $uye;
    private StudyTable $masa;
    private Product $kahve;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-26 14:00', config('kafe.timezone')));

        $this->uye = User::factory()->student()->withPackage(\App\Models\Package::factory()->tier1())
            ->create(['name' => 'Elif Yıldırım', 'phone' => '5321234567']);
        $this->masa = StudyTable::create(['name' => 'Pencere Önü 3']);
        $this->kahve = Product::create(['name' => 'Filtre Kahve', 'unit_price' => 25, 'unit_type' => 'adet', 'is_active' => true, 'emoji' => '☕']);
    }

    private function ekle(int $adet, ?Product $urun = null, ?string $zaman = null): Consumption
    {
        if ($zaman !== null) {
            $this->travelTo(Carbon::parse($zaman, config('kafe.timezone')));
        }

        $kayit = Consumption::create([
            'user_id' => $this->uye->id, 'product_id' => ($urun ?? $this->kahve)->id,
            'location_id' => \App\Models\Location::selfService()->id,
            'quantity' => $adet, 'covered_quantity' => 0, 'unit_price' => ($urun ?? $this->kahve)->unit_price,
        ]);
        $this->travelTo(Carbon::parse('2026-10-26 14:00', config('kafe.timezone')));

        return $kayit;
    }

    // --- QR -> Adisyonum ----------------------------------------------------

    public function test_the_table_qr_keeps_its_address(): void
    {
        $this->assertSame(url('/masa/' . $this->masa->qr_code), $this->masa->qr_url);
        $this->assertSame($this->masa->qr_url, route('table.scan', $this->masa->qr_code));
    }

    public function test_scanning_the_table_qr_opens_the_tab_first(): void
    {
        $this->ekle(2, zaman: '2026-10-26 10:15');

        $this->actingAs($this->uye)->get(route('table.scan', $this->masa->qr_code))
            ->assertOk()
            ->assertSeeInOrder(['Adisyonum', 'Ekim 2026', 'Pencere Önü 3', '50,00 ₺', 'Ödeme durumu',
                'Ürün ekle', 'Çalışma', 'Son eklenenler', 'Filtre Kahve'])
            // Urun ekleme masa bilgisini tasir; oturum ikinci adimda.
            ->assertSee(route('user.tab.add', ['masa' => $this->masa->qr_code]), false)
            ->assertSee(route('table.session', $this->masa->qr_code), false)
            ->assertDontSee(route('table.session.start', $this->masa->qr_code), false);
    }

    public function test_the_session_is_the_second_step(): void
    {
        $this->actingAs($this->uye)->get(route('table.session', $this->masa->qr_code))
            ->assertOk()
            ->assertSee('Çalışmaya Başla')
            ->assertSee(route('table.session.start', $this->masa->qr_code), false)
            ->assertSee(route('table.scan', $this->masa->qr_code), false);

        $this->actingAs($this->uye)->post(route('table.session.start', $this->masa->qr_code))
            ->assertRedirect(route('session.timer'));
    }

    public function test_a_running_session_at_this_table_is_shown_on_the_tab(): void
    {
        app(StudySessionService::class)->start($this->uye, $this->masa, []);

        $this->actingAs($this->uye)->get(route('table.scan', $this->masa->qr_code))
            ->assertOk()
            ->assertSee('Çalışma sürüyor')
            ->assertSee(route('session.timer'), false);
    }

    public function test_staff_scanning_a_table_go_straight_to_the_table_screen(): void
    {
        $yonetici = User::factory()->admin()->create();

        $this->actingAs($yonetici)->get(route('table.scan', $this->masa->qr_code))
            ->assertRedirect(route('table.session', $this->masa->qr_code));
    }

    public function test_an_unknown_table_code_is_not_found(): void
    {
        $this->actingAs($this->uye)->get('/masa/MASA-YOKBOYLE')->assertNotFound();
    }

    // --- Giris sonrasi donus ------------------------------------------------

    public function test_a_signed_out_scan_returns_to_the_tab_after_login(): void
    {
        $this->uye->update(['password' => 'gizli-sifre']);

        $this->get(route('table.scan', $this->masa->qr_code))->assertRedirect(route('login'));

        $this->post(route('login'), ['kimlik' => '0532 123 45 67'])->assertRedirect(route('login'));
        $this->post(route('login'), ['kimlik' => '0532 123 45 67', 'adim' => 'sifre', 'password' => 'gizli-sifre'])
            ->assertRedirect(route('table.scan', $this->masa->qr_code));

        $this->get(route('table.scan', $this->masa->qr_code))->assertOk()->assertSee('Adisyonum')->assertSee('Pencere Önü 3');
    }

    // --- Urun ekleme akisi --------------------------------------------------

    public function test_adding_from_the_table_returns_to_the_same_table_tab(): void
    {
        $this->actingAs($this->uye)->get(route('user.tab.add', ['masa' => $this->masa->qr_code]))
            ->assertOk()
            ->assertSee('Filtre Kahve')
            ->assertSee('name="masa" value="' . $this->masa->qr_code . '"', false)
            ->assertSee(route('table.scan', $this->masa->qr_code), false);

        $this->actingAs($this->uye)->post(route('user.tab.store'), [
            'product_id' => $this->kahve->id, 'quantity' => 2, 'masa' => $this->masa->qr_code,
        ])->assertRedirect(route('table.scan', $this->masa->qr_code))->assertSessionHas('success');

        $this->assertSame(2, Consumption::sole()->quantity);
        $this->actingAs($this->uye)->get(route('table.scan', $this->masa->qr_code))
            ->assertSee('Filtre Kahve')->assertSee('Geri al');
    }

    public function test_adding_without_a_table_returns_to_the_tab(): void
    {
        $this->actingAs($this->uye)->post(route('user.tab.store'), ['product_id' => $this->kahve->id, 'quantity' => 1])
            ->assertRedirect(route('user.tab'));

        // Uydurma masa kodu yonlendirmeyi degistirmez.
        $this->actingAs($this->uye)->post(route('user.tab.store'), ['product_id' => $this->kahve->id, 'quantity' => 1, 'masa' => 'MASA-YOK'])
            ->assertRedirect(route('user.tab'));

        $this->assertSame(2, Consumption::count());
    }

    // --- Adisyonum ----------------------------------------------------------

    public function test_the_tab_lists_items_grouped_and_the_latest_entries(): void
    {
        $su = Product::create(['name' => 'Su', 'unit_price' => 10, 'unit_type' => 'adet', 'is_active' => true]);
        $this->ekle(1, zaman: '2026-10-02 10:00');
        $this->ekle(2, zaman: '2026-10-20 10:00');
        $this->ekle(3, $su, '2026-10-25 10:00');
        $geriAlinan = $this->ekle(5, $su, '2026-10-26 13:59');
        $geriAlinan->undo();

        $sayfa = $this->actingAs($this->uye)->get(route('user.tab'))->assertOk();

        // 3 kahve (75) + 3 su (30) = 105; geri alinan sayilmaz.
        $sayfa->assertSeeInOrder(['105,00 ₺', '6 ürün', 'Kalemler', 'Filtre Kahve', '×3', '75,00 ₺', 'Su', '×3', '30,00 ₺']);
        $this->assertSame(['Su', 'Filtre Kahve', 'Filtre Kahve'], $sayfa->viewData('latest')->pluck('product.name')->all());
    }

    public function test_past_months_are_listed_and_open_read_only(): void
    {
        $this->ekle(2, zaman: '2026-09-10 10:00');
        $this->ekle(1, zaman: '2026-08-05 10:00');
        $this->ekle(1);

        $this->actingAs($this->uye)->get(route('user.tab'))
            ->assertOk()
            ->assertSeeInOrder(['Geçmiş adisyonlar', 'Eylül 2026', '50,00 ₺', 'Ağustos 2026', '25,00 ₺'])
            ->assertSee(route('user.tab', ['ay' => '2026-09']), false);

        $this->actingAs($this->uye)->get(route('user.tab', ['ay' => '2026-09']))
            ->assertOk()
            ->assertSee('Eylül 2026')
            ->assertSee('50,00 ₺')
            ->assertDontSee('Ürün ekle')
            ->assertDontSee('Geri al');
    }

    public function test_the_tab_is_first_in_the_member_menu(): void
    {
        $gruplar = \App\Support\Navigation::groups($this->uye);
        $this->assertSame(['user.tab', 'Adisyonum'], [$gruplar[0]['items'][0]['route'], $gruplar[0]['items'][0]['label']]);
        $this->assertSame('user.tab', \App\Support\Navigation::quick($this->uye)[0]['route']);
    }
}
