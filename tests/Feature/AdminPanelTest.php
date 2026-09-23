<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * UX turu (23 Eyl) - yonetim paneli. Panel yalnizca para gosteriyordu;
 * yoneticinin gunluk sorulari ustte: iceride kim var, onay bekleyen var
 * mi, biten urun var mi. Her sayi ilgili sayfaya goturur.
 */
class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Acik oturum kafe kapanisindan (21:00) sonra kendiliginden kapanir.
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
    }

    private function oturum(StudyTable $masa, bool $bitti = false): StudySession
    {
        return StudySession::create([
            'student_id' => User::factory()->student()->create()->id,
            'study_table_id' => $masa->id,
            'started_at' => now()->subHour(),
            'ended_at' => $bitti ? now()->subMinutes(5) : null,
            'duration_minutes' => $bitti ? 55 : null,
            'approval_status' => 'pending',
        ]);
    }

    public function test_occupancy_counts_seats_inside_and_free(): void
    {
        $masalar = collect(range(1, 4))->map(fn ($n) => StudyTable::create(['name' => "Masa {$n}"]));
        StudyTable::create(['name' => 'Kapalı', 'is_active' => false]);
        $this->oturum($masalar[0]);
        $this->oturum($masalar[1]);
        $this->oturum($masalar[2], bitti: true);

        $this->assertSame(['total' => 4, 'inside' => 2, 'free' => 2], StudyTable::occupancy());
    }

    /** Kapatilmis bir masada unutulan oturum bos yeri eksiye dusurmemeli. */
    public function test_free_seats_never_go_below_zero(): void
    {
        $masa = StudyTable::create(['name' => 'Masa 1']);
        $this->oturum($masa);
        $this->oturum($masa);

        $this->assertSame(0, StudyTable::occupancy()['free']);
    }

    public function test_the_panel_shows_what_needs_attention_now(): void
    {
        $masa = StudyTable::create(['name' => 'Masa 1']);
        $this->oturum($masa);
        $this->oturum($masa, bitti: true);
        foreach ([[3, 5], [0, 2], [40, 10], [null, null]] as [$stok, $kritik]) {
            Product::create(['name' => "Ürün {$stok}", 'unit_price' => 10, 'unit_type' => 'adet',
                'stock_quantity' => $stok, 'critical_quantity' => $kritik]);
        }

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('occupancy', fn ($d) => $d['inside'] === 1)
            ->assertViewHas('pendingApprovals', 1)
            ->assertViewHas('criticalStock', 2)
            ->assertSee(route('admin.live'), false)
            ->assertSee(route('admin.stock.index', ['durum' => 'critical']), false);
    }

    /** substr bayt keser: "Ömer" avatarda "�" gorunuyordu. */
    public function test_user_initials_keep_turkish_letters(): void
    {
        User::factory()->student()->create(['name' => 'ömer Şahin']);

        $this->actingAs(User::factory()->admin()->create())->get(route('admin.users.index'))
            ->assertSee('ÖM');
    }
}
