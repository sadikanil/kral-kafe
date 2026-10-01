<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Kafenin koclari ada gore tanimlanir; rol degismez (1 Ekim 2026). */
class CoachSubjectsMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function calistir(): void
    {
        (require database_path('migrations/2026_10_01_130000_assign_coach_subjects.php'))->up();
    }

    public function test_cahit_and_ibrahim_get_their_subjects_and_rights(): void
    {
        $cahit = User::factory()->admin()->create(['name' => 'Cahit ATILĞAN']);
        $ibrahim = User::factory()->parent()->create(['name' => 'İBRAHİM ACAR']);
        $baskasi = User::factory()->parent()->create(['name' => 'İbrahim Acarsoy']);

        $this->calistir();
        $this->calistir();

        $this->assertSame('Fizik', $cahit->fresh()->coach_subject);
        $this->assertSame([true, 'Matematik', Role::Parent->value], [$ibrahim->fresh()->is_coach, $ibrahim->fresh()->coach_subject, $ibrahim->fresh()->role]);
        $this->assertTrue($ibrahim->fresh()->hasRole(Role::Coach));
        $this->assertFalse($baskasi->fresh()->is_coach);
    }

    public function test_nothing_happens_when_they_are_not_registered(): void
    {
        $ogrenci = User::factory()->student()->create(['name' => 'İbrahim Acar']);

        $this->calistir();

        $this->assertFalse($ogrenci->fresh()->is_coach);
        $this->assertNull($ogrenci->fresh()->coach_subject);
    }
}
