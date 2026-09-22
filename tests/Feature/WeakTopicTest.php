<?php

namespace Tests\Feature;

use App\Enums\PlanPeriod;
use App\Enums\Role;
use App\Models\StudentParent;
use App\Models\StudyPlanItem;
use App\Models\Subject;
use App\Models\User;
use App\Models\WeakTopic;
use App\Support\LocalDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dalga 17b - Zayif konu listesi.
 *
 * Konu KAPANIR, SILINMEZ (silme ayri bir ucta duruyor): "bunu hallettin"
 * bilgisi ogrencinin gorebilecegi tek ilerleme isareti. Kayit silinirse
 * gecmiste neyin duzeldigi de kaybolur.
 *
 * Her konuya tek dokunusla PLAN MADDESI baglanabilir (SS7-D): zayif konu
 * listesi kendi basina bir gorev listesi degil, plana donusmesi gerekiyor.
 *
 * Veli ve ogrenci ACIK konulari gorur (SS6.1-2: profile islenen her sey
 * veliye acik). Kocun ham gozlemi ozel notta kaliyor (Dalga 14b).
 */
class WeakTopicTest extends TestCase
{
    use RefreshDatabase;

    private function yonetici(): User
    {
        return User::factory()->create(['role' => Role::Admin->value]);
    }

    private function ogrenci(string $ad = 'Öğrenci'): User
    {
        return User::factory()->create([
            'name' => $ad,
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);
    }

    private function atanmisKoc(User $ogrenci): User
    {
        $koc = User::factory()->create(['role' => Role::Coach->value]);
        $koc->coachStudents()->attach($ogrenci->id);

        return $koc;
    }

    private function veli(User $ogrenci): User
    {
        $veli = User::factory()->parent()->create();
        StudentParent::factory()->create(['student_id' => $ogrenci->id, 'parent_id' => $veli->id]);

        return $veli;
    }

    private function konu(User $ogrenci, string $baslik, string $durum = 'open'): WeakTopic
    {
        return WeakTopic::create([
            'student_id' => $ogrenci->id,
            'topic' => $baslik,
            'status' => $durum,
            'created_by' => $this->yonetici()->id,
        ]);
    }

    // --- Ekleme --------------------------------------------------------------

    public function test_a_coach_adds_a_weak_topic(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);
        $ders = Subject::create(['name' => 'Matematik', 'exam_type' => 'tyt']);

        $this->actingAs($koc)
            ->post(route('coach.topics.store', $ogrenci), [
                'topic' => 'Türev - zincir kuralı',
                'subject_id' => $ders->id,
            ])
            ->assertRedirect();

        $konu = WeakTopic::sole();

        $this->assertSame($ogrenci->id, $konu->student_id);
        $this->assertSame($ders->id, $konu->subject_id);
        $this->assertSame('open', $konu->status);
        $this->assertSame($koc->id, $konu->created_by);
    }

    /** Ders ISTEGE BAGLI: her zayif konu bir derse oturmuyor. */
    public function test_the_subject_is_optional(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);

        $this->actingAs($koc)
            ->post(route('coach.topics.store', $ogrenci), ['topic' => 'Soru çözme hızı'])
            ->assertRedirect();

