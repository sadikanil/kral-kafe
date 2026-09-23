<?php

namespace Tests\Feature;

use App\Enums\ApprovalStatus;
use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\StudentParent;
use App\Models\StudyGoal;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Dalga 6 - Veli-ogrenci bagi ve salt okunur veli paneli (MVP #8).
 *
 * Asil is yetki siniri: veli YALNIZCA kendi cocugunu gorur. Sinir global
 * scope ile degil, User::accessibleStudentIds()'e delege eden policy + acik
 * scope ile kurulu; bu dosya o sinirin her iki kolunu (izin ve red) test eder.
 */
class ParentPanelTest extends TestCase
{
    use RefreshDatabase;

    private function bagla(User $veli, User ...$ogrenciler): void
    {
        foreach ($ogrenciler as $ogrenci) {
            StudentParent::factory()->create([
                'student_id' => $ogrenci->id,
                'parent_id' => $veli->id,
            ]);
        }
    }

    /**
     * Kapali oturumlar ONAYLI kurulur: bu dosya velinin ne gordugunu siniyor,
     * onay akisinin kendisini degil. Onaysiz kurmak her sureyi sifirlar ve
     * ekranin dogru sayiyi gosterip gostermedigi sinanmamis kalirdi. Onayin
     * veliyi nasil siniri ayri testte (asagida).
     */
    private function oturum(
        User $ogrenci,
        string $bas,
        ?string $bit,
        string $masa = 'Masa 1',
        ApprovalStatus $onay = ApprovalStatus::Approved,
    ): StudySession {
        $b = Carbon::parse($bas, config('kafe.timezone'));
        $s = $bit ? Carbon::parse($bit, config('kafe.timezone')) : null;

        return StudySession::create([
            'student_id' => $ogrenci->id,
            'study_table_id' => StudyTable::firstOrCreate(['name' => $masa])->id,
            'started_at' => $b->copy()->utc(),
            'ended_at' => $s?->copy()->utc(),
            'duration_minutes' => $s ? (int) $b->diffInMinutes($s) : null,
            'end_reason' => $s ? SessionEndReason::Manual->value : null,
            'approval_status' => $s ? $onay->value : ApprovalStatus::Pending->value,
        ]);
    }

    // --- Yetki siniri -------------------------------------------------------

    public function test_accessible_student_ids_follows_the_role(): void
    {
        $veli = User::factory()->parent()->create();
        $cocuk = User::factory()->student()->create();
        $baskasi = User::factory()->student()->create();
        $this->bagla($veli, $cocuk);

        $this->assertSame([$cocuk->id], $veli->accessibleStudentIds());
        $this->assertSame([$cocuk->id], $cocuk->accessibleStudentIds());
        $this->assertNull(User::factory()->admin()->create()->accessibleStudentIds());
        $this->assertSame([], User::factory()->create(['role' => Role::Coach->value])->accessibleStudentIds());

        $this->assertTrue($veli->canViewStudent($cocuk));
        $this->assertFalse($veli->canViewStudent($baskasi));
    }

    public function test_visible_to_scope_lists_only_linked_students(): void
    {
        $veli = User::factory()->parent()->create();
        $cocuk = User::factory()->student()->create();
        User::factory()->student()->create();
        $this->bagla($veli, $cocuk);

        $this->assertSame([$cocuk->id], User::visibleTo($veli)->pluck('id')->all());
        $this->assertCount(2, User::visibleTo(User::factory()->admin()->create())->get());
    }

    public function test_a_parent_can_be_linked_to_several_students_and_a_student_to_several_parents(): void
    {
        $anne = User::factory()->parent()->create();
        $baba = User::factory()->parent()->create();
        $cocuk1 = User::factory()->student()->create();
        $cocuk2 = User::factory()->student()->create();
        $this->bagla($anne, $cocuk1, $cocuk2);
        $this->bagla($baba, $cocuk1);

        $this->assertEqualsCanonicalizing([$cocuk1->id, $cocuk2->id], $anne->students()->pluck('users.id')->all());
        $this->assertEqualsCanonicalizing([$anne->id, $baba->id], $cocuk1->parents()->pluck('users.id')->all());
    }

    public function test_the_same_link_cannot_be_stored_twice(): void
    {
        $veli = User::factory()->parent()->create();
        $cocuk = User::factory()->student()->create();
        $this->bagla($veli, $cocuk);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        $this->bagla($veli, $cocuk);
    }

    // --- Panel erisimi ------------------------------------------------------

    public function test_a_parent_lands_on_the_parent_panel(): void
    {
        $veli = User::factory()->parent()->create(['password' => bcrypt('Parola-123!')]);

        $this->post(route('login'), ['kimlik' => $veli->email, 'password' => 'Parola-123!'])
            ->assertRedirect(route('parent.dashboard'));
    }

    public function test_only_parents_enter_the_parent_panel(): void
    {
        $this->get('/veli')->assertRedirect(route('login'));
        $this->actingAs(User::factory()->student()->create())->get('/veli')->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get('/veli')->assertForbidden();
        $this->actingAs(User::factory()->parent()->create())->get('/veli')->assertOk();
    }

    public function test_a_parent_without_a_subscription_still_enters(): void
    {
        // Abonelik ogrencinin; velinin subscription_status'u pasif olsa da girer.
        $veli = User::factory()->parent()->create(['subscription_status' => 'inactive']);

        $this->actingAs($veli)->get('/veli')->assertOk();
    }

    public function test_the_panel_lists_linked_students_only(): void
    {
        $veli = User::factory()->parent()->create();
        $cocuk = User::factory()->student()->create(['name' => 'Ayşe Bağlı']);
        $baskasi = User::factory()->student()->create(['name' => 'Mehmet Yabancı']);
        $this->bagla($veli, $cocuk);

        $this->actingAs($veli)->get('/veli')
            ->assertOk()
            ->assertSee('Ayşe Bağlı')
            ->assertDontSee('Mehmet Yabancı')
            ->assertSee(route('parent.student', $cocuk));
    }

    public function test_a_parent_with_no_links_sees_an_explanation(): void
    {
        $this->actingAs(User::factory()->parent()->create())->get('/veli')
            ->assertOk()
            ->assertSee('Hesabınıza bağlı öğrenci yok');
    }

    public function test_a_parent_cannot_open_an_unlinked_student(): void
    {
        $veli = User::factory()->parent()->create();
        $cocuk = User::factory()->student()->create();
        $baskasi = User::factory()->student()->create();
        $this->bagla($veli, $cocuk);

        $this->actingAs($veli)->get(route('parent.student', $cocuk))->assertOk();
        $this->actingAs($veli)->get(route('parent.student', $baskasi))->assertForbidden();
    }

    public function test_the_student_page_shows_arrival_and_departure_in_cafe_time(): void
    {
        $veli = User::factory()->parent()->create();
        $cocuk = User::factory()->student()->create();
        $this->bagla($veli, $cocuk);

        $this->travelTo(Carbon::parse('2026-09-16 15:00', config('kafe.timezone')));
        $this->oturum($cocuk, '2026-09-16 10:00', '2026-09-16 12:30', 'Pencere Kenarı');

        $this->actingAs($veli)->get(route('parent.student', $cocuk))
            ->assertOk()
            ->assertSee('16.09.2026')
            ->assertSee('10:00')
            ->assertSee('12:30')
            ->assertSee('2 sa 30 dk')
            ->assertSee('Pencere Kenarı')
            ->assertSee('Son geliş');
    }

    public function test_an_open_session_is_shown_as_currently_inside(): void
    {
        $veli = User::factory()->parent()->create();
        $cocuk = User::factory()->student()->create();
        $this->bagla($veli, $cocuk);

        $this->travelTo(Carbon::parse('2026-09-16 11:00', config('kafe.timezone')));
        $this->oturum($cocuk, '2026-09-16 10:00', null, 'Köşe');

        $this->actingAs($veli)->get('/veli')
            ->assertOk()
            ->assertSee('Şu an içeride')
            ->assertSee('Köşe')
            ->assertSee("10:00'den beri", false);
    }

    public function test_short_sessions_are_left_out_of_the_parent_list(): void
    {
        $veli = User::factory()->parent()->create();
        $cocuk = User::factory()->student()->create();
        $this->bagla($veli, $cocuk);

        $this->travelTo(Carbon::parse('2026-09-16 15:00', config('kafe.timezone')));
        $this->oturum($cocuk, '2026-09-16 10:00', '2026-09-16 10:01', 'Yanlış Okutma');

        $this->actingAs($veli)->get(route('parent.student', $cocuk))
            ->assertOk()
            ->assertDontSee('Yanlış Okutma')
            ->assertSee('Henüz kayıtlı bir çalışma yok');
    }

    /**
     * Dalga 9: onay gelene kadar veli hicbir sey gormez.
     *
     * Sure zaten StudyStats uzerinden suzuluyor, ama oturum LISTESI modele
     * dogrudan gidiyordu - onaysiz bir oturum listede gorunurse kural
     * yalnizca yarim uygulanmis olur.
     */
    public function test_a_parent_does_not_see_a_session_awaiting_approval(): void
    {
        $veli = User::factory()->parent()->create();
        $cocuk = User::factory()->student()->create();
        $this->bagla($veli, $cocuk);

        $this->travelTo(Carbon::parse('2026-09-16 15:00', config('kafe.timezone')));
        $this->oturum($cocuk, '2026-09-16 10:00', '2026-09-16 13:00', 'Onaysız Masa', ApprovalStatus::Pending);

        $this->actingAs($veli)->get(route('parent.student', $cocuk))
            ->assertOk()
            ->assertDontSee('Onaysız Masa');
    }

    public function test_a_parent_does_not_see_a_rejected_session(): void
    {
        $veli = User::factory()->parent()->create();
        $cocuk = User::factory()->student()->create();
        $this->bagla($veli, $cocuk);

        $this->travelTo(Carbon::parse('2026-09-16 15:00', config('kafe.timezone')));
        $this->oturum($cocuk, '2026-09-16 10:00', '2026-09-16 13:00', 'Reddedilen Masa', ApprovalStatus::Rejected);

        $this->actingAs($veli)->get(route('parent.student', $cocuk))
            ->assertOk()
            ->assertDontSee('Reddedilen Masa');
    }

    public function test_the_weekly_goal_progress_is_shown(): void
    {
        $veli = User::factory()->parent()->create();
        $cocuk = User::factory()->student()->create();
        $this->bagla($veli, $cocuk);

        $this->travelTo(Carbon::parse('2026-09-16 15:00', config('kafe.timezone')));
        StudyGoal::create([
            'student_id' => $cocuk->id,
            'period' => 'weekly',
            'target_minutes' => 600,
            'effective_from' => '2026-09-01',
        ]);
        $this->oturum($cocuk, '2026-09-15 10:00', '2026-09-15 13:00');

        $this->actingAs($veli)->get('/veli')
            ->assertOk()
            ->assertSee('Haftalık hedef')
            ->assertSee('3 sa / 10 sa');
    }

    public function test_the_parent_panel_has_no_writing_routes(): void
    {
        $rotalar = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_starts_with($r->uri(), 'veli'));

        $this->assertNotEmpty($rotalar);

        foreach ($rotalar as $rota) {
            $this->assertSame(['GET', 'HEAD'], $rota->methods(), $rota->uri() . ' yazma yontemi tasiyor');
        }
    }

    // --- Yonetici bag kurma -------------------------------------------------

    private function guncellemeVerisi(User $kullanici, array $ek = []): array
    {
        return array_merge([
            'name' => $kullanici->name,
            'email' => $kullanici->email,
            'role' => $kullanici->role,
            'subscription_status' => 'active',
        ], $ek);
    }

    public function test_an_admin_links_students_to_a_parent_from_the_edit_form(): void
    {
        $yonetici = User::factory()->admin()->create();
        $veli = User::factory()->parent()->create();
        $cocuk1 = User::factory()->student()->create();
        $cocuk2 = User::factory()->student()->create();

        $this->actingAs($yonetici)
            ->get(route('admin.users.edit', $veli))
            ->assertOk()
            ->assertSee('Bağlı Öğrenciler')
            ->assertSee($cocuk1->name);

        $this->actingAs($yonetici)
            ->put(route('admin.users.update', $veli), $this->guncellemeVerisi($veli, [
                'student_ids' => [$cocuk1->id, $cocuk2->id],
            ]))
            ->assertRedirect(route('admin.users.index'));

        $this->assertEqualsCanonicalizing([$cocuk1->id, $cocuk2->id], $veli->students()->pluck('users.id')->all());
        $this->assertSame($yonetici->id, $veli->students()->first()->pivot->created_by);

        // Birini kaldir: created_by kalanda korunur, kaldirilan gider.
        $this->actingAs($yonetici)
            ->put(route('admin.users.update', $veli), $this->guncellemeVerisi($veli, [
                'student_ids' => [$cocuk1->id],
            ]))
            ->assertRedirect();

        $this->assertSame([$cocuk1->id], $veli->students()->pluck('users.id')->all());

        // Gizli alan bos gelirse (hicbiri isaretli degil) hepsi kaldirilir.
        $this->actingAs($yonetici)
            ->put(route('admin.users.update', $veli), $this->guncellemeVerisi($veli, [
                'student_ids' => '',
            ]))
            ->assertRedirect();

        $this->assertSame([], $veli->students()->pluck('users.id')->all());
    }

    public function test_links_are_untouched_when_the_form_has_no_link_section(): void
    {
        $yonetici = User::factory()->admin()->create();
        $veli = User::factory()->parent()->create();
        $cocuk = User::factory()->student()->create();
        $this->bagla($veli, $cocuk);

        $this->actingAs($yonetici)
            ->put(route('admin.users.update', $veli), $this->guncellemeVerisi($veli, ['name' => 'Yeni Ad']))
            ->assertRedirect();

        $this->assertSame([$cocuk->id], $veli->fresh()->students()->pluck('users.id')->all());
    }

    public function test_only_students_can_be_linked_as_children(): void
    {
        $yonetici = User::factory()->admin()->create();
        $veli = User::factory()->parent()->create();
        $baskaVeli = User::factory()->parent()->create();

        $this->actingAs($yonetici)
            ->from(route('admin.users.edit', $veli))
            ->put(route('admin.users.update', $veli), $this->guncellemeVerisi($veli, [
                'student_ids' => [$baskaVeli->id],
            ]))
            ->assertRedirect(route('admin.users.edit', $veli))
            ->assertSessionHasErrors('student_ids.0');

        $this->assertSame([], $veli->students()->pluck('users.id')->all());
    }

    public function test_an_admin_links_parents_from_the_student_side_too(): void
    {
        $yonetici = User::factory()->admin()->create();
        $veli = User::factory()->parent()->create();
        $cocuk = User::factory()->student()->create();

        $this->actingAs($yonetici)
            ->get(route('admin.users.edit', $cocuk))
            ->assertOk()
            ->assertSee('Velileri');

        $this->actingAs($yonetici)
            ->put(route('admin.users.update', $cocuk), $this->guncellemeVerisi($cocuk, [
                'parent_ids' => [$veli->id],
            ]))
            ->assertRedirect();

        $this->assertSame([$veli->id], $cocuk->parents()->pluck('users.id')->all());
        $this->assertTrue($veli->canViewStudent($cocuk));
    }

    public function test_the_user_list_shows_how_many_students_a_parent_has(): void
    {
        $veli = User::factory()->parent()->create();
        $this->bagla($veli, User::factory()->student()->create(), User::factory()->student()->create());

        $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.users.index', ['role' => 'parent']))
            ->assertOk()
            ->assertSee('2 öğrenci');
    }
}
