<?php

namespace Tests\Feature;

use App\Enums\CoachNoteKind;
use App\Enums\NoteVisibility;
use App\Enums\Role;
use App\Models\CoachNote;
use App\Models\StudentParent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dalga 14b - Koc notlari ve koc-veli gorusme kaydi.
 *
 * Karar 6 (22 Eylul): notta "veliyle paylas" VARSAYILAN ISARETLI, ama ozel
 * not secenegi kalir. Gerekce SS6.1-2'de: gozlem notuyla veliye giden
 * degerlendirme ayni sey degil; ozel secenegi olmayan bir sistemde koc ham
 * gozlemini hic yazmaz - ozellik kullanilmaz hale gelir.
 *
 * SS6.1-3 ile celismiyor: veliye giden her sey ogrenciye de gorunur. Ozel
 * not veliye GITMEDIGI icin ogrenciden de gizlenebilir; "gizli izleme yok"
 * kurali velinin gordugunu ogrencinin de gormesi demek.
 *
 * Gorusme kaydi (SS7-I) SOHBET DEGIL: tek yonlu kayit, WhatsApp'in yerini
 * almaya calismaz. Ayri tablo yok, kind = meeting.
 */
class CoachNoteTest extends TestCase
{
    use RefreshDatabase;

    private function yonetici(): User
    {
        return User::factory()->create(['role' => Role::Admin->value]);
    }

    private function koc(string $ad = 'Koç'): User
    {
        return User::factory()->create(['name' => $ad, 'role' => Role::Coach->value]);
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
        $koc = $this->koc();
        $koc->coachStudents()->attach($ogrenci->id);

        return $koc;
    }

    private function veli(User $ogrenci): User
    {
        $veli = User::factory()->parent()->create();
        StudentParent::factory()->create([
            'student_id' => $ogrenci->id,
            'parent_id' => $veli->id,
        ]);

        return $veli;
    }

    private function not(User $ogrenci, string $govde, NoteVisibility $gorunurluk = NoteVisibility::Parent): CoachNote
    {
        return CoachNote::create([
            'student_id' => $ogrenci->id,
            'created_by' => $this->yonetici()->id,
            'kind' => CoachNoteKind::Note->value,
            'visibility' => $gorunurluk->value,
            'body' => $govde,
        ]);
    }

    // --- Yazma ---------------------------------------------------------------

    public function test_a_coach_writes_a_note_for_their_student(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);

        $this->actingAs($koc)
            ->post(route('coach.notes.store', $ogrenci), [
                'kind' => 'note',
                'visibility' => 'parent',
                'body' => 'Matematikte tempo düştü, hafta içi tekrar ekledik.',
            ])
            ->assertRedirect();

        $not = CoachNote::sole();

