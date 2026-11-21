<?php

namespace Tests\Feature;

use App\Enums\BillPaymentStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Models\Consumption;
use App\Models\Location;
use App\Models\MonthlyBill;
use App\Models\Package;
use App\Models\Product;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Hesaplar (21 Kasim 2026): paket ve adisyon her ayin basinda yenilenir;
 * ikisi ayri kontrol edilir; kapanan ayin borcu ve odemesi uye bazinda
 * filtrelenir.
 */
class MonthlyAccountsTest extends TestCase
{
    use RefreshDatabase;

    private User $yonetici;

    private User $ayse;

    private User $burak;

    private Package $standart;

    private Product $tost;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-11-18 12:00', config('kafe.timezone')));

        $this->yonetici = User::factory()->admin()->create();
        $this->ayse = User::factory()->student()->create(['name' => 'Ayşe Kaya']);
        $this->burak = User::factory()->student()->create(['name' => 'Burak Demir']);
        $this->standart = Package::factory()->tier1()->create(['monthly_price' => 7500]);
        $this->tost = Product::create(['name' => 'Tost', 'unit_price' => 50, 'unit_type' => 'adet', 'is_active' => true]);
    }

    private function donem(User $uye, string $ay, array $ek = []): Subscription
    {
        $bas = Carbon::parse($ay . '-01');

        return Subscription::factory()->create(array_merge([
            'student_id' => $uye->id, 'package_id' => $this->standart->id,
            'starts_on' => $bas->toDateString(), 'ends_on' => $bas->copy()->endOfMonth()->toDateString(),
            'price' => 7500, 'base_price' => 7500,
        ], $ek));
    }

    private function siparis(User $uye, string $zaman, int $adet): Consumption
    {
        return Consumption::create([
            'user_id' => $uye->id, 'product_id' => $this->tost->id, 'location_id' => Location::selfService()->id,
            'quantity' => $adet, 'covered_quantity' => 0, 'unit_price' => 50,
            // Eloquent yerel Carbon'u UTC'ye cevirmez (README SS10.1).
            'consumed_at' => Carbon::parse($zaman, config('kafe.timezone'))->utc(),
        ]);
    }

    private function sayfa(array $filtre = [])
    {
        return $this->actingAs($this->yonetici)->get(route('admin.accounts.index', $filtre));
    }

    private function bill(User $uye, string $ay): ?MonthlyBill
    {
        return MonthlyBill::where('user_id', $uye->id)->whereDate('bill_month', $ay . '-01')->first();
    }

    // --- Adisyon her sipariste kendiliginden ------------------------------------

    public function test_an_order_opens_and_updates_its_months_adisyon(): void
    {
        $this->actingAs($this->ayse)->post(route('user.tab.store'), ['product_id' => $this->tost->id, 'quantity' => 2])
            ->assertRedirect();

        $kasim = $this->bill($this->ayse, '2026-11');
        $this->assertNotNull($kasim, 'ilk siparis ayin adisyonunu acar');
        $this->assertEquals(100, $kasim->total_amount);

        $kasim->payments()->create(['amount' => 100, 'method' => 'cash', 'paid_on' => '2026-11-18', 'recorded_by' => $this->yonetici->id]);
        $kasim->syncPayment();
        $this->assertSame(BillPaymentStatus::Paid, $kasim->fresh()->payment_status);

        // Odendikten sonra yeni siparis: ay yeniden "kismen" olur (karar 3).
        $son = $this->siparis($this->ayse, '2026-11-18 13:00', 1);
        $this->assertEquals(150, $kasim->fresh()->total_amount);
        $this->assertSame(BillPaymentStatus::Partial, $kasim->fresh()->payment_status);

        // Geri alma da yansir.
        $son->undo();
        $this->assertEquals(100, $kasim->fresh()->total_amount);
        $this->assertSame(BillPaymentStatus::Paid, $kasim->fresh()->payment_status);
    }

    public function test_a_new_month_starts_a_new_adisyon(): void
    {
        $this->siparis($this->ayse, '2026-11-30 23:50', 1);
        $this->siparis($this->ayse, '2026-12-01 00:10', 3);

        $this->assertEquals(50, $this->bill($this->ayse, '2026-11')->total_amount);
        $this->assertEquals(150, $this->bill($this->ayse, '2026-12')->total_amount);
    }

    // --- Sayfa -------------------------------------------------------------------

    public function test_the_current_month_shows_package_and_adisyon_separately_per_member(): void
    {
        $ayseDonem = $this->donem($this->ayse, '2026-11');
        $odenen = $this->donem($this->burak, '2026-11');
        $odenen->payments()->create(['amount' => 7500, 'paid_at' => '2026-11-02', 'method' => 'cash']);
        $this->siparis($this->ayse, '2026-11-10 12:00', 2);

        $this->sayfa()->assertOk()
            ->assertSee('Kasım 2026')
            ->assertSee("paketler ayın 1'inde yenilenir", false)
            ->assertSeeInOrder(['Ayşe Kaya</strong>', '🎫 Paket', 'Gecikmiş', 'Kalan <strong>7.500,00 ₺</strong>', '🧾 Adisyon', 'Ödenmedi', 'Kalan <strong>100,00 ₺</strong>'], false)
            ->assertSeeInOrder(['Burak Demir</strong>', 'Ödendi', 'Bu ay adisyon yok.'], false)
            ->assertSee('Paket ödemesi al')
            ->assertSee('Adisyon ödemesi al')
            ->assertSee(route('admin.subscriptions.payments.store', $ayseDonem), false)
            ->assertDontSee(route('admin.subscriptions.payments.store', $odenen), false);
    }

    public function test_a_closed_month_is_filtered_per_member(): void
    {
        $this->donem($this->ayse, '2026-10');
        $this->donem($this->burak, '2026-10', ['payment_status' => PaymentStatus::Paid->value])
            ->payments()->create(['amount' => 7500, 'paid_at' => '2026-10-03', 'method' => 'cash']);
        $this->donem($this->ayse, '2026-11');
        $this->siparis($this->ayse, '2026-10-12 12:00', 4);

        $this->sayfa(['ay' => '2026-10'])->assertOk()
            ->assertSee('Ekim 2026 kapandı.')
            ->assertSee('1 üyenin toplam 7.700,00 ₺ borcu kaldı.')
            ->assertSee('Ayşe Kaya</strong>', false)->assertSee('Burak Demir</strong>', false)
            ->assertDontSee('01.11–30.11');

        $this->sayfa(['ay' => '2026-10', 'uye' => $this->burak->id])->assertOk()
            ->assertSee('Burak Demir</strong>', false)->assertDontSee('Ayşe Kaya</strong>', false)
            ->assertSee('Borcu yok');

        $this->sayfa(['ay' => '2026-10', 'durum' => 'borclu'])->assertOk()
            ->assertSee('Ayşe Kaya</strong>', false)->assertDontSee('Burak Demir</strong>', false);
    }

    public function test_one_member_across_all_months(): void
    {
        $this->donem($this->ayse, '2026-10');
        $this->donem($this->ayse, '2026-11');
        $this->siparis($this->ayse, '2026-09-05 12:00', 1);

        $this->sayfa(['uye' => $this->ayse->id, 'ay' => 'tum'])->assertOk()
            ->assertSee('Tüm aylar (Ayşe Kaya)')
            ->assertSeeInOrder(['Kasım 2026', 'Ekim 2026', 'Eylül 2026']);
    }

    public function test_the_type_filter_shows_only_packages_or_only_adisyon(): void
    {
        $this->donem($this->ayse, '2026-11');
        $this->siparis($this->burak, '2026-11-10 12:00', 1);

        $this->sayfa(['tur' => 'paket'])->assertOk()
            ->assertSee('Ayşe Kaya</strong>', false)->assertDontSee('Burak Demir</strong>', false)
            ->assertSee('🎫 Paket')->assertDontSee('🧾 Adisyon');

        $this->sayfa(['tur' => 'adisyon'])->assertOk()
            ->assertSee('Burak Demir</strong>', false)->assertDontSee('Ayşe Kaya</strong>', false)
            ->assertSee('🧾 Adisyon')->assertDontSee('🎫 Paket');
    }

    public function test_the_status_filter_follows_the_chosen_type(): void
    {
        $this->donem($this->ayse, '2026-11', ['price' => 7500])
            ->payments()->create(['amount' => 7500, 'paid_at' => '2026-11-02', 'method' => 'cash']);
        $this->siparis($this->ayse, '2026-11-10 12:00', 1);   // adisyon borclu

        $this->sayfa(['tur' => 'paket', 'durum' => 'odendi'])->assertOk()->assertSee('Ayşe Kaya</strong>', false);
        $this->sayfa(['tur' => 'paket', 'durum' => 'borclu'])->assertOk()->assertSee('Bu filtrede hesap yok.');
        $this->sayfa(['tur' => 'adisyon', 'durum' => 'borclu'])->assertOk()->assertSee('Ayşe Kaya</strong>', false);
        $this->sayfa(['durum' => 'odendi'])->assertOk()->assertSee('Bu filtrede hesap yok.');
    }

    public function test_payments_taken_on_the_page_come_back_to_it(): void
    {
        $donem = $this->donem($this->ayse, '2026-11');
        $this->siparis($this->ayse, '2026-11-10 12:00', 2);
        $adres = route('admin.accounts.index', ['ay' => '2026-11', 'uye' => $this->ayse->id]);

        $this->actingAs($this->yonetici)->from($adres)
            ->post(route('admin.subscriptions.payments.store', $donem), ['amount' => 7500, 'paid_at' => '2026-11-18', 'method' => 'cash', 'kaynak' => 'paket-' . $donem->id])
            ->assertRedirect($adres);
        $this->actingAs($this->yonetici)->from($adres)
            ->post(route('admin.bills.payments.store', $this->bill($this->ayse, '2026-11')), ['amount' => 60, 'method' => 'cash'])
            ->assertRedirect($adres);

        $this->assertSame(PaymentStatus::Paid, $donem->fresh()->payment_status);
        $this->sayfa(['ay' => '2026-11', 'uye' => $this->ayse->id])->assertOk()
            ->assertSee('Kısmen ödendi')
            ->assertSee('Kalan 40,00 ₺');
    }

    /** Eylul gibi, kendiliginden acilma oncesi aylarin kaydi sayfa acilinca uretilir. */
    public function test_an_old_month_without_an_adisyon_record_gets_one_so_it_can_be_paid(): void
    {
        $this->siparis($this->ayse, '2026-09-05 12:00', 3);
        MonthlyBill::query()->delete();

        $this->sayfa(['ay' => '2026-09'])->assertOk()
            ->assertSee('Ayşe Kaya</strong>', false)
            ->assertSee('Kalan <strong>150,00 ₺</strong>', false)
            ->assertSee('Adisyon ödemesi al');

        $this->assertNotNull($this->bill($this->ayse, '2026-09'));
    }

    public function test_recalculating_from_the_page_returns_to_the_same_month(): void
    {
        $this->siparis($this->ayse, '2026-10-05 12:00', 1);

        $this->actingAs($this->yonetici)
            ->post(route('admin.reports.generate-bills'), ['year' => 2026, 'month' => 10, 'geri' => 'hesaplar'])
            ->assertRedirect(route('admin.accounts.index', ['ay' => '2026-10']))
            ->assertSessionHas('success');
    }

    public function test_packages_renewed_on_the_first_show_up_in_the_new_month(): void
    {
        $this->donem($this->ayse, '2026-11', ['auto_renew' => true]);

        $this->travelTo(Carbon::parse('2026-12-01 09:00', config('kafe.timezone')));

        $this->sayfa()->assertOk()
            ->assertSee('Aralık 2026')
            ->assertSee('Standart · 01.12–31.12')
            ->assertSee('Kalan <strong>7.500,00 ₺</strong>', false);
    }

    public function test_only_the_admin_sees_the_accounts_and_finds_them_in_the_menu(): void
    {
        foreach ([Role::Staff, Role::Coach, Role::Student, Role::Parent] as $rol) {
            $this->actingAs(User::factory()->create(['role' => $rol->value, 'subscription_status' => 'active']))
                ->get(route('admin.accounts.index'))->assertForbidden();
        }

        $this->actingAs($this->yonetici)->get(route('admin.dashboard'))
            ->assertSee('href="' . route('admin.accounts.index') . '"', false);
    }
}
