<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Dalga 1 - Roller (MVP #7).
 *
 * Rol sayisi ikiden altiya cikiyor. Uc ayri yerde kirilabilir ve ucu de
 * sessizce kirilir:
 *
 *   1. Veritabani sutunu - enum('student','admin') CHECK kisiti tasiyor.
 *   2. UserController dogrulamasi - in:student,admin.
 *   3. Formlardaki acilir liste - iki secenek sabit yazilmis.
 *
 * Yalnizca birini duzeltmek "rol eklendi" hissi verir ama yonetici paneli
 * hala kocu olusturamaz. Ucu de burada test ediliyor.
 */
class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    private function yonetici(): User
    {
        return User::factory()->create([
            'role' => Role::Admin->value,
            'subscription_status' => 'active',
        ]);
    }

    /** Katman 1: veritabani sutunu. */
    public function test_the_role_column_accepts_every_role_the_enum_defines(): void
    {
        foreach (Role::cases() as $rol) {
            $kullanici = User::factory()->create(['role' => $rol->value]);

            $this->assertSame(
                $rol->value,
                DB::table('users')->where('id', $kullanici->id)->value('role'),
                "'{$rol->value}' rolu veritabanina yazilamadi."
            );
        }
    }

    /** Katman 2: yonetici paneli dogrulamasi - olusturma. */
    public function test_an_admin_can_create_a_coach(): void
    {
        $this->actingAs($this->yonetici())
            ->post(route('admin.users.store'), [
                'name' => 'Koç Ayşe',
                'email' => 'koc@kralkafe.test',
                'password' => 'Parola-123!',
                'password_confirmation' => 'Parola-123!',
                'role' => Role::Coach->value,
            ])
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('admin.users.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'koc@kralkafe.test',
            'role' => Role::Coach->value,
        ]);
    }

    /** Katman 2: yonetici paneli dogrulamasi - guncelleme. */
    public function test_an_admin_can_change_a_users_role(): void
    {
        $kullanici = User::factory()->create(['role' => Role::Student->value]);

        $this->actingAs($this->yonetici())
            ->put(route('admin.users.update', $kullanici), [
                'name' => $kullanici->name,
                'email' => $kullanici->email,
                'role' => Role::Parent->value,
                'subscription_status' => 'active',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(Role::Parent, $kullanici->fresh()->role());
    }

    public function test_an_unknown_role_is_refused(): void
    {
        $this->actingAs($this->yonetici())
            ->post(route('admin.users.store'), [
                'name' => 'Sahte',
                'email' => 'sahte@kralkafe.test',
                'password' => 'Parola-123!',
                'password_confirmation' => 'Parola-123!',
                'role' => 'superadmin',
            ])
            ->assertSessionHasErrors('role');

        $this->assertDatabaseMissing('users', ['email' => 'sahte@kralkafe.test']);
    }

    /** Katman 3: formlar. Dogrulama genisletilip form unutulursa rol secilemez. */
    public function test_the_user_forms_offer_every_role(): void
    {
        $yonetici = $this->yonetici();

        foreach ([route('admin.users.create'), route('admin.users.edit', $yonetici)] as $adres) {
            $yanit = $this->actingAs($yonetici)->get($adres);
            $yanit->assertOk();

            foreach (Role::cases() as $rol) {
                $yanit->assertSee('value="' . $rol->value . '"', false);
                $yanit->assertSee($rol->label());
            }
        }
    }

    /**
     * Giris sonrasi yonlendirme rolu tanimali ve TEK bir yerde tanimlanmali.
     *
     * Ayni karar hem routes/web.php'deki '/' hem de AuthenticatedSessionController
     * icinde kopyalanmisti. Ikisi ayrisirsa kullanici girise basinca bir yere,
     * ana sayfaya girince baska yere gider.
     */
    public function test_login_and_home_agree_on_where_each_role_lands(): void
    {
        foreach (Role::cases() as $rol) {
            $kullanici = User::factory()->create([
                'role' => $rol->value,
                'password' => bcrypt('Parola-123!'),
                'subscription_status' => 'active',
            ]);

            // Beklenti BILEREK elle yaziliyor: Role::homeRoute()'u cagirsaydi
            // test kendi kendini dogrular ve iki yerin ayrismasini yakalayamazdi.
            $beklenen = route(match ($rol) {
                Role::Admin => 'admin.dashboard',
                Role::Parent => 'parent.dashboard',
                Role::Coach => 'coach.plan.index',
                default => 'user.dashboard',
            });

            $this->post(route('login'), [
                'kimlik' => $kullanici->email,
                'password' => 'Parola-123!',
            ])->assertRedirect($beklenen);

            $this->actingAs($kullanici)->get('/')->assertRedirect($beklenen);

            $this->post(route('logout'));
        }
    }

    /** Hata mesaji Turkce olmali; 'role' niteligi ceviri dosyasinda eksikti. */
    public function test_the_role_validation_message_is_turkish(): void
    {
        $this->actingAs($this->yonetici())
            ->post(route('admin.users.store'), [
                'name' => 'Sahte',
                'email' => 'sahte@kralkafe.test',
                'password' => 'Parola-123!',
                'password_confirmation' => 'Parola-123!',
                'role' => 'superadmin',
            ])
            ->assertSessionHasErrors(['role' => 'Seçilen rol geçersiz.']);
    }
}
