<?php

namespace Tests\Feature\Smoke;

use App\Enums\ApprovalStatus;
use App\Enums\NotificationType;
use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\ExamEvent;
use App\Models\Notification as Bildirim;
use App\Models\Package;
use App\Models\StudentParent;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Smoke / QA: giris, cikis, sifre akislari, ana sayfa yonlendirmesi,
 * bildirimler sayfasi ve gunluk cron ucu - uctan uca.
 *
 * Kapsanan rotalar: home, login (GET/POST), login.set-password, logout,
 * password.request, password.email, password.reset, password.store,
 * notifications.index, user.history, cron.daily.
 */
class AuthShellSmokeTest extends TestCase
{
    use RefreshDatabase;

    private const SIFRE = 'Parola-123';

    protected function setUp(): void
    {
        parent::setUp();

        // Sali 14:00 yerel: kafe acik, SettleStaleSessions hicbir seyi kapatmaz.
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
    }

    // --- Yardimcilar ------------------------------------------------------

    private function kisi(Role $rol, array $ek = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $rol->value,
            'subscription_status' => 'active',
            'password' => Hash::make(self::SIFRE),
        ], $ek));
    }

    private function ogrenci(array $ek = []): User
    {
        return User::factory()->student()->withPackage(Package::factory()->tier1())->create(array_merge([
            'name' => 'Ayşe Yılmaz',
            'phone' => '5321234567',
            'password' => Hash::make(self::SIFRE),
        ], $ek));
    }

    private function veliBagla(User $ogrenci, array $ek = []): User
    {
        $veli = $this->kisi(Role::Parent, array_merge(['name' => 'Şükrü Yılmaz', 'phone' => '5339876543'], $ek));
        StudentParent::factory()->create(['student_id' => $ogrenci->id, 'parent_id' => $veli->id]);

        return $veli;
    }

    private function bildirim(User $kime, array $ek = []): Bildirim
    {
        static $n = 0;

        return Bildirim::create(array_merge([
            'type' => NotificationType::Absence->value,
            'user_id' => $kime->id,
            'unique_key' => 'smoke:' . (++$n),
            'title' => 'Bildirim ' . $n,
        ], $ek));
    }

    private function oturum(User $ogrenci, string $gun): StudySession
    {
        $bas = Carbon::parse($gun . ' 10:00', config('kafe.timezone'));

        return StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa ' . uniqid()])->id,
            'started_at' => $bas->copy()->utc(),
            'ended_at' => $bas->copy()->addHours(3)->utc(),
            'duration_minutes' => 180,
            'end_reason' => SessionEndReason::Manual->value,
            'approval_status' => ApprovalStatus::Approved->value,
        ]);
    }

    /** Iki adimli giris: once kimlik, sonra sifre. Ikinci yaniti dondurur. */
    private function girisYap(string $kimlik, string $sifre = self::SIFRE)
    {
        $this->post(route('login'), ['kimlik' => $kimlik])
            ->assertRedirect(route('login'))
            ->assertSessionHas('giris_adimi', 'sifre');

        return $this->post(route('login'), ['kimlik' => $kimlik, 'adim' => 'sifre', 'password' => $sifre, 'remember' => 'on']);
    }

    // --- Ana sayfa (Closure) -----------------------------------------------

    public function test_home_sends_a_guest_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_home_sends_every_role_to_a_landing_page_that_opens(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->kisi(Role::Coach, ['name' => 'Koç Gülşen']);
        $koc->coachStudents()->attach($ogrenci->id);

        $beklenen = [
            'admin.dashboard' => $this->kisi(Role::Admin),
            'coach.plan.index' => $koc,
            'parent.dashboard' => $this->veliBagla($ogrenci),
            'user.dashboard' => $ogrenci,
        ];

        foreach ($beklenen as $rota => $kisi) {
            $this->actingAs($kisi)->get('/')->assertRedirect(route($rota));
            $this->actingAs($kisi)->get(route($rota))->assertOk()->assertSee($kisi->name);
        }
    }

    /** Ogretmen/gorevlinin ayri paneli yok; ogrenci paneline iner ve acilmali. */
    public function test_teacher_and_staff_land_on_a_page_that_opens(): void
    {
        foreach ([Role::Teacher, Role::Staff] as $rol) {
            $kisi = $this->kisi($rol, ['name' => 'Görevli ' . $rol->value]);

            $this->actingAs($kisi)->get('/')->assertRedirect(route('user.dashboard'));
            $this->actingAs($kisi)->get(route('user.dashboard'))->assertOk();
        }
    }

    // --- Giris sayfasi -----------------------------------------------------

    public function test_the_login_page_opens_for_a_guest_and_asks_for_the_phone(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Telefon numarası')
            ->assertSee('name="kimlik"', false)
            ->assertSee('action="' . route('login') . '"', false)
            ->assertDontSee('name="password"', false);
    }

    public function test_a_signed_in_user_opening_the_login_page_is_sent_home(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($ogrenci)->get(route('login'))->assertRedirect(route('home'));
        $this->actingAs($ogrenci)->get(route('home'))->assertRedirect(route('user.dashboard'));
    }

    public function test_the_identity_step_requires_input(): void
    {
        $this->from(route('login'))
            ->post(route('login'), ['kimlik' => ''])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['kimlik' => 'Telefon numaranızı girin.']);
    }

    // --- Telefonla giris ---------------------------------------------------

    /** Yonetici ve ogrenci numarayi farkli yaziyor; hepsi ayni hesaba gitmeli. */
    public function test_every_common_way_of_writing_the_phone_is_recognised(): void
    {
        $this->ogrenci();

        foreach (['0532 123 45 67', '05321234567', '5321234567', '+90 532 123 45 67', '0 (532) 123-45-67', '905321234567', '0090 532 123 4567', '532.123.45.67'] as $yazim) {
            $this->post(route('login'), ['kimlik' => $yazim])
                ->assertRedirect(route('login'))
                ->assertSessionHas('giris_adimi', 'sifre')
                ->assertSessionHas('giris_kimlik', '5321234567');
        }
    }

    public function test_a_student_signs_in_with_the_phone_end_to_end(): void
    {
        $ogrenci = $this->ogrenci();

        $this->post(route('login'), ['kimlik' => '0532 123 45 67'])->assertRedirect(route('login'));

        // Ikinci adim sayfasi: sifre alani ve bicimli numara.
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('name="password"', false)
            ->assertSee('0532 123 45 67')
            ->assertSee('Değiştir');

        $this->post(route('login'), ['kimlik' => '5321234567', 'adim' => 'sifre', 'password' => self::SIFRE, 'remember' => 'on'])
            ->assertRedirect(route('user.dashboard'))
            ->assertCookie(Auth::guard('web')->getRecallerName());

        $this->assertAuthenticatedAs($ogrenci);

        $this->get(route('user.dashboard'))->assertOk()->assertSee('Ayşe');
    }

    public function test_without_remember_me_no_long_lived_cookie_is_set(): void
    {
        $this->ogrenci();

        $this->post(route('login'), ['kimlik' => '5321234567', 'adim' => 'sifre', 'password' => self::SIFRE])
            ->assertRedirect(route('user.dashboard'))
            ->assertCookieMissing(Auth::guard('web')->getRecallerName());
    }

    public function test_each_role_lands_on_its_own_home_after_signing_in(): void
    {
        $ogrenci = $this->ogrenci();
        $veli = $this->veliBagla($ogrenci);
        $koc = $this->kisi(Role::Coach, ['phone' => '5441112233']);
        $this->kisi(Role::Admin, ['email' => 'admin@kralkafe.com', 'phone' => null]);

        $this->girisYap('admin@kralkafe.com')->assertRedirect(route('admin.dashboard'));
        $this->post(route('logout'));

        $this->girisYap('0544 111 22 33')->assertRedirect(route('coach.plan.index'));
        $this->assertAuthenticatedAs($koc);
        $this->post(route('logout'));

        $this->girisYap('0533 987 65 43')->assertRedirect(route('parent.dashboard'));
        $this->assertAuthenticatedAs($veli);
    }

    /** Veli aboneligi yok; pasif isaretli veli yine paneline girebilmeli. */
    public function test_a_parent_marked_passive_still_signs_in_to_the_parent_panel(): void
    {
        $veli = $this->veliBagla($this->ogrenci(), ['subscription_status' => 'suspended']);

        $this->girisYap('05339876543')->assertRedirect(route('parent.dashboard'));
        $this->get(route('parent.dashboard'))->assertOk()->assertSee('Ayşe Yılmaz');
        $this->assertAuthenticatedAs($veli);
    }

    public function test_a_wrong_password_stays_on_the_password_step_with_a_turkish_error(): void
    {
        $this->ogrenci();

        $this->from(route('login'))
            ->post(route('login'), ['kimlik' => '5321234567', 'adim' => 'sifre', 'password' => 'yanlış-şifre'])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['kimlik' => 'Girdiğiniz bilgiler kayıtlarımızla eşleşmiyor.']);

        $this->assertGuest();

        $this->get(route('login'))
            ->assertSee('name="password"', false)
            ->assertSee('Girdiğiniz bilgiler kayıtlarımızla eşleşmiyor.');
    }

    public function test_login_locks_after_five_wrong_passwords(): void
    {
        $this->ogrenci();

        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login'), ['kimlik' => '5321234567', 'adim' => 'sifre', 'password' => 'yanlis' . $i]);
        }

        // Dogru sifre bile kilit suresince gecmez.
        $this->from(route('login'))
            ->post(route('login'), ['kimlik' => '5321234567', 'adim' => 'sifre', 'password' => self::SIFRE])
            ->assertSessionHasErrors('kimlik');

        $this->assertGuest();
        $this->assertStringContainsString('Çok fazla giriş denemesi', session('errors')->first('kimlik'));
    }

    /**
     * Kilit anahtari ham girdiden kuruluyor (LoginRequest::throttleKey), ama
     * kimlik normalize edilerek araniyor. Ayni numarayi her seferinde baska
     * bosluklarla yazan biri kilide hic takilmadan sinirsiz sifre dener.
     */
    public function test_the_lockout_cannot_be_bypassed_by_writing_the_phone_differently(): void
    {
        $this->ogrenci();

        for ($i = 1; $i <= 12; $i++) {
            // 0532 1234567, 0532  1234567, 0532   1234567 ... hepsi ayni numara
            $yazim = '0532' . str_repeat(' ', $i) . '1234567';
            $this->post(route('login'), ['kimlik' => $yazim, 'adim' => 'sifre', 'password' => 'tahmin' . $i]);
        }

        $this->from(route('login'))
            ->post(route('login'), ['kimlik' => '05321234567', 'adim' => 'sifre', 'password' => self::SIFRE])
            ->assertSessionHasErrors('kimlik');

        $this->assertGuest();
        $this->assertStringContainsString('Çok fazla giriş denemesi', session('errors')->first('kimlik'));
    }

    /** QR'i girissiz okutan ogrenci giristen sonra ayni masaya donmeli. */
    public function test_a_table_qr_scanned_while_signed_out_returns_to_the_table_after_login(): void
    {
        $this->ogrenci();
        $masa = StudyTable::create(['name' => 'Pencere Önü 3']);

        $this->get(route('table.scan', $masa->qr_code))->assertRedirect(route('login'));

        $this->girisYap('0532 123 45 67')->assertRedirect(route('table.scan', $masa->qr_code));

        $this->get(route('table.scan', $masa->qr_code))->assertOk()->assertSee('Pencere Önü 3');
    }

    // --- E-postayla giris ---------------------------------------------------

    public function test_an_email_typed_with_capitals_and_spaces_still_finds_the_account(): void
    {
        $yonetici = $this->kisi(Role::Admin, ['email' => 'admin@kralkafe.com', 'phone' => null]);

        $this->girisYap('  Admin@KralKafe.com ')->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($yonetici);
    }

    /**
     * Yonetici e-postayi buyuk harfle kaydederse (yalnizca e-postali koc,
     * yonetici) LoginRequest aramayi kucuk harfle yaptigi icin kullanici
     * yazdigi adresle HIC giremez. Postgres'te '=' buyuk/kucuk harf duyarli.
     */
    public function test_a_user_saved_with_capitals_in_the_email_can_sign_in_with_it(): void
    {
        $this->actingAs($this->kisi(Role::Admin))
            ->post(route('admin.users.store'), [
                'name' => 'Gülşen Koç',
                'email' => 'Gulsen.Koc@KralKafe.com',
                'password' => 'Koc-Sifre-2026',
                'password_confirmation' => 'Koc-Sifre-2026',
                'role' => Role::Coach->value,
            ])
            ->assertSessionHasNoErrors();
        $this->post(route('logout'));

        $this->post(route('login'), ['kimlik' => 'Gulsen.Koc@KralKafe.com'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('giris_adimi', 'sifre');

        $this->post(route('login'), ['kimlik' => 'Gulsen.Koc@KralKafe.com', 'adim' => 'sifre', 'password' => 'Koc-Sifre-2026'])
            ->assertRedirect(route('coach.plan.index'));
    }

    // --- Ilk giris: sifre belirleme ------------------------------------------

    public function test_a_new_parent_sets_a_turkish_password_and_lands_on_the_parent_panel(): void
    {
        $veli = $this->veliBagla($this->ogrenci(), ['password' => null]);

        $this->post(route('login'), ['kimlik' => '0533 987 65 43'])
            ->assertSessionHas('giris_adimi', 'belirle');

        $this->get(route('login'))
            ->assertOk()
            ->assertSee('İlk girişiniz')
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('action="' . route('login.set-password') . '"', false);

        $this->post(route('login.set-password'), [
            'kimlik' => '5339876543',
            'adim' => 'belirle',
            'password' => 'Şifrem-ığüöç',
            'password_confirmation' => 'Şifrem-ığüöç',
        ])->assertRedirect(route('parent.dashboard'));

        $this->assertAuthenticatedAs($veli);
        $this->assertTrue(Hash::check('Şifrem-ığüöç', $veli->fresh()->password));

        // Cikip ayni Turkce sifreyle normal giris.
        $this->post(route('logout'));
        $this->girisYap('05339876543', 'Şifrem-ığüöç')->assertRedirect(route('parent.dashboard'));
    }

    /** Yalnizca e-postasi olan, sifresiz eklenmis koc: ilk giris e-postayla. */
    public function test_an_email_only_coach_without_a_password_sets_one_by_email(): void
    {
        $koc = $this->kisi(Role::Coach, ['email' => 'yeni.koc@kralkafe.com', 'phone' => null, 'password' => null]);

        $this->post(route('login'), ['kimlik' => 'yeni.koc@kralkafe.com'])
            ->assertSessionHas('giris_adimi', 'belirle')
            ->assertSessionHas('giris_kimlik', 'yeni.koc@kralkafe.com');

        $this->get(route('login'))->assertOk()->assertSee('yeni.koc@kralkafe.com');

        $this->post(route('login.set-password'), [
            'kimlik' => 'yeni.koc@kralkafe.com',
            'adim' => 'belirle',
            'password' => 'koc-sifre',
            'password_confirmation' => 'koc-sifre',
        ])->assertRedirect(route('coach.plan.index'));

        $this->assertAuthenticatedAs($koc);
        $this->get(route('coach.plan.index'))->assertOk();
    }

    public function test_a_too_short_new_password_stays_on_the_set_password_step(): void
    {
        $this->ogrenci(['password' => null]);

        $this->from(route('login'))
            ->post(route('login.set-password'), [
                'kimlik' => '5321234567',
                'adim' => 'belirle',
                'password' => '12345',
                'password_confirmation' => '12345',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['password' => 'Şifre en az 6 karakter olmalı.']);

        $this->assertGuest();

        $this->get(route('login'))
            ->assertSee('name="password_confirmation"', false)
            ->assertSee('Şifre en az 6 karakter olmalı.');
    }

    public function test_set_password_cannot_take_over_an_account_that_has_one(): void
    {
        $koc = $this->kisi(Role::Coach, ['phone' => '5441112233']);

        $this->from(route('login'))
            ->post(route('login.set-password'), [
                'kimlik' => '05441112233',
                'password' => 'ele-gecir',
                'password_confirmation' => 'ele-gecir',
            ])
            ->assertSessionHasErrors(['kimlik' => 'Bu hesabın şifresi zaten var. Şifreyle giriş yapın.']);

        $this->assertGuest();
        $this->assertTrue(Hash::check(self::SIFRE, $koc->fresh()->password));
    }

    /** Telefonda cift dokunus: ikinci gonderim hata vermeden eve gitmeli. */
    public function test_a_double_submitted_set_password_does_not_error_or_change_the_password(): void
    {
        $ogrenci = $this->ogrenci(['password' => null]);
        $veri = ['kimlik' => '5321234567', 'password' => 'ilk-sifre', 'password_confirmation' => 'ilk-sifre'];

        $this->post(route('login.set-password'), $veri)->assertRedirect(route('user.dashboard'));
        $ikinci = $this->post(route('login.set-password'), array_merge($veri, ['password' => 'ikinci', 'password_confirmation' => 'ikinci']));

        $this->assertTrue($ikinci->isRedirect());
        $this->assertTrue(Hash::check('ilk-sifre', $ogrenci->fresh()->password));
        $this->assertAuthenticatedAs($ogrenci);
    }

    // --- Cikis ------------------------------------------------------------------

    public function test_logout_ends_the_session_and_leads_back_to_login(): void
    {
        $this->actingAs($this->ogrenci())
            ->post(route('logout'))
            ->assertRedirect('/');

        $this->assertGuest();
        $this->get('/')->assertRedirect(route('login'));
        $this->get(route('user.dashboard'))->assertRedirect(route('login'));
    }

    public function test_logout_as_a_guest_goes_to_login(): void
    {
        $this->post(route('logout'))->assertRedirect(route('login'));
    }

    public function test_every_shell_page_offers_the_logout_form(): void
    {
        $ogrenci = $this->ogrenci();

        foreach ([$ogrenci, $this->veliBagla($ogrenci), $this->kisi(Role::Admin), $this->kisi(Role::Coach)] as $kisi) {
            $this->actingAs($kisi)->get(route($kisi->homeRoute()))
                ->assertOk()
                ->assertSee('action="' . route('logout') . '"', false);
        }
    }

    // --- Sifresiz oturum kurali (EndPasswordlessSession) ----------------------

    public function test_a_password_reset_by_the_admin_ends_the_open_session_on_the_next_request(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($this->kisi(Role::Admin))
            ->post(route('admin.users.reset-password', $ogrenci))
            ->assertSessionHas('success');

        $this->actingAs($ogrenci->fresh())
            ->get(route('notifications.index'))
            ->assertRedirect(route('login'));

        $this->assertGuest();

        // Ardindan giris "sifre belirle" adimini acar.
        $this->post(route('login'), ['kimlik' => '0532 123 45 67'])->assertSessionHas('giris_adimi', 'belirle');
    }

    public function test_a_passwordless_session_is_ended_for_every_role_and_route_kind(): void
    {
        foreach ([Role::Admin, Role::Coach, Role::Parent] as $rol) {
            $kisi = $this->kisi($rol, ['password' => null]);

            $this->actingAs($kisi)->get(route($kisi->homeRoute()))->assertRedirect(route('login'));
            $this->assertGuest();
        }

        // Yazma istegi de engellenir (ornegin bildirim sayfasi yerine cikis).
        $this->actingAs($this->kisi(Role::Student, ['password' => null]))
            ->post(route('logout'))
            ->assertRedirect(route('login'));
        $this->assertGuest();
    }

    // --- Sifremi unuttum (e-posta) --------------------------------------------

    public function test_the_forgot_password_page_opens_and_links_back_to_login(): void
    {
        $this->get(route('password.request'))
            ->assertOk()
            ->assertSee('Şifremi Unuttum')
            ->assertSee('action="' . route('password.email') . '"', false)
            ->assertSee(route('login'));
    }

    public function test_a_reset_link_is_sent_to_a_registered_email(): void
    {
        Notification::fake();
        $koc = $this->kisi(Role::Coach, ['email' => 'koc@kralkafe.com']);

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'koc@kralkafe.com'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'Şifre sıfırlama bağlantısı e-posta adresinize gönderildi.');

        Notification::assertSentTo($koc, ResetPassword::class);
    }

    public function test_an_unknown_email_is_reported_in_turkish_and_nothing_is_sent(): void
    {
        Notification::fake();

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'yok@kralkafe.com'])
            ->assertSessionHasErrors(['email' => 'Bu e-posta adresine sahip bir kullanıcı bulamadık.'])
            ->assertSessionHasInput('email', 'yok@kralkafe.com');

        Notification::assertNothingSent();
    }

    public function test_a_phone_number_in_the_email_field_is_refused_cleanly(): void
    {
        $this->ogrenci();

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => '0532 123 45 67'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors('email');
    }

    public function test_the_full_email_reset_flow_ends_with_a_working_login(): void
    {
        Notification::fake();
        $koc = $this->kisi(Role::Coach, ['email' => 'koc@kralkafe.com', 'phone' => null]);

        $this->post(route('password.email'), ['email' => 'koc@kralkafe.com']);

        $token = null;
        Notification::assertSentTo($koc, ResetPassword::class, function (ResetPassword $n) use (&$token) {
            $token = $n->token;

            return true;
        });

        $this->get(route('password.reset', ['token' => $token, 'email' => 'koc@kralkafe.com']))
            ->assertOk()
            ->assertSee('value="' . $token . '"', false)
            ->assertSee('value="koc@kralkafe.com"', false)
            ->assertSee('action="' . route('password.store') . '"', false);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => 'koc@kralkafe.com',
            'password' => 'Yeni-Şifre-2026',
            'password_confirmation' => 'Yeni-Şifre-2026',
        ])->assertRedirect(route('login'))->assertSessionHas('status', 'Şifreniz sıfırlandı.');

        $this->get(route('login'))->assertSee('Şifreniz sıfırlandı.');

        $this->girisYap('koc@kralkafe.com', 'Yeni-Şifre-2026')->assertRedirect(route('coach.plan.index'));
        $this->assertAuthenticatedAs($koc);

        // Ayni baglanti ikinci kez kullanilamaz.
        $this->post(route('logout'));
        $this->from(route('password.reset', $token))
            ->post(route('password.store'), [
                'token' => $token,
                'email' => 'koc@kralkafe.com',
                'password' => 'Ucuncu-Sifre-99',
                'password_confirmation' => 'Ucuncu-Sifre-99',
            ])
            ->assertSessionHasErrors(['email' => 'Bu şifre sıfırlama bağlantısı geçersiz veya süresi dolmuş.']);
        $this->assertTrue(Hash::check('Yeni-Şifre-2026', $koc->fresh()->password));
    }

    public function test_a_reset_with_a_bad_token_or_short_password_is_refused_in_turkish(): void
    {
        $koc = $this->kisi(Role::Coach, ['email' => 'koc@kralkafe.com']);

        $this->from(route('password.reset', 'sahte'))
            ->post(route('password.store'), [
                'token' => 'sahte',
                'email' => 'koc@kralkafe.com',
                'password' => 'Yeni-Sifre-2026',
                'password_confirmation' => 'Yeni-Sifre-2026',
            ])
            ->assertRedirect(route('password.reset', 'sahte'))
            ->assertSessionHasErrors(['email' => 'Bu şifre sıfırlama bağlantısı geçersiz veya süresi dolmuş.']);

        $this->from(route('password.reset', 'sahte'))
            ->post(route('password.store'), [
                'token' => 'sahte',
                'email' => 'koc@kralkafe.com',
                'password' => 'kısa',
                'password_confirmation' => 'kısa',
            ])
            ->assertSessionHasErrors('password');
        $this->assertStringContainsString('karakter', session('errors')->first('password'));

        $this->assertTrue(Hash::check(self::SIFRE, $koc->fresh()->password));
    }

    /** Uygulamanin tamami Turkce; sifirlama e-postasi Ingilizce gidiyor. */
    public function test_the_reset_email_is_written_in_turkish(): void
    {
        $koc = $this->kisi(Role::Coach, ['email' => 'koc@kralkafe.com']);
        $posta = (new ResetPassword('belirteç'))->toMail($koc);

        $this->assertStringNotContainsString('Reset Password', (string) $posta->subject);
        $this->assertStringNotContainsString('You are receiving this email', implode(' ', $posta->introLines));
    }

    public function test_the_reset_pages_are_for_guests_only(): void
    {
        $this->actingAs($this->ogrenci())->get(route('password.request'))->assertRedirect(route('home'));
        $this->actingAs($this->ogrenci(['phone' => '5550001122']))->get(route('password.reset', 'x'))->assertRedirect(route('home'));
    }

    // --- Bildirimler ---------------------------------------------------------------

    public function test_the_notifications_page_lists_own_items_newest_first_and_marks_them_read(): void
    {
        $ogrenci = $this->ogrenci();
        $eski = $this->bildirim($ogrenci, ['title' => 'Eski bildirim: Şubat', 'body' => 'Gövde ığüşöç']);
        $eski->forceFill(['created_at' => now()->subDays(2)])->save();
        $yeni = $this->bildirim($ogrenci, ['title' => 'Yarın deneme var: TYT Deneme 5', 'type' => NotificationType::ExamTomorrow->value]);
        $baskasi = $this->bildirim($this->kisi(Role::Student), ['title' => 'Başkasının bildirimi']);

        // Zilde okunmamis sayisi 2.
        $this->actingAs($ogrenci)->get(route('user.dashboard'))->assertSee('notif-count">2<', false);

        $this->actingAs($ogrenci)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Bildirimler')
            ->assertSeeInOrder(['Yarın deneme var: TYT Deneme 5', 'Eski bildirim: Şubat'])
            ->assertSee('Gövde ığüşöç')
            ->assertSee('Yeni')
            ->assertDontSee('Başkasının bildirimi')
            // Sayfa acilinca sayac sifirlanir.
            ->assertDontSee('notif-count', false);

        $this->assertNotNull($eski->fresh()->read_at);
        $this->assertNotNull($yeni->fresh()->read_at);
        $this->assertNull($baskasi->fresh()->read_at);

        // Ikinci ziyarette "Yeni" rozeti kalkar.
        $this->actingAs($ogrenci)->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('badge badge-primary">Yeni', false);
    }

    public function test_the_notifications_page_opens_empty_for_every_role(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->kisi(Role::Coach);
        $koc->coachStudents()->attach($ogrenci->id);

        $kisiler = [
            $ogrenci,
            $this->ogrenci(['phone' => '5550001122', 'subscription_status' => 'suspended']),
            $this->veliBagla($ogrenci),
            $koc,
            $this->kisi(Role::Admin),
            $this->kisi(Role::Teacher),
            $this->kisi(Role::Staff),
        ];

        foreach ($kisiler as $kisi) {
            $this->actingAs($kisi)->get(route('notifications.index'))
                ->assertOk()
                ->assertSee('Henüz bildirim yok.');
        }
    }

    /** Gece yarisindan sonra olusan bildirim kafe gununde gosterilmeli. */
    public function test_notification_times_are_shown_in_cafe_time_across_midnight(): void
    {
        $ogrenci = $this->ogrenci();
        $b = $this->bildirim($ogrenci, ['title' => 'Gece bildirimi']);
        $b->forceFill(['created_at' => Carbon::parse('2026-09-28 22:30:00', 'UTC')])->save();

        $this->actingAs($ogrenci)->get(route('notifications.index'))
            ->assertSee('29.09.2026 01:30');
    }

    public function test_a_guest_cannot_open_notifications(): void
    {
        $this->get(route('notifications.index'))->assertRedirect(route('login'));
    }

    public function test_a_long_notification_list_is_capped_and_all_marked_read(): void
    {
        $ogrenci = $this->ogrenci();
        for ($i = 1; $i <= 55; $i++) {
            $this->bildirim($ogrenci, ['title' => "Liste ögesi #{$i}#"])
                ->forceFill(['created_at' => now()->subMinutes(100 - $i)])->save();
        }

        $yanit = $this->actingAs($ogrenci)->get(route('notifications.index'))->assertOk();

        // Sayfa kartlari; zil paneli ayrica son 6'yi gosteriyor (notif-item).
        $this->assertSame(50, substr_count($yanit->getContent(), 'class="session-card mb-2"'));
        $yanit->assertSee('Liste ögesi #55#')->assertDontSee('Liste ögesi #5#');
        $this->assertSame(0, Bildirim::for($ogrenci)->whereNull('read_at')->count());
    }

    // --- Eski yer imi: /kullanici/gecmis --------------------------------------

    public function test_the_old_history_bookmark_goes_to_payments(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($ogrenci)->get(route('user.history'))->assertRedirect('/kullanici/odemeler');
        $this->actingAs($ogrenci)->get('/kullanici/odemeler')->assertOk();
    }

    public function test_the_old_history_bookmark_requires_login(): void
    {
        $this->get('/kullanici/gecmis')->assertRedirect(route('login'));
    }

    // --- Cron ucu ---------------------------------------------------------------

    public function test_the_cron_endpoint_refuses_every_request_without_the_right_bearer(): void
    {
        config(['kafe.cron_anahtari' => 'gizli-anahtar']);

        $this->get(route('cron.daily'))->assertForbidden();
        $this->withToken('yanlis')->get(route('cron.daily'))->assertForbidden();
        $this->get(route('cron.daily') . '?token=gizli-anahtar')->assertForbidden();
        $this->withHeader('Authorization', 'Basic ' . base64_encode('gizli-anahtar'))->get(route('cron.daily'))->assertForbidden();
        // Giris yapmis yonetici de anahtarsiz calistiramaz.
        $this->actingAs($this->kisi(Role::Admin))->get(route('cron.daily'))->assertForbidden();

        $this->assertSame(0, Bildirim::count());
    }

    public function test_the_cron_endpoint_is_closed_when_no_secret_is_configured(): void
    {
        config(['kafe.cron_anahtari' => '']);

        $this->withToken('')->get(route('cron.daily'))->assertForbidden();
        $this->withToken('herhangi')->get(route('cron.daily'))->assertForbidden();
    }

    /**
     * Vercel cron'u 20:00 UTC = 23:00 Istanbul. Uc bildirim turu uretilir,
     * ikinci calisma hic kopya uretmez ve veli bildirimleri sayfasinda gorur.
     */
    public function test_the_daily_cron_produces_all_notices_once_and_parents_see_them(): void
    {
        config(['kafe.cron_anahtari' => 'gizli-anahtar']);
        $this->travelTo(Carbon::parse('2026-09-29 20:00', 'UTC'));

        $ogrenci = $this->ogrenci();
        $veli = $this->veliBagla($ogrenci);
        $yonetici = $this->kisi(Role::Admin);
        $this->oturum($ogrenci, '2026-09-28'); // pazartesi geldi, bugun (sali) gelmedi

        $deneme = ExamEvent::create(['title' => 'TYT Deneme 5', 'exam_type' => 'tyt', 'exam_date' => '2026-09-30', 'starts_at' => '10:00']);
        // Serbest deneme bir gune bagli degil; hatirlatma uretmemeli.
        ExamEvent::create(['title' => 'Serbest AYT', 'exam_type' => 'ayt', 'exam_date' => '2026-09-30', 'available_until' => '2026-10-15', 'is_flexible' => true]);

        $this->withToken('gizli-anahtar')->get(route('cron.daily'))
            ->assertOk()
            ->assertExactJson([
                'gun' => '2026-09-29',
                'devamsizlik' => 1,
                'deneme_hatirlatmasi' => 2,
                'stok_sayimi' => 1,
            ]);

        $this->assertDatabaseHas('notifications', ['user_id' => $veli->id, 'type' => NotificationType::Absence->value, 'student_id' => $ogrenci->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $ogrenci->id, 'type' => NotificationType::ExamTomorrow->value, 'related_id' => $deneme->id]);
        $this->assertDatabaseHas('notifications', ['user_id' => $veli->id, 'type' => NotificationType::ExamTomorrow->value]);
        $this->assertDatabaseHas('notifications', ['user_id' => $yonetici->id, 'type' => NotificationType::StockCount->value]);
        $this->assertSame(4, Bildirim::count());

        // Vercel yeniden dener ya da elle tetiklenir: kopya yok.
        $this->withToken('gizli-anahtar')->get(route('cron.daily'))
            ->assertOk()
            ->assertJson(['devamsizlik' => 0, 'deneme_hatirlatmasi' => 0, 'stok_sayimi' => 0]);
        $this->assertSame(4, Bildirim::count());

        $this->actingAs($veli)->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Ayşe Yılmaz bugün kafeye gelmedi')
            ->assertSee('29 Eylül 2026 tarihinde çalışma oturumu açılmadı.')
            ->assertSee('Yarın deneme var: TYT Deneme 5')
            ->assertSee('Başlangıç saati 10:00.');
    }

    /** Cron kaymasi (Hobby: ±59 dk) en gec 20:59 UTC; hala ayni kafe gunu. */
    public function test_the_cron_day_is_the_cafe_day_even_late_in_the_drift_window(): void
    {
        config(['kafe.cron_anahtari' => 'gizli-anahtar']);
        $this->travelTo(Carbon::parse('2026-09-29 20:59', 'UTC'));

        $this->withToken('gizli-anahtar')->get(route('cron.daily'))
            ->assertOk()
            ->assertJson(['gun' => '2026-09-29']);
    }

    /**
     * Iki kardes ayni veliye bagli ve ikisi de bugun gelmedi. Bildirim
     * anahtari "absence:{veli}:{gun}" - ogrenci yok - bu yuzden ikinci
     * cocugun devamsizligi sessizce yutuluyor.
     */
    public function test_a_parent_with_two_children_is_told_about_each_absent_child(): void
    {
        config(['kafe.cron_anahtari' => 'gizli-anahtar']);
        $this->travelTo(Carbon::parse('2026-09-29 20:00', 'UTC'));

        $abla = $this->ogrenci();
        $kardes = $this->ogrenci(['name' => 'Emre Yılmaz', 'phone' => '5550001122']);
        $veli = $this->veliBagla($abla);
        StudentParent::factory()->create(['student_id' => $kardes->id, 'parent_id' => $veli->id]);
        $this->oturum($abla, '2026-09-28');
        $this->oturum($kardes, '2026-09-28');

        $this->withToken('gizli-anahtar')->get(route('cron.daily'))
            ->assertOk()
            ->assertJson(['devamsizlik' => 2]);

        $this->assertDatabaseHas('notifications', ['user_id' => $veli->id, 'student_id' => $abla->id, 'type' => NotificationType::Absence->value]);
        $this->assertDatabaseHas('notifications', ['user_id' => $veli->id, 'student_id' => $kardes->id, 'type' => NotificationType::Absence->value]);

        $this->actingAs($veli)->get(route('notifications.index'))
            ->assertSee('Ayşe Yılmaz bugün kafeye gelmedi')
            ->assertSee('Emre Yılmaz bugün kafeye gelmedi');
    }

    /**
     * Uygulama resmi sinavi (YKS) bilerek "deneme" diye anmiyor
     * (ExamType::isPractice). Gece hatirlatmasi ise "Yarın deneme var: YKS" diyor.
     */
    public function test_the_reminder_for_an_official_exam_does_not_call_it_a_practice_exam(): void
    {
        config(['kafe.cron_anahtari' => 'gizli-anahtar']);
        $this->travelTo(Carbon::parse('2026-09-29 20:00', 'UTC'));

        $ogrenci = $this->ogrenci();
        ExamEvent::create(['title' => 'YKS 2027', 'exam_type' => 'official', 'exam_date' => '2026-09-30', 'starts_at' => '10:15']);

        $this->withToken('gizli-anahtar')->get(route('cron.daily'))->assertOk();

        $hatirlatma = Bildirim::where('user_id', $ogrenci->id)->where('type', NotificationType::ExamTomorrow->value)->sole();
        $this->assertSame('Yarın sınav günü: YKS 2027', $hatirlatma->title);
    }

    public function test_a_student_who_came_today_gets_no_absence_notice(): void
    {
        config(['kafe.cron_anahtari' => 'gizli-anahtar']);
        $this->travelTo(Carbon::parse('2026-09-29 20:00', 'UTC'));

        $ogrenci = $this->ogrenci();
        $this->veliBagla($ogrenci);
        $this->oturum($ogrenci, '2026-09-28');
        $this->oturum($ogrenci, '2026-09-29');

        $this->withToken('gizli-anahtar')->get(route('cron.daily'))
            ->assertOk()
            ->assertJson(['devamsizlik' => 0]);

        $this->assertSame(0, Bildirim::where('type', NotificationType::Absence->value)->count());
    }
}
