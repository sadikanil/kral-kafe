<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * E-posta kimligi tek bicimde: kirpilmis, kucuk harf (QA bug 2).
 *
 * Giris aramayi kucuk harfle yapiyor, '=' ise Postgres'te de SQLite'ta da
 * harf duyarli. Buyuk harfle kaydedilen adres ne girise ne sifre sifirlamaya
 * eslesiyordu.
 */
class EmailIdentityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_email_is_stored_trimmed_and_lower_cased_on_create_and_update(): void
    {
        $kullanici = User::factory()->create(['email' => '  Gulsen.Koc@KralKafe.com ']);
        $this->assertSame('gulsen.koc@kralkafe.com', $kullanici->fresh()->email);

        $kullanici->update(['email' => 'YENI@KralKafe.com']);
        $this->assertSame('yeni@kralkafe.com', $kullanici->fresh()->email);

        // Telefonla eklenen kullanicinin e-postasi yok; null null kalmali.
        $kullanici->update(['email' => null]);
        $this->assertNull($kullanici->fresh()->email);
    }

    public function test_a_reset_link_can_be_requested_with_capitals_in_the_email(): void
    {
        Notification::fake();
        $koc = User::factory()->create(['email' => 'koc@kralkafe.com']);

        $this->post(route('password.email'), ['email' => 'Koc@KralKafe.com'])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($koc, ResetPassword::class);
    }

    public function test_a_reset_with_capitals_in_the_email_sets_the_new_password(): void
    {
        Notification::fake();
        $koc = User::factory()->create(['email' => 'koc@kralkafe.com']);

        $this->post(route('password.email'), ['email' => 'koc@kralkafe.com']);

        $belirtec = null;
        Notification::assertSentTo($koc, ResetPassword::class, function (ResetPassword $n) use (&$belirtec) {
            $belirtec = $n->token;

            return true;
        });

        $this->post(route('password.store'), [
            'token' => $belirtec,
            'email' => ' KOC@kralkafe.com',
            'password' => 'Yeni-Sifre-2026',
            'password_confirmation' => 'Yeni-Sifre-2026',
        ])->assertSessionHasNoErrors()->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('Yeni-Sifre-2026', $koc->fresh()->password));
    }

    /**
     * Kucuk harfe cevirme yalnizca metne uygulanir. Elle hazirlanmis istek
     * (email[]=...) trim'e dizi verip 500 donduruyordu; dogrulama hatasi
     * olarak geri donmeli.
     */
    public function test_an_array_email_is_a_validation_error_not_a_crash(): void
    {
        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => ['koc@kralkafe.com']])
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors('email');

        $this->from(route('password.reset', 'x'))
            ->post(route('password.store'), [
                'token' => 'x',
                'email' => ['koc@kralkafe.com'],
                'password' => 'Yeni-Sifre-2026',
                'password_confirmation' => 'Yeni-Sifre-2026',
            ])
            ->assertRedirect(route('password.reset', 'x'))
            ->assertSessionHasErrors('email');
    }

    /**
     * Onceden buyuk harfle kaydedilmis hesaplar migration'la duzelir. Kucuk
     * harfli hali baska bir satirda zaten varsa ikisine de dokunulmaz: tekil
     * indeks patlardi, hangisinin dogru hesap oldugunu yonetici bilir.
     */
    public function test_the_migration_lower_cases_existing_emails_but_leaves_collisions_alone(): void
    {
        $yaz = fn (string $email) => DB::table('users')->insertGetId([
            'name' => $email, 'email' => $email, 'role' => 'coach',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $buyuk = $yaz('Gulsen.Koc@KralKafe.com');
        $zatenKucuk = $yaz('ali@kralkafe.com');
        $cakisanKucuk = $yaz('dup@kralkafe.com');
        $cakisanBuyuk = $yaz('DUP@kralkafe.com');
        $ikizA = $yaz('Ikiz@kralkafe.com');
        $ikizB = $yaz('IKIZ@kralkafe.com');

        (require database_path('migrations/2026_09_23_201500_lower_case_user_emails.php'))->up();

        $email = fn (int $id) => DB::table('users')->where('id', $id)->value('email');
        $this->assertSame('gulsen.koc@kralkafe.com', $email($buyuk));
        $this->assertSame('ali@kralkafe.com', $email($zatenKucuk));
        $this->assertSame('dup@kralkafe.com', $email($cakisanKucuk));
        $this->assertSame('DUP@kralkafe.com', $email($cakisanBuyuk));
        $this->assertSame('Ikiz@kralkafe.com', $email($ikizA));
        $this->assertSame('IKIZ@kralkafe.com', $email($ikizB));
    }
}
