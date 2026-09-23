<?php

namespace Tests\Feature;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

/**
 * Faz 2 / H: ortak kabuk (layouts/app, layouts/auth, alt menu).
 *
 * Her sayfanin basinda calisan kod burada: bir hata ya da fazladan sorgu
 * tek bir sayfada degil, hepsinde birden yasanir. Ekran okuyucu isaretleri
 * de burada sabitleniyor; gorunmedikleri icin bir duzenlemede sessizce
 * kaybolmalari kolay.
 */
class AppShellTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Sali 14:00 yerel: kafe acik, SettleStaleSessions hicbir seyi kapatmaz.
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
    }

    private function ogrenci(): User
    {
        return User::factory()->student()->withPackage(Package::factory()->tier1())->create();
    }

    private function bildirim(User $kime, array $ek = []): Notification
    {
        static $n = 0;

        return Notification::create(array_merge([
            'type' => NotificationType::Absence->value,
            'user_id' => $kime->id,
            'unique_key' => 'kabuk:' . (++$n),
            'title' => 'Kabuk bildirimi ' . $n,
        ], $ek));
    }

    private function surum(string $yol): string
    {
        return asset($yol) . '?v=' . substr(md5_file(public_path($yol)), 0, 12);
    }

    // --- Yazi tipi ve CSS onbellegi (P14, P15) ---------------------------------

    /** Ucuncu taraf yazi tipi ilk boyamayi DNS+TLS+CSS+font kadar bekletir. */
    public function test_no_page_loads_fonts_from_google(): void
    {
        foreach ([$this->get(route('login')), $this->actingAs($this->ogrenci())->get(route('user.dashboard'))] as $yanit) {
            $yanit->assertOk()
                ->assertDontSee('fonts.googleapis.com', false)
                ->assertDontSee('fonts.gstatic.com', false);
        }
    }

    public function test_the_stylesheet_uses_the_system_font_stack(): void
    {
        $css = (string) file_get_contents(public_path('css/app.css'));

        $this->assertStringContainsString(
            "--font-sans: -apple-system, BlinkMacSystemFont, 'SF Pro Text', system-ui, 'Segoe UI', Roboto, sans-serif;",
            $css
        );
        $this->assertStringNotContainsString("'Inter'", $css);
    }

    /**
     * Adres icerikle degismezse vercel.json'daki bir yillik onbellek
     * telefonlarda eski CSS'i tutar; surum parametresi dosyanin ozeti.
     */
    public function test_the_stylesheet_and_script_urls_carry_a_content_version(): void
    {
        $misafir = $this->get(route('login'));
        $girisli = $this->actingAs($this->ogrenci())->get(route('user.dashboard'));

        foreach ([$misafir, $girisli] as $yanit) {
            $yanit->assertOk()
                ->assertSee('href="' . $this->surum('css/app.css') . '"', false)
                ->assertSee('src="' . $this->surum('js/kabuk.js') . '"', false);
        }
    }

    public function test_versioned_assets_are_cached_for_a_year_on_vercel(): void
    {
        $rotalar = json_decode((string) file_get_contents(base_path('vercel.json')), true)['routes'];
        $dosyaSistemi = array_search(['handle' => 'filesystem'], $rotalar, true);

        $onbellek = collect($rotalar)->search(fn ($r) => ($r['headers']['Cache-Control'] ?? null) === 'public, max-age=31536000, immutable');

        $this->assertNotFalse($onbellek, 'Surumlu varliklar icin uzun onbellek rotasi yok');
        $rota = $rotalar[$onbellek];

        // Filesystem'den ONCE gelmeli ve devam etmeli; yoksa dosya hic sunulmaz
        // ya da basliksiz sunulur.
        $this->assertLessThan($dosyaSistemi, $onbellek);
        $this->assertTrue($rota['continue'] ?? false);
        $this->assertMatchesRegularExpression('#' . str_replace('#', '\#', $rota['src']) . '#', '/css/app.css');
        $this->assertMatchesRegularExpression('#' . str_replace('#', '\#', $rota['src']) . '#', '/js/kabuk.js');
        // Simge sprite'i her sayfada onlarca kez istenir (Faz 3).
        $this->assertMatchesRegularExpression('#' . str_replace('#', '\#', $rota['src']) . '#', '/img/simgeler.svg');
        $this->assertDoesNotMatchRegularExpression('#' . str_replace('#', '\#', $rota['src']) . '#', '/kullanici/panel');
        // Surumsuz adres (eski sekme, elle yazilmis link) uzun onbellege girmesin.
        $this->assertSame([['type' => 'query', 'key' => 'v']], $rota['has'] ?? null);
    }

    // --- Zil (P8, A8) ----------------------------------------------------------

    /** Zil her sayfada calisiyor: okunmamis sayisi ve son 6 bildirim tek sorgu. */
    public function test_the_bell_costs_a_single_query(): void
    {
        $ogrenci = $this->ogrenci();
        $this->bildirim($ogrenci);
        $this->bildirim($ogrenci, ['read_at' => now()]);

        DB::enableQueryLog();
        $yanit = $this->actingAs($ogrenci)->get(route('user.dashboard'));
        $zilSorgulari = collect(DB::getQueryLog())->filter(fn ($q) => str_contains($q['query'], '"notifications"'));

        $yanit->assertOk()->assertSee('notif-count">1<', false)->assertSee('Kabuk bildirimi');
        $this->assertCount(1, $zilSorgulari, $zilSorgulari->pluck('query')->implode("\n"));
    }

    public function test_without_notifications_the_bell_shows_no_count(): void
    {
        $this->actingAs($this->ogrenci())->get(route('user.dashboard'))
            ->assertOk()
            ->assertDontSee('notif-count', false)
            ->assertSee('Bildirim yok.')
            ->assertSee('aria-label="Bildirimler"', false);
    }

    /** Ekran okuyucu emojiyi okur: "zil 1", "kapi". Adlar metinle verilir. */
    public function test_icon_only_shell_controls_have_accessible_names(): void
    {
        $ogrenci = $this->ogrenci();
        $this->bildirim($ogrenci);
        $this->bildirim($ogrenci);

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('aria-label="Çıkış yap"', false)
            ->assertSee('aria-label="Bildirimler, 2 okunmamış"', false)
            ->assertSee('<span aria-hidden="true" class="notif-count">2</span>', false);
    }

    // --- Menu (A7, A17) ----------------------------------------------------------

    public function test_the_menu_button_reports_its_state(): void
    {
        $this->actingAs($this->ogrenci())->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('aria-controls="sidebar" aria-expanded="false"', false)
            // Karartma dokunulabilir bir dugme; ekran okuyucuda adi var.
            ->assertSee('<button type="button" class="sidebar-overlay js-menu-kapat" id="sidebarOverlay" aria-label="Menüyü kapat" tabindex="-1"></button>', false)
            // Eski satir ici onclick kalmadi (betik dugmeleri kendisi baglar).
            ->assertDontSee('onclick="toggleSidebar()"', false);
    }

    /** Etkin sayfa yalnizca renkle degil, aria-current ile de belli. */
    public function test_the_active_page_is_marked_in_both_navigations(): void
    {
        $icerik = $this->actingAs($this->ogrenci())->get(route('user.dashboard'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<a href="' . preg_quote(route('user.dashboard'), '#') . '" class="sidebar-nav-link active" aria-current="page">#', $icerik);
        $this->assertMatchesRegularExpression('#<a href="' . preg_quote(route('user.dashboard'), '#') . '" class="bottom-nav-item is-active" aria-current="page">#', $icerik);
        // Etkin olmayan sayfada isaret yok.
        $this->assertDoesNotMatchRegularExpression('#href="' . preg_quote(route('user.tab'), '#') . '"[^>]*aria-current#', $icerik);
    }

    public function test_navigations_are_labelled_and_icons_hidden_from_screen_readers(): void
    {
        $icerik = $this->actingAs($this->ogrenci())->get(route('user.dashboard'))->assertOk()->getContent();

        $this->assertStringContainsString('<nav class="sidebar-nav" aria-label="Ana menü">', $icerik);
        $this->assertStringContainsString('<nav class="bottom-nav" aria-label="Hızlı menü">', $icerik);
        $this->assertStringNotContainsString('<span class="sidebar-nav-link-icon">', $icerik);
        $this->assertStringNotContainsString('<span class="bottom-nav-icon">', $icerik);
        $this->assertStringContainsString('<span class="sidebar-nav-link-icon" aria-hidden="true">', $icerik);
        $this->assertStringContainsString('<span class="bottom-nav-icon" aria-hidden="true">', $icerik);
    }

    /** Masaustunde kenar menusunun 10+ linki her sayfada once gecilmesin. */
    public function test_a_skip_link_jumps_to_the_content(): void
    {
        $this->actingAs($this->ogrenci())->get(route('user.dashboard'))
            ->assertOk()
            ->assertSeeInOrder(['<body>', '<a href="#icerik" class="skip-link">İçeriğe geç</a>', 'class="sidebar"'], false)
            ->assertSee('<main class="main-content has-bottom-nav" id="icerik" tabindex="-1">', false);
    }

    /**
     * Telefonda kapali menu yalnizca transform ile disari itiliyordu; ekran
     * okuyucu ve klavye gorunmeyen linklerin hepsinden geciyordu.
     */
    public function test_the_closed_mobile_menu_is_hidden_from_assistive_technology(): void
    {
        $css = (string) file_get_contents(public_path('css/app.css'));
        preg_match('#@media \(max-width: 1024px\) \{\s*(?:/\*.*?\*/\s*)?\.sidebar \{(.*?)\}\s*\.sidebar\.open \{(.*?)\}#s', $css, $m);

        $this->assertNotEmpty($m, 'Mobil kenar menusu kurali bulunamadi');
        $this->assertStringContainsString('visibility: hidden', $m[1]);
        $this->assertStringContainsString('visibility: visible', $m[2]);
    }

    // --- Duyurular (A10) ---------------------------------------------------------

    /**
     * Faz 3: mesajlar yuzen balon. Basari role=status (betik acilista yeniden
     * duyurur), hata role=alert ve acilista odak alir; ikisinin de adli bir
     * kapat dugmesi var.
     */
    public function test_flash_messages_are_announced(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($ogrenci)->withSession(['success' => 'Adisyona eklendi'])->get(route('user.dashboard'))
            ->assertSee('<div class="toast toast-success" role="status">', false)
            ->assertSee('<span class="toast-message">Adisyona eklendi</span>', false)
            ->assertSee('toast-close js-balon-kapat" aria-label="Kapat"', false);

        $this->actingAs($ogrenci)->withSession(['error' => 'Olmadı'])->get(route('user.dashboard'))
            ->assertSee('<div class="toast toast-error" role="alert" tabindex="-1" data-odakla>', false)
            ->assertSee('<span class="toast-message">Olmadı</span>', false);
    }

    /**
     * Hata ozeti sayfa acilinca odak alir (betik) ve her satir hangi alana
     * ait oldugunu tasir; betik o alani bulup satiri baglantiya cevirir.
     */
    public function test_the_error_summary_is_an_alert_that_points_at_the_fields(): void
    {
        $hatalar = (new ViewErrorBag)->put('default', new MessageBag([
            'code' => ['Kod hatalı.'],
            'subjects.7.correct' => ['Türkçe doğru sayısı en fazla 200 olabilir.'],
        ]));

        $this->actingAs($this->ogrenci())->withSession(['errors' => $hatalar])->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('<div class="alert alert-danger animate-slide-up" role="alert" tabindex="-1" data-odakla>', false)
            ->assertSee('Hata!')
            ->assertSee('<ul class="mb-0 mt-1">', false)
            ->assertSee('<li data-hata-alani="code">Kod hatalı.</li>', false)
            ->assertSee('<li data-hata-alani="subjects.7.correct">Türkçe doğru sayısı en fazla 200 olabilir.</li>', false);
    }

    /** Suresi dolan sayfadan (419) giris ekranina donen mesaj orada gorunmeli. */
    public function test_the_auth_layout_shows_an_error_flash(): void
    {
        $this->withSession(['error' => 'Sayfanın süresi dolmuştu, tekrar dene.'])->get(route('login'))
            ->assertOk()
            ->assertSee('role="alert"', false)
            ->assertSee('Sayfanın süresi dolmuştu, tekrar dene.');
    }
}