        $this->assertSame($ogrenci->id, $not->student_id);
        $this->assertSame($koc->id, $not->created_by);
        $this->assertSame(CoachNoteKind::Note, $not->kind);
        $this->assertSame(NoteVisibility::Parent, $not->visibility);
    }

    /**
     * Gorunurluk verilmezse VELIYE ACIK (karar 6).
     *
     * Varsayilanin kapali olmasi, SS6.1-2'nin ("veli profile islenen her seyi
     * gorur") tersine calisirdi: koc her seferinde isaretlemeyi unutur ve
     * veli hicbir sey gormezdi.
     */
    public function test_the_default_visibility_is_open_to_the_parent(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);

        $this->actingAs($koc)
            ->post(route('coach.notes.store', $ogrenci), [
                'kind' => 'note',
                'body' => 'Deneme sonrası görüşüldü.',
            ])
            ->assertRedirect();

        $this->assertSame(NoteVisibility::Parent, CoachNote::sole()->visibility);
    }

    public function test_a_coach_cannot_write_for_an_unassigned_student(): void
    {
        $baskasinin = $this->ogrenci('Başkasının');
        $koc = $this->koc();

        $this->actingAs($koc)
            ->post(route('coach.notes.store', $baskasinin), [
                'kind' => 'note',
                'body' => 'İzinsiz not',
            ])
            ->assertForbidden();

        $this->assertSame(0, CoachNote::count());
    }

    /** Yonetici zaten koctur (karar 11): atanma gerekmez. */
    public function test_an_admin_writes_without_being_assigned(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($this->yonetici())
            ->post(route('coach.notes.store', $ogrenci), [
                'kind' => 'note',
                'body' => 'Yönetici notu',
            ])
            ->assertRedirect();

        $this->assertSame(1, CoachNote::count());
    }

    public function test_a_student_cannot_write_a_note_about_themselves(): void
    {
        $ogrenci = $this->ogrenci();

        $this->actingAs($ogrenci)
            ->post(route('coach.notes.store', $ogrenci), [
                'kind' => 'note',
                'body' => 'Kendime not',
            ])
            ->assertForbidden();
    }

    public function test_an_empty_note_is_refused(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);

        $this->actingAs($koc)->from(route('coach.notes.index', $ogrenci))
            ->post(route('coach.notes.store', $ogrenci), ['kind' => 'note', 'body' => '   '])
            ->assertSessionHasErrors('body');

        $this->assertSame(0, CoachNote::count());
    }

    public function test_an_unknown_visibility_is_refused(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);

        $this->actingAs($koc)->from(route('coach.notes.index', $ogrenci))
            ->post(route('coach.notes.store', $ogrenci), [
                'kind' => 'note',
                'visibility' => 'herkese',
                'body' => 'Geçersiz',
            ])
            ->assertSessionHasErrors('visibility');
    }

    // --- Gorusme kaydi -------------------------------------------------------

    /**
     * Gorusme kaydi TARIH ISTER.
     *
     * Gorusme cogu zaman sonradan yaziliyor; created_at'i gorusme ani saymak
     * "ne zaman konusuldu" sorusunu gunler kaydirirdi. Duz notta boyle bir
     * ayrim yok - yazildigi an olayin kendisi.
     */
    public function test_a_meeting_record_requires_the_date_it_happened(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);

        $this->actingAs($koc)->from(route('coach.notes.index', $ogrenci))
            ->post(route('coach.notes.store', $ogrenci), [
                'kind' => 'meeting',
                'body' => 'Ekim planı konuşuldu, hedef 20 saat.',
            ])
            ->assertSessionHasErrors('occurred_on');

        $this->assertSame(0, CoachNote::count());
    }

    public function test_a_meeting_record_keeps_the_date_it_happened(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);

        $this->actingAs($koc)
            ->post(route('coach.notes.store', $ogrenci), [
                'kind' => 'meeting',
                'occurred_on' => '2026-09-18',
                'body' => 'Ekim planı konuşuldu, hedef 20 saat.',
            ])
            ->assertRedirect();

        $not = CoachNote::sole();

        $this->assertSame(CoachNoteKind::Meeting, $not->kind);
        $this->assertSame('2026-09-18', $not->occurred_on->toDateString());
    }

    public function test_a_plain_note_needs_no_date(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);

        $this->actingAs($koc)
            ->post(route('coach.notes.store', $ogrenci), [
                'kind' => 'note',
                'body' => 'Bugün odaklanamadı.',
            ])
            ->assertRedirect();

        $this->assertNull(CoachNote::sole()->occurred_on);
    }

    // --- Kim gorur -----------------------------------------------------------

    public function test_a_parent_sees_a_shared_note(): void
    {
        $ogrenci = $this->ogrenci('Çocuk');
        $veli = $this->veli($ogrenci);
        $this->not($ogrenci, 'Matematikte tempo düştü.');

        $this->actingAs($veli)->get(route('parent.student', $ogrenci))
            ->assertOk()
            ->assertSee('Matematikte tempo düştü.');
    }

    /**
     * OZEL NOT VELIYE GITMEZ. Bu dalganin en onemli siniri: sizarsa koc bir
     * daha ham gozlem yazmaz ve ozellik olur.
     */
    public function test_a_parent_never_sees_a_private_note(): void
    {
        $ogrenci = $this->ogrenci('Çocuk');
        $veli = $this->veli($ogrenci);
        $this->not($ogrenci, 'Ailevi bir sıkıntı olabilir, gözlemliyorum.', NoteVisibility::Private);

        $this->actingAs($veli)->get(route('parent.student', $ogrenci))
            ->assertOk()
            ->assertDontSee('Ailevi bir sıkıntı olabilir');
    }

    /** SS6.1-3: veliye giden ogrenciye de gorunur. Gizli izleme yok. */
    public function test_a_student_sees_what_their_parent_sees(): void
    {
        $ogrenci = $this->ogrenci();
        $this->not($ogrenci, 'Matematikte tempo düştü.');

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertSee('Matematikte tempo düştü.');
    }

    public function test_a_student_does_not_see_a_private_note(): void
    {
        $ogrenci = $this->ogrenci();
        $this->not($ogrenci, 'Ailevi bir sıkıntı olabilir.', NoteVisibility::Private);

        $this->actingAs($ogrenci)->get(route('user.dashboard'))
            ->assertOk()
            ->assertDontSee('Ailevi bir sıkıntı olabilir');
    }

    public function test_a_coach_sees_both_kinds_for_their_own_student(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);
        $this->not($ogrenci, 'Veliyle paylaşılan');
        $this->not($ogrenci, 'Yalnız koça açık', NoteVisibility::Private);

        $this->actingAs($koc)->get(route('coach.notes.index', $ogrenci))
            ->assertOk()
            ->assertSee('Veliyle paylaşılan')
            ->assertSee('Yalnız koça açık');
    }

    public function test_a_coach_cannot_read_an_unassigned_students_notes(): void
    {
        $baskasinin = $this->ogrenci('Başkasının');
        $this->not($baskasinin, 'Gizli kalmalı');

        $this->actingAs($this->koc())
            ->get(route('coach.notes.index', $baskasinin))
            ->assertForbidden();
    }

    public function test_a_parent_cannot_read_another_students_notes(): void
    {
        $baskasinin = $this->ogrenci('Başkasının');
        $this->not($baskasinin, 'Başkasının notu');

        $this->actingAs(User::factory()->parent()->create())
            ->get(route('parent.student', $baskasinin))
            ->assertForbidden();
    }

    // --- Silme ---------------------------------------------------------------

    public function test_a_coach_deletes_a_note_of_their_own_student(): void
    {
        $ogrenci = $this->ogrenci();
        $koc = $this->atanmisKoc($ogrenci);
        $not = $this->not($ogrenci, 'Silinecek');

        $this->actingAs($koc)
            ->delete(route('coach.notes.destroy', $not))
            ->assertRedirect();

        $this->assertSame(0, CoachNote::count());
    }

    public function test_a_coach_cannot_delete_an_unassigned_students_note(): void
    {
        $baskasinin = $this->ogrenci('Başkasının');
        $not = $this->not($baskasinin, 'Dokunulmaz');

        $this->actingAs($this->koc())
            ->delete(route('coach.notes.destroy', $not))
            ->assertForbidden();

        $this->assertSame(1, CoachNote::count());
    }
}
