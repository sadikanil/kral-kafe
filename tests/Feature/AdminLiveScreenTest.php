<?php

namespace Tests\Feature;

use App\Enums\SessionEndReason;
use App\Models\Setting;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Once;
use Tests\TestCase;

/**
 * Canli ekran (faz 2, grup F): sorgu sayisi onay kuyruguyla buyumez (P9),
 * kafe konumu istek basina bir kez okunur.
 */
class AdminLiveScreenTest extends TestCase
{
    use RefreshDatabase;

    private User $yonetici;

    protected function setUp(): void
    {
        parent::setUp();

        // Sali 14:00 - kafe acik, acik oturumlar kapanmaz.
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
        $this->yonetici = User::factory()->admin()->create();
    }

    private function bekleyenler(int $adet): void
    {
        for ($i = 0; $i < $adet; $i++) {
            $masa = StudyTable::create(['name' => 'Masa B' . $i]);
            StudySession::create([
                'student_id' => User::factory()->student()->create()->id,
                'study_table_id' => $masa->id,
                'started_at' => now()->subMinutes(100),
                'ended_at' => now()->subMinutes(10),
                'duration_minutes' => 90,
                'end_reason' => SessionEndReason::Manual->value,
                'latitude' => 41.0082, 'longitude' => 28.9784,
            ]);
        }
    }

    private function acikOturumlar(int $adet): void
    {
        for ($i = 0; $i < $adet; $i++) {
            $masa = StudyTable::create(['name' => 'Masa A' . $i]);
            StudySession::create([
                'student_id' => User::factory()->student()->create()->id,
                'study_table_id' => $masa->id,
                'started_at' => now()->subMinutes(30),
            ]);
        }
    }

    /** @return list<string> */
    private function canliEkranSorgulari(): array
    {
        // Gercekte her istek yeni bir PHP istegi; test ayni sureci paylasir.
        Once::flush();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->actingAs($this->yonetici)->get(route('admin.live'))->assertOk();
        DB::disableQueryLog();

        return array_column(DB::getQueryLog(), 'query');
    }

    public function test_the_cafe_location_is_read_once_however_long_the_queue_is(): void
    {
        Setting::putCafeLocation(41.0082, 28.9784);
        $this->bekleyenler(6);

        $ayar = array_filter($this->canliEkranSorgulari(), fn ($q) => str_contains($q, 'from "settings"'));

        $this->assertCount(1, $ayar);
    }

    public function test_the_query_count_does_not_grow_with_the_approval_queue(): void
    {
        Setting::putCafeLocation(41.0082, 28.9784);
        $this->bekleyenler(1);
        $this->acikOturumlar(1);
        $az = count($this->canliEkranSorgulari());

        $this->bekleyenler(7);
        $this->acikOturumlar(5);
        $cok = count($this->canliEkranSorgulari());

        $this->assertSame($az, $cok);
    }

    public function test_open_sessions_are_not_counted_again_for_occupancy(): void
    {
        $this->acikOturumlar(2);
        StudyTable::create(['name' => 'Bos masa']);

        $sayim = array_filter($this->canliEkranSorgulari(),
            fn ($q) => str_contains($q, 'count(*)') && str_contains($q, 'from "study_sessions"'));

        $this->assertSame([], array_values($sayim));

        $this->actingAs($this->yonetici)->get(route('admin.live'))
            ->assertOk()
            ->assertSeeInOrder(['2', 'İçeride', '1', 'Boş yer', '3', 'Toplam yer']);
    }

    public function test_a_freshly_saved_cafe_location_is_read_back_in_the_same_request(): void
    {
        $this->assertNull(Setting::cafeLocation());

        Setting::putCafeLocation(39.9208, 32.8541);
        $this->assertSame(['lat' => 39.9208, 'lng' => 32.8541], Setting::cafeLocation());

        Setting::putCafeLocation(41.0082, 28.9784);
        $this->assertSame(['lat' => 41.0082, 'lng' => 28.9784], Setting::cafeLocation());

        Setting::where('key', Setting::KAFE_KONUM)->sole()->delete();
        $this->assertNull(Setting::cafeLocation());
    }
}