        $this->assertNull(WeakTopic::sole()->subject_id);
    }

    public function test_a_coach_cannot_add_for_an_unassigned_student(): void
    {
        $baskasinin = $this->ogrenci('Başkasının');
        $koc = User::factory()->create(['role' => Role::Coach->value]);

        $this->actingAs($koc)
            ->post(route('coach.topics.store', $baskasinin), ['topic' => 'İzinsiz'])
            ->assertForbidden();

        $this->assertSame(0, WeakTopic::count());
    }

    public function test_a_student_cannot_add_a_topic(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($ogrenci)
            ->post(route('coach.topics.store', $ogrenci), ['topic' => 'Kendime'])
            ->assertForbidden();
    }

    public function test_an_empty_topic_is_refused(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);

        $this->actingAs($koc)->from(route('coach.topics.index', $ogrenci))
            ->post(route('coach.topics.store', $ogrenci), ['topic' => '   '])
            ->assertSessionHasErrors('topic');
    }

    // --- Kapatma -------------------------------------------------------------

    public function test_a_coach_closes_a_topic(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);
        $konu = $this->konu($ogrenci, 'Türev');

        $this->actingAs($koc)
            ->post(route('coach.topics.close', $konu))
            ->assertRedirect();

        $konu->refresh();

        $this->assertSame('closed', $konu->status);
        $this->assertNotNull($konu->closed_at);
    }

    /** Ikinci kez kapatmak kapanma anini ILERI KAYDIRMAZ - markDone ile ayni. */
    public function test_closing_twice_does_not_move_the_moment(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);
        $konu = $this->konu($ogrenci, 'Türev');

        $this->actingAs($koc)->post(route('coach.topics.close', $konu));
        $ilkAn = $konu->fresh()->closed_at;

        $this->travel(2)->hours();
        $this->actingAs($koc)->post(route('coach.topics.close', $konu));

        $this->assertEquals($ilkAn, $konu->fresh()->closed_at);
    }

    /** Kapanan konu yeniden acilabilir: dususun tekrarlamasi olagan. */
    public function test_a_closed_topic_can_be_reopened(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);
        $konu = $this->konu($ogrenci, 'Türev', 'closed');

        $this->actingAs($koc)->post(route('coach.topics.reopen', $konu))->assertRedirect();

        $konu->refresh();

        $this->assertSame('open', $konu->status);
        $this->assertNull($konu->closed_at);
    }

    public function test_a_coach_cannot_close_an_unassigned_students_topic(): void
    {
        $baskasinin = $this->ogrenci('Başkasının');
        $konu = $this->konu($baskasinin, 'Dokunulmaz');

        $this->actingAs(User::factory()->create(['role' => Role::Coach->value]))
            ->post(route('coach.topics.close', $konu))
            ->assertForbidden();

        $this->assertSame('open', $konu->fresh()->status);
    }

    // --- Plana baglama -------------------------------------------------------

    /**
     * Konu tek dokunusla PLAN MADDESINE donusur.
     *
     * Zayif konu listesi kendi basina bir gorev listesi degil; plana
     * donusmezse ogrenci onu hicbir yerde gormez ve liste kocun not
     * defterinden ibaret kalir.
     */
    public function test_a_topic_becomes_a_plan_item(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);
        $ders = Subject::create(['name' => 'Matematik', 'exam_type' => 'tyt']);

        $konu = WeakTopic::create([
            'student_id' => $ogrenci->id,
            'subject_id' => $ders->id,
            'topic' => 'Türev - zincir kuralı',
            'created_by' => $koc->id,
        ]);

        $this->actingAs($koc)->post(route('coach.topics.plan', $konu))->assertRedirect();

        $madde = StudyPlanItem::sole();

        $this->assertSame($ogrenci->id, $madde->student_id);
        $this->assertSame('Türev - zincir kuralı', $madde->title);
        $this->assertSame($ders->id, $madde->subject_id);
        $this->assertSame(PlanPeriod::Week, $madde->period);
        $this->assertSame(
            PlanPeriod::Week->startFor(LocalDay::today()),
            $madde->week_start->toDateString(),
        );
    }

    public function test_a_coach_cannot_plan_for_an_unassigned_student(): void
    {
        $baskasinin = $this->ogrenci('Başkasının');
        $konu = $this->konu($baskasinin, 'Türev');

        $this->actingAs(User::factory()->create(['role' => Role::Coach->value]))
            ->post(route('coach.topics.plan', $konu))
            ->assertForbidden();

        $this->assertSame(0, StudyPlanItem::count());
    }

    // --- Kim gorur -----------------------------------------------------------

    public function test_a_coach_sees_open_and_closed_topics(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);
        $this->konu($ogrenci, 'Açık konu');
        $this->konu($ogrenci, 'Kapanmış konu', 'closed');

        $this->actingAs($koc)->get(route('coach.topics.index', $ogrenci))
            ->assertOk()
            ->assertSee('Açık konu')
            ->assertSee('Kapanmış konu');
    }

    /** SS6.1-2: profile islenen her sey veliye acik. */
    public function test_a_parent_sees_the_open_topics(): void
    {
        $ogrenci = $this->ogrenci('Çocuk');
        $veli = $this->veli($ogrenci);
        $this->konu($ogrenci, 'Türev - zincir kuralı');

        $this->actingAs($veli)->get(route('parent.student', $ogrenci))
            ->assertOk()
            ->assertSee('Türev - zincir kuralı');
    }

    /**
     * KAPANMIS konu veliye ve ogrenciye gosterilmez.
     *
     * "Geliştirilmesi gereken" listesi gecmisin degil BUGUNUN listesi;
     * kapanmislari birakmak liste uzadikca ogrenciyi bogar. Koc gecmisi
     * kendi ekraninda goruyor.
     */
    public function test_a_closed_topic_leaves_the_parents_list(): void
    {
        $ogrenci = $this->ogrenci('Çocuk');
        $veli = $this->veli($ogrenci);
        $this->konu($ogrenci, 'Hallolmuş konu', 'closed');

        $this->actingAs($veli)->get(route('parent.student', $ogrenci))
            ->assertOk()
            ->assertDontSee('Hallolmuş konu');
    }

    public function test_a_student_sees_their_own_open_topics(): void
    {
        $ogrenci = $this->ogrenci();
        $this->konu($ogrenci, 'Paragraf hızı');

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('Paragraf hızı');
    }

    public function test_a_coach_cannot_read_an_unassigned_students_topics(): void
    {
        $baskasinin = $this->ogrenci('Başkasının');

        $this->actingAs(User::factory()->create(['role' => Role::Coach->value]))
            ->get(route('coach.topics.index', $baskasinin))
            ->assertForbidden();
    }
}
