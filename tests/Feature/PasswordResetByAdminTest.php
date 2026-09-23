<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Dalga 18b: yonetici sifreyi sifirlar. Kullanici her yerden atilir ve
 * sonraki giriste yeni sifre belirlemek zorundadir (e-posta akisi yok).
 */
class PasswordResetByAdminTest extends TestCase
{
    use RefreshDatabase;

    private function ogrenci(): User
    {
        return User::factory()->student()->create([
            'phone' => '5321234567',
            'password' => Hash::make('Parola-123'),
            'remember_token' => 'eski-token',
        ]);
    }

    public function test_the_admin_clears_the_password(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.users.reset-password', $ogrenci))
            ->assertSessionHas('success');

        $this->assertNull($ogrenci->fresh()->password);
    }

    /** "Beni hatirla" cerezi eski token'la geri giremesin. */
    public function test_the_remember_token_is_replaced(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.users.reset-password', $ogrenci));

        $this->assertNotSame('eski-token', $ogrenci->fresh()->remember_token);
    }

    /** Acik oturumu olan kullanici bir sonraki isteginde disari atilir. */
    public function test_an_open_session_is_ended_on_the_next_request(): void
    {
        $ogrenci = $this->ogrenci();
        $ogrenci->forceFill(['password' => null])->save();

        $this->actingAs($ogrenci)
            ->get(route('user.dashboard'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_after_a_reset_the_login_asks_for_a_new_password(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs(User::factory()->admin()->create())
            ->post(route('admin.users.reset-password', $ogrenci));
        $this->post(route('logout'));

        $this->post(route('login'), ['kimlik' => '0532 123 45 67'])
            ->assertSessionHas('giris_adimi', 'belirle');
    }

    /** Yonetici kendi sifresini sifirlarsa sisteme kimse giremez hale gelebilir. */
    public function test_the_admin_cannot_reset_their_own_password(): void
    {
        $yonetici = User::factory()->admin()->create();

        $this->actingAs($yonetici)
            ->post(route('admin.users.reset-password', $yonetici))
            ->assertSessionHas('error');

        $this->assertNotNull($yonetici->fresh()->password);
    }

    public function test_only_the_admin_can_reset(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs(User::factory()->student()->create())
            ->post(route('admin.users.reset-password', $ogrenci))
            ->assertForbidden();

        $this->assertNotNull($ogrenci->fresh()->password);
    }

    public function test_the_edit_page_offers_the_reset_button(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.users.edit', $this->ogrenci()))
            ->assertSee(route('admin.users.reset-password', User::where('phone', '5321234567')->sole()));
    }
}
