<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Consumption;
use App\Models\ExamEvent;
use App\Models\Product;
use App\Models\StudyPlanItem;
use App\Models\User;
use App\Models\WeeklyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Ogrencinin kendi verisini yazan uclar yalnizca ogrenciye acik (QA hata
 * 11 ve 13).
 *
 * /kullanici grubunda rol kapisi yoktu ve abonelik middleware'i ogrenci
 * olmayani oldugu gibi geciriyordu: veli /kullanici/rapor'u acinca KENDI
 * adina haftalik rapor donduruluyor, /kullanici/adisyon'dan kendi adina
 * urun ekleyip stok dusurebiliyordu. Panel (ogretmen/gorevlinin ana sayfasi)
 * ve deneme raporu (koc/veli de okuyor) bilerek acik kaliyor.
 */
class StudentOnlyRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
    }

    /** @return array<string,array{0:string}> */
    public static function ogrenciOlmayanlar(): array
    {
        return collect(Role::cases())
            ->reject(fn (Role $r) => $r === Role::Student)
            ->mapWithKeys(fn (Role $r) => [$r->value => [$r->value]])
            ->all();
    }

    private function kullanici(string $rol): User
    {
        return User::factory()->create(['role' => $rol, 'subscription_status' => 'active']);
    }

    #[DataProvider('ogrenciOlmayanlar')]
    public function test_non_students_cannot_open_or_write_student_pages(string $rol): void
    {
        $kisi = $this->kullanici($rol);
        $deneme = ExamEvent::create([
            'title' => 'Serbest', 'exam_type' => 'tyt', 'exam_date' => '2026-10-01',
            'is_flexible' => true, 'available_until' => '2026-10-31',
        ]);
        $urun = Product::create(['name' => 'Su', 'unit_price' => 10, 'unit_type' => 'adet', 'stock_quantity' => 5]);

        $this->actingAs($kisi)->get(route('user.report'))->assertForbidden();
        $this->actingAs($kisi)->get(route('user.plan'))->assertForbidden();
        $this->actingAs($kisi)->get(route('user.tab'))->assertForbidden();
        $this->actingAs($kisi)->post(route('user.plan.exam'), ['exam_event_id' => $deneme->id, 'plan_date' => '2026-10-10'])->assertForbidden();
        $this->actingAs($kisi)->post(route('user.tab.store'), ['product_id' => $urun->id, 'quantity' => 1])->assertForbidden();

        $this->assertSame(0, WeeklyReport::count());
        $this->assertSame(0, StudyPlanItem::count());
        $this->assertSame(0, Consumption::count());
        $this->assertSame(5, $urun->fresh()->stock_quantity);
    }

    /** Ogretmen ve gorevlinin ana sayfasi panel; kapi onu kilitlememeli. */
    public function test_the_panel_stays_open_for_teachers_and_staff(): void
    {
        foreach ([Role::Teacher, Role::Staff] as $rol) {
            $this->actingAs($this->kullanici($rol->value))->get(route('user.dashboard'))->assertOk();
        }
    }

    /** Kapi yeni bir cikmaz uretmesin: panel ogrenci olmayana "Haftam"i gostermez. */
    public function test_the_panel_does_not_link_non_students_to_the_student_plan(): void
    {
        $this->actingAs($this->kullanici(Role::Teacher->value))->get(route('user.dashboard'))
            ->assertOk()
            ->assertDontSee(route('user.plan'), false);
    }

    public function test_a_student_still_reaches_their_pages(): void
    {
        $ogrenci = User::factory()->student()->withPackage(\App\Models\Package::factory()->tier3()->create())->create();

        $this->actingAs($ogrenci)->get(route('user.report'))->assertOk();
        $this->actingAs($ogrenci)->get(route('user.plan'))->assertOk();
        $this->actingAs($ogrenci)->get(route('user.tab'))->assertOk();
        $this->actingAs($ogrenci)->get(route('user.dashboard'))->assertOk()->assertSee(route('user.plan'), false);
    }
}
