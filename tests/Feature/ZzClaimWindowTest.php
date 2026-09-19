<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ZzClaimWindowTest extends TestCase
{
    use RefreshDatabase;

    private function yerel(string $z): Carbon
    {
        return Carbon::parse($z, config('kafe.timezone'));
    }

    private function bak(string $ad, string $baslangic, string $bakisAni): bool
    {
        $ogrenci = User::factory()->create([
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
        StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => StudyTable::create(['name' => 'Masa 1'])->id,
            'started_at' => $this->yerel($baslangic)->copy()->utc(),
        ]);
        $yonetici = User::factory()->create(['role' => Role::Admin->value]);

        Carbon::setTestNow($this->yerel($bakisAni));
        $cevap = $this->actingAs($yonetici)->get(route('admin.live'))->assertOk();
        $oturum = StudySession::first()->refresh();
        Carbon::setTestNow();

        $gorunur = str_contains($cevap->getContent(), 'Süre aşımı');
        fwrite(STDERR, sprintf(
            "\n  %-32s sebep=%-11s ended_at(UTC)=%s  GORUNUR: %s\n",
            $ad, $oturum->end_reason?->value ?? 'NULL',
            $oturum->getRawOriginal('ended_at') ?? 'ACIK',
            $gorunur ? 'EVET' : '>>> HAYIR <<<'
        ));

        return $gorunur;
    }

    public function test_a_ayni_gun_2300(): void
    {
        $this->assertTrue($this->bak('ayni gun 23:00 (mevcut test)', '2026-09-21 08:00', '2026-09-21 23:00'));
    }

    public function test_b_ertesi_sabah(): void
    {
        $this->assertTrue($this->bak('ERTESI SABAH 09:00', '2026-09-21 08:00', '2026-09-22 09:00'));
    }

    public function test_c_iki_gun_sonra(): void
    {
        $this->assertTrue($this->bak('iki gun sonra 10:00', '2026-09-21 08:00', '2026-09-23 10:00'));
    }

    public function test_d_gece_basli_ayni_gun(): void
    {
        $this->assertTrue($this->bak('gece 22:00 basli, ertesi 15:00', '2026-09-21 22:00', '2026-09-22 15:00'));
    }

    public function test_e_gece_basli_gun_sonra(): void
    {
        $this->assertTrue($this->bak('gece 22:00 basli, +2 gun', '2026-09-21 22:00', '2026-09-23 15:00'));
    }
}
