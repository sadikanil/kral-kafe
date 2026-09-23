<?php

namespace Tests\Feature;

use App\Enums\SessionEndReason;
use App\Models\Package;
use App\Models\PrivateLessonSlot;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Yonetim ekranlarinin erisilebilirlik isaretlemesi (faz 2, grup F: A5, A9,
 * A19, A20). Gorsel duzen degil, yalnizca dogruluk: alan adlari, klavye
 * ipuclari, onay sorulari ve canli ekranin kendini tazelemesi.
 */
class AdminPeopleA11yTest extends TestCase
{
    use RefreshDatabase;

    private User $yonetici;

    protected function setUp(): void
    {
        parent::setUp();

        // 29 Eylul 2026 Sali 14:00 - kafe acik.
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
        $this->yonetici = User::factory()->admin()->create();
    }

    // --- A5: kullanici listesi ----------------------------------------------

    public function test_suspending_from_the_list_asks_first_and_names_the_user(): void
    {
        $aktif = User::factory()->student()->create(['name' => "Ayşe O'Neil"]);
        $askida = User::factory()->student()->create(['name' => 'Berk Askı', 'subscription_status' => 'suspended']);

        $this->actingAs($this->yonetici)->get(route('admin.users.index'))
            ->assertOk()
            // Ad JS dizesine guvenli girer (kesme isareti onay kutusunu bozmaz).
            ->assertSee("onsubmit=\"return confirm('Ay\\u015fe O\\u0027Neil ask\\u0131ya al\\u0131ns\\u0131n m\\u0131?')\"", false)
            ->assertSee('aria-label="Askıya al: Ayşe O&#039;Neil"', false)
            ->assertSee("onsubmit=\"return confirm('Berk Ask\\u0131 aktifle\\u015ftirilsin mi?')\"", false)
            ->assertSee('aria-label="Aktifleştir: Berk Askı"', false);
    }

    public function test_the_list_filters_have_names_and_a_search_key(): void
    {
        $this->actingAs($this->yonetici)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('type="search" name="search"', false)
            ->assertSee('enterkeyhint="search"', false)
            ->assertSee('aria-label="Ad, telefon ya da e-posta ara"', false)
            ->assertSee('aria-label="Rol"', false)
            ->assertSee('aria-label="Durum"', false);
    }

    // --- A9, A19: kullanici formlari ----------------------------------------

    public function test_the_create_form_labels_the_parent_fields_and_skips_autofill(): void
    {
        User::factory()->parent()->create();

        $this->actingAs($this->yonetici)->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('id="name" name="name" autocomplete="off"', false)
            ->assertSee('id="phone" name="phone" autocomplete="off"', false)
            ->assertSee('id="email" name="email" autocomplete="off"', false)
            ->assertSee('aria-label="Kayıtlı veli ara"', false)
            ->assertSee('<label for="new_parent_name" class="form-label">Yeni veli adı soyadı</label>', false)
            ->assertSee('<label for="new_parent_phone" class="form-label">Yeni velinin telefonu</label>', false);
    }

    public function test_the_edit_form_keeps_the_admins_keychain_out(): void
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier2())->create(['name' => "Ayşe O'Neil"]);

        $this->actingAs($this->yonetici)->get(route('admin.users.edit', $ogrenci))
            ->assertOk()
            ->assertSee('id="name" name="name" autocomplete="off"', false)
            ->assertSee('id="password" name="password" autocomplete="new-password"', false)
            ->assertSee('id="password_confirmation" name="password_confirmation" autocomplete="new-password"', false)
            ->assertSee('aria-label="Atanacak koç"', false)
            // Kesme isaretli ad sifre sifirlama onayini bozmaz (eskiden JS hatasi
            // verip onaysiz gonderiyordu).
            ->assertSee("confirm('Ay\\u015fe O\\u0027Neil her cihazdan", false);
    }

    public function test_the_private_lesson_fields_are_named(): void
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier3())->create();
        PrivateLessonSlot::create(['student_id' => $ogrenci->id, 'weekday' => 3,
            'starts_at' => '17:00', 'ends_at' => '18:30', 'starts_on' => '2026-09-01']);

        $this->actingAs($this->yonetici)->get(route('admin.users.edit', $ogrenci))
            ->assertOk()
            ->assertSee('<label for="ders-gun" class="form-label">Gün</label>', false)
            ->assertSee('<label for="ders-bas" class="form-label">Başlangıç</label>', false)
            ->assertSee('<label for="ders-bit" class="form-label">Bitiş</label>', false)
            ->assertSee('aria-label="30 Eyl Çar dersinin yeni günü"', false)
            ->assertSee('aria-label="30 Eyl Çar dersinin yeni başlangıcı"', false)
            ->assertSee('aria-label="30 Eyl Çar dersinin yeni bitişi"', false);
    }

    // --- A9, A19: ayarlar ---------------------------------------------------

    public function test_the_coordinates_have_labels_and_a_decimal_keypad(): void
    {
        $this->actingAs($this->yonetici)->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('<label for="js-konum-enlem" class="form-label">Enlem</label>', false)
            ->assertSee('<label for="js-konum-boylam" class="form-label">Boylam</label>', false)
            ->assertSee('name="latitude" id="js-konum-enlem" class="form-control" inputmode="decimal"', false)
            ->assertSee('name="longitude" id="js-konum-boylam" class="form-control" inputmode="decimal"', false);
    }

    // --- A9, A20: canli ekran ve panel --------------------------------------

    public function test_the_reject_reason_names_the_student(): void
    {
        $masa = StudyTable::create(['name' => 'Masa 1']);
        StudySession::create([
            'student_id' => User::factory()->student()->create(['name' => 'Deniz Ak'])->id,
            'study_table_id' => $masa->id,
            'started_at' => now()->subMinutes(100), 'ended_at' => now()->subMinutes(10),
            'duration_minutes' => 90, 'end_reason' => SessionEndReason::Manual->value,
        ]);

        $this->actingAs($this->yonetici)->get(route('admin.live'))
            ->assertOk()
            ->assertSee('aria-label="Deniz Ak için red sebebi"', false);
    }

    public function test_the_live_screen_refreshes_itself_and_ticks_the_times(): void
    {
        $masa = StudyTable::create(['name' => 'Masa 1']);
        StudySession::create([
            'student_id' => User::factory()->student()->create()->id,
            'study_table_id' => $masa->id,
            'started_at' => now()->subMinutes(75),
        ]);

        $this->actingAs($this->yonetici)->get(route('admin.live'))
            ->assertOk()
            ->assertSee('data-kendini-yenile="60"', false)
            ->assertSee('<span class="live-row-time" data-dakika="75" data-akiyor="1">01:15</span>', false)
            ->assertSee('role="status" aria-live="polite"', false)
            ->assertSee('data-onay-sayisi="0"', false);
    }

    public function test_a_paused_session_time_does_not_tick(): void
    {
        $masa = StudyTable::create(['name' => 'Masa 1']);
        $oturum = StudySession::create([
            'student_id' => User::factory()->student()->create()->id,
            'study_table_id' => $masa->id,
            'started_at' => now()->subMinutes(75),
        ]);
        $oturum->pauses()->create(['kind' => 'pause', 'started_at' => now()->subMinutes(15)]);

        $this->actingAs($this->yonetici)->get(route('admin.live'))
            ->assertOk()
            ->assertSee('<span class="live-row-time" data-dakika="60" data-akiyor="0">01:00</span>', false);
    }

    public function test_the_dashboard_refreshes_itself(): void
    {
        $this->actingAs($this->yonetici)->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-kendini-yenile="60"', false);
    }
}
