<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Giris ve sifre sayfalarinin erisilebilirligi (QA A14, A16).
 *
 * - type="tel" telefon tus takimini acar; orada harf ve '@' yok. Yalnizca
 *   e-postali hesap (koc, yonetici) telefondan hic giremiyordu.
 * - Ikinci adimda kimlik type="hidden" idi; sifre yoneticileri sifreyi
 *   kullanici adisiz kaydediyor, bir dahaki sefere dolduramiyordu.
 * - Giris sayfalarinda h1 yoktu; VoiceOver'in basliklar listesi bos kaliyordu.
 */
class LoginFormAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    private function ogrenci(array $ek = []): User
    {
        return User::factory()->student()->create(array_merge([
            'phone' => '5321234567',
            'password' => Hash::make('Parola-123'),
        ], $ek));
    }

    public function test_the_identity_field_is_plain_text_with_a_phone_keypad_and_username_hint(): void
    {
        $sayfa = $this->get(route('login'))->assertOk();

        $sayfa->assertSee('type="text"', false)
            ->assertSee('inputmode="tel"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocapitalize="off"', false)
            ->assertSee('spellcheck="false"', false)
            ->assertDontSee('type="tel"', false)
            ->assertSee('href="' . route('login', ['ile' => 'eposta']) . '"', false)
            ->assertSee('E-posta ile gir');
    }

    public function test_the_email_link_opens_the_same_step_with_an_email_keyboard(): void
    {
        $this->get(route('login', ['ile' => 'eposta']))
            ->assertOk()
            ->assertSee('E-posta adresi')
            ->assertSee('inputmode="email"', false)
            ->assertSee('name="ile" value="eposta"', false)
            ->assertSee('href="' . route('login') . '"', false)
            ->assertSee('Telefonla gir');
    }

    public function test_an_error_in_email_mode_keeps_the_email_keyboard(): void
    {
        // Bos gonderim: kimlik yok, mod gizli alandan geri gelir.
        $this->from(route('login', ['ile' => 'eposta']))
            ->post(route('login'), ['kimlik' => '', 'ile' => 'eposta'])
            ->assertSessionHasErrors(['kimlik' => 'E-posta adresinizi girin.']);
        $this->get(route('login'))->assertSee('inputmode="email"', false);

        // '@' unutulmus e-posta: e-posta ekraninda telefon uyarisi sasirtir.
        $this->from(route('login', ['ile' => 'eposta']))
            ->post(route('login'), ['kimlik' => 'koc.kralkafe.com', 'ile' => 'eposta'])
            ->assertSessionHasErrors(['kimlik' => 'Geçerli bir e-posta adresi girin.']);

        // Bilinmeyen e-posta: girilen deger '@' tasidigi icin yine e-posta modu.
        $this->from(route('login'))
            ->post(route('login'), ['kimlik' => 'yok@kralkafe.com']);
        $this->get(route('login'))
            ->assertSee('inputmode="email"', false)
            ->assertSee('value="yok@kralkafe.com"', false);
    }

    public function test_the_password_step_carries_a_visible_username_for_password_managers(): void
    {
        $this->ogrenci();
        $this->post(route('login'), ['kimlik' => '05321234567']);

        $sayfa = $this->get(route('login'))->assertOk();
        $sayfa->assertDontSee('type="hidden" name="kimlik"', false)
            ->assertSee('<label for="kimlik" class="form-label">Telefon numarası</label>', false)
            ->assertSee('value="0532 123 45 67"', false)
            ->assertSee('autocomplete="username"', false)
            ->assertSee('readonly', false)
            ->assertSee('autocomplete="current-password"', false);

        // Formun gonderdigi bicimli numara ayni hesaba gider.
        $this->post(route('login'), ['kimlik' => '0532 123 45 67', 'adim' => 'sifre', 'password' => 'Parola-123'])
            ->assertRedirect(route('user.dashboard'));
        $this->assertAuthenticated();
    }

    public function test_an_email_only_account_sees_its_email_on_the_password_step(): void
    {
        User::factory()->create(['email' => 'koc@kralkafe.com', 'phone' => null, 'role' => 'coach']);
        $this->post(route('login'), ['kimlik' => 'koc@kralkafe.com']);

        $this->get(route('login'))
            ->assertSee('<label for="kimlik" class="form-label">E-posta adresi</label>', false)
            ->assertSee('value="koc@kralkafe.com"', false);
    }

    public function test_the_reset_pages_hint_email_and_new_password(): void
    {
        $this->get(route('password.request'))
            ->assertSee('autocomplete="email"', false);

        $this->get(route('password.reset', ['token' => 'x', 'email' => 'koc@kralkafe.com']))
            ->assertSee('autocomplete="username"', false)
            ->assertSee('autocomplete="new-password"', false);
    }

    public function test_every_auth_page_has_one_h1_as_its_title(): void
    {
        $this->ogrenci(['password' => null]);

        $sayfalar = [
            'Hoş Geldiniz' => fn () => $this->get(route('login')),
            'Şifremi Unuttum' => fn () => $this->get(route('password.request')),
            'Yeni Şifre Belirle' => fn () => $this->get(route('password.reset', 'x')),
        ];

        foreach ($sayfalar as $baslik => $ac) {
            $html = $ac()->assertOk()->getContent();
            $this->assertSame(1, substr_count($html, '<h1'), $baslik);
            $this->assertStringContainsString('<h1 class="auth-title">' . $baslik . '</h1>', $html);
            $this->assertStringNotContainsString('<h2', $html, $baslik);
        }

        // Sifre belirleme adimi da ayni baslikla.
        $this->post(route('login'), ['kimlik' => '05321234567']);
        $this->assertStringContainsString('<h1 class="auth-title">Hoş Geldiniz</h1>', $this->get(route('login'))->getContent());
    }
}
