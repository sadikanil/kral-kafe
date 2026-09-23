<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Faz 2 / H (A18): hata sayfalari Turkce ve uygulamanin gorunumunde.
 *
 * Telefonda sekmeler gunlerce acik kaliyor; dunku sekmeden basilan dugme
 * 419 (sayfanin suresi doldu) aliyordu - Ingilizce, baska gorunumde ve
 * yazilan not kayboluyordu. Artik forma geri donuluyor, girilenler duruyor.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
        // Uretimdeki gibi: hata ayiklama sayfasi yerine hata gorunumu cizilir.
        config(['app.debug' => false]);
    }

    public function test_an_unknown_address_shows_a_turkish_404(): void
    {
        $this->get('/boyle-bir-sayfa-yok')
            ->assertNotFound()
            ->assertSee('Sayfa bulunamadı')
            ->assertSee('Ana sayfaya dön')
            ->assertSee('href="' . route('home') . '"', false)
            ->assertDontSee('Not Found');
    }

    public function test_a_forbidden_page_shows_a_turkish_403(): void
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier1())->create();

        $this->actingAs($ogrenci)->get(route('admin.dashboard'))
            ->assertForbidden()
            ->assertSee('Bu sayfaya erişimin yok')
            ->assertDontSee('Forbidden');
    }

    public function test_a_server_error_shows_a_turkish_500_without_details(): void
    {
        Route::middleware('web')->get('/_test/patla', fn () => throw new \RuntimeException('gizli ayrinti'));

        $this->get('/_test/patla')
            ->assertStatus(500)
            ->assertSee('Bir şeyler ters gitti')
            ->assertDontSee('gizli ayrinti')
            ->assertDontSee('Server Error');
    }

    public function test_maintenance_shows_a_turkish_503(): void
    {
        Route::get('/_test/bakim', fn () => abort(503));

        $this->get('/_test/bakim')
            ->assertStatus(503)
            ->assertSee('Kısa bir bakım yapıyoruz')
            ->assertDontSee('Service Unavailable');
    }

    /** Adres cubuguna yazilan POST adresi (GET /cikis) Symfony'nin Ingilizce sayfasini aciyordu. */
    public function test_other_client_errors_fall_back_to_a_turkish_page(): void
    {
        $this->get('/cikis')
            ->assertStatus(405)
            ->assertSee('Bu işlem yapılamadı')
            ->assertDontSee('Method Not Allowed');
    }

    public function test_the_419_page_itself_is_turkish(): void
    {
        Route::get('/_test/suresi-doldu', fn () => abort(419));

        $this->get('/_test/suresi-doldu')
            ->assertStatus(419)
            ->assertSee('Sayfanın süresi doldu')
            ->assertDontSee('Page Expired');
    }

    // --- Suresi dolan form (TokenMismatchException) ---------------------------

    private function suresiDolanForm(): void
    {
        // Testte CSRF denetimi atlanir; suresi dolmus jetonun firlattigini
        // dogrudan firlatiyoruz (VerifyCsrfToken ayni istisnayi atar).
        Route::middleware('web')->post('/_test/form', fn () => throw new TokenMismatchException('CSRF token mismatch.'));
    }

    public function test_an_expired_form_goes_back_with_the_typed_input_and_a_message(): void
    {
        $this->suresiDolanForm();

        $this->from('/kullanici/panel')->post('/_test/form', [
            'note' => 'Uzun uzun yazılmış not',
            'password' => 'Parola-123',
            'password_confirmation' => 'Parola-123',
        ])
            ->assertRedirect('/kullanici/panel')
            ->assertSessionHas('error', 'Sayfanın süresi dolmuştu, tekrar dene.')
            ->assertSessionHasInput('note', 'Uzun uzun yazılmış not')
            // Sifre asla oturuma yazilmaz.
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation')
            ->assertSessionMissing('_old_input._token');
    }

    public function test_an_expired_login_form_comes_back_to_the_login_page_with_the_message(): void
    {
        $this->suresiDolanForm();

        $this->from(route('login'))->followingRedirects()->post('/_test/form', ['kimlik' => '0532 123 45 67'])
            ->assertOk()
            ->assertSee('Sayfanın süresi dolmuştu, tekrar dene.')
            ->assertSee('value="0532 123 45 67"', false);
    }

    /** Betikten gelen istek yonlendirme degil, 419 JSON bekler. */
    public function test_an_expired_json_request_still_gets_a_419(): void
    {
        $this->suresiDolanForm();

        $this->postJson('/_test/form', ['note' => 'x'])->assertStatus(419);
    }
}
