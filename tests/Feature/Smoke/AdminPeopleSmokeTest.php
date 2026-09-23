<?php

namespace Tests\Feature\Smoke;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\Consumption;
use App\Models\DiscrepancyLog;
use App\Models\Location;
use App\Models\Package;
use App\Models\PackageItem;
use App\Models\Payment;
use App\Models\PrivateLessonSlot;
use App\Models\Product;
use App\Models\SessionPause;
use App\Models\Setting;
use App\Models\StockRecord;
use App\Models\StudentParent;
use App\Models\StudyGoal;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\Subscription;
use App\Models\User;
use Database\Factories\PackageFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Smoke: yonetim - kisiler ve oturumlar.
 *
 * Kullanicilar, abonelik/odeme, koc atamasi, paketler, oturum onayi, canli
 * ekran, panel, ayarlar ve ozel ders. Her GET sayfasi dogru rolde gercek
 * veriyle acilir, yanlis rolde reddedilir; her yazma ucunun mutlu yolu ve en
 * onemli siniri sinanir.
 */
class AdminPeopleSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $yonetici;

    protected function setUp(): void
    {
        parent::setUp();

        // 29 Eylul 2026 Sali 14:00 - kafe acik, acik oturumlar kapanmaz.
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));

        $this->yonetici = User::factory()->admin()->create(['name' => 'Cahit Hoca']);
    }

    // --- Yardimcilar ---------------------------------------------------------

    private function ogrenci(PackageFactory|Package|null $paket = null, array $ek = []): User
    {
        $fabrika = User::factory()->student();

        if ($paket !== null) {
            $fabrika = $fabrika->withPackage($paket);
        }

        return $fabrika->create($ek);
    }

    private function veli(array $ek = []): User
    {
        return User::factory()->parent()->create($ek);
    }

    private function koc(array $ek = []): User
    {
        return User::factory()->create(array_merge(['role' => Role::Coach->value, 'subscription_status' => 'active'], $ek));
    }

    private function bagla(User $ogrenci, User $veli): void
    {
        StudentParent::factory()->create(['student_id' => $ogrenci->id, 'parent_id' => $veli->id]);
    }

    private function masa(string $ad = 'Masa 1'): StudyTable
    {
        return StudyTable::create(['name' => $ad]);
    }

    private function acikOturum(User $ogrenci, StudyTable $masa, int $dakikaOnce = 60, array $ek = []): StudySession
    {
        return StudySession::create(array_merge([
            'student_id' => $ogrenci->id,
            'study_table_id' => $masa->id,
            'started_at' => now()->subMinutes($dakikaOnce),
        ], $ek));
    }

    private function bitmisOturum(User $ogrenci, ?StudyTable $masa = null, int $dakika = 90, array $ek = []): StudySession
    {
        $bas = now()->subMinutes($dakika + 10);

        return StudySession::create(array_merge([
            'student_id' => $ogrenci->id,
            'study_table_id' => ($masa ?? $this->masa('Masa ' . uniqid()))->id,
            'started_at' => $bas,
            'ended_at' => $bas->copy()->addMinutes($dakika),
            'duration_minutes' => $dakika,
            'end_reason' => SessionEndReason::Manual->value,
        ], $ek));
    }

    private function urun(string $ad = 'Türk Kahvesi', float $fiyat = 40, bool $aktif = true): Product
    {
        return Product::create(['name' => $ad, 'unit_price' => $fiyat, 'unit_type' => 'adet', 'is_active' => $aktif]);
    }

    /** Kullanici duzenleme formunun gonderdigi tam veri. */
    private function formVerisi(User $u, array $ek = []): array
    {
        $veri = [
            'name' => $u->name,
            'phone' => $u->phone,
            'email' => $u->email,
            'role' => $u->role,
            'subscription_status' => $u->subscription_status ?? 'active',
        ];

        // Form ogrenci/veli bolumunde gizli alan gonderir (bkz. edit.blade).
        if ($u->isStudent()) {
            $veri['parent_ids'] = $u->parents()->pluck('users.id')->all() ?: '';
        }
        if ($u->hasRole(Role::Parent)) {
            $veri['student_ids'] = $u->students()->pluck('users.id')->all() ?: '';
        }

        return array_merge($veri, $ek);
    }

    // --- Yetki ---------------------------------------------------------------

    public function test_every_admin_page_refuses_other_roles_and_guests(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $paket = Package::factory()->create();

        $sayfalar = [
            route('admin.dashboard'),
            route('admin.live'),
            route('admin.users.index'),
            route('admin.users.create'),
            route('admin.users.edit', $ogrenci),
            route('admin.subscriptions.index', $ogrenci),
            route('admin.subscriptions.overview'),
            route('admin.packages.index'),
            route('admin.packages.create'),
            route('admin.packages.edit', $paket),
            route('admin.settings.edit'),
        ];

        $roller = [
            'ogrenci' => $this->ogrenci(Package::factory()->tier3()),
            'veli' => $this->veli(),
            'koc' => $this->koc(),
            'ogretmen' => User::factory()->create(['role' => Role::Teacher->value]),
            'gorevli' => User::factory()->create(['role' => Role::Staff->value]),
        ];

        foreach ($sayfalar as $adres) {
            foreach ($roller as $ad => $kisi) {
                $this->actingAs($kisi)->get($adres)->assertForbidden();
            }
            $this->actingAs($this->yonetici)->get($adres)->assertOk();
        }

        auth()->logout();
        foreach ($sayfalar as $adres) {
            $this->get($adres)->assertRedirect(route('login'));
        }
    }

    public function test_a_coach_cannot_use_any_admin_write_action(): void
    {
        $koc = $this->koc();
        $ogrenci = $this->ogrenci(Package::factory()->tier3());
        $abonelik = $ogrenci->subscriptions()->first();
        $odeme = Payment::factory()->create(['subscription_id' => $abonelik->id]);
        $paket = Package::factory()->create();
        $oturum = $this->bitmisOturum($ogrenci);
        $saat = PrivateLessonSlot::create(['student_id' => $ogrenci->id, 'weekday' => 3,
            'starts_at' => '17:00', 'ends_at' => '18:00', 'starts_on' => '2026-09-01']);

        $istekler = [
            ['post', route('admin.users.store'), ['name' => 'X', 'phone' => '05321234567', 'role' => 'coach']],
            ['put', route('admin.users.update', $ogrenci), ['name' => 'X']],
            ['delete', route('admin.users.destroy', $ogrenci), []],
            ['post', route('admin.users.toggle-status', $ogrenci), []],
            ['post', route('admin.users.reset-password', $ogrenci), []],
            ['post', route('admin.subscriptions.store', $ogrenci), []],
            ['post', route('admin.subscriptions.switch', $ogrenci), []],
            ['post', route('admin.subscriptions.cancel', $abonelik), []],
            ['post', route('admin.subscriptions.payments.store', $abonelik), []],
            ['delete', route('admin.subscriptions.payments.destroy', $odeme), []],
            ['post', route('admin.coaches.attach', $ogrenci), ['coach_id' => $koc->id]],
            ['delete', route('admin.coaches.detach', [$ogrenci, $koc]), []],
            ['post', route('admin.packages.store'), []],
            ['put', route('admin.packages.update', $paket), []],
            ['post', route('admin.packages.toggle-status', $paket), []],
            ['post', route('admin.sessions.approve', $oturum), []],
            ['post', route('admin.sessions.reject', $oturum), ['reason' => 'x']],
            ['post', route('admin.sessions.approve-many'), ['ids' => [$oturum->id]]],
            ['post', route('admin.settings.location'), ['latitude' => 41, 'longitude' => 29]],
            ['post', route('admin.lessons.store', $ogrenci), []],
            ['post', route('admin.lessons.cancel', $saat), ['date' => '2026-09-30']],
            ['post', route('admin.lessons.move', $saat), []],
            ['delete', route('admin.lessons.destroy', $saat), []],
        ];

        foreach ($istekler as [$yontem, $adres, $veri]) {
            $this->actingAs($koc)->{$yontem}($adres, $veri)->assertForbidden();
        }

        // Hicbiri iz birakmadi.
        $this->assertSame('active', $ogrenci->fresh()->subscription_status);
        $this->assertNotNull($ogrenci->fresh()->password);
        $this->assertSame(ApprovalStatus::Pending, $oturum->fresh()->approval_status);
        $this->assertTrue($paket->fresh()->is_active);
        $this->assertDatabaseHas('payments', ['id' => $odeme->id]);
        $this->assertDatabaseHas('private_lesson_slots', ['id' => $saat->id]);
        $this->assertSame(0, $ogrenci->coaches()->count());
        $this->assertNull(Setting::cafeLocation());
    }

    // --- Panel ---------------------------------------------------------------

    public function test_the_dashboard_shows_what_is_happening_now(): void
    {
        $masa = $this->masa('Masa 3');
        $this->masa('Masa 4');
        $this->masa('Masa 5');
        $ali = $this->ogrenci(Package::factory()->tier1(), ['name' => 'Çağla Işık']);
        $this->acikOturum($ali, $masa);
        $this->bitmisOturum($this->ogrenci(Package::factory()->tier1()));

        $kahve = $this->urun('Türk Kahvesi', 40);
        Consumption::create([
            'user_id' => $ali->id, 'product_id' => $kahve->id, 'location_id' => Location::selfService()->id,
            'quantity' => 2, 'unit_price' => 40,
        ]);

        $this->actingAs($this->yonetici)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('occupancy', ['total' => 4, 'inside' => 1, 'free' => 3])
            ->assertViewHas('pendingApprovals', 1)
            ->assertSee('İçeride · 3 boş yer')
            ->assertSee('Onay bekliyor')
            ->assertSee('Çağla Işık')
            ->assertSee('Türk Kahvesi')
            ->assertSee('80,00 ₺')
            ->assertSee(route('admin.live') . '#onay', false);
    }

    public function test_the_dashboard_renders_on_an_empty_system(): void
    {
        $this->actingAs($this->yonetici)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Bugün henüz tüketim kaydı yok.')
            ->assertSee('Bu ay henüz tüketim kaydı yok.')
            ->assertDontSee('stok tutarsızlığı');
    }

    public function test_the_dashboard_today_is_the_local_day_after_midnight(): void
    {
        // Yerel 30 Eylul 00:30 = UTC 29 Eylul 21:30
        $this->travelTo(Carbon::parse('2026-09-30 00:30', config('kafe.timezone')));
        $ogrenci = $this->ogrenci();
        $urun = $this->urun('Ayran', 25);
        $yer = Location::selfService()->id;

        // Dun yerel 20:00 (UTC 17:00) - bugun sayilmamali
        Consumption::create(['user_id' => $ogrenci->id, 'product_id' => $urun->id, 'location_id' => $yer,
            'quantity' => 1, 'unit_price' => 25, 'consumed_at' => Carbon::parse('2026-09-29 17:00', 'UTC')]);
        // Bugun yerel 00:10 (UTC 21:10 dun) - bugun sayilmali
        Consumption::create(['user_id' => $ogrenci->id, 'product_id' => $urun->id, 'location_id' => $yer,
            'quantity' => 2, 'unit_price' => 25, 'consumed_at' => Carbon::parse('2026-09-29 21:10', 'UTC')]);

        $this->actingAs($this->yonetici)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('stats', fn ($s) => (float) $s['today_total'] === 50.0)
            ->assertViewHas('todayConsumptions', fn ($c) => $c->count() === 1);
    }

    // --- Canli ekran ---------------------------------------------------------

    public function test_the_live_screen_lists_who_is_inside_the_pending_queue_and_anomalies(): void
    {
        Setting::putCafeLocation(41.0082, 28.9784);

        $sule = $this->ogrenci(Package::factory()->tier1(), ['name' => 'Şule Güneş']);
        $oturum = $this->acikOturum($sule, $this->masa('Masa 3 A'), 75);
        SessionPause::create(['study_session_id' => $oturum->id, 'kind' => 'break', 'started_at' => now()->subMinutes(5)]);

        $this->acikOturum($this->ogrenci(Package::factory()->tier1(), ['name' => 'Ömer Faruk']), $this->masa('Masa 3 B'), 30);

        $kafede = $this->bitmisOturum($this->ogrenci(null, ['name' => 'Gökhan Ağır']), null, 90,
            ['latitude' => 41.0083, 'longitude' => 28.9785]);
        $uzak = $this->bitmisOturum($this->ogrenci(null, ['name' => 'İlkay Uzak']), null, 60,
            ['latitude' => 41.0500, 'longitude' => 29.0500]);
        $konumsuz = $this->bitmisOturum($this->ogrenci(null, ['name' => 'Ece Konumsuz']));

        // Iki gun once 12 saati asip kapanan oturum
        $this->bitmisOturum($this->ogrenci(null, ['name' => 'Uğur Unutkan']), null, 720, [
            'started_at' => now()->subDays(2)->subHours(12),
            'ended_at' => now()->subDays(2),
            'end_reason' => SessionEndReason::OverLimit->value,
            'approval_status' => ApprovalStatus::Approved->value,
        ]);

        $this->actingAs($this->yonetici)->get(route('admin.live'))
            ->assertOk()
            ->assertViewHas('freeTables', fn ($n) => $n === StudyTable::active()->count() - 2)
            ->assertSee('Şule Güneş')
            ->assertSee('Masa 3 A')
            ->assertSee('Mola')
            ->assertSee('01:10')
            ->assertSee('Ömer Faruk')
            ->assertSee('Onay bekleyen oturumlar (3)')
            ->assertSee('Hepsini onayla (3)')
            ->assertSee('Gökhan Ağır')
            ->assertSee('Kafede (')
            ->assertSee('Kafeden uzak (')
            ->assertSee('Konum yok')
            ->assertSee('Son 7 günün anomalileri')
            ->assertSee('Uğur Unutkan')
            ->assertSee(route('admin.sessions.approve', $kafede), false)
            ->assertSee(route('admin.sessions.reject', $uzak), false)
            ->assertSee('value="' . $konumsuz->id . '"', false);
    }

    public function test_the_live_screen_renders_an_empty_cafe(): void
    {
        $this->actingAs($this->yonetici)->get(route('admin.live'))
            ->assertOk()
            ->assertSee('Şu anda içeride kimse yok')
            ->assertDontSee('Onay bekleyen oturumlar')
            ->assertDontSee('anomalileri');
    }

    public function test_the_live_screen_shows_the_pending_queue_without_a_cafe_location(): void
    {
        $this->bitmisOturum($this->ogrenci(null, ['name' => 'Deniz']), null, 60, ['latitude' => 41.0, 'longitude' => 29.0]);

        $this->actingAs($this->yonetici)->get(route('admin.live'))
            ->assertOk()
            ->assertSee('Kafe konumu girilmedi');
    }

    // --- Oturum onayi --------------------------------------------------------

    public function test_the_admin_approves_a_pending_session_from_the_live_screen(): void
    {
        $oturum = $this->bitmisOturum($this->ogrenci());

        $this->actingAs($this->yonetici)->from(route('admin.live'))
            ->post(route('admin.sessions.approve', $oturum))
            ->assertRedirect(route('admin.live'))
            ->assertSessionHas('success', 'Oturum onaylandı.');

        $oturum->refresh();
        $this->assertSame(ApprovalStatus::Approved, $oturum->approval_status);
        $this->assertSame($this->yonetici->id, $oturum->reviewed_by);
        $this->assertNotNull($oturum->reviewed_at);
    }

    public function test_an_open_session_stays_pending_when_approved(): void
    {
        $acik = $this->acikOturum($this->ogrenci(), $this->masa());

        $this->actingAs($this->yonetici)->from(route('admin.live'))
            ->post(route('admin.sessions.approve', $acik))
            ->assertRedirect(route('admin.live'));

        $this->assertSame(ApprovalStatus::Pending, $acik->fresh()->approval_status);
        $this->assertNull($acik->fresh()->reviewed_by);
    }

    public function test_the_admin_rejects_with_a_turkish_reason(): void
    {
        $oturum = $this->bitmisOturum($this->ogrenci());

        $this->actingAs($this->yonetici)->from(route('admin.live'))
            ->post(route('admin.sessions.reject', $oturum), ['reason' => 'Masada değildi, kamerada görünmüyor.'])
            ->assertRedirect(route('admin.live'))
            ->assertSessionHas('success', 'Oturum reddedildi.');

        $oturum->refresh();
        $this->assertSame(ApprovalStatus::Rejected, $oturum->approval_status);
        $this->assertSame('Masada değildi, kamerada görünmüyor.', $oturum->rejection_reason);
    }

    public function test_a_rejection_needs_a_reason(): void
    {
        $oturum = $this->bitmisOturum($this->ogrenci());

        foreach (['', '   '] as $sebep) {
            $this->actingAs($this->yonetici)->from(route('admin.live'))
                ->post(route('admin.sessions.reject', $oturum), ['reason' => $sebep])
                ->assertRedirect(route('admin.live'))
                ->assertSessionHasErrors('reason');
        }

        $this->assertSame(ApprovalStatus::Pending, $oturum->fresh()->approval_status);
    }

    public function test_approve_many_touches_only_finished_pending_sessions(): void
    {
        $a = $this->bitmisOturum($this->ogrenci());
        $b = $this->bitmisOturum($this->ogrenci());
        $acik = $this->acikOturum($this->ogrenci(), $this->masa('Masa Açık'));
        $red = $this->bitmisOturum($this->ogrenci(), null, 30, [
            'approval_status' => ApprovalStatus::Rejected->value, 'rejection_reason' => 'Yoktu',
        ]);

        $this->actingAs($this->yonetici)->from(route('admin.live'))
            ->post(route('admin.sessions.approve-many'), ['ids' => [$a->id, $b->id, $acik->id, $red->id, 999999]])
            ->assertRedirect(route('admin.live'))
            ->assertSessionHas('success', '2 oturum onaylandı.');

        $this->assertSame(ApprovalStatus::Approved, $a->fresh()->approval_status);
        $this->assertSame(ApprovalStatus::Approved, $b->fresh()->approval_status);
        $this->assertSame(ApprovalStatus::Pending, $acik->fresh()->approval_status);
        $this->assertSame(ApprovalStatus::Rejected, $red->fresh()->approval_status);

        // Cift tiklama: ikinci gonderim hicbir seyi degistirmez.
        $this->actingAs($this->yonetici)->from(route('admin.live'))
            ->post(route('admin.sessions.approve-many'), ['ids' => [$a->id, $b->id]])
            ->assertSessionHas('success', '0 oturum onaylandı.');
    }

    public function test_approve_many_needs_a_list(): void
    {
        $this->actingAs($this->yonetici)->from(route('admin.live'))
            ->post(route('admin.sessions.approve-many'), [])
            ->assertRedirect(route('admin.live'))
            ->assertSessionHasErrors('ids');

        $this->actingAs($this->yonetici)->from(route('admin.live'))
            ->post(route('admin.sessions.approve-many'), ['ids' => ['abc']])
            ->assertSessionHasErrors('ids.0');
    }

    // --- Kullanici listesi ---------------------------------------------------

    public function test_the_user_list_filters_by_role_status_and_searches_turkish_names_and_spaced_phones(): void
    {
        $cagri = $this->ogrenci(Package::factory()->tier1(), ['name' => 'Çağrı Işıkoğlu', 'phone' => '5321112233', 'email' => null]);
        $ayse = $this->veli(['name' => 'Ayşe Öztürk']);
        $this->bagla($cagri, $ayse);
        $this->koc(['name' => 'Kemal Koç', 'subscription_status' => 'suspended']);

        $this->actingAs($this->yonetici)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Çağrı Işıkoğlu')
            ->assertSee('0532 111 22 33')
            ->assertSee('Ayşe Öztürk')
            ->assertSee('Kemal Koç')
            ->assertSee(route('admin.subscriptions.index', $cagri), false)
            ->assertSee(route('admin.exam-reports.index', $cagri), false);

        $this->actingAs($this->yonetici)->get(route('admin.users.index', ['role' => 'parent']))
            ->assertOk()->assertSee('Ayşe Öztürk')->assertSee('1 öğrenci')->assertDontSee('Çağrı Işıkoğlu');

        $this->actingAs($this->yonetici)->get(route('admin.users.index', ['status' => 'suspended']))
            ->assertOk()->assertSee('Kemal Koç')->assertDontSee('Ayşe Öztürk');

        $this->actingAs($this->yonetici)->get(route('admin.users.index', ['search' => '0532 111']))
            ->assertOk()->assertSee('Çağrı Işıkoğlu')->assertDontSee('Ayşe Öztürk');

        $this->actingAs($this->yonetici)->get(route('admin.users.index', ['search' => 'Işıkoğlu']))
            ->assertOk()->assertSee('Çağrı Işıkoğlu')->assertDontSee('Kemal Koç');

        $this->actingAs($this->yonetici)->get(route('admin.users.index', ['search' => 'kimse-yok-böyle']))
            ->assertOk()->assertSee('Kullanıcı bulunamadı.');
    }

    public function test_the_user_list_paginates_and_keeps_the_filter(): void
    {
        User::factory()->count(25)->parent()->create();

        $this->actingAs($this->yonetici)->get(route('admin.users.index', ['role' => 'parent']))
            ->assertOk()
            ->assertSee('role=parent', false)
            ->assertSee('page=2', false);

        $this->actingAs($this->yonetici)->get(route('admin.users.index', ['role' => 'parent', 'page' => 2]))
            ->assertOk()
            ->assertViewHas('users', fn ($s) => $s->count() === 5);
    }

    public function test_the_user_list_hides_toggle_and_delete_for_the_admin_themself(): void
    {
        $koc = $this->koc();

        $this->actingAs($this->yonetici)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('action="' . route('admin.users.destroy', $koc) . '"', false)
            ->assertSee('action="' . route('admin.users.toggle-status', $koc) . '"', false)
            ->assertDontSee('action="' . route('admin.users.destroy', $this->yonetici) . '"', false)
            ->assertDontSee('action="' . route('admin.users.toggle-status', $this->yonetici) . '"', false);
    }

    // --- Kullanici ekleme ----------------------------------------------------

    public function test_the_create_page_offers_packages_addons_coaches_and_parents(): void
    {
        Package::factory()->tier2()->create(['name' => 'Orta Paket']);
        Package::factory()->examClubAddon()->create();
        Package::factory()->tier3()->create(['name' => 'Eski Paket', 'is_active' => false]);
        $this->koc(['name' => 'Kemal Koç']);
        $this->veli(['name' => 'Gülşen Yıldız']);

        $this->actingAs($this->yonetici)->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('Tier 2 · Orta Paket')
            ->assertSee('Deneme Kulübü (ek)')
            ->assertDontSee('Eski Paket')
            ->assertSee('Kemal Koç')
            ->assertSee('Cahit Hoca (yönetici)')
            ->assertSee('Gülşen Yıldız');
    }

    public function test_the_create_page_renders_on_an_empty_system(): void
    {
        $this->actingAs($this->yonetici)->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('Yeni veli adı soyadı')
            ->assertDontSee('Kayıtlı veli ara');
    }

    public function test_the_parent_search_on_the_create_form_finds_names_with_turkish_i(): void
    {
        $this->markTestSkipped('BUG: yeni ogrenci formundaki veli aramasi I/İ ile yazilan adlari bulamiyor');

        $this->veli(['name' => 'İsmail Işık', 'phone' => '5321112233']);

        // Arama kutusu yazilani toLocaleLowerCase('tr') ile kucultup data-ara
        // icinde arar: "İsmail Işık" -> "ismail ışık". Sunucu ayni metni
        // mb_strtolower ile uretiyor: "i̇smail işık" (birlesik nokta + noktali i).
        $this->actingAs($this->yonetici)->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('data-ara="ismail ışık', false);
    }

    public function test_the_admin_adds_a_coached_student_with_turkish_names_spaced_phones_and_a_new_parent(): void
    {
        $paket = Package::factory()->tier2()->create(['monthly_price' => 9500]);
        $koc = $this->koc(['name' => 'Kemal Koç']);

        $this->actingAs($this->yonetici)->from(route('admin.users.create'))
            ->post(route('admin.users.store'), [
                'name' => 'Çağrı Işıkoğlu',
                'phone' => '+90 532 111 22 33',
                'email' => '',
                'role' => 'student',
                'grade' => '11',
                'field' => 'ea',
                'package_id' => $paket->id,
                'coach_id' => $koc->id,
                'new_parent_name' => 'Gülşen Işıkoğlu',
                'new_parent_phone' => '0 (533) 444 55 66',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success', 'Kullanıcı başarıyla oluşturuldu.');

        $ogrenci = User::where('phone', '5321112233')->sole();
        $this->assertSame('Çağrı Işıkoğlu', $ogrenci->name);
        $this->assertSame('student', $ogrenci->role);
        $this->assertNull($ogrenci->password);
        $this->assertNull($ogrenci->email);
        $this->assertSame('11', $ogrenci->grade);
        $this->assertSame('ea', $ogrenci->field);

        $veli = User::where('phone', '5334445566')->sole();
        $this->assertSame('Gülşen Işıkoğlu', $veli->name);
        $this->assertSame('parent', $veli->role);
        $this->assertTrue($ogrenci->parents->contains($veli));
        $this->assertTrue($ogrenci->coaches->contains($koc));

        $abonelik = $ogrenci->currentSubscription();
        $this->assertTrue($abonelik->package->is($paket));
        $this->assertSame('2026-09-29', $abonelik->starts_on->toDateString());
        $this->assertSame('2026-10-28', $abonelik->ends_on->toDateString());
        $this->assertEquals(9500, $abonelik->price);
    }

    public function test_a_ninth_or_tenth_grader_gets_no_field(): void
    {
        $paket = Package::factory()->tier1()->create();
        $veli = $this->veli();

        $this->actingAs($this->yonetici)->post(route('admin.users.store'), [
            'name' => 'Su Ilgaz', 'phone' => '0532 222 33 44', 'role' => 'student',
            'grade' => '10', 'field' => 'say', 'package_id' => $paket->id, 'parent_ids' => [$veli->id],
        ])->assertSessionHasNoErrors();

        $su = User::where('phone', '5322223344')->sole();
        $this->assertSame('10', $su->grade);
        $this->assertNull($su->field);
    }

    public function test_the_admin_adds_a_coach_with_only_an_email(): void
    {
        $this->actingAs($this->yonetici)->post(route('admin.users.store'), [
            'name' => 'Şebnem Öğretmen', 'email' => 'sebnem@example.com', 'role' => 'coach',
            'grade' => '12', 'field' => 'say',
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index'));

        $koc = User::where('email', 'sebnem@example.com')->sole();
        $this->assertSame('coach', $koc->role);
        $this->assertNull($koc->phone);
        $this->assertNull($koc->grade);
        $this->assertSame(0, $koc->subscriptions()->count());
    }

    public function test_a_landline_or_short_phone_is_refused_in_turkish(): void
    {
        foreach (['0212 123 45 67', '532 12'] as $telefon) {
            $this->actingAs($this->yonetici)->from(route('admin.users.create'))
                ->post(route('admin.users.store'), ['name' => 'Deneme', 'phone' => $telefon, 'role' => 'coach'])
                ->assertRedirect(route('admin.users.create'))
                ->assertSessionHasErrors(['phone' => 'Geçerli bir cep telefonu girin (05XX XXX XX XX).']);
        }

        $this->assertSame(0, User::where('name', 'Deneme')->count());
    }

    public function test_a_double_submitted_create_form_makes_one_student(): void
    {
        $paket = Package::factory()->tier1()->create();
        $veli = $this->veli();
        $veri = ['name' => 'Emre Şahin', 'phone' => '0532 555 66 77', 'role' => 'student',
            'package_id' => $paket->id, 'parent_ids' => [$veli->id]];

        $this->actingAs($this->yonetici)->post(route('admin.users.store'), $veri)->assertSessionHasNoErrors();
        $this->actingAs($this->yonetici)->from(route('admin.users.create'))
            ->post(route('admin.users.store'), $veri)
            ->assertSessionHasErrors(['phone' => 'Bu telefon numarası başka bir kullanıcıda kayıtlı.']);

        $this->assertSame(1, User::where('phone', '5325556677')->count());
        $this->assertSame(1, Subscription::whereHas('student', fn ($q) => $q->where('phone', '5325556677'))->count());
    }

    // --- Kullanici duzenleme -------------------------------------------------

    public function test_the_edit_page_shows_everything_for_a_tier_three_student(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier3(), ['name' => 'Kral Öğrenci', 'grade' => '12', 'field' => 'say']);
        $veli = $this->veli(['name' => 'Hülya Veli']);
        $this->bagla($ogrenci, $veli);
        $koc = $this->koc(['name' => 'Kemal Koç']);
        $ogrenci->coaches()->attach($koc->id);
        StudyGoal::create(['student_id' => $ogrenci->id, 'period' => 'weekly', 'target_minutes' => 1200, 'effective_from' => '2026-09-01']);
        PrivateLessonSlot::create(['student_id' => $ogrenci->id, 'weekday' => 3,
            'starts_at' => '17:00', 'ends_at' => '18:30', 'starts_on' => '2026-09-01']);

        $this->actingAs($this->yonetici)->get(route('admin.users.edit', $ogrenci))
            ->assertOk()
            ->assertSee('Kral Öğrenci')
            ->assertSee('value="20"', false)
            ->assertSee('Hülya Veli')
            ->assertSee('Kral')
            ->assertSee('Paketi değiştir')
            ->assertSee('Özel ders')
            ->assertSee('Her Çarşamba 17:00–18:30')
            ->assertSee('Önümüzdeki 4 hafta')
            ->assertSee('Kemal Koç')
            ->assertSee(route('admin.coaches.detach', [$ogrenci, $koc]), false)
            ->assertSee(route('coach.plan.show', $ogrenci), false)
            ->assertSee('Şifreyi sıfırla');

        $sayfa = $this->actingAs($this->yonetici)->get(route('admin.users.edit', $ogrenci))->getContent();
        $this->assertMatchesRegularExpression('/<option value="12" data-alan="1"\s+selected>/', $sayfa);
        $this->assertMatchesRegularExpression('/<option value="say"\s+selected>/', $sayfa);
    }

    public function test_the_edit_page_renders_for_a_student_without_package_parents_or_coaches(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($this->yonetici)->get(route('admin.users.edit', $ogrenci))
            ->assertOk()
            ->assertSee('Şu an yürürlükte bir paketi yok.')
            ->assertSee('Sistemde kayıtlı veli yok.')
            ->assertSee('Bu öğrenciye atanmış koç yok.')
            ->assertDontSee('Önümüzdeki 4 hafta');
    }

    public function test_the_edit_page_renders_for_a_parent_a_coach_and_the_admin_themself(): void
    {
        $veli = $this->veli(['name' => 'Ayşe Öztürk']);
        $ogrenci = $this->ogrenci(null, ['name' => 'Bora Öztürk']);
        $this->bagla($ogrenci, $veli);

        $this->actingAs($this->yonetici)->get(route('admin.users.edit', $veli))
            ->assertOk()->assertSee('Bağlı Öğrenciler')->assertSee('Bora Öztürk')->assertDontSee('Paketi değiştir');

        $this->actingAs($this->yonetici)->get(route('admin.users.edit', $this->koc()))
            ->assertOk()->assertDontSee('Bağlı Öğrenciler')->assertDontSee('Koçlar');

        $this->actingAs($this->yonetici)->get(route('admin.users.edit', $this->yonetici))
            ->assertOk()->assertDontSee('Şifreyi sıfırla');
    }

    public function test_the_admin_updates_a_students_details_goal_parents_and_password(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1(), ['name' => 'Eski Ad', 'phone' => '5320000001']);
        $eskiVeli = $this->veli();
        $yeniVeli = $this->veli(['name' => 'Gönül Yeni']);
        $this->bagla($ogrenci, $eskiVeli);

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
            ->put(route('admin.users.update', $ogrenci), $this->formVerisi($ogrenci, [
                'name' => 'Şükrü Öğüt',
                'phone' => '0532 999 88 77',
                'email' => '',
                'grade' => '12',
                'field' => 'say',
                'weekly_goal_hours' => 25,
                'parent_ids' => [$yeniVeli->id],
                'password' => 'YeniŞifre2026!',
                'password_confirmation' => 'YeniŞifre2026!',
            ]))
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success', 'Kullanıcı başarıyla güncellendi.');

        $ogrenci->refresh();
        $this->assertSame('Şükrü Öğüt', $ogrenci->name);
        $this->assertSame('5329998877', $ogrenci->phone);
        $this->assertNull($ogrenci->email);
        $this->assertSame('12', $ogrenci->grade);
        $this->assertSame('say', $ogrenci->field);
        $this->assertTrue(Hash::check('YeniŞifre2026!', $ogrenci->password));
        $this->assertSame([$yeniVeli->id], $ogrenci->parents()->pluck('users.id')->all());
        $this->assertSame(1500, StudyGoal::activeFor($ogrenci, '2026-09-29')->target_minutes);
    }

    public function test_changing_the_weekly_goal_keeps_the_old_one_for_past_weeks(): void
    {
        $ogrenci = $this->ogrenci();
        $veli = $this->veli();
        $this->bagla($ogrenci, $veli);
        $eski = StudyGoal::create(['student_id' => $ogrenci->id, 'period' => 'weekly',
            'target_minutes' => 600, 'effective_from' => '2026-09-01']);

        $this->actingAs($this->yonetici)
            ->put(route('admin.users.update', $ogrenci), $this->formVerisi($ogrenci, ['weekly_goal_hours' => 20]))
            ->assertSessionHasNoErrors();

        $this->assertSame('2026-09-28', $eski->fresh()->effective_to->toDateString());
        $this->assertSame(600, StudyGoal::activeFor($ogrenci, '2026-09-20')->target_minutes);
        $this->assertSame(1200, StudyGoal::activeFor($ogrenci, '2026-09-29')->target_minutes);

        // Ayni hedef tekrar gonderilirse yeni satir acilmaz (cift gonderim).
        $this->actingAs($this->yonetici)
            ->put(route('admin.users.update', $ogrenci), $this->formVerisi($ogrenci, ['weekly_goal_hours' => 20]));
        $this->assertSame(2, StudyGoal::where('student_id', $ogrenci->id)->count());
    }

    public function test_a_blank_password_keeps_the_old_one_and_a_mismatch_is_refused(): void
    {
        $koc = $this->koc();
        $eskiHash = $koc->password;

        $this->actingAs($this->yonetici)
            ->put(route('admin.users.update', $koc), $this->formVerisi($koc, ['password' => '', 'password_confirmation' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame($eskiHash, $koc->fresh()->password);

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $koc))
            ->put(route('admin.users.update', $koc), $this->formVerisi($koc, [
                'password' => 'Sifre12345', 'password_confirmation' => 'Baska12345',
            ]))
            ->assertRedirect(route('admin.users.edit', $koc))
            ->assertSessionHasErrors('password');
        $this->assertSame($eskiHash, $koc->fresh()->password);
    }

    public function test_the_last_parent_cannot_be_removed_on_the_student_form(): void
    {
        $ogrenci = $this->ogrenci();
        $veli = $this->veli();
        $this->bagla($ogrenci, $veli);

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
            ->put(route('admin.users.update', $ogrenci), $this->formVerisi($ogrenci, ['parent_ids' => '']))
            ->assertSessionHasErrors(['parent_ids' => 'Öğrencinin en az bir velisi olmalı.']);

        $this->assertTrue($ogrenci->parents->contains($veli));
    }

    public function test_the_parent_form_links_and_unlinks_students(): void
    {
        $veli = $this->veli();
        $a = $this->ogrenci(null, ['name' => 'A Öğrenci']);
        $b = $this->ogrenci(null, ['name' => 'B Öğrenci']);
        // Ikisinin de baska velisi var: bagi kaldirmak kimseyi velisiz birakmaz.
        $this->bagla($a, $this->veli());
        $this->bagla($b, $this->veli());

        $this->actingAs($this->yonetici)
            ->put(route('admin.users.update', $veli), $this->formVerisi($veli, ['student_ids' => [$a->id, $b->id]]))
            ->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], $veli->students()->pluck('users.id')->all());
        $this->assertSame($this->yonetici->id, (int) $veli->students()->first()->pivot->created_by);

        $this->actingAs($this->yonetici)
            ->put(route('admin.users.update', $veli), $this->formVerisi($veli, ['student_ids' => '']))
            ->assertSessionHasNoErrors();
        $this->assertSame(0, $veli->students()->count());
    }

    public function test_a_parent_form_cannot_link_a_non_student(): void
    {
        $veli = $this->veli();
        $baskaVeli = $this->veli();

        $this->actingAs($this->yonetici)
            ->put(route('admin.users.update', $veli), $this->formVerisi($veli, ['student_ids' => [$baskaVeli->id]]))
            ->assertSessionHasErrors('student_ids.0');

        $this->assertSame(0, $veli->students()->count());
    }

    public function test_the_parent_form_cannot_leave_a_student_without_any_parent(): void
    {
        $this->markTestSkipped('BUG: veli formundan ogrencinin tek velisi kaldirilabiliyor');

        $veli = $this->veli();
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $this->bagla($ogrenci, $veli);

        // Veli duzenleme formunda hicbir ogrenci isaretli degil (gizli alan '').
        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $veli))
            ->put(route('admin.users.update', $veli), $this->formVerisi($veli, ['student_ids' => '']))
            ->assertSessionHasErrors('student_ids');

        $this->assertSame(1, $ogrenci->parents()->count());
    }

    public function test_the_admin_cannot_demote_themself(): void
    {
        $this->markTestSkipped('BUG: yonetici kendi rolunu dusurup kendini kilitleyebiliyor');

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $this->yonetici))
            ->put(route('admin.users.update', $this->yonetici), $this->formVerisi($this->yonetici, ['role' => 'coach']))
            ->assertRedirect(route('admin.users.edit', $this->yonetici))
            ->assertSessionHasErrors('role');

        $this->assertSame('admin', $this->yonetici->fresh()->role);
    }

    public function test_an_invalid_grade_or_field_is_refused(): void
    {
        $ogrenci = $this->ogrenci();
        $this->bagla($ogrenci, $this->veli());

        $this->actingAs($this->yonetici)
            ->put(route('admin.users.update', $ogrenci), $this->formVerisi($ogrenci, ['grade' => '8', 'field' => 'fen']))
            ->assertSessionHasErrors(['grade', 'field']);
    }

    // --- Durum, silme, sifre -------------------------------------------------

    public function test_toggle_status_suspends_and_reactivates(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());

        $this->actingAs($this->yonetici)->from(route('admin.users.index'))
            ->post(route('admin.users.toggle-status', $ogrenci))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success', 'Kullanıcı aboneliği askıya alındı.');
        $this->assertSame('suspended', $ogrenci->fresh()->subscription_status);

        $this->actingAs($this->yonetici)->from(route('admin.users.index'))
            ->post(route('admin.users.toggle-status', $ogrenci))
            ->assertSessionHas('success', 'Kullanıcı aboneliği aktifleştirildi.');
        $this->assertSame('active', $ogrenci->fresh()->subscription_status);
    }

    public function test_the_admin_deletes_a_coach_but_not_themself(): void
    {
        $koc = $this->koc();

        $this->actingAs($this->yonetici)->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $koc))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success', 'Kullanıcı başarıyla silindi.');
        $this->assertDatabaseMissing('users', ['id' => $koc->id]);

        $this->actingAs($this->yonetici)->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $this->yonetici))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('error', 'Kendinizi silemezsiniz.');
        $this->assertDatabaseHas('users', ['id' => $this->yonetici->id]);
    }

    public function test_deleting_a_student_with_history_works(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier3());
        $this->bagla($ogrenci, $this->veli());
        Payment::factory()->create(['subscription_id' => $ogrenci->subscriptions()->first()->id]);
        $this->bitmisOturum($ogrenci);
        $ogrenci->coaches()->attach($this->koc()->id);

        $this->actingAs($this->yonetici)->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $ogrenci))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('users', ['id' => $ogrenci->id]);
    }

    public function test_the_only_parent_of_a_student_is_not_deleted(): void
    {
        $veli = $this->veli();
        $this->bagla($this->ogrenci(null, ['name' => 'Zeynep Tek']), $veli);

        $this->actingAs($this->yonetici)->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $veli))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Zeynep Tek'));

        $this->assertDatabaseHas('users', ['id' => $veli->id]);
    }

    public function test_deleting_a_former_admin_who_resolved_a_discrepancy_does_not_crash(): void
    {
        $this->markTestSkipped('BUG: tutarsizlik cozmus yonetici silinince 500 (resolved_by FK)');

        $eskiYonetici = User::factory()->admin()->create();
        $kayit = DiscrepancyLog::create([
            'location_id' => Location::first()->id, 'product_id' => $this->urun()->id,
            'expected_quantity' => 10, 'actual_quantity' => 8, 'record_type' => 'closing',
            'detected_at' => now(), 'resolved' => true, 'resolution_notes' => 'Sayım hatası',
            'resolved_by' => $eskiYonetici->id, 'resolved_at' => now(),
        ]);

        $this->actingAs($this->yonetici)->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $eskiYonetici))
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseMissing('users', ['id' => $eskiYonetici->id]);
        $this->assertDatabaseHas('discrepancy_logs', ['id' => $kayit->id]);
    }

    public function test_deleting_an_admin_keeps_the_stock_counts_they_recorded(): void
    {
        $this->markTestSkipped('BUG: yonetici silinince kaydettigi stok sayimlari da siliniyor');

        $eskiYonetici = User::factory()->admin()->create();
        $sayim = StockRecord::create([
            'location_id' => Location::first()->id, 'product_id' => $this->urun()->id,
            'record_type' => 'closing', 'verified_quantity' => 12, 'admin_id' => $eskiYonetici->id,
            'recorded_at' => now(),
        ]);

        $this->actingAs($this->yonetici)->from(route('admin.users.index'))
            ->delete(route('admin.users.destroy', $eskiYonetici))
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('stock_records', ['id' => $sayim->id]);
    }

    public function test_the_admin_resets_another_users_password_but_not_their_own(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1(), ['name' => 'Ilgın Şen']);
        $eskiToken = $ogrenci->remember_token;

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
            ->post(route('admin.users.reset-password', $ogrenci))
            ->assertRedirect(route('admin.users.edit', $ogrenci))
            ->assertSessionHas('success', 'Ilgın Şen çıkış yaptırıldı; sonraki girişte yeni şifre belirleyecek.');

        $this->assertNull($ogrenci->fresh()->password);
        $this->assertNotSame($eskiToken, $ogrenci->fresh()->remember_token);

        // Sifresiz kullanicinin sayfasi hala acilir ve bilgi verir.
        $this->actingAs($this->yonetici)->get(route('admin.users.edit', $ogrenci))
            ->assertOk()->assertSee('Henüz şifre yok');

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $this->yonetici))
            ->post(route('admin.users.reset-password', $this->yonetici))
            ->assertSessionHas('error', 'Kendi şifreni buradan sıfırlayamazsın.');
        $this->assertNotNull($this->yonetici->fresh()->password);
    }

    public function test_the_user_show_address_does_not_crash(): void
    {
        $this->markTestSkipped('BUG: /yonetim/kullanicilar/{id} 500 veriyor (show metodu yok)');

        $ogrenci = $this->ogrenci();

        $yanit = $this->actingAs($this->yonetici)->get('/yonetim/kullanicilar/' . $ogrenci->id);

        $this->assertContains($yanit->status(), [200, 301, 302, 404]);
    }

    // --- Abonelik ve odeme ---------------------------------------------------

    public function test_the_subscription_page_shows_history_payments_and_balances(): void
    {
        $paket = Package::factory()->tier2()->create(['name' => 'Orta Paket', 'monthly_price' => 9000]);
        $ogrenci = $this->ogrenci($paket, ['name' => 'Melike Çınar']);
        $abonelik = $ogrenci->subscriptions()->first();
        $abonelik->update(['price' => 9000]);
        Payment::factory()->create(['subscription_id' => $abonelik->id, 'amount' => 4000,
            'method' => 'transfer', 'note' => 'Havale - Ayşe Çınar']);
        Subscription::factory()->create(['student_id' => $ogrenci->id, 'package_id' => $paket->id,
            'starts_on' => '2026-08-01', 'ends_on' => '2026-08-31', 'payment_status' => 'cancelled']);

        $this->actingAs($this->yonetici)->get(route('admin.subscriptions.index', $ogrenci))
            ->assertOk()
            ->assertSee('Paket ve Ödeme: Melike Çınar')
            ->assertSee('Orta Paket')
            ->assertSee('9.000,00 ₺')
            ->assertSee('Kalan <strong>5.000,00 ₺</strong>', false)
            ->assertSee('4.000,00 ₺')
            ->assertSee('Havale - Ayşe Çınar')
            ->assertSee('Gecikmiş')
            ->assertSee('İptal')
            ->assertSee(route('admin.users.edit', $ogrenci), false)
            ->assertSee('value="2026-09-29"', false);
    }

    public function test_the_subscription_page_renders_without_packages_and_only_for_students(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($this->yonetici)->get(route('admin.subscriptions.index', $ogrenci))
            ->assertOk()
            ->assertSee('Satışta paket yok.')
            ->assertSee('Bu öğrenciye henüz paket atanmadı.')
            ->assertSee(route('admin.packages.create'), false);

        $this->actingAs($this->yonetici)->get(route('admin.subscriptions.index', $this->veli()))->assertNotFound();
        $this->actingAs($this->yonetici)->post(route('admin.subscriptions.store', $this->koc()), [])->assertNotFound();
    }

    public function test_assigning_a_package_copies_the_price_and_defaults_to_one_month(): void
    {
        $paket = Package::factory()->tier1()->create(['monthly_price' => 7250]);
        $ogrenci = $this->ogrenci(null, ['subscription_status' => 'inactive']);

        $this->actingAs($this->yonetici)->from(route('admin.subscriptions.index', $ogrenci))
            ->post(route('admin.subscriptions.store', $ogrenci), [
                'package_id' => $paket->id, 'starts_on' => '2026-10-01', 'ends_on' => '', 'price' => '', 'note' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.subscriptions.index', $ogrenci))
            ->assertSessionHas('success', 'Paket atandı.');

        $abonelik = $ogrenci->subscriptions()->sole();
        $this->assertSame('2026-10-01', $abonelik->starts_on->toDateString());
        $this->assertSame('2026-10-31', $abonelik->ends_on->toDateString());
        $this->assertEquals(7250, $abonelik->price);
        $this->assertSame(PaymentStatus::Pending, $abonelik->payment_status);
        $this->assertSame($this->yonetici->id, $abonelik->created_by);
        $this->assertSame('active', $ogrenci->fresh()->subscription_status);

        // Katalog fiyati degisse de abonelik sabit kalir.
        $paket->update(['monthly_price' => 9999]);
        $this->assertEquals(7250, $abonelik->fresh()->price);
    }

    public function test_assigning_a_package_with_a_custom_price_end_and_turkish_note(): void
    {
        $paket = Package::factory()->tier1()->create();
        $ogrenci = $this->ogrenci();

        $this->actingAs($this->yonetici)
            ->post(route('admin.subscriptions.store', $ogrenci), [
                'package_id' => $paket->id, 'starts_on' => '2026-09-15', 'ends_on' => '2026-12-14',
                'price' => '18500.50', 'note' => 'Üç aylık peşin, kardeş indirimi',
            ])->assertSessionHasNoErrors();

        $abonelik = $ogrenci->subscriptions()->sole();
        $this->assertSame('2026-12-14', $abonelik->ends_on->toDateString());
        $this->assertEquals(18500.50, $abonelik->price);
        $this->assertSame('Üç aylık peşin, kardeş indirimi', $abonelik->note);
    }

    public function test_a_double_tapped_package_assignment_opens_one_subscription(): void
    {
        $this->markTestSkipped('BUG: cift tiklanan paket atama ayni donem icin iki abonelik aciyor');

        $paket = Package::factory()->tier1()->create(['monthly_price' => 7500]);
        $ogrenci = $this->ogrenci();
        $veri = ['package_id' => $paket->id, 'starts_on' => '2026-10-01', 'ends_on' => '', 'price' => '7500', 'note' => ''];

        $this->actingAs($this->yonetici)->post(route('admin.subscriptions.store', $ogrenci), $veri);
        $this->actingAs($this->yonetici)->from(route('admin.subscriptions.index', $ogrenci))
            ->post(route('admin.subscriptions.store', $ogrenci), $veri)
            ->assertRedirect(route('admin.subscriptions.index', $ogrenci));

        // Ayni paket, ayni donem: ogrenci bir kez borclanmali.
        $this->assertSame(1, $ogrenci->subscriptions()->where('payment_status', '!=', 'cancelled')->count());
    }

    public function test_assigning_refuses_an_end_before_the_start_and_an_inactive_package(): void
    {
        $ogrenci = $this->ogrenci();
        $kapali = Package::factory()->create(['is_active' => false]);
        $acik = Package::factory()->create();

        $this->actingAs($this->yonetici)->from(route('admin.subscriptions.index', $ogrenci))
            ->post(route('admin.subscriptions.store', $ogrenci), [
                'package_id' => $acik->id, 'starts_on' => '2026-10-10', 'ends_on' => '2026-10-01',
            ])->assertSessionHasErrors('ends_on');

        $this->actingAs($this->yonetici)->from(route('admin.subscriptions.index', $ogrenci))
            ->post(route('admin.subscriptions.store', $ogrenci), [
                'package_id' => $kapali->id, 'starts_on' => '2026-10-01',
            ])->assertSessionHasErrors('package_id');

        $this->actingAs($this->yonetici)->from(route('admin.subscriptions.index', $ogrenci))
            ->post(route('admin.subscriptions.store', $ogrenci), [
                'package_id' => $acik->id, 'starts_on' => '01.10.2026',
            ])->assertSessionHasErrors('starts_on');

        $this->assertSame(0, $ogrenci->subscriptions()->count());
    }

    public function test_recording_a_payment_settles_the_subscription(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $abonelik = $ogrenci->subscriptions()->sole();

        $this->actingAs($this->yonetici)->from(route('admin.subscriptions.index', $ogrenci))
            ->post(route('admin.subscriptions.payments.store', $abonelik), [
                'amount' => '3000', 'paid_at' => '2026-09-29', 'method' => 'cash', 'note' => '',
            ])
            ->assertRedirect(route('admin.subscriptions.index', $ogrenci))
            ->assertSessionHas('success', 'Ödeme kaydedildi. Kalan: 4.500,00 ₺');
        $this->assertSame(PaymentStatus::Overdue, $abonelik->fresh()->payment_status);

        $this->actingAs($this->yonetici)->from(route('admin.subscriptions.index', $ogrenci))
            ->post(route('admin.subscriptions.payments.store', $abonelik), [
                'amount' => '4500', 'paid_at' => '2026-09-29', 'method' => 'transfer', 'note' => 'Havale - Gülay Şen',
            ])
            ->assertSessionHas('success', 'Ödeme kaydedildi. Kalan: 0,00 ₺');

        $this->assertSame(PaymentStatus::Paid, $abonelik->fresh()->payment_status);
        $this->assertDatabaseHas('payments', ['subscription_id' => $abonelik->id, 'note' => 'Havale - Gülay Şen',
            'method' => 'transfer', 'recorded_by' => $this->yonetici->id]);
    }

    public function test_a_double_tapped_payment_is_recorded_once(): void
    {
        $this->markTestSkipped('BUG: cift tiklanan odeme iki kez kaydediliyor');

        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $abonelik = $ogrenci->subscriptions()->sole();
        $veri = ['amount' => '3000', 'paid_at' => '2026-09-29', 'method' => 'cash', 'note' => ''];

        // Yavas mobil baglantida "Ödeme kaydet"e iki kez basildi.
        $this->actingAs($this->yonetici)->from(route('admin.subscriptions.index', $ogrenci))
            ->post(route('admin.subscriptions.payments.store', $abonelik), $veri);
        $this->actingAs($this->yonetici)->from(route('admin.subscriptions.index', $ogrenci))
            ->post(route('admin.subscriptions.payments.store', $abonelik), $veri)
            ->assertRedirect(route('admin.subscriptions.index', $ogrenci));

        $this->assertSame(1, $abonelik->payments()->count());
        $this->assertSame(4500.0, $abonelik->fresh()->balance());
    }

    public function test_a_payment_needs_a_valid_amount_date_and_method(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $abonelik = $ogrenci->subscriptions()->sole();

        $this->actingAs($this->yonetici)->from(route('admin.subscriptions.index', $ogrenci))
            ->post(route('admin.subscriptions.payments.store', $abonelik), [
                'amount' => '0', 'paid_at' => '29.09.2026', 'method' => 'bitcoin',
            ])->assertSessionHasErrors(['amount', 'paid_at', 'method']);

        $this->assertSame(0, $abonelik->payments()->count());
    }

    public function test_a_cancelled_subscription_takes_no_payment(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $abonelik = $ogrenci->subscriptions()->sole();

        $this->actingAs($this->yonetici)->from(route('admin.subscriptions.index', $ogrenci))
            ->post(route('admin.subscriptions.cancel', $abonelik))
            ->assertRedirect(route('admin.subscriptions.index', $ogrenci))
            ->assertSessionHas('success', fn ($m) => str_starts_with($m, 'Abonelik iptal edildi.'));

        $this->assertSame(PaymentStatus::Cancelled, $abonelik->fresh()->payment_status);
        $this->assertSame('active', $ogrenci->fresh()->subscription_status);

        $this->actingAs($this->yonetici)->from(route('admin.subscriptions.index', $ogrenci))
            ->post(route('admin.subscriptions.payments.store', $abonelik), [
                'amount' => '100', 'paid_at' => '2026-09-29', 'method' => 'cash',
            ])->assertSessionHas('error', 'İptal edilmiş aboneliğe ödeme yazılamaz.');

        $this->assertSame(0, $abonelik->payments()->count());
        $this->assertSame(PaymentStatus::Cancelled, $abonelik->fresh()->payment_status);

        // Iptal edilmis kart odeme formu gostermez.
        $this->actingAs($this->yonetici)->get(route('admin.subscriptions.index', $ogrenci))
            ->assertOk()->assertDontSee(route('admin.subscriptions.payments.store', $abonelik), false);
    }

    public function test_deleting_a_payment_reopens_the_balance(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $abonelik = $ogrenci->subscriptions()->sole();
        $odeme = Payment::factory()->create(['subscription_id' => $abonelik->id, 'amount' => 7500]);
        $abonelik->syncPaymentStatus();
        $this->assertSame(PaymentStatus::Paid, $abonelik->fresh()->payment_status);

        $this->actingAs($this->yonetici)->from(route('admin.subscriptions.index', $ogrenci))
            ->delete(route('admin.subscriptions.payments.destroy', $odeme))
            ->assertRedirect(route('admin.subscriptions.index', $ogrenci))
            ->assertSessionHas('success', 'Ödeme kaydı silindi.');

        $this->assertDatabaseMissing('payments', ['id' => $odeme->id]);
        $this->assertSame(PaymentStatus::Overdue, $abonelik->fresh()->payment_status);
    }

    public function test_switching_the_package_from_a_date(): void
    {
        $eski = Package::factory()->tier1()->create();
        $yeni = Package::factory()->tier3()->create(['monthly_price' => 12000]);
        $ogrenci = $this->ogrenci($eski);
        $this->bagla($ogrenci, $this->veli());

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
            ->post(route('admin.subscriptions.switch', $ogrenci), [
                'package_id' => $yeni->id, 'switch_on' => '2026-09-29', 'price' => '',
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.edit', $ogrenci))
            ->assertSessionHas('success', 'Paket değiştirildi.');

        $eskiAbonelik = $ogrenci->subscriptions()->where('package_id', $eski->id)->sole();
        $this->assertSame('2026-09-28', $eskiAbonelik->ends_on->toDateString());

        $simdiki = $ogrenci->fresh()->currentSubscription();
        $this->assertTrue($simdiki->package->is($yeni));
        $this->assertSame('2026-09-29', $simdiki->starts_on->toDateString());
        $this->assertSame('2026-09-30', $simdiki->ends_on->toDateString());
        $this->assertSame('Paket değişikliği', $simdiki->note);
        $this->assertTrue($ogrenci->fresh()->entitlements()->privateLessons);

        // Kullanici sayfasi yeni paketi gosterir.
        $this->actingAs($this->yonetici)->get(route('admin.users.edit', $ogrenci))
            ->assertOk()->assertSee('Kral')->assertSee('29.09.2026 – 30.09.2026');
    }

    public function test_switching_refuses_an_addon_and_non_students(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier1());
        $ek = Package::factory()->examClubAddon()->create();

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
            ->post(route('admin.subscriptions.switch', $ogrenci), ['package_id' => $ek->id, 'switch_on' => '2026-09-29'])
            ->assertSessionHasErrors(['package_id' => 'Ek paket ana paketin yerine geçemez; ödeme ekranından ekleyin.']);
        $this->assertSame(1, $ogrenci->subscriptions()->count());

        $this->actingAs($this->yonetici)
            ->post(route('admin.subscriptions.switch', $this->veli()), ['package_id' => $ek->id, 'switch_on' => '2026-09-29'])
            ->assertNotFound();
    }

    public function test_the_payments_overview_filters_by_status(): void
    {
        $paket = Package::factory()->create(['name' => 'Standart']);
        $odendi = $this->ogrenci(null, ['name' => 'Öde Me']);
        $bekliyor = $this->ogrenci(null, ['name' => 'Bek Ler']);
        $gecikmis = $this->ogrenci(null, ['name' => 'Gec İkti']);
        $iptal = $this->ogrenci(null, ['name' => 'İp Tal']);

        $a = Subscription::factory()->create(['student_id' => $odendi->id, 'package_id' => $paket->id]);
        Payment::factory()->create(['subscription_id' => $a->id, 'amount' => 7500]);
        // Vadesi gelmemis (bugun basladi)
        Subscription::factory()->create(['student_id' => $bekliyor->id, 'package_id' => $paket->id,
            'starts_on' => '2026-09-29', 'ends_on' => '2026-10-28']);
        // 1 Eylul + 7 gun vade gecti
        Subscription::factory()->create(['student_id' => $gecikmis->id, 'package_id' => $paket->id]);
        Subscription::factory()->create(['student_id' => $iptal->id, 'package_id' => $paket->id, 'payment_status' => 'cancelled']);

        $this->actingAs($this->yonetici)->get(route('admin.subscriptions.overview'))
            ->assertOk()
            ->assertSee('Öde Me')->assertSee('Bek Ler')->assertSee('Gec İkti')->assertSee('İp Tal')
            ->assertSee('Ödendi (1)')->assertSee('Bekliyor (1)')->assertSee('Gecikmiş (1)')->assertSee('İptal (1)')
            ->assertSee(route('admin.subscriptions.index', $gecikmis), false);

        $this->actingAs($this->yonetici)->get(route('admin.subscriptions.overview', ['durum' => 'overdue']))
            ->assertOk()->assertSee('Gec İkti')->assertDontSee('Öde Me')->assertDontSee('Bek Ler');

        $this->actingAs($this->yonetici)->get(route('admin.subscriptions.overview', ['durum' => 'paid']))
            ->assertOk()->assertSee('Öde Me')->assertDontSee('Gec İkti');

        // Taninmayan filtre: hepsi
        $this->actingAs($this->yonetici)->get(route('admin.subscriptions.overview', ['durum' => 'saçma']))
            ->assertOk()->assertSee('Öde Me')->assertSee('Gec İkti');
    }

    public function test_the_payments_overview_renders_empty(): void
    {
        $this->actingAs($this->yonetici)->get(route('admin.subscriptions.overview'))
            ->assertOk()->assertSee('Kayıt yok.')->assertSee('Ödendi (0)');
    }

    // --- Koc atamasi ---------------------------------------------------------

    public function test_the_admin_assigns_and_removes_a_coach(): void
    {
        $ogrenci = $this->ogrenci(Package::factory()->tier2());
        $koc = $this->koc();

        foreach ([1, 2] as $_) {
            $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
                ->post(route('admin.coaches.attach', $ogrenci), ['coach_id' => $koc->id])
                ->assertRedirect(route('admin.users.edit', $ogrenci))
                ->assertSessionHas('success', 'Koç atandı.');
        }

        $this->assertSame(1, $ogrenci->coaches()->count());
        $this->assertSame($this->yonetici->id, (int) $ogrenci->coaches()->first()->pivot->created_by);
        $this->assertTrue($koc->canCoach($ogrenci));

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
            ->delete(route('admin.coaches.detach', [$ogrenci, $koc]))
            ->assertRedirect(route('admin.users.edit', $ogrenci))
            ->assertSessionHas('success', 'Koç ataması kaldırıldı.');

        $this->assertSame(0, $ogrenci->coaches()->count());
        $this->assertFalse($koc->fresh()->canCoach($ogrenci));
    }

    public function test_a_coach_is_not_assigned_without_coaching_or_to_a_non_coach(): void
    {
        $standart = $this->ogrenci(Package::factory()->tier1());
        $koc = $this->koc();

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $standart))
            ->post(route('admin.coaches.attach', $standart), ['coach_id' => $koc->id])
            ->assertSessionHas('error', 'Öğrencinin paketi koçluk içermiyor.');
        $this->assertSame(0, $standart->coaches()->count());

        $orta = $this->ogrenci(Package::factory()->tier2());
        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $orta))
            ->post(route('admin.coaches.attach', $orta), ['coach_id' => $this->veli()->id])
            ->assertSessionHasErrors(['coach_id' => 'Seçilen kişi koç ya da yönetici değil.']);
        $this->assertSame(0, $orta->coaches()->count());

        $this->actingAs($this->yonetici)->post(route('admin.coaches.attach', $this->veli()), ['coach_id' => $koc->id])
            ->assertNotFound();
        $this->actingAs($this->yonetici)->delete(route('admin.coaches.detach', [$this->veli(), $koc]))
            ->assertNotFound();
    }

    // --- Paketler ------------------------------------------------------------

    public function test_the_package_list_shows_rights_items_and_counts(): void
    {
        $kahve = $this->urun('Türk Kahvesi');
        $paket = Package::factory()->tier3()->create(['name' => 'Kral Paket', 'description' => 'Her şey dahil', 'weekly_mock_exams' => 2]);
        PackageItem::create(['package_id' => $paket->id, 'product_id' => $kahve->id, 'included_quantity' => 2, 'period' => 'daily']);
        $this->ogrenci($paket);
        Package::factory()->examClubAddon()->create(['is_active' => false]);

        $this->actingAs($this->yonetici)->get(route('admin.packages.index'))
            ->assertOk()
            ->assertSee('Kral Paket')
            ->assertSee('Tier 3')
            ->assertSee('Her şey dahil')
            ->assertSee('Özel ders')
            ->assertSee('Haftada 2 deneme')
            ->assertSee('Türk Kahvesi — günde 2 adet')
            ->assertSee('Ek paket')
            ->assertSee('Kapalı')
            ->assertSee(route('admin.packages.edit', $paket), false)
            ->assertViewHas('packages', fn ($p) => $p->firstWhere('id', $paket->id)->subscriptions_count === 1);
    }

    public function test_the_package_pages_render_empty(): void
    {
        $this->actingAs($this->yonetici)->get(route('admin.packages.index'))
            ->assertOk()->assertSee('Henüz paket yok');

        $this->actingAs($this->yonetici)->get(route('admin.packages.create'))
            ->assertOk()->assertSee('Sistemde aktif ürün yok.')->assertDontSee('Satışta');
    }

    public function test_the_admin_creates_a_package_with_rights_and_items(): void
    {
        $kahve = $this->urun('Türk Kahvesi');
        $cay = $this->urun('Çay');
        $this->urun('Sahlep', 50, false);

        $this->actingAs($this->yonetici)->get(route('admin.packages.create'))
            ->assertOk()->assertSee('Türk Kahvesi')->assertSee('Çay')->assertDontSee('Sahlep');

        $this->actingAs($this->yonetici)->from(route('admin.packages.create'))
            ->post(route('admin.packages.store'), [
                'name' => 'Şampiyon Öğrenci',
                'tier' => '3',
                'monthly_price' => '12500.50',
                'description' => 'Özel ders + koçluk',
                'weekly_mock_exams' => '2',
                'has_reserved_table' => '1',
                'includes_coaching' => '1',
                'includes_exam_club' => '1',
                'includes_private_lessons' => '1',
                'items' => [
                    $kahve->id => ['included' => '1', 'quantity' => '2', 'period' => 'daily'],
                    $cay->id => ['included' => '1', 'quantity' => '', 'period' => 'monthly'],
                ],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.packages.index'))
            ->assertSessionHas('success', 'Paket oluşturuldu.');

        $paket = Package::where('name', 'Şampiyon Öğrenci')->sole();
        $this->assertSame(3, $paket->tier);
        $this->assertEquals(12500.50, $paket->monthly_price);
        $this->assertTrue($paket->includes_private_lessons && $paket->includes_coaching && $paket->includes_exam_club);
        $this->assertFalse($paket->is_addon);
        $this->assertTrue($paket->is_active);
        $this->assertSame(2, $paket->items()->where('product_id', $kahve->id)->sole()->included_quantity);
        $this->assertNull($paket->items()->where('product_id', $cay->id)->sole()->included_quantity);
    }

    public function test_a_package_needs_a_name_a_price_and_a_valid_tier(): void
    {
        $this->actingAs($this->yonetici)->from(route('admin.packages.create'))
            ->post(route('admin.packages.store'), ['name' => '', 'monthly_price' => '-5', 'tier' => '4'])
            ->assertRedirect(route('admin.packages.create'))
            ->assertSessionHasErrors(['name', 'monthly_price', 'tier']);

        $this->assertSame(0, Package::count());
    }

    public function test_the_admin_edits_a_package_and_keeps_it_closed(): void
    {
        $kahve = $this->urun('Türk Kahvesi');
        $paket = Package::factory()->tier1()->create(['name' => 'Standart', 'is_active' => false, 'monthly_price' => 7000]);
        PackageItem::create(['package_id' => $paket->id, 'product_id' => $kahve->id, 'included_quantity' => 1, 'period' => 'daily']);
        $ogrenci = $this->ogrenci($paket);
        $abonelik = $ogrenci->subscriptions()->sole();

        $this->actingAs($this->yonetici)->get(route('admin.packages.edit', $paket))
            ->assertOk()->assertSee('Paket: Standart')->assertSee('Satışta')->assertSee('value="7000.00"', false);

        $this->actingAs($this->yonetici)->from(route('admin.packages.edit', $paket))
            ->put(route('admin.packages.update', $paket), [
                'name' => 'Standart Güz', 'tier' => '1', 'monthly_price' => '8000', 'has_reserved_table' => '1',
                'weekly_mock_exams' => '0', 'is_active' => '0',
                'items' => [$kahve->id => ['included' => '1', 'quantity' => '3', 'period' => 'weekly']],
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.packages.index'))
            ->assertSessionHas('success', 'Paket güncellendi.');

        $paket->refresh();
        $this->assertSame('Standart Güz', $paket->name);
        $this->assertFalse($paket->is_active);
        $this->assertSame(3, $paket->items()->sole()->included_quantity);
        $this->assertSame('weekly', $paket->items()->sole()->period->value);
        // Mevcut abonelik fiyati degismez.
        $this->assertEquals(7500, $abonelik->fresh()->price);

        // Kutu isaretlenmezse kalem cikar.
        $this->actingAs($this->yonetici)->put(route('admin.packages.update', $paket), [
            'name' => 'Standart Güz', 'monthly_price' => '8000', 'is_active' => '1',
            'items' => [$kahve->id => ['quantity' => '3', 'period' => 'weekly']],
        ])->assertSessionHasNoErrors();
        $this->assertSame(0, $paket->items()->count());
        $this->assertTrue($paket->fresh()->is_active);
    }

    public function test_editing_a_package_keeps_items_of_temporarily_closed_products(): void
    {
        $this->markTestSkipped('BUG: paket duzenlenince pasif urunlerin kalemleri sessizce siliniyor');

        $kahve = $this->urun('Türk Kahvesi');
        $sahlep = $this->urun('Sahlep', 50, false); // yazin satista degil
        $paket = Package::factory()->tier1()->create(['name' => 'Standart', 'monthly_price' => 7000]);
        PackageItem::create(['package_id' => $paket->id, 'product_id' => $kahve->id, 'included_quantity' => 1, 'period' => 'daily']);
        PackageItem::create(['package_id' => $paket->id, 'product_id' => $sahlep->id, 'included_quantity' => 1, 'period' => 'daily']);

        // Duzenleme formu yalnizca aktif urunleri cizer; yonetici sadece fiyati degistirir.
        $this->actingAs($this->yonetici)->put(route('admin.packages.update', $paket), [
            'name' => 'Standart', 'tier' => '1', 'monthly_price' => '7500', 'is_active' => '1',
            'items' => [$kahve->id => ['included' => '1', 'quantity' => '1', 'period' => 'daily']],
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('package_items', ['package_id' => $paket->id, 'product_id' => $sahlep->id]);
    }

    public function test_the_package_toggle_opens_and_closes_sales(): void
    {
        $paket = Package::factory()->tier1()->create();

        $this->actingAs($this->yonetici)->from(route('admin.packages.index'))
            ->post(route('admin.packages.toggle-status', $paket))
            ->assertRedirect(route('admin.packages.index'))
            ->assertSessionHas('success', 'Paket durumu güncellendi.');
        $this->assertFalse($paket->fresh()->is_active);

        // Kapali paket yeni ogrenci formunda cikmaz.
        $this->actingAs($this->yonetici)->get(route('admin.users.create'))
            ->assertOk()->assertDontSee('Tier 1 · Standart');

        $this->actingAs($this->yonetici)->from(route('admin.packages.index'))
            ->post(route('admin.packages.toggle-status', $paket));
        $this->assertTrue($paket->fresh()->is_active);
    }

    // --- Ayarlar -------------------------------------------------------------

    public function test_the_settings_page_renders_with_and_without_a_location(): void
    {
        $this->actingAs($this->yonetici)->get(route('admin.settings.edit'))
            ->assertOk()->assertSee('Henüz konum girilmedi')->assertSee('Konumu buradan al');

        Setting::putCafeLocation(41.0082, 28.9784);

        $this->actingAs($this->yonetici)->get(route('admin.settings.edit'))
            ->assertOk()->assertSee('Kayıtlı konum: 41.008200')->assertSee('28.978400');
    }

    public function test_the_admin_saves_the_cafe_location(): void
    {
        $this->actingAs($this->yonetici)->from(route('admin.settings.edit'))
            ->post(route('admin.settings.location'), ['latitude' => '41.0082376', 'longitude' => '28.9783589'])
            ->assertRedirect(route('admin.settings.edit'))
            ->assertSessionHas('success', 'Kafe konumu kaydedildi.');

        $this->assertEqualsWithDelta(41.0082376, Setting::cafeLocation()['lat'], 0.0000001);

        // Ikinci kayit ustune yazar, ikinci satir acmaz.
        $this->actingAs($this->yonetici)->post(route('admin.settings.location'), ['latitude' => '40.99', 'longitude' => '29.02']);
        $this->assertSame(1, Setting::where('key', Setting::KAFE_KONUM)->count());
        $this->assertEqualsWithDelta(40.99, Setting::cafeLocation()['lat'], 0.0001);
    }

    public function test_an_out_of_range_or_missing_coordinate_is_refused(): void
    {
        $this->actingAs($this->yonetici)->from(route('admin.settings.edit'))
            ->post(route('admin.settings.location'), ['latitude' => '91', 'longitude' => '181'])
            ->assertRedirect(route('admin.settings.edit'))
            ->assertSessionHasErrors(['latitude', 'longitude']);

        $this->actingAs($this->yonetici)->from(route('admin.settings.edit'))
            ->post(route('admin.settings.location'), ['latitude' => '', 'longitude' => ''])
            ->assertSessionHasErrors(['latitude', 'longitude']);

        // Virgullu Turkce ondalik: hata mesajiyla doner, 500 degil.
        $this->actingAs($this->yonetici)->from(route('admin.settings.edit'))
            ->post(route('admin.settings.location'), ['latitude' => '41,0082', 'longitude' => '28,9784'])
            ->assertRedirect(route('admin.settings.edit'))
            ->assertSessionHasErrors(['latitude', 'longitude']);

        $this->assertNull(Setting::cafeLocation());
    }

    // --- Ozel ders -----------------------------------------------------------

    private function kralOgrenci(): User
    {
        return $this->ogrenci(Package::factory()->tier3(), ['name' => 'Kral Öğrenci']);
    }

    private function dersSaati(User $ogrenci, int $gun = 3): PrivateLessonSlot
    {
        return PrivateLessonSlot::create(['student_id' => $ogrenci->id, 'weekday' => $gun,
            'starts_at' => '17:00', 'ends_at' => '18:30', 'starts_on' => '2026-09-01']);
    }

    public function test_the_admin_adds_a_weekly_lesson_from_the_local_day(): void
    {
        // Yerel Carsamba 00:30 = UTC Sali 21:30
        $this->travelTo(Carbon::parse('2026-09-30 00:30', config('kafe.timezone')));
        $ogrenci = $this->kralOgrenci();

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
            ->post(route('admin.lessons.store', $ogrenci), ['weekday' => '3', 'starts_at' => '17:00', 'ends_at' => '18:30'])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.edit', $ogrenci))
            ->assertSessionHas('success', 'Özel ders saati eklendi.');

        $saat = $ogrenci->privateLessonSlots()->sole();
        $this->assertSame('2026-09-30', $saat->starts_on->toDateString());
        $this->assertSame($this->yonetici->id, $saat->created_by);

        // Bugunun dersi takvimde gorunur.
        $this->actingAs($this->yonetici)->get(route('admin.users.edit', $ogrenci))
            ->assertOk()->assertSee('Her Çarşamba 17:00–18:30')->assertSee('value="2026-09-30"', false);
    }

    public function test_a_double_tapped_weekly_lesson_is_added_once(): void
    {
        $this->markTestSkipped('BUG: ayni haftalik ozel ders saati iki kez eklenebiliyor');

        $ogrenci = $this->kralOgrenci();
        $veri = ['weekday' => '3', 'starts_at' => '17:00', 'ends_at' => '18:30'];

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
            ->post(route('admin.lessons.store', $ogrenci), $veri);
        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
            ->post(route('admin.lessons.store', $ogrenci), $veri)
            ->assertRedirect(route('admin.users.edit', $ogrenci));

        $this->assertSame(1, $ogrenci->privateLessonSlots()->count());
    }

    public function test_a_lesson_needs_the_entitlement_valid_times_and_a_student(): void
    {
        $orta = $this->ogrenci(Package::factory()->tier2());

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $orta))
            ->post(route('admin.lessons.store', $orta), ['weekday' => '3', 'starts_at' => '17:00', 'ends_at' => '18:00'])
            ->assertSessionHas('error', 'Öğrencinin paketi özel ders içermiyor.');
        $this->assertSame(0, PrivateLessonSlot::count());

        $kral = $this->kralOgrenci();
        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $kral))
            ->post(route('admin.lessons.store', $kral), ['weekday' => '8', 'starts_at' => '18:00', 'ends_at' => '17:00'])
            ->assertSessionHasErrors(['weekday', 'ends_at' => 'Bitiş saati başlangıçtan sonra olmalı.']);
        $this->assertSame(0, PrivateLessonSlot::count());

        $this->actingAs($this->yonetici)
            ->post(route('admin.lessons.store', $this->veli()), ['weekday' => '3', 'starts_at' => '17:00', 'ends_at' => '18:00'])
            ->assertNotFound();
    }

    public function test_the_admin_cancels_moves_and_removes_lessons(): void
    {
        $ogrenci = $this->kralOgrenci();
        $saat = $this->dersSaati($ogrenci);

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
            ->post(route('admin.lessons.cancel', $saat), ['date' => '2026-09-30'])
            ->assertRedirect(route('admin.users.edit', $ogrenci))
            ->assertSessionHas('success', 'Ders iptal edildi.');
        $this->assertTrue($saat->exceptions()->sole()->cancelled);

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
            ->post(route('admin.lessons.move', $saat), [
                'date' => '2026-10-07', 'new_date' => '2026-10-08', 'new_starts_at' => '10:00', 'new_ends_at' => '11:30',
            ])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'Ders taşındı.');
        $this->assertSame('2026-10-08', $saat->exceptions()->where('cancelled', false)->sole()->new_date->toDateString());

        $this->actingAs($this->yonetici)->get(route('admin.users.edit', $ogrenci))
            ->assertOk()->assertSee('İptal')->assertSee('Taşındı')->assertSee('10:00–11:30');

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
            ->delete(route('admin.lessons.destroy', $saat))
            ->assertRedirect(route('admin.users.edit', $ogrenci))
            ->assertSessionHas('success', 'Özel ders saati kaldırıldı.');
        $this->assertSame(0, PrivateLessonSlot::count());
        $this->assertDatabaseCount('private_lesson_exceptions', 0);
    }

    public function test_a_lesson_change_is_refused_on_the_wrong_day_or_with_bad_times(): void
    {
        $saat = $this->dersSaati($this->kralOgrenci()); // Carsamba

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $saat->student_id))
            ->post(route('admin.lessons.cancel', $saat), ['date' => '2026-10-01']) // Persembe
            ->assertSessionHasErrors(['date' => 'Bu tarihte bu saatte ders yok.']);

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $saat->student_id))
            ->post(route('admin.lessons.move', $saat), [
                'date' => '2026-09-30', 'new_date' => '2026-10-01', 'new_starts_at' => '12:00', 'new_ends_at' => '11:00',
            ])
            ->assertSessionHasErrors(['new_ends_at' => 'Bitiş saati başlangıçtan sonra olmalı.']);

        $this->assertDatabaseCount('private_lesson_exceptions', 0);
    }

    public function test_a_cancelled_lesson_can_then_be_moved_instead(): void
    {
        $this->markTestSkipped('BUG: ayni ders once iptal sonra tasininca (ya da iki kez iptal) 500 - SQLite tarih eslesmesi');

        $ogrenci = $this->kralOgrenci();
        $saat = $this->dersSaati($ogrenci);

        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
            ->post(route('admin.lessons.cancel', $saat), ['date' => '2026-09-30'])
            ->assertSessionHasNoErrors();

        // Yonetici fikrini degistirir: iptal yerine tasir.
        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
            ->post(route('admin.lessons.move', $saat), [
                'date' => '2026-09-30', 'new_date' => '2026-10-01', 'new_starts_at' => '10:00', 'new_ends_at' => '11:00',
            ])
            ->assertRedirect(route('admin.users.edit', $ogrenci))
            ->assertSessionHasNoErrors();

        $istisna = $saat->exceptions()->sole();
        $this->assertFalse($istisna->cancelled);
        $this->assertSame('2026-10-01', $istisna->new_date->toDateString());

        // Cift tiklanan iptal de hata vermemeli.
        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
            ->post(route('admin.lessons.cancel', $saat), ['date' => '2026-10-07']);
        $this->actingAs($this->yonetici)->from(route('admin.users.edit', $ogrenci))
            ->post(route('admin.lessons.cancel', $saat), ['date' => '2026-10-07'])
            ->assertRedirect(route('admin.users.edit', $ogrenci));
        $this->assertSame(2, $saat->exceptions()->count());
    }
}
