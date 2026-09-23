<?php

namespace Tests\Unit;

use App\Enums\Role;
use App\Models\Package;
use App\Models\User;
use App\Support\Navigation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dalga 21: tek menu. Herkes ayni kabukta; menu rol ve paket haklarina
 * gore suzulur. Ic ice/acilir menu yok: baslikli gruplar + duz linkler.
 */
class NavigationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<int,string> tum gruplardaki rota adlari */
    private function rotalar(User $u): array
    {
        return collect(Navigation::groups($u))->flatMap(fn ($g) => array_column($g['items'], 'route'))->all();
    }

    public function test_the_admin_sees_coach_pages_in_the_same_menu(): void
    {
        $rotalar = $this->rotalar(User::factory()->admin()->create());

        $this->assertContains('admin.dashboard', $rotalar);
        $this->assertContains('coach.plan.index', $rotalar);
        $this->assertContains('admin.products.index', $rotalar);
    }

    public function test_the_admin_menu_is_grouped_under_headings(): void
    {
        $basliklar = array_column(Navigation::groups(User::factory()->admin()->create()), 'title');

        $this->assertSame(['Günlük', 'Öğrenciler', 'Kafe', 'Ayarlar'], $basliklar);
    }

    public function test_a_coach_sees_only_coaching_pages(): void
    {
        $rotalar = $this->rotalar(User::factory()->create(['role' => Role::Coach->value]));

        $this->assertContains('coach.plan.index', $rotalar);
        $this->assertNotContains('admin.dashboard', $rotalar);
    }

    public function test_a_student_with_the_exam_club_sees_results(): void
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier3())->create();

        $this->assertContains('user.exam-results', $this->rotalar($ogrenci));
    }

    public function test_a_student_without_the_exam_club_does_not_see_results(): void
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier1())->create();
        $rotalar = $this->rotalar($ogrenci);

        $this->assertNotContains('user.exam-results', $rotalar);
        $this->assertNotContains('user.exam-reports.index', $rotalar);
        $this->assertContains('user.exams', $rotalar);
    }

    public function test_a_student_without_a_table_is_not_offered_the_scanner(): void
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->examOnly())->create();

        $this->assertNotContains('table.scanner', $this->rotalar($ogrenci));
    }

    public function test_a_parent_sees_the_parent_pages(): void
    {
        $rotalar = $this->rotalar(User::factory()->parent()->create());

        $this->assertSame(['parent.dashboard', 'parent.exams'], $rotalar);
    }

    /** Alt barda en fazla 4 sayfa; 5. yer "Menu" dugmesi. */
    public function test_the_quick_bar_has_at_most_four_items(): void
    {
        foreach ([User::factory()->admin()->create(), User::factory()->student()->withPackage(Package::factory()->tier3())->create()] as $u) {
            $hizli = Navigation::quick($u);

            $this->assertLessThanOrEqual(4, count($hizli));
            $this->assertEmpty(array_diff(array_column($hizli, 'route'), $this->rotalar($u)),
                'Alt bar menude olmayan bir sayfa gosteriyor.');
        }
    }

    public function test_the_student_quick_bar_starts_with_the_panel_and_scanner(): void
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier1())->create();

        $this->assertSame(['user.dashboard', 'table.scanner'], array_slice(array_column(Navigation::quick($ogrenci), 'route'), 0, 2));
    }

    /**
     * UX turu (23 Eyl): Planim ve Adisyon her gun acilir, deneme takvimi
     * ayda birkac kez. Deneme takvimi Menu'de kalir.
     */
    public function test_the_student_quick_bar_has_the_daily_pages(): void
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier1())->create();

        $this->assertSame(
            ['user.dashboard', 'table.scanner', 'user.plan', 'user.tab'],
            array_column(Navigation::quick($ogrenci), 'route'),
        );
    }
}
