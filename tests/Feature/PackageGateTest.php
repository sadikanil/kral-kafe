<?php

namespace Tests\Feature;

use App\Models\ExamEvent;
use App\Models\Package;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dalga 19: paket haklari kapilari.
 *
 *  - Masa: rezerve masa hakki olmayan (ornegin "sadece deneme") calisma
 *    oturumu baslatamaz.
 *  - Deneme detayi: deneme kulubu olmayan takvimde yalnizca tarih ve turu
 *    gorur; ad, sonuclar ve raporlar kapali.
 */
class PackageGateTest extends TestCase
{
    use RefreshDatabase;

    // --- Masa ---------------------------------------------------------------

    public function test_a_student_with_a_table_can_start(): void
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier1())->create();
        $masa = StudyTable::create(['name' => 'Masa 1']);

        $this->actingAs($ogrenci)->post(route('table.session.start', $masa->qr_code));

        $this->assertSame(1, StudySession::count());
    }

    public function test_a_student_without_a_table_cannot_start(): void
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->examOnly())->create();
        $masa = StudyTable::create(['name' => 'Masa 1']);

        $this->actingAs($ogrenci)
            ->from(route('table.scan', $masa->qr_code))
            ->post(route('table.session.start', $masa->qr_code))
            ->assertSessionHas('error');

        $this->assertSame(0, StudySession::count());
    }

    public function test_a_student_without_any_package_is_told_why(): void
    {
        $ogrenci = User::factory()->student()->create();
        $masa = StudyTable::create(['name' => 'Masa 1']);

        $this->actingAs($ogrenci)
            ->get(route('table.scan', $masa->qr_code))
            ->assertSee('Paketin masa kullanımını kapsamıyor');
    }

    // --- Deneme detayi ------------------------------------------------------

    private function deneme(): ExamEvent
    {
        return ExamEvent::factory()->create([
            'title' => 'Gizli Deneme Adı',
            'exam_date' => now()->addDays(3)->toDateString(),
        ]);
    }

    public function test_without_the_exam_club_the_calendar_hides_the_name(): void
    {
        $this->deneme();
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier1())->create();

        $this->actingAs($ogrenci)
            ->get(route('user.exams'))
            ->assertOk()
            ->assertDontSee('Gizli Deneme Adı');
    }

    public function test_with_the_exam_club_the_calendar_shows_the_name(): void
    {
        $this->deneme();
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier3())->create();

        $this->actingAs($ogrenci)
            ->get(route('user.exams'))
            ->assertSee('Gizli Deneme Adı');
    }

    public function test_without_the_exam_club_results_are_closed(): void
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier1())->create();

        $this->actingAs($ogrenci)->get(route('user.exam-results'))->assertForbidden();
        $this->actingAs($ogrenci)->get(route('user.exam-reports.index'))->assertForbidden();
    }

    public function test_with_the_exam_club_results_are_open(): void
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->examOnly())->create();

        $this->actingAs($ogrenci)->get(route('user.exam-results'))->assertOk();
        $this->actingAs($ogrenci)->get(route('user.exam-reports.index'))->assertOk();
    }

    public function test_the_menu_hides_results_without_the_exam_club(): void
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier1())->create();

        $this->actingAs($ogrenci)
            ->get(route('user.dashboard'))
            ->assertDontSee(route('user.exam-results'));
    }

    /** Veli cocugunun paketini gorur: kulupsuz cocugun takviminde ad yok. */
    public function test_a_parent_follows_the_childs_package(): void
    {
        $this->deneme();
        $veli = User::factory()->parent()->create();
        $veli->students()->attach(User::factory()->student()->withPackage(Package::factory()->tier1())->create());

        $this->actingAs($veli)
            ->get(route('parent.exams'))
            ->assertDontSee('Gizli Deneme Adı');
    }
}
