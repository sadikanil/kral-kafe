<?php

namespace Tests\Feature\Smoke;

use App\Enums\Role;
use App\Models\Consumption;
use App\Models\ExamEvent;
use App\Models\ExamReport;
use App\Models\ExamResult;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Payment;
use App\Models\PrivateLessonSlot;
use App\Models\Product;
use App\Models\StudentParent;
use App\Models\Subject;
use App\Models\Subscription;
use App\Models\User;
use Database\Factories\PackageFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Smoke/QA: ogrencinin para ve deneme ekranlari uctan uca.
 *
 *  - Adisyon (User\TabController): listele, ekle, 60 sn icinde geri al,
 *    stok dusumu, paket kapsami.
 *  - Odemeler (StatementController@student): ay gezinmesi, yerel ay siniri.
 *  - Deneme sonuclari (User\ExamResultController): deneme kulubu kapisi,
 *    yoneticinin girdigi/duzenledigi sonucun ogrenciye yansimasi.
 *  - Deneme raporlari (User\ExamReportController): liste, analiz, PDF.
 *  - Deneme takvimi (Study\ExamCalendarController@student): ay gezinmesi.
 *
 * Saat: 29 Eylul 2026 Sali 14:00 (kafe saati). Kafe 21:00'de kapaniyor;
 * sabit saat oturum kapatici ara katmanin testi etkilememesi icin.
 */
class StudentMoneyExamsSmokeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));

        config(['filesystems.uploads' => 'yukleme']);
        Storage::fake('yukleme');
    }

    // --- Yardimcilar ---------------------------------------------------------

    private function ogrenci(?PackageFactory $paket = null, array $ek = []): User
    {
        return User::factory()->student()->withPackage($paket ?? Package::factory()->tier3())->create($ek);
    }

    private function yonetici(): User
    {
        return User::factory()->admin()->create();
    }

    private function urun(string $ad = 'Çikolatalı Gofret', float $fiyat = 25, ?int $stok = 10, bool $aktif = true): Product
    {
        return Product::create([
            'name' => $ad,
            'emoji' => '🍫',
            'category' => 'Atıştırmalık',
            'unit_price' => $fiyat,
            'unit_type' => 'adet',
            'is_active' => $aktif,
            'stock_quantity' => $stok,
        ]);
    }

    /** Ogrencinin yururlukteki ana paketine bir kapsam kalemi ekler. */
    private function paketeEkle(User $ogrenci, Product $urun, ?int $adet, string $donem = 'daily'): PackageItem
    {
        return PackageItem::create([
            'package_id' => $ogrenci->currentSubscription()->package_id,
            'product_id' => $urun->id,
            'included_quantity' => $adet,
            'period' => $donem,
        ]);
    }

    private function ekle(User $ogrenci, Product $urun, int $adet = 1)
    {
        return $this->actingAs($ogrenci)
            ->from(route('user.tab'))
            ->post(route('user.tab.store'), ['product_id' => $urun->id, 'quantity' => $adet]);
    }

    private function deneme(string $ad = 'Türkiye Geneli TYT 3', string $tarih = '2026-10-04', string $tur = 'tyt', array $ek = []): ExamEvent
    {
        return ExamEvent::create(array_merge([
            'title' => $ad,
            'exam_type' => $tur,
            'exam_date' => $tarih,
            'starts_at' => '10:15',
        ], $ek));
    }

    private function tytDersleri()
    {
        return Subject::where('exam_type', 'tyt')->orderBy('sort_order')->get();
    }

    private function sonucGir(User $yonetici, ExamEvent $deneme, User $ogrenci, array $veri)
    {
        return $this->actingAs($yonetici)->post(route('admin.exam-results.store', [$deneme, $ogrenci]), $veri);
    }

    private function rapor(User $ogrenci, array $ek = [], bool $analizli = true, string $icerik = "%PDF-1.4\n% deneme\n"): ExamReport
    {
        $fabrika = ExamReport::factory();
        $rapor = ($analizli ? $fabrika->analyzed() : $fabrika)->create(array_merge(['student_id' => $ogrenci->id], $ek));

        if ($icerik !== '') {
            Storage::disk('yukleme')->put($rapor->file_path, $icerik);
        }

        return $rapor;
    }

    // =========================================================================
    // ADISYON
    // =========================================================================

    public function test_the_tab_page_lists_active_products_with_turkish_names_and_the_month_summary(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $this->urun('Çikolatalı Gofret', 25);
        $this->urun('Şalgam Suyu', 30);
        $this->urun('Satıştan Kalkmış Ürün', 10, aktif: false);

        // Bu ayin onceki bir gunu: ozet kartinda sayilir, "bugun" listesinde yok.
        Consumption::create([
            'user_id' => $ogrenci->id, 'product_id' => Product::where('name', 'Şalgam Suyu')->value('id'),
            'location_id' => \App\Models\Location::selfService()->id,
            'quantity' => 2, 'unit_price' => 30, 'consumed_at' => now()->subDays(3),
        ]);

        $this->actingAs($ogrenci)->get(route('user.tab'))
            ->assertOk()
            ->assertSee('Ne aldın?')
            ->assertSee('Çikolatalı Gofret')
            ->assertSee('Şalgam Suyu')
            ->assertSee('25,00 ₺')
            ->assertDontSee('Satıştan Kalkmış Ürün')
            ->assertSee('60,00 ₺')          // bu ay toplam
            ->assertSee('Bu Ay Toplam')
            ->assertDontSee('Bugün eklediklerim');
    }

    public function test_the_tab_page_with_no_products_shows_the_empty_state(): void
    {
        $this->actingAs($this->ogrenci())->get(route('user.tab'))
            ->assertOk()
            ->assertSee('Tanımlı ürün yok')
            ->assertSee('0,00 ₺');
    }

    public function test_adding_a_product_charges_the_tab_decrements_stock_and_reaches_payments(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $urun = $this->urun('Çikolatalı Gofret', 25, stok: 10);

        $this->ekle($ogrenci, $urun, 2)
            ->assertRedirect(route('user.tab'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, '2 × Çikolatalı Gofret adisyonuna eklendi')
                && str_contains($m, '50,00 ₺')
                && str_contains($m, '60 saniye'));

        $kayit = Consumption::sole();
        $this->assertSame($ogrenci->id, $kayit->user_id);
        $this->assertSame(2, $kayit->quantity);
        $this->assertSame(0, $kayit->covered_quantity);
        $this->assertSame('25.00', (string) $kayit->unit_price);
        $this->assertSame('50.00', (string) $kayit->total_price);
        $this->assertFalse($kayit->is_undone);
        $this->assertSame(8, $urun->fresh()->stock_quantity);

        $this->actingAs($ogrenci)->get(route('user.tab'))
            ->assertOk()
            ->assertSee('Bugün eklediklerim')
            ->assertSee('14:00')
            ->assertSee('Geri al')
            ->assertSee(route('user.tab.undo', $kayit), false);

        $this->actingAs($ogrenci)->get(route('user.payments'))
            ->assertOk()
            ->assertSee('Çikolatalı Gofret ×2')
            ->assertSee('50,00 ₺');
    }

    public function test_the_price_is_frozen_at_the_moment_of_sale(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $urun = $this->urun('Ayran', 15);

        $this->ekle($ogrenci, $urun, 1)->assertRedirect(route('user.tab'));
        $urun->update(['unit_price' => 20]);

        $this->assertSame('15.00', (string) Consumption::sole()->total_price);
        $this->actingAs($ogrenci)->get(route('user.payments'))->assertSee('15,00 ₺');
    }

    public function test_package_coverage_free_then_partial_then_charged(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $cay = $this->urun('Demli Çay', 10, stok: null);
        $this->paketeEkle($ogrenci, $cay, 2, 'daily');

        // 1 adet: tamami pakette
        $this->ekle($ogrenci, $cay, 1)
            ->assertRedirect(route('user.tab'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'paketine dahil, ücret yok'));

        // 2 adet: 1 hak kaldi -> 1 pakette, 1 ucretli
        $this->ekle($ogrenci, $cay, 2)
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 adedi paketinden, kalanı 10,00 ₺'));

        // Hak bitti: tam ucret
        $this->ekle($ogrenci, $cay, 1)
            ->assertSessionHas('success', fn ($m) => str_contains($m, '(10,00 ₺)'));

        $kayitlar = Consumption::orderBy('id')->get();
        $this->assertSame([1, 1, 0], $kayitlar->pluck('covered_quantity')->all());
        $this->assertSame(['0.00', '10.00', '10.00'], $kayitlar->map(fn ($k) => (string) $k->total_price)->all());
        // Takip kapali urunde stok bos kalir
        $this->assertNull($cay->fresh()->stock_quantity);

        $this->actingAs($ogrenci)->get(route('user.tab'))
            ->assertOk()
            ->assertSee('Paketinde')
            ->assertSee('1 paketten');

        $this->actingAs($ogrenci)->get(route('user.payments'))
            ->assertOk()
            ->assertSee('pakete dahil')
            ->assertSee('1 adedi pakete dahil')
            ->assertSee('20,00 ₺'); // adisyon toplami
    }

    public function test_undoing_a_covered_item_gives_the_package_right_back(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $kahve = $this->urun('Türk Kahvesi', 40, stok: null);
        $this->paketeEkle($ogrenci, $kahve, 1, 'daily');

        $this->ekle($ogrenci, $kahve, 1);
        $ilk = Consumption::sole();
        $this->assertTrue($ilk->isCoveredByPackage());

        $this->actingAs($ogrenci)->from(route('user.tab'))
            ->post(route('user.tab.undo', $ilk))
            ->assertRedirect(route('user.tab'))
            ->assertSessionHas('success', 'Kayıt geri alındı.');

        // Hak geri geldi: yeni ekleme yine pakette
        $this->ekle($ogrenci, $kahve, 1);
        $this->assertTrue(Consumption::latest('id')->first()->isCoveredByPackage());
    }

    public function test_undo_within_sixty_seconds_restores_stock_and_removes_the_charge(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $urun = $this->urun('Soğuk Kahve', 55, stok: 5);

        $this->ekle($ogrenci, $urun, 3);
        $kayit = Consumption::sole();
        $this->assertSame(2, $urun->fresh()->stock_quantity);

        $this->travel(59)->seconds();

        $this->actingAs($ogrenci)->from(route('user.tab'))
            ->post(route('user.tab.undo', $kayit))
            ->assertRedirect(route('user.tab'))
            ->assertSessionHas('success', 'Kayıt geri alındı.');

        $kayit->refresh();
        $this->assertTrue($kayit->is_undone);
        $this->assertNotNull($kayit->undone_at);
        $this->assertSame(5, $urun->fresh()->stock_quantity);
        $this->assertSame(0.0, (float) $ogrenci->getCurrentMonthTotal());

        $this->actingAs($ogrenci)->get(route('user.tab'))->assertOk()->assertDontSee('Bugün eklediklerim');
        $this->actingAs($ogrenci)->get(route('user.payments'))->assertOk()->assertSee('Bu ay adisyon yok.');
    }

    public function test_undo_after_sixty_seconds_is_refused_and_the_stock_stays_down(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $urun = $this->urun('Su 0,5 L', 10, stok: 20);

        $this->ekle($ogrenci, $urun, 1);
        $kayit = Consumption::sole();

        $this->travel(61)->seconds();

        // Sayfa artik geri al dugmesi gostermiyor
        $this->actingAs($ogrenci)->get(route('user.tab'))
            ->assertOk()
            ->assertSee('Bugün eklediklerim')
            ->assertDontSee(route('user.tab.undo', $kayit), false);

        $this->actingAs($ogrenci)->from(route('user.tab'))
            ->post(route('user.tab.undo', $kayit))
            ->assertRedirect(route('user.tab'))
            ->assertSessionHas('error', 'Geri alma süresi doldu.');

        $this->assertFalse($kayit->fresh()->is_undone);
        $this->assertSame(19, $urun->fresh()->stock_quantity);
    }

    public function test_a_double_submitted_undo_returns_the_stock_only_once(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $urun = $this->urun('Tost', 45, stok: 8);

        $this->ekle($ogrenci, $urun, 2);
        $kayit = Consumption::sole();

        $this->actingAs($ogrenci)->from(route('user.tab'))->post(route('user.tab.undo', $kayit))->assertSessionHas('success');
        $this->actingAs($ogrenci)->from(route('user.tab'))->post(route('user.tab.undo', $kayit))->assertSessionHas('error');

        $this->assertSame(8, $urun->fresh()->stock_quantity);
    }

    public function test_a_student_cannot_undo_someone_elses_item(): void
    {
        $ali = $this->ogrenci(Package::factory()->tier1(), ['name' => 'Ali Çağlar']);
        $ayse = $this->ogrenci(Package::factory()->tier1(), ['name' => 'Ayşe Işık']);
        $urun = $this->urun('Simit', 15, stok: 10);

        $this->ekle($ali, $urun, 1);
        $kayit = Consumption::sole();

        $this->actingAs($ayse)->post(route('user.tab.undo', $kayit))->assertForbidden();

        $this->assertFalse($kayit->fresh()->is_undone);
        $this->assertSame(9, $urun->fresh()->stock_quantity);

        // Ayse'nin sayfasi Ali'nin kaydini gostermez
        $this->actingAs($ayse)->get(route('user.tab'))->assertOk()->assertDontSee('Bugün eklediklerim');
    }

    public function test_the_tab_refuses_bad_input_and_inactive_products(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $aktif = $this->urun('Poğaça', 20, stok: 10);
        $pasif = $this->urun('Eski Ürün', 20, stok: 10, aktif: false);

        foreach ([
            ['quantity' => 1],                                        // urun yok
            ['product_id' => 999999, 'quantity' => 1],                // olmayan urun
            ['product_id' => $aktif->id, 'quantity' => 0],            // sifir
            ['product_id' => $aktif->id, 'quantity' => 'iki'],        // metin
            ['product_id' => $aktif->id, 'quantity' => 11],           // ust sinir
        ] as $veri) {
            $yanit = $this->actingAs($ogrenci)->from(route('user.tab'))->post(route('user.tab.store'), $veri);
            $yanit->assertRedirect(route('user.tab'))->assertSessionHasErrors();

            // Hata metni Turkce, ham anahtar degil
            foreach (session('errors')->all() as $mesaj) {
                $this->assertStringNotContainsString('validation.', $mesaj);
            }
        }

        $this->ekle($ogrenci, $pasif, 1)
            ->assertRedirect(route('user.tab'))
            ->assertSessionHas('error', 'Bu ürün şu an satışta değil.');

        $this->assertSame(0, Consumption::count());
        $this->assertSame(10, $aktif->fresh()->stock_quantity);
        $this->assertSame(10, $pasif->fresh()->stock_quantity);
    }

    public function test_todays_list_uses_the_cafe_day_not_the_utc_day(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $urun = $this->urun('Gece Kahvesi', 30, stok: null);
        $raf = \App\Models\Location::selfService();

        // 29 Eylul 01:30 yerel = 28 Eylul 22:30 UTC -> BUGUN
        Consumption::create(['user_id' => $ogrenci->id, 'product_id' => $urun->id, 'location_id' => $raf->id,
            'quantity' => 1, 'unit_price' => 30, 'consumed_at' => Carbon::parse('2026-09-28 22:30', 'UTC')]);
        // 28 Eylul 23:30 yerel = 28 Eylul 20:30 UTC -> DUN
        Consumption::create(['user_id' => $ogrenci->id, 'product_id' => $urun->id, 'location_id' => $raf->id,
            'quantity' => 1, 'unit_price' => 30, 'consumed_at' => Carbon::parse('2026-09-28 20:30', 'UTC')]);

        $this->actingAs($ogrenci)->get(route('user.tab'))
            ->assertOk()
            ->assertSee('Bugün eklediklerim')
            ->assertSee('01:30')
            ->assertDontSee('23:30');
    }

    public function test_guests_and_suspended_students_are_kept_out_of_the_tab(): void
    {
        $urun = $this->urun();
        $kayit = Consumption::create([
            'user_id' => $this->ogrenci()->id, 'product_id' => $urun->id,
            'location_id' => \App\Models\Location::selfService()->id, 'quantity' => 1, 'unit_price' => 25,
        ]);

        $this->get(route('user.tab'))->assertRedirect(route('login'));
        $this->post(route('user.tab.store'), ['product_id' => $urun->id, 'quantity' => 1])->assertRedirect(route('login'));
        $this->post(route('user.tab.undo', $kayit))->assertRedirect(route('login'));

        $askida = User::factory()->student()->create(['subscription_status' => 'suspended']);
        $this->actingAs($askida)->get(route('user.tab'))->assertRedirect(route('user.dashboard'));
        $this->actingAs($askida)->post(route('user.tab.store'), ['product_id' => $urun->id, 'quantity' => 1])
            ->assertRedirect(route('user.dashboard'));

        $this->assertSame(1, Consumption::count());
        $this->assertSame(10, $urun->fresh()->stock_quantity);
    }

    /**
     * Adisyon ogrencinin hesabi. Veli kafede bir sey tuketmiyor ve menusunde
     * Adisyon yok; ama /kullanici grubunda rol kapisi olmadigi icin adresi
     * yazan veli kendi adina adisyon acip stok dusurebiliyor. Bu kayit hicbir
     * ekranda (ogrencinin Odemeler'i, velinin cocuk dokumu) faturalanmiyor.
     */
    public function test_a_parent_cannot_put_items_on_a_tab(): void
    {
        $veli = User::factory()->parent()->create(['name' => 'Veli Hanım']);
        $urun = $this->urun('Çikolatalı Gofret', 25, stok: 10);

        $yanit = $this->actingAs($veli)->post(route('user.tab.store'), ['product_id' => $urun->id, 'quantity' => 2]);

        $this->assertTrue($yanit->isForbidden() || $yanit->isRedirect(route('parent.dashboard')),
            'Veli adisyona urun ekleyememeli; durum: ' . $yanit->status());
        $this->assertSame(0, Consumption::count());
        $this->assertSame(10, $urun->fresh()->stock_quantity);
    }

    // =========================================================================
    // ODEMELER
    // =========================================================================

    private function abonelik(User $ogrenci, string $bas, float $fiyat, string $paketAdi = 'Kral Öğrenci Paketi'): Subscription
    {
        return Subscription::factory()->create([
            'student_id' => $ogrenci->id,
            'package_id' => Package::factory()->tier3()->create(['name' => $paketAdi])->id,
            'starts_on' => $bas,
            'ends_on' => Carbon::parse($bas)->addMonthNoOverflow()->subDay()->toDateString(),
            'price' => $fiyat,
        ]);
    }

    private function tuket(User $ogrenci, string $utc, string $urunAdi = 'Tost', int $adet = 1, float $fiyat = 45): Consumption
    {
        $urun = Product::firstOrCreate(['name' => $urunAdi], ['unit_price' => $fiyat, 'unit_type' => 'adet']);

        return Consumption::create([
            'user_id' => $ogrenci->id, 'product_id' => $urun->id,
            'location_id' => \App\Models\Location::selfService()->id,
            'quantity' => $adet, 'unit_price' => $fiyat, 'consumed_at' => Carbon::parse($utc, 'UTC'),
        ]);
    }

    public function test_the_payments_page_shows_this_months_package_payment_and_tab(): void
    {
        $ogrenci = User::factory()->student()->create(['name' => 'Şükrü Öztürk']);
        $abonelik = $this->abonelik($ogrenci, '2026-09-01', 12000, 'Kral Öğrenci Paketi');
        Payment::create(['subscription_id' => $abonelik->id, 'amount' => 8000, 'paid_at' => '2026-09-02', 'method' => 'cash']);
        $this->tuket($ogrenci, '2026-09-10 09:00', 'Kaşarlı Tost', 2, 45);

        $this->actingAs($ogrenci)->get(route('user.payments'))
            ->assertOk()
            ->assertSee('Ödemelerim')
            ->assertSee('Eylül 2026')
            ->assertSee('Kral Öğrenci Paketi')
            ->assertSee('12.000,00 ₺')
            ->assertSee('Ödenen 8.000,00 ₺')
            ->assertSee('Kalan 4.000,00 ₺')
            ->assertSee('Vade 08.09.2026')
            ->assertSee('Kaşarlı Tost ×2')
            ->assertSee('90,00 ₺')
            ->assertSee('12.090,00 ₺')                 // ay toplami
            ->assertSee('Paketten kalan borç')
            ->assertSee(route('user.payments', ['ay' => '2026-08']), false)
            ->assertSee(route('user.payments', ['ay' => '2026-10']), false);
    }

    public function test_the_payments_page_walks_months_and_shows_empty_months(): void
    {
        $ogrenci = User::factory()->student()->create();
        $this->abonelik($ogrenci, '2026-08-01', 7500, 'Ağustos Paketi');
        $this->tuket($ogrenci, '2026-08-20 10:00', 'Ağustos Tostu', 1, 40);
        $this->tuket($ogrenci, '2026-09-20 10:00', 'Eylül Tostu', 1, 45);

        $this->actingAs($ogrenci)->get(route('user.payments', ['ay' => '2026-08']))
            ->assertOk()
            ->assertSee('Ağustos 2026')
            ->assertSee('Ağustos Paketi')
            ->assertSee('Ağustos Tostu')
            ->assertDontSee('Eylül Tostu')
            ->assertSee(route('user.payments', ['ay' => '2026-07']), false)
            ->assertSee(route('user.payments', ['ay' => '2026-09']), false);

        $this->actingAs($ogrenci)->get(route('user.payments', ['ay' => '2026-10']))
            ->assertOk()
            ->assertSee('Ekim 2026')
            ->assertSee('Bu ay başlayan paket yok.')
            ->assertSee('Bu ay adisyon yok.')
            ->assertDontSee('Paketten kalan borç');

        // Yil donumu
        $this->actingAs($ogrenci)->get(route('user.payments', ['ay' => '2026-12']))
            ->assertOk()
            ->assertSee('Aralık 2026')
            ->assertSee(route('user.payments', ['ay' => '2027-01']), false)
            ->assertSee(route('user.payments', ['ay' => '2026-11']), false);
    }

    public function test_a_broken_month_parameter_falls_back_to_this_month(): void
    {
        $ogrenci = User::factory()->student()->create();

        foreach (['2026-13', 'eylul', '2026-9', ''] as $ay) {
            $this->actingAs($ogrenci)->get(route('user.payments', ['ay' => $ay]))
                ->assertOk()
                ->assertSee('Eylül 2026');
        }
    }

    public function test_a_tampered_array_month_parameter_does_not_crash_the_payments_page(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($ogrenci)->get('/kullanici/odemeler?ay[]=2026-08')->assertOk()->assertSee('Eylül 2026');
        $this->actingAs($ogrenci)->get('/kullanici/denemeler?ay[]=2026-08')->assertOk()->assertSee('Eylül 2026');
    }

    public function test_a_late_night_purchase_belongs_to_the_local_month(): void
    {
        $ogrenci = User::factory()->student()->create();
        // 30 Eylul 22:30 UTC = 1 Ekim 01:30 Istanbul -> EKIM
        $this->tuket($ogrenci, '2026-09-30 22:30', 'Gece Tostu', 1, 45);

        $this->actingAs($ogrenci)->get(route('user.payments', ['ay' => '2026-09']))
            ->assertOk()->assertDontSee('Gece Tostu')->assertSee('Bu ay adisyon yok.');
        $this->actingAs($ogrenci)->get(route('user.payments', ['ay' => '2026-10']))
            ->assertOk()->assertSee('Gece Tostu')->assertSee('01.10');
    }

    /**
     * SQLite (yerel gelistirme + testler): 'date' cast'li sutun
     * "2026-09-30 00:00:00" diye yaziliyor; Subscription::scopeStartingIn
     * whereBetween(['2026-09-01','2026-09-30']) ile metin karsilastirdigi icin
     * ayin SON gunu disarida kaliyor. Postgres'te date sutunu oldugundan
     * uretimde gorunmez.
     */
    public function test_a_package_starting_on_the_last_day_of_the_month_is_on_that_months_statement(): void
    {
        $ogrenci = User::factory()->student()->create();
        $this->abonelik($ogrenci, '2026-09-30', 9000, 'Ay Sonu Paketi');

        $this->actingAs($ogrenci)->get(route('user.payments', ['ay' => '2026-09']))
            ->assertOk()
            ->assertSee('Ay Sonu Paketi')
            ->assertSee('9.000,00 ₺');
        $this->actingAs($ogrenci)->get(route('user.payments', ['ay' => '2026-10']))
            ->assertOk()
            ->assertDontSee('Ay Sonu Paketi');
    }

    /**
     * Vadesi (baslangic + 7 gun) gecmis, odenmemis paket. Veli paneli ve
     * yonetici listesi durumu okurken syncPaymentStatus() cagiriyor; ogrencinin
     * Odemeler sayfasi cagirmiyor ve rozet "Bekliyor" kaliyor.
     */
    public function test_an_unpaid_package_past_its_due_date_shows_as_overdue(): void
    {
        $ogrenci = User::factory()->student()->create();
        $this->abonelik($ogrenci, '2026-09-01', 12000, 'Vadesi Geçmiş Paket'); // vade 08.09, bugun 29.09

        $this->actingAs($ogrenci)->get(route('user.payments'))
            ->assertOk()
            ->assertSee('Vade 08.09.2026')
            ->assertSee('Gecikmiş')
            ->assertDontSee('Bekliyor');
    }

    public function test_the_old_history_address_and_guests(): void
    {
        $this->get(route('user.payments'))->assertRedirect(route('login'));

        $this->actingAs(User::factory()->student()->create())
            ->get('/kullanici/gecmis')
            ->assertRedirect('/kullanici/odemeler');
    }

    public function test_the_student_payments_page_only_shows_the_viewers_own_money(): void
    {
        $ali = User::factory()->student()->create();
        $ayse = User::factory()->student()->create();
        $this->abonelik($ayse, '2026-09-01', 15000, 'Ayşe Paketi');
        $this->tuket($ayse, '2026-09-10 10:00', 'Ayşe Tostu');

        $this->actingAs($ali)->get(route('user.payments'))
            ->assertOk()
            ->assertDontSee('Ayşe Paketi')
            ->assertDontSee('Ayşe Tostu')
            ->assertSee('Bu ay başlayan paket yok.');
    }

    /**
     * Adisyon gecmisi faturadir. Urunu silmek (yonetici paneli) consumptions
     * satirlarini ON DELETE CASCADE ile siliyor; ogrencinin o ay yaptigi
     * harcama dokumden ve aylik toplamdan sessizce kayboluyor.
     */
    public function test_deleting_a_product_does_not_erase_students_charges(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $urun = $this->urun('Sezonluk Limonata', 35, stok: 10);
        $this->ekle($ogrenci, $urun, 2)->assertRedirect(route('user.tab'));

        $this->actingAs($this->yonetici())->delete(route('admin.products.destroy', $urun));

        $this->assertSame(1, Consumption::count(), 'Satilmis urunun adisyon kaydi kalmali');
        $this->assertSame(70.0, (float) $ogrenci->getCurrentMonthTotal());
        $this->actingAs($ogrenci)->get(route('user.payments'))->assertOk()->assertSee('70,00 ₺');
    }

    // =========================================================================
    // DENEME SONUCLARI
    // =========================================================================

    public function test_an_admin_enters_then_edits_a_result_and_the_exam_club_student_sees_it(): void
    {
        $yonetici = $this->yonetici();
        $ogrenci = $this->ogrenci(Package::factory()->tier3(), ['name' => 'Gülşen Çiçek']);
        $deneme = $this->deneme('Türkiye Geneli TYT 3', '2026-09-27');
        [$turkce, $sosyal] = $this->tytDersleri()->take(2)->all();

        $this->actingAs($yonetici)->get(route('admin.exam-results.edit', [$deneme, $ogrenci]))
            ->assertOk()
            ->assertSee('Gülşen Çiçek')
            ->assertSee($turkce->name);

        $this->sonucGir($yonetici, $deneme, $ogrenci, [
            'rank_institution' => 3, 'total_institution' => 42,
            'rank_country' => 1240, 'total_country' => 180000,
            'note' => 'Paragrafta çok iyi; sosyalde dikkatsizlik var, şıkları iki kez oku.',
            'subjects' => [
                $turkce->id => ['correct' => 32, 'wrong' => 4, 'blank' => 4],
                $sosyal->id => ['correct' => 12, 'wrong' => 6, 'blank' => 2],
            ],
        ])->assertRedirect(route('admin.exam-results.edit', [$deneme, $ogrenci]))->assertSessionHas('success');

        $sonuc = ExamResult::sole();
        $this->assertSame(41.5, $sonuc->totalNet());

        $this->actingAs($ogrenci)->get(route('user.exam-results'))
            ->assertOk()
            ->assertSee('Deneme Sonuçlarım')
            ->assertSee('Türkiye Geneli TYT 3')
            ->assertSee('27.09.2026')
            ->assertSee('Toplam net 41,50')
            ->assertSee($turkce->name . ':')
            ->assertSee('32D 4Y 4B')
            ->assertSee('net 31,00')
            ->assertSee('42 kişide 3.')
            ->assertSee('180.000 kişide 1.240.')
            ->assertSee('şıkları iki kez oku', false)
            ->assertDontSee('Henüz sonuç girilmedi');

        // Duzenleme: siralama sonradan geldi, bir ders duzeltildi; yeni kayit acilmaz
        $this->sonucGir($yonetici, $deneme, $ogrenci, [
            'rank_institution' => 2, 'total_institution' => 42,
            'rank_district' => '', 'total_district' => '',
            'rank_city' => 150, 'total_city' => 9000,
            // Formda bos birakilan alan bos metin olarak gelir
            'rank_country' => '', 'total_country' => '',
            'note' => 'Güncellendi: il sıralaması geldi.',
            'subjects' => [
                $turkce->id => ['correct' => 33, 'wrong' => 4, 'blank' => 3],
                $sosyal->id => ['correct' => 12, 'wrong' => 6, 'blank' => 2],
            ],
        ])->assertSessionHas('success');

        $this->assertSame(1, ExamResult::count());
        $this->assertSame(2, $sonuc->fresh()->subjects()->count());

        $this->actingAs($ogrenci)->get(route('user.exam-results'))
            ->assertOk()
            ->assertSee('Toplam net 42,50')
            ->assertSee('42 kişide 2.')
            ->assertSee('9.000 kişide 150.')
            ->assertSee('Güncellendi: il sıralaması geldi.')
            // Duzenlemede bos birakilan Turkiye siralamasi silinir
            ->assertDontSee('180.000 kişide');
    }

    public function test_results_are_newest_first_and_two_exams_draw_the_net_chart(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->examOnly());
        $turkce = $this->tytDersleri()->first();
        $yonetici = $this->yonetici();

        foreach ([['Eski TYT', '2026-09-06', 20], ['Yeni TYT', '2026-09-27', 28]] as [$ad, $tarih, $dogru]) {
            $this->sonucGir($yonetici, $this->deneme($ad, $tarih), $ogrenci, [
                'subjects' => [$turkce->id => ['correct' => $dogru, 'wrong' => 4, 'blank' => 0]],
            ])->assertSessionHas('success');
        }

        $this->actingAs($ogrenci)->get(route('user.exam-results'))
            ->assertOk()
            ->assertSee('Net gelişimi')
            ->assertSee('TYT · 2 deneme')
            ->assertSeeInOrder(['Deneme sonuçları', 'Yeni TYT', 'Eski TYT']);
    }

    public function test_results_page_is_empty_for_a_new_member_and_hides_other_students(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier3());
        $baskasi = $this->ogrenci(Package::factory()->tier3());
        $this->sonucGir($this->yonetici(), $this->deneme('Başkasının Denemesi', '2026-09-20'), $baskasi, [
            'subjects' => [$this->tytDersleri()->first()->id => ['correct' => 10, 'wrong' => 0, 'blank' => 0]],
        ]);

        $this->actingAs($ogrenci)->get(route('user.exam-results'))
            ->assertOk()
            ->assertSee('Henüz sonuç girilmedi')
            ->assertDontSee('Başkasının Denemesi');
    }

    public function test_results_need_the_exam_club_which_an_add_on_can_give(): void
    {
        // Misafir ONCE: actingAs oturumu test boyunca acik birakir.
        $this->get(route('user.exam-results'))->assertRedirect(route('login'));

        $kulupsuz = $this->ogrenci(Package::factory()->tier1());
        $this->actingAs($kulupsuz)->get(route('user.exam-results'))->assertForbidden();

        // Tier 1 + deneme kulubu eki
        $ekli = $this->ogrenci(Package::factory()->tier1());
        Subscription::factory()->create([
            'student_id' => $ekli->id,
            'package_id' => Package::factory()->examClubAddon()->create()->id,
        ]);
        $this->actingAs($ekli)->get(route('user.exam-results'))->assertOk();
    }

    public function test_the_menu_links_to_results_only_with_the_exam_club(): void
    {
        $this->actingAs($this->ogrenci(Package::factory()->tier3()))->get(route('user.tab'))
            ->assertSee(route('user.exam-results'), false)
            ->assertSee(route('user.exam-reports.index'), false)
            ->assertSee(route('user.payments'), false);

        $this->actingAs($this->ogrenci(Package::factory()->tier1()))->get(route('user.tab'))
            ->assertDontSee(route('user.exam-results'), false)
            ->assertDontSee(route('user.exam-reports.index'), false);
    }

    // =========================================================================
    // DENEME RAPORLARI
    // =========================================================================

    public function test_the_report_list_shows_own_reports_with_status_and_links(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('Özdebir TYT 2', '2026-09-20');
        $hazir = $this->rapor($ogrenci, ['title' => 'Özdebir TYT 2 sonuç', 'exam_event_id' => $deneme->id]);
        $bekleyen = $this->rapor($ogrenci, ['title' => 'Bekleyen Rapor'], analizli: false);
        $this->rapor($ogrenci, ['title' => 'Bozuk Rapor', 'status' => ExamReport::FAILED, 'error' => 'API 502'], analizli: false);
        $this->rapor($this->ogrenci(), ['title' => 'Başkasının Raporu']);

        $this->actingAs($ogrenci)->get(route('user.exam-reports.index'))
            ->assertOk()
            ->assertSee('Deneme Raporlarım')
            ->assertSee('Özdebir TYT 2 sonuç')
            ->assertSee('Özdebir TYT 2')
            ->assertSee('Bekleyen Rapor')
            ->assertSee('Analiz hazır')
            ->assertSee('Analiz bekliyor')
            ->assertSee('Analiz başarısız')
            ->assertSee('29.09.2026')
            ->assertDontSee('Başkasının Raporu')
            ->assertSee(route('user.exam-reports.show', $hazir), false)
            ->assertSee(route('user.exam-reports.pdf', $bekleyen), false);
    }

    public function test_the_report_list_empty_state(): void
    {
        $this->actingAs($this->ogrenci())->get(route('user.exam-reports.index'))
            ->assertOk()
            ->assertSee('Henüz rapor yok');
    }

    public function test_an_analyzed_report_shows_the_analysis_and_links(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('Özdebir TYT 2', '2026-09-20');
        $rapor = $this->rapor($ogrenci, ['title' => 'Özdebir TYT 2 sonuç', 'exam_event_id' => $deneme->id]);

        $this->actingAs($ogrenci)->get(route('user.exam-reports.show', $rapor))
            ->assertOk()
            ->assertSee('Özdebir TYT 2 sonuç')
            ->assertSee('Takvimdeki deneme: Özdebir TYT 2 · 20 Eylül Pazar')
            ->assertSee('Başarılı alanlar')
            ->assertSee('Paragraf')
            ->assertSee('Geliştirilmesi gereken alanlar')
            ->assertSee('Problemler konusunu tekrar et.')
            ->assertSee('28,75')
            ->assertSee(route('user.exam-reports.pdf', $rapor), false)
            ->assertSee(route('user.exam-reports.index'), false);
    }

    public function test_a_report_title_with_apostrophe_and_ampersand_is_escaped_once(): void
    {
        $ogrenci = $this->ogrenci();
        $rapor = $this->rapor($ogrenci, ['title' => "TYT & AYT - Ali'nin sonucu"]);

        $this->actingAs($ogrenci)->get(route('user.exam-reports.show', $rapor))
            ->assertOk()
            ->assertSee("TYT & AYT - Ali'nin sonucu")
            ->assertDontSee('&amp;#039;', false)
            ->assertDontSee('&amp;amp;', false);
    }

    public function test_pending_and_failed_reports_explain_themselves(): void
    {
        $ogrenci = $this->ogrenci();
        $bekleyen = $this->rapor($ogrenci, ['title' => 'Bekleyen'], analizli: false);
        $bozuk = $this->rapor($ogrenci, ['title' => 'Bozuk', 'status' => ExamReport::FAILED, 'error' => 'OpenAI 502 döndü'], analizli: false);

        $this->actingAs($ogrenci)->get(route('user.exam-reports.show', $bekleyen))
            ->assertOk()->assertSee('Analiz henüz yapılmadı.')->assertDontSee('Takvimdeki deneme');
        $this->actingAs($ogrenci)->get(route('user.exam-reports.show', $bozuk))
            ->assertOk()->assertSee('Analiz yapılamadı: OpenAI 502 döndü');
    }

    public function test_the_report_survives_its_exam_being_removed_from_the_calendar(): void
    {
        $ogrenci = $this->ogrenci();
        $deneme = $this->deneme('Silinecek Deneme', '2026-09-20');
        $rapor = $this->rapor($ogrenci, ['title' => 'Kalan Rapor', 'exam_event_id' => $deneme->id]);

        $deneme->delete();

        $this->actingAs($ogrenci)->get(route('user.exam-reports.index'))
            ->assertOk()->assertSee('Kalan Rapor')->assertSee('—')->assertDontSee('Silinecek Deneme');
        $this->actingAs($ogrenci)->get(route('user.exam-reports.show', $rapor))
            ->assertOk()->assertDontSee('Takvimdeki deneme');
    }

    public function test_the_pdf_is_served_inline_with_a_readable_turkish_file_name(): void
    {
        $ogrenci = $this->ogrenci();
        $rapor = $this->rapor($ogrenci, ['title' => 'Özdebir TYT 2 Sonuç Belgesi'], icerik: "%PDF-1.4\nicerik\n");

        $yanit = $this->actingAs($ogrenci)->get(route('user.exam-reports.pdf', $rapor));

        $yanit->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('ozdebir-tyt-2-sonuc-belgesi.pdf', $yanit->headers->get('Content-Disposition'));
        $this->assertSame("%PDF-1.4\nicerik\n", $yanit->streamedContent());
    }

    public function test_another_students_report_is_forbidden(): void
    {
        $ogrenci = $this->ogrenci();
        $rapor = $this->rapor($this->ogrenci(), ['title' => 'Gizli Rapor']);

        $this->actingAs($ogrenci)->get(route('user.exam-reports.show', $rapor))->assertForbidden();
        $this->actingAs($ogrenci)->get(route('user.exam-reports.pdf', $rapor))->assertForbidden();
        $this->actingAs($ogrenci)->get(route('user.exam-reports.show', 999999))->assertNotFound();
    }

    public function test_reports_close_when_the_exam_club_is_gone(): void
    {
        // Kulupsuz pakete gecmis ogrencinin eski raporu: kapi kapali
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $rapor = $this->rapor($ogrenci, ['title' => 'Eski Rapor']);

        $this->actingAs($ogrenci)->get(route('user.exam-reports.index'))->assertForbidden();
        $this->actingAs($ogrenci)->get(route('user.exam-reports.show', $rapor))->assertForbidden();
        $this->actingAs($ogrenci)->get(route('user.exam-reports.pdf', $rapor))->assertForbidden();
    }

    public function test_a_parent_opens_their_childs_report_but_not_anothers(): void
    {
        $cocuk = $this->ogrenci();
        $veli = User::factory()->parent()->create();
        StudentParent::factory()->create(['student_id' => $cocuk->id, 'parent_id' => $veli->id]);
        $rapor = $this->rapor($cocuk, ['title' => 'Çocuğun Raporu']);
        $yabanci = $this->rapor($this->ogrenci(), ['title' => 'Yabancı Rapor']);

        $this->actingAs($veli)->get(route('user.exam-reports.show', $rapor))->assertOk()->assertSee('Çocuğun Raporu');
        $this->actingAs($veli)->get(route('user.exam-reports.show', $yabanci))->assertForbidden();
    }

    /** Dosyasi depodan kaybolmus rapor 500 degil, anlasilir bir 404 vermeli. */
    public function test_a_report_whose_file_is_missing_is_not_found_rather_than_a_crash(): void
    {
        $ogrenci = $this->ogrenci();
        $rapor = $this->rapor($ogrenci, ['title' => 'Dosyasız'], icerik: '');

        $this->actingAs($ogrenci)->get(route('user.exam-reports.pdf', $rapor))->assertNotFound();
    }

    // =========================================================================
    // DENEME TAKVIMI
    // =========================================================================

    public function test_the_calendar_shows_this_month_upcoming_exams_and_countdown(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier3());
        $this->deneme('Yarınki Kurum Denemesi', '2026-09-30', 'tyt', ['note' => 'Kalem getir, sınıf 2']);
        $this->deneme('Ekim TYT', '2026-10-04');
        $this->deneme('Geçmiş Deneme', '2026-09-13');

        $this->actingAs($ogrenci)->get(route('user.exams'))
            ->assertOk()
            ->assertSee('Deneme Takvimi')
            ->assertSee('Eylül 2026')
            ->assertSee('Sıradaki deneme:')
            ->assertSee('Yarınki Kurum Denemesi')
            ->assertSee('Yarın')
            ->assertSee('5 gün kaldı')
            ->assertSee('Kalem getir, sınıf 2')
            ->assertSee('Geçmiş Deneme')      // izgarada, gecmis gun
            ->assertSee('● 29', false)          // bugun isaretli
            ->assertSee(route('user.exams', ['ay' => '2026-08']), false)
            ->assertSee(route('user.exams', ['ay' => '2026-10']), false);
    }

    public function test_the_calendar_walks_months(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier3());
        $this->deneme('Kasım AYT', '2026-11-15', 'ayt');
        $this->deneme('Ağustos Denemesi', '2026-08-16');

        $this->actingAs($ogrenci)->get(route('user.exams', ['ay' => '2026-11']))
            ->assertOk()
            ->assertSee('Kasım 2026')
            ->assertSee('Kasım AYT')
            ->assertSee(route('user.exams', ['ay' => '2026-12']), false);

        $this->actingAs($ogrenci)->get(route('user.exams', ['ay' => '2026-08']))
            ->assertOk()
            ->assertSee('Ağustos 2026')
            ->assertSee('Ağustos Denemesi')
            ->assertDontSee('● 29', false);    // bugun bu ayda degil

        $this->actingAs($ogrenci)->get(route('user.exams', ['ay' => 'bozuk']))
            ->assertOk()
            ->assertSee('Eylül 2026');
    }

    /**
     * SQLite: exam_date "2026-08-31 00:00:00" olarak yaziliyor;
     * ExamEvent::scopeInMonth whereBetween(['2026-08-01','2026-08-31'])
     * metin karsilastirmasinda ayin son gununu disarida birakiyor.
     */
    public function test_an_exam_on_the_last_day_of_the_month_is_in_that_months_grid(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier3());
        // Gecmis ay: "yaklasan" listesine girmez, yalnizca izgarada gorunur.
        $this->deneme('Ağustos Son Gün Denemesi', '2026-08-31');

        $this->actingAs($ogrenci)->get(route('user.exams', ['ay' => '2026-08']))
            ->assertOk()
            ->assertSee('Ağustos Son Gün Denemesi');
    }

    public function test_without_the_exam_club_the_calendar_shows_dates_but_not_names(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $this->deneme('Gizli Kurum Denemesi', '2026-10-04', 'tyt', ['note' => 'Gizli not']);

        $this->actingAs($ogrenci)->get(route('user.exams'))
            ->assertOk()
            ->assertSee('TYT')
            ->assertSee('5 gün kaldı')
            ->assertDontSee('Gizli Kurum Denemesi')
            ->assertDontSee('Gizli not');
    }

    /**
     * Dalga 19 kurali: deneme adi (ve notu) yalnizca deneme kulubunde.
     * Tarihli denemelerde uygulanıyor ama "Serbest denemeler" kutusu adi ve
     * notu herkese gosteriyor.
     */
    public function test_without_the_exam_club_flexible_exam_names_are_hidden_too(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $this->deneme('Gizli Serbest Deneme', '2026-09-01', 'tyt', [
            'is_flexible' => true, 'available_until' => '2026-10-15', 'note' => 'Gizli serbest not',
        ]);

        $this->actingAs($ogrenci)->get(route('user.exams'))
            ->assertOk()
            ->assertSee('Serbest denemeler')
            ->assertDontSee('Gizli Serbest Deneme')
            ->assertDontSee('Gizli serbest not');
    }

    public function test_the_calendar_shows_private_lessons_for_a_tier_three_student(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier3());
        // Carsamba 16:00
        PrivateLessonSlot::create([
            'student_id' => $ogrenci->id, 'weekday' => 3,
            'starts_at' => '16:00', 'ends_at' => '17:00', 'starts_on' => '2026-09-01',
        ]);

        $this->actingAs($ogrenci)->get(route('user.exams'))
            ->assertOk()
            ->assertSee('Özel ders 16:00');

        // Ozel dersi olmayan pakette takvim yine acilir, ders gorunmez
        $this->actingAs($this->ogrenci(Package::factory()->tier1()))->get(route('user.exams'))
            ->assertOk()
            ->assertDontSee('Özel ders');
    }

    public function test_the_calendar_with_nothing_planned(): void
    {
        $this->get(route('user.exams'))->assertRedirect(route('login'));

        $this->actingAs($this->ogrenci(Package::factory()->tier1()))->get(route('user.exams'))
            ->assertOk()
            ->assertSee('Planlanmış deneme yok.')
            ->assertDontSee('Sıradaki deneme:');
    }
}
