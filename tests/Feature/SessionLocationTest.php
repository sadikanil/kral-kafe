<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Support\GeoDistance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dalga 10b - Oturum konumu.
 *
 * Konum SERT KAPI DEGIL: ic mekanda GPS sapmasi 50-100 metreyi buluyor, izin
 * reddedilebilir ve kafedeki gercek ogrenciyi disarida birakmak sahtekari
 * durdurmuyor. Konum yalnizca onay kuyrugunda bir isaret; asil dogrulama
 * yoneticinin onayi (Dalga 9).
 */
class SessionLocationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Bilinen iki nokta arasi mesafe.
     *
     * Ankara Kizilay - Ulus arasi kus ucusu ~2,1 km. Formulun kendisini degil,
     * buyukluk mertebesini sabitliyoruz: haversine'i yanlis yazmanin tipik
     * sonucu 111 kat sapma (derece/radyan karisikligi).
     */
    public function test_it_measures_the_distance_between_two_points(): void
    {
        $metre = GeoDistance::metersBetween(39.9208, 32.8541, 39.9400, 32.8541);

        $this->assertGreaterThan(2000, $metre);
        $this->assertLessThan(2300, $metre);
    }

    public function test_the_same_point_is_zero_away(): void
    {
        $this->assertSame(0.0, round(GeoDistance::metersBetween(39.9208, 32.8541, 39.9208, 32.8541), 1));
    }

    /**
     * Yon fark etmez: A'dan B'ye mesafe, B'den A'ya mesafeye esit.
     */
    public function test_the_distance_is_symmetric(): void
    {
        $ileri = GeoDistance::metersBetween(41.0082, 28.9784, 39.9208, 32.8541);
        $geri = GeoDistance::metersBetween(39.9208, 32.8541, 41.0082, 28.9784);

        $this->assertSame(round($ileri), round($geri));
    }

    // --- Kafe konumu (ayar) -------------------------------------------------

    /**
     * Kafe koordinati koda degil veritabanina yaziliyor: yonetici kafedeyken
     * "konumu buradan al" diyor. Tasinirsa tek tikla guncellenir, deploy
     * gerekmez ve degerin dogrulugu panelde gorunur.
     */
    public function test_the_cafe_location_is_empty_until_it_is_set(): void
    {
        $this->assertNull(Setting::cafeLocation());
    }

    public function test_the_cafe_location_can_be_stored_and_read_back(): void
    {
        Setting::putCafeLocation(39.9208, 32.8541);

        $this->assertSame(
            ['lat' => 39.9208, 'lng' => 32.8541],
            Setting::cafeLocation()
        );
    }

    public function test_storing_the_cafe_location_again_replaces_it(): void
    {
        Setting::putCafeLocation(39.9208, 32.8541);
        Setting::putCafeLocation(41.0082, 28.9784);

        $this->assertSame(
            ['lat' => 41.0082, 'lng' => 28.9784],
            Setting::cafeLocation()
        );
        $this->assertDatabaseCount('settings', 1);
    }

    // --- Oturumun konumu ----------------------------------------------------

    private function ogrenci(): \App\Models\User
    {
        return \App\Models\User::factory()->create([
            'role' => \App\Enums\Role::Student->value,
            'subscription_status' => 'active',
        ]);
    }

    public function test_starting_a_session_records_the_location(): void
    {
        $masa = \App\Models\StudyTable::create(['name' => 'Masa 1']);

        $this->actingAs($this->ogrenci())
            ->post(route('table.session.start', $masa->qr_code), [
                'latitude' => 39.9208,
                'longitude' => 32.8541,
                'accuracy' => 18.5,
            ])
            ->assertRedirect();

        $oturum = \App\Models\StudySession::latest('id')->first();

        $this->assertSame(39.9208, (float) $oturum->latitude);
        $this->assertSame(32.8541, (float) $oturum->longitude);
        $this->assertSame(18.5, (float) $oturum->accuracy);
    }

    /**
     * Konum izni reddedilirse oturum YINE baslar.
     *
     * Engellemek gercek ogrenciyi cezalandirir, sahtekari durdurmaz: basili
     * QR bir kez fotograflanip evden okutulabilir. Asil dogrulama yoneticinin
     * onayi; konum orada yalnizca bir isaret.
     */
    public function test_a_session_starts_even_without_a_location(): void
    {
        $masa = \App\Models\StudyTable::create(['name' => 'Masa 1']);

        $this->actingAs($this->ogrenci())
            ->post(route('table.session.start', $masa->qr_code))
            ->assertRedirect();

        $oturum = \App\Models\StudySession::latest('id')->first();

        $this->assertNotNull($oturum);
        $this->assertNull($oturum->latitude);
    }

    public function test_distance_is_unknown_when_the_cafe_location_is_not_set(): void
    {
        $oturum = new \App\Models\StudySession(['latitude' => 39.9208, 'longitude' => 32.8541]);

        $this->assertNull($oturum->distanceFromCafe());
    }

    public function test_distance_is_unknown_when_the_session_has_no_location(): void
    {
        Setting::putCafeLocation(39.9208, 32.8541);

        $this->assertNull((new \App\Models\StudySession())->distanceFromCafe());
    }

    public function test_a_session_at_the_cafe_is_not_far(): void
    {
        Setting::putCafeLocation(39.9208, 32.8541);
        $oturum = new \App\Models\StudySession(['latitude' => 39.9209, 'longitude' => 32.8542]);

        $this->assertFalse($oturum->isFarFromCafe());
    }

    public function test_a_session_beyond_the_threshold_is_far(): void
    {
        config(['kafe.konum_esigi_metre' => 250]);
        Setting::putCafeLocation(39.9208, 32.8541);

        // ~2 km kuzeyde
        $oturum = new \App\Models\StudySession(['latitude' => 39.9400, 'longitude' => 32.8541]);

        $this->assertTrue($oturum->isFarFromCafe());
    }

    /**
     * Konumu olmayan oturum "uzak" DEGIL, "bilinmiyor". Ikisini karistirmak,
     * izni kapali her ogrenciyi supheli isaretlerdi.
     */
    public function test_a_session_without_a_location_is_not_flagged_as_far(): void
    {
        Setting::putCafeLocation(39.9208, 32.8541);

        $this->assertFalse((new \App\Models\StudySession())->isFarFromCafe());
    }

    // --- Yonetici ayarlari ve onay kuyrugundaki isaret ----------------------

    private function yonetici(): \App\Models\User
    {
        return \App\Models\User::factory()->create(['role' => \App\Enums\Role::Admin->value]);
    }

    public function test_an_admin_opens_the_settings_page(): void
    {
        $this->actingAs($this->yonetici())
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('Kafe konumu');
    }

    public function test_an_admin_saves_the_cafe_location(): void
    {
        $this->actingAs($this->yonetici())
            ->post(route('admin.settings.location'), [
                'latitude' => 39.9208,
                'longitude' => 32.8541,
            ])
            ->assertRedirect();

        $this->assertSame(['lat' => 39.9208, 'lng' => 32.8541], Setting::cafeLocation());
    }

    public function test_the_cafe_location_must_be_a_real_coordinate(): void
    {
        $this->actingAs($this->yonetici())
            ->post(route('admin.settings.location'), [
                'latitude' => 999,
                'longitude' => 32.8541,
            ])
            ->assertSessionHasErrors('latitude');

        $this->assertNull(Setting::cafeLocation());
    }

    public function test_a_student_cannot_change_the_cafe_location(): void
    {
        $this->actingAs($this->ogrenci())
            ->post(route('admin.settings.location'), ['latitude' => 39.9, 'longitude' => 32.8])
            ->assertForbidden();
    }

    /**
     * Onay kuyrugunda konum bir ISARET, bir karar degil: yonetici onu gorup
     * kendi karari veriyor.
     */
    public function test_the_queue_marks_a_session_without_a_location(): void
    {
        Setting::putCafeLocation(39.9208, 32.8541);
        $this->bitmisOturum();

        $this->actingAs($this->yonetici())->get(route('admin.live'))
            ->assertOk()
            ->assertSee('Konum yok');
    }

    public function test_the_queue_marks_a_session_far_from_the_cafe(): void
    {
        config(['kafe.konum_esigi_metre' => 250]);
        Setting::putCafeLocation(39.9208, 32.8541);
        $this->bitmisOturum(39.9400, 32.8541);

        $this->actingAs($this->yonetici())->get(route('admin.live'))
            ->assertOk()
            ->assertSee('Kafeden uzak');
    }

    private function bitmisOturum(?float $lat = null, ?float $lng = null): \App\Models\StudySession
    {
        $baslangic = now()->subHours(2);

        return \App\Models\StudySession::create([
            'student_id' => $this->ogrenci()->id,
            'study_table_id' => \App\Models\StudyTable::create(['name' => 'Masa ' . uniqid()])->id,
            'started_at' => $baslangic,
            'ended_at' => $baslangic->copy()->addHours(2),
            'duration_minutes' => 120,
            'end_reason' => \App\Enums\SessionEndReason::Manual->value,
            'latitude' => $lat,
            'longitude' => $lng,
        ]);
    }
}
