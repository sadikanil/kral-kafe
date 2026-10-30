<?php

namespace Tests\Feature;

use App\Enums\BillPaymentStatus;
use App\Models\BillPayment;
use App\Models\Consumption;
use App\Models\Location;
use App\Models\MonthlyBill;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * DEV-507 (27 Ekim 2026): adisyon tahsilati.
 *
 * Yonetici aylik adisyona "odeme alindi" der (tutar + yontem + not);
 * kismi odeme olur, her odeme ve geri alma kayit altinda. Tahsil edilen
 * ADISYON tutari (total_amount): paket bedelinin odemeleri ayrica
 * Odemeler'de (abonelik) tutuluyor, iki kez sayilmasin.
 */
class AdisyonTahsilatTest extends TestCase
{
    use RefreshDatabase;

    private User $yonetici;
    private User $uye;
    private MonthlyBill $eylul;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-27 11:00', config('kafe.timezone')));

        $this->yonetici = User::factory()->admin()->create(['name' => 'Cahit Atılğan']);
        $this->uye = User::factory()->student()->create(['name' => 'Elif Yıldırım']);
        $this->eylul = $this->adisyon($this->uye, '2026-09-01', 240);
    }

    private function adisyon(User $uye, string $ay, float $tutar, float $paket = 0): MonthlyBill
    {
        return MonthlyBill::create([
            'user_id' => $uye->id, 'bill_month' => $ay, 'total_items' => 6,
            'total_amount' => $tutar, 'package_amount' => $paket, 'status' => 'pending',
        ]);
    }

    private function ode(MonthlyBill $adisyon, float $tutar, string $yontem = 'cash', array $ek = [])
    {
        return $this->actingAs($this->yonetici)->post(route('admin.bills.payments.store', $adisyon),
            ['amount' => $tutar, 'method' => $yontem] + $ek);
    }

    // --- Migration ----------------------------------------------------------

    public function test_bills_start_unpaid_with_the_new_columns(): void
    {
        foreach (['payment_status', 'paid_amount', 'paid_at', 'payment_method', 'paid_by', 'payment_note'] as $sutun) {
            $this->assertTrue(Schema::hasColumn('monthly_bills', $sutun), $sutun);
        }

        $this->eylul->refresh();
        $this->assertSame(BillPaymentStatus::Unpaid, $this->eylul->payment_status);
        $this->assertSame(0.0, (float) $this->eylul->paid_amount);
        $this->assertNull($this->eylul->paid_at);
    }

    // --- Yetki --------------------------------------------------------------

    public function test_only_an_admin_can_see_and_mark(): void
    {
        $koc = User::factory()->create(['role' => 'coach']);
        $veli = User::factory()->parent()->create();
        $veli->students()->attach($this->uye->id);

        foreach ([$this->uye, $koc, $veli] as $kisi) {
            $this->actingAs($kisi)->get(route('admin.bills.index'))->assertForbidden();
            $this->actingAs($kisi)->post(route('admin.bills.payments.store', $this->eylul), ['amount' => 240, 'method' => 'cash'])
                ->assertForbidden();
        }
        auth()->logout();
        $this->post(route('admin.bills.payments.store', $this->eylul), ['amount' => 240, 'method' => 'cash'])
            ->assertRedirect(route('login'));

        $this->assertSame(0, BillPayment::count());
        $this->assertSame(BillPaymentStatus::Unpaid, $this->eylul->fresh()->payment_status);
    }

    // --- Odeme --------------------------------------------------------------

    public function test_a_full_payment_marks_the_bill_paid_and_is_logged(): void
    {
        $this->ode($this->eylul, 240, 'card', ['note' => 'Kasada çekildi', 'paid_on' => '2026-10-26'])
            ->assertRedirect()->assertSessionHas('success');

        $adisyon = $this->eylul->fresh();
        $this->assertSame(BillPaymentStatus::Paid, $adisyon->payment_status);
        $this->assertSame(240.0, (float) $adisyon->paid_amount);
        $this->assertSame('2026-10-26', $adisyon->paid_at->toDateString());
        $this->assertSame('card', $adisyon->payment_method->value);
        $this->assertSame($this->yonetici->id, $adisyon->paid_by);
        $this->assertSame('Kasada çekildi', $adisyon->payment_note);

        $kayit = BillPayment::sole();
        $this->assertSame([240.0, 'card', $this->yonetici->id], [(float) $kayit->amount, $kayit->method->value, $kayit->recorded_by]);
        $this->assertNotNull($kayit->created_at);
        $this->assertNull($kayit->reversed_at);
    }

    public function test_partial_payments_add_up(): void
    {
        $this->ode($this->eylul, 100)->assertSessionHas('success');
        $this->assertSame(BillPaymentStatus::Partial, $this->eylul->fresh()->payment_status);
        $this->assertSame(140.0, $this->eylul->fresh()->remaining());

        $this->ode($this->eylul, 140, 'transfer');
        $adisyon = $this->eylul->fresh();
        $this->assertSame(BillPaymentStatus::Paid, $adisyon->payment_status);
        $this->assertSame(240.0, (float) $adisyon->paid_amount);
        $this->assertSame('transfer', $adisyon->payment_method->value);
        $this->assertSame(2, BillPayment::count());
    }

    public function test_more_than_the_open_amount_or_a_bad_method_is_refused(): void
    {
        $this->ode($this->eylul, 100);

        $this->ode($this->eylul, 140.01)->assertSessionHasErrors('amount');
        $this->ode($this->eylul, 0)->assertSessionHasErrors('amount');
        $this->ode($this->eylul, 50, 'other')->assertSessionHasErrors('method');
        $this->ode($this->eylul, 50, 'cash', ['paid_on' => '2026-10-28'])->assertSessionHasErrors('paid_on');

        $this->assertSame(1, BillPayment::count());
    }

    public function test_a_bill_with_nothing_to_collect_takes_no_payment(): void
    {
        $paketli = $this->adisyon(User::factory()->student()->create(), '2026-09-01', 0, 1500);

        $this->ode($paketli, 10)->assertSessionHasErrors('amount');
        $this->assertSame(0, BillPayment::count());
    }

    // --- Geri alma ----------------------------------------------------------

    public function test_a_payment_can_be_reversed_and_stays_in_the_log(): void
    {
        $this->ode($this->eylul, 100, 'cash', ['note' => 'İlk']);
        $this->travel(1)->hours();
        $this->ode($this->eylul, 140, 'card');
        $ikinci = BillPayment::latest('id')->first();

        $this->actingAs($this->yonetici)->post(route('admin.bills.payments.reverse', $ikinci))
            ->assertRedirect()->assertSessionHas('success');

        $adisyon = $this->eylul->fresh();
        $this->assertSame(BillPaymentStatus::Partial, $adisyon->payment_status);
        $this->assertSame(100.0, (float) $adisyon->paid_amount);
        // Odeme bilgisi bir onceki gecerli odemeye doner.
        $this->assertSame(['cash', 'İlk'], [$adisyon->payment_method->value, $adisyon->payment_note]);

        $ikinci->refresh();
        $this->assertNotNull($ikinci->reversed_at);
        $this->assertSame($this->yonetici->id, $ikinci->reversed_by);
        $this->assertSame(2, BillPayment::count(), 'geri alinan odeme silinmez');

        // Ikinci kez geri alinamaz.
        $this->actingAs($this->yonetici)->post(route('admin.bills.payments.reverse', $ikinci))->assertSessionHas('error');

        // Hepsi geri alininca odenmedi; odeme bilgisi bosalir.
        $this->actingAs($this->yonetici)->post(route('admin.bills.payments.reverse', BillPayment::oldest('id')->first()));
        $adisyon = $this->eylul->fresh();
        $this->assertSame(BillPaymentStatus::Unpaid, $adisyon->payment_status);
        $this->assertNull($adisyon->paid_at);
        $this->assertNull($adisyon->payment_method);
        $this->assertNull($adisyon->paid_by);
    }

    public function test_only_an_admin_can_reverse(): void
    {
        $this->ode($this->eylul, 240);

        $this->actingAs($this->uye)->post(route('admin.bills.payments.reverse', BillPayment::sole()))->assertForbidden();
        $this->assertSame(BillPaymentStatus::Paid, $this->eylul->fresh()->payment_status);
    }

    // --- Liste --------------------------------------------------------------

    public function test_the_list_filters_unpaid_and_shows_the_open_total(): void
    {
        $odenmis = $this->adisyon($ali = User::factory()->student()->create(['name' => 'Ali Kaya']), '2026-08-01', 90);
        $kismi = $this->adisyon(User::factory()->student()->create(['name' => 'Bora Şen']), '2026-09-01', 300);
        $this->adisyon(User::factory()->student()->create(['name' => 'Paketli Can']), '2026-09-01', 0, 1500);
        $this->ode($odenmis, 90);
        $this->ode($kismi, 120);

        // Acik alacak: Elif 240 + Bora 180 = 420.
        $this->actingAs($this->yonetici)->get(route('admin.bills.index', ['durum' => 'odenmemis']))
            ->assertOk()
            ->assertSeeInOrder(['Toplam açık alacak', '420,00 ₺'])
            ->assertSee('Elif Yıldırım')
            ->assertSee('Bora Şen')
            ->assertSee('Kısmen ödendi')
            ->assertDontSee('Ali Kaya')
            ->assertDontSee('Paketli Can');

        $this->actingAs($this->yonetici)->get(route('admin.bills.index', ['durum' => 'odendi']))
            ->assertOk()->assertSee('Ali Kaya')->assertDontSee('Elif Yıldırım');

        $this->actingAs($this->yonetici)->get(route('admin.bills.index', ['ay' => '2026-08', 'durum' => 'tumu']))
            ->assertOk()->assertSee('Ali Kaya')->assertDontSee('Bora Şen');
    }

    public function test_the_list_offers_the_payment_form_and_the_history(): void
    {
        $this->ode($this->eylul, 100, 'cash', ['note' => 'Kasa']);

        $this->actingAs($this->yonetici)->get(route('admin.bills.index'))
            ->assertOk()
            ->assertSee(route('admin.bills.payments.store', $this->eylul), false)
            ->assertSee('value="140.00"', false)                    // kalan onerilir
            ->assertSeeInOrder(['Ödeme geçmişi', '100,00 ₺', 'Nakit', 'Cahit Atılğan', 'Kasa'])
            ->assertSee(route('admin.bills.payments.reverse', BillPayment::sole()), false);
    }

    public function test_the_admin_menu_links_the_bills(): void
    {
        $rotalar = collect(\App\Support\Navigation::groups($this->yonetici))->flatMap(fn ($g) => $g['items'])->pluck('route');
        $this->assertContains('admin.bills.index', $rotalar);
    }

    // --- Uyenin rozeti ------------------------------------------------------

    public function test_the_member_sees_the_payment_badge_on_their_tab(): void
    {
        $sayfa = fn () => $this->actingAs($this->uye)->get(route('user.tab', ['ay' => '2026-09']))->assertOk();

        $sayfa()->assertSee('Ödeme bekleniyor');

        $this->ode($this->eylul, 100);
        $sayfa()->assertSee('Kısmen ödendi')->assertSee('140,00 ₺ kaldı');

        $this->ode($this->eylul, 140, 'cash', ['paid_on' => '2026-10-26']);
        $sayfa()->assertSee('Ödendi (26.10.2026)')->assertDontSee('Ödeme bekleniyor');

        // Bu ay hesap henuz cikmadi.
        $this->actingAs($this->uye)->get(route('user.tab'))->assertSee('Ay sonunda kesinleşir');
    }

    public function test_the_payments_page_shows_the_badge_to_the_member_and_the_parent(): void
    {
        $veli = User::factory()->parent()->create();
        $veli->students()->attach($this->uye->id);
        $this->ode($this->eylul, 240, 'cash', ['paid_on' => '2026-10-02']);

        $this->actingAs($this->uye)->get(route('user.payments', ['ay' => '2026-09']))->assertOk()->assertSee('Ödendi (02.10.2026)');
        $this->actingAs($veli)->get(route('parent.payments', [$this->uye, 'ay' => '2026-09']))->assertOk()->assertSee('Ödendi (02.10.2026)');
    }

    public function test_the_monthly_report_links_the_payment_status(): void
    {
        $this->ode($this->eylul, 100);

        $this->actingAs($this->yonetici)->get(route('admin.reports.monthly', ['year' => 2026, 'month' => 9]))
            ->assertOk()
            ->assertSee('Kısmen ödendi')
            ->assertSee(e(route('admin.bills.index', ['ay' => '2026-09', 'durum' => 'tumu'])), false);
    }

    // --- Yeniden hesap ve CSV -----------------------------------------------

    public function test_regenerating_a_paid_bill_with_a_higher_total_reopens_it(): void
    {
        $this->ode($this->eylul, 240);
        $urun = Product::create(['name' => 'Tost', 'unit_price' => 300, 'unit_type' => 'adet', 'is_active' => true]);
        Consumption::create(['user_id' => $this->uye->id, 'product_id' => $urun->id, 'location_id' => Location::selfService()->id,
            'quantity' => 1, 'unit_price' => 300, 'consumed_at' => Carbon::parse('2026-09-15 12:00', config('kafe.timezone'))]);

        MonthlyBill::generateForUserMonth($this->uye, 2026, 9);

        $adisyon = $this->eylul->fresh();
        $this->assertSame(300.0, (float) $adisyon->total_amount);
        $this->assertSame(BillPaymentStatus::Partial, $adisyon->payment_status);
        $this->assertSame(60.0, $adisyon->remaining());
    }

    public function test_the_summary_csv_has_the_payment_status_and_amounts(): void
    {
        $this->ode($this->eylul, 100, 'transfer', ['paid_on' => '2026-10-20']);

        $csv = $this->actingAs($this->yonetici)->get(route('admin.reports.export-summary', ['year' => 2026, 'month' => 9]))
            ->assertOk()->getContent();
        $satirlar = array_map('str_getcsv', array_values(array_filter(explode("\n", $csv))));

        $this->assertSame(['Ödeme Durumu', 'Ödenen', 'Kalan', 'Ödeme Tarihi', 'Ödeme Yöntemi'], array_slice($satirlar[0], 7));
        $this->assertSame(['Kısmen ödendi', '100.00', '140.00', '20.10.2026', 'Havale / EFT'], array_slice($satirlar[1], 7));
    }
}
