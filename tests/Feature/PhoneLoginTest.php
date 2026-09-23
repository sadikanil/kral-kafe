<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Dalga 18: telefonla giris.
 *
 * Birinci adim yalnizca kimlik (telefon ya da e-posta) sorar. Sifresi olan
 * hesaba sifre, sifresi hic olmayan (yonetici yeni ekledi) hesaba "sifre
 * belirle" adimi acilir. Dogrulama kodu yok (karar: yonetici ekliyor).
 */
class PhoneLoginTest extends TestCase
{
    use RefreshDatabase;

    private function ogrenci(array $ek = []): User
    {
        return User::factory()->student()->create(array_merge([
            'phone' => '5321234567',
            'password' => Hash::make('Parola-123'),
        ], $ek));
    }

    // --- Birinci adim -------------------------------------------------------

    public function test_the_login_page_asks_for_a_phone_number_first(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Telefon numarası')
            ->assertDontSee('name="password"', false);
    }

    public function test_a_known_phone_with_a_password_is_asked_for_the_password(): void
    {
        $this->ogrenci();

        $this->post(route('login'), ['kimlik' => '0532 123 45 67'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('giris_adimi', 'sifre')
            ->assertSessionHas('giris_kimlik', '5321234567');
    }

    public function test_a_new_user_without_a_password_is_asked_to_set_one(): void
    {
        $this->ogrenci(['password' => null]);

        $this->post(route('login'), ['kimlik' => '05321234567'])
            ->assertRedirect(route('login'))
            ->assertSessionHas('giris_adimi', 'belirle');
    }

    public function test_the_password_step_shows_a_password_field(): void
    {
        $this->withSession(['giris_adimi' => 'sifre', 'giris_kimlik' => '5321234567'])
            ->get(route('login'))
            ->assertSee('name="password"', false)
            ->assertSee('0532 123 45 67');
    }

    public function test_an_unknown_phone_is_reported(): void
    {
        $this->from(route('login'))
            ->post(route('login'), ['kimlik' => '0555 000 00 00'])
            ->assertSessionHasErrors(['kimlik' => 'Bu bilgiyle kayıtlı bir kullanıcı yok.']);
    }

    public function test_an_invalid_phone_is_reported(): void
    {
        $this->from(route('login'))
            ->post(route('login'), ['kimlik' => '123'])
            ->assertSessionHasErrors('kimlik');
    }

    public function test_an_email_still_works_as_the_identity(): void
    {
        User::factory()->admin()->create(['email' => 'admin@kralkafe.com']);

        $this->post(route('login'), ['kimlik' => 'admin@kralkafe.com'])
            ->assertSessionHas('giris_adimi', 'sifre');
    }

    // --- Sifre adimi --------------------------------------------------------

    public function test_the_right_password_signs_in(): void
    {
        $ogrenci = $this->ogrenci();

        $this->post(route('login'), ['kimlik' => '0532 123 45 67', 'password' => 'Parola-123'])
            ->assertRedirect(route('user.dashboard'));

        $this->assertAuthenticatedAs($ogrenci);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        $this->ogrenci();

        $this->from(route('login'))
            ->post(route('login'), ['kimlik' => '5321234567', 'password' => 'yanlis'])
            ->assertSessionHasErrors('kimlik');

        $this->assertGuest();
    }

    /** Yanlis sifreden sonra kullanici telefon adimina geri dusmemeli. */
    public function test_a_wrong_password_returns_to_the_password_step(): void
    {
        $this->ogrenci();

        $this->from(route('login'))
            ->post(route('login'), ['kimlik' => '5321234567', 'adim' => 'sifre', 'password' => 'yanlis'])
            ->assertRedirect(route('login'));

        $this->get(route('login'))
            ->assertSee('name="password"', false)
            ->assertSee('0532 123 45 67');
    }

    public function test_a_user_without_a_password_cannot_sign_in_with_any_password(): void
    {
        $this->ogrenci(['password' => null]);

        $this->from(route('login'))
            ->post(route('login'), ['kimlik' => '5321234567', 'password' => ''])
            ->assertSessionHasErrors();

        $this->assertGuest();
    }

    // --- Sifre belirleme ----------------------------------------------------

    public function test_a_new_user_sets_a_password_and_is_signed_in(): void
    {
        $ogrenci = $this->ogrenci(['password' => null]);

        $this->post(route('login.set-password'), [
            'kimlik' => '0532 123 45 67',
            'password' => 'yeni-sifre',
            'password_confirmation' => 'yeni-sifre',
        ])->assertRedirect(route('user.dashboard'));

        $this->assertAuthenticatedAs($ogrenci);
        $this->assertTrue(Hash::check('yeni-sifre', $ogrenci->fresh()->password));
    }

    /**
     * Guvenligin tamami bu test: sifresi olan bir hesabin sifresi bu uctan
     * DEGISTIRILEMEZ. Yoksa numarayi bilen herkes hesabi ele gecirirdi.
     */
    public function test_a_password_that_exists_cannot_be_overwritten(): void
    {
        $ogrenci = $this->ogrenci();

        $this->from(route('login'))->post(route('login.set-password'), [
            'kimlik' => '5321234567',
            'password' => 'ele-gecirme',
            'password_confirmation' => 'ele-gecirme',
        ])->assertSessionHasErrors('kimlik');

        $this->assertGuest();
        $this->assertTrue(Hash::check('Parola-123', $ogrenci->fresh()->password));
    }

    public function test_the_new_password_must_be_confirmed(): void
    {
        $this->ogrenci(['password' => null]);

        $this->from(route('login'))->post(route('login.set-password'), [
            'kimlik' => '5321234567',
            'password' => 'yeni-sifre',
            'password_confirmation' => 'baska',
        ])->assertSessionHasErrors('password');
    }

    public function test_the_new_password_needs_six_characters(): void
    {
        $this->ogrenci(['password' => null]);

        $this->from(route('login'))->post(route('login.set-password'), [
            'kimlik' => '5321234567',
            'password' => '12345',
            'password_confirmation' => '12345',
        ])->assertSessionHasErrors('password');
    }
}
