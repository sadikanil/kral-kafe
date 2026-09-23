<?php

namespace Tests\Feature\Smoke;

use App\Enums\ApprovalStatus;
use App\Enums\PaymentStatus;
use App\Enums\Role;
use App\Enums\SessionEndReason;
use App\Models\CoachNote;
use App\Models\Consumption;
use App\Models\ExamEvent;
use App\Models\ExamResult;
use App\Models\ExamResultSubject;
use App\Models\Location;
use App\Models\Package;
use App\Models\Payment;
use App\Models\PrivateLessonSlot;
use App\Models\Product;
use App\Models\StudentCommitment;
use App\Models\StudentParent;
use App\Models\StudyGoal;
use App\Models\StudyLog;
use App\Models\StudyPlanItem;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Models\Subject;
use App\Models\Subscription;
use App\Models\User;
use App\Models\WeakTopic;
use App\Models\WeeklyReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Veli alani - uctan uca duman testi (QA turu).
 *
 * Kapsanan rotalar (hepsi GET; veli paneli salt okunur):
 *   parent.dashboard  /veli
 *   parent.student    /veli/ogrenci/{student}
 *   parent.report     /veli/ogrenci/{student}/rapor
 *   parent.payments   /veli/ogrenci/{student}/odemeler
 *   parent.exams      /veli/denemeler
 *
 * Saat: 29 Eylul 2026 Sali 14:00 (kafe saati). Suren hafta 28 Eyl - 4 Eki,
 * tamamlanmis son hafta 21-27 Eyl.
 */
class ParentSmokeTest extends TestCase
{
    use RefreshDatabase;

    private const BU_HAFTA = '2026-09-28';

    private const GECEN_HAFTA = '2026-09-21';

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
    }

    // --- Yardimcilar ----------------------------------------------------------

    private function veli(): User
    {
        return User::factory()->parent()->create(['name' => 'Fatma Işıkoğlu']);
    }

    private function bagla(User $veli, User ...$ogrenciler): void
    {
        foreach ($ogrenciler as $ogrenci) {
            StudentParent::factory()->create(['student_id' => $ogrenci->id, 'parent_id' => $veli->id]);
        }
    }

    private function ogrenci(string $ad, ?\Database\Factories\PackageFactory $paket = null): User
    {
        $fabrika = User::factory()->student();

        if ($paket !== null) {
            $fabrika = $fabrika->withPackage($paket);
        }

        return $fabrika->create(['name' => $ad, 'grade' => '12', 'field' => 'say']);
    }

    private function koc(User $ogrenci): User
    {
        $koc = User::factory()->create(['role' => Role::Coach->value, 'name' => 'Koç Gülşen']);
        $koc->coachStudents()->attach($ogrenci->id);

        return $koc;
    }

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

    private function ders(string $kod): Subject
    {
        return Subject::where('code', $kod)->sole();
    }

    private function kayit(User $ogrenci, StudySession $oturum, int $adet, string $birim, ?Subject $ders, string $an, ?string $not = null): StudyLog
    {
        $kayit = StudyLog::create([
            'student_id' => $ogrenci->id,
            'study_session_id' => $oturum->id,
            'subject_id' => $ders?->id,
            'amount' => $adet,
            'unit' => $birim,
            'note' => $not,
        ]);
        $kayit->forceFill(['created_at' => Carbon::parse($an, config('kafe.timezone'))->utc()])->save();

        return $kayit;
    }

    /** @param array<string,array{0:int,1:int}> $netler ders kodu => [dogru, yanlis] */
    private function sonuc(User $ogrenci, string $baslik, string $gun, array $netler, array $ek = []): ExamResult
    {
        $olay = ExamEvent::create(['title' => $baslik, 'exam_type' => 'tyt', 'exam_date' => $gun, 'starts_at' => '10:00']);

        $sonuc = ExamResult::create(array_merge([
            'exam_event_id' => $olay->id,
            'student_id' => $ogrenci->id,
        ], $ek));

        foreach ($netler as $kod => [$dogru, $yanlis]) {
            ExamResultSubject::create([
                'exam_result_id' => $sonuc->id,
                'subject_id' => $this->ders($kod)->id,
                'correct' => $dogru,
                'wrong' => $yanlis,
                'blank' => 0,
            ]);
        }

        return $sonuc->fresh();
    }

    private function tuket(User $ogrenci, string $urunAdi, string $an, int $adet, float $fiyat, int $kapsanan = 0): Consumption
    {
        $urun = Product::firstOrCreate(['name' => $urunAdi], ['unit_price' => $fiyat, 'unit_type' => 'adet']);
        $raf = Location::firstOrCreate(['qr_code' => 'LOC-SMOKE'], ['name' => 'Raf', 'type' => 'shelf']);

        return Consumption::create([
            'user_id' => $ogrenci->id, 'product_id' => $urun->id, 'location_id' => $raf->id,
            'quantity' => $adet, 'covered_quantity' => $kapsanan, 'unit_price' => $fiyat,
            'consumed_at' => Carbon::parse($an, config('kafe.timezone'))->utc(),
        ]);
    }

    /** Ayni veliye bagli uc cocuk, uc farkli durumda. */
    private function ucCocukluVeli(): array
    {
        $veli = $this->veli();
        $cagla = $this->ogrenci('Çağla Işık', Package::factory()->tier3());
        $sukru = $this->ogrenci('Şükrü Öztürk', Package::factory()->tier1());
        $ahmet = $this->ogrenci('Ahmet Ünal');
        $this->bagla($veli, $cagla, $sukru, $ahmet);

        return [$veli, $cagla, $sukru, $ahmet];
    }

    // =========================================================================
    // parent.dashboard
    // =========================================================================

    public function test_dashboard_lists_every_linked_child_with_their_state_and_links(): void
    {
        [$veli, $cagla, $sukru, $ahmet] = $this->ucCocukluVeli();
        $yabanci = $this->ogrenci('Yabancı Öğrenci', Package::factory()->tier1());
        $this->oturum($yabanci, '2026-09-29 09:00', null, 'Yabancı Masa');

        $this->oturum($cagla, '2026-09-29 09:00', '2026-09-29 11:30', 'Pencere Önü');
        $this->oturum($cagla, '2026-09-29 12:30', null, 'Pencere Önü');
        $this->oturum($sukru, '2026-09-28 16:00', '2026-09-28 18:15', 'Köşe Masa');

        $yanit = $this->actingAs($veli)->get(route('parent.dashboard'))->assertOk();

        $yanit->assertSeeInOrder(['Ahmet Ünal', 'Çağla Işık', 'Şükrü Öztürk'])
            ->assertDontSee('Yabancı Öğrenci')
            ->assertDontSee('Yabancı Masa')
            // Cagla iceride
            ->assertSee('Şu an içeride')
            ->assertSee('giriş 12:30')
            ->assertSee('2 sa 30 dk') // bugun onayli sure
            // Sukru dun gelmis
            ->assertSee('Son geliş: 28.09.2026 16:00')
            ->assertSee('18:15')
            // Ahmet hic gelmemis, paketsiz
            ->assertSee('Henüz kayıtlı bir çalışma yok.')
            // Paket satirlari
            ->assertSee('Kral')
            ->assertSee('Standart');

        foreach ([$cagla, $sukru, $ahmet] as $cocuk) {
            $yanit->assertSee(route('parent.student', $cocuk), false)
                ->assertSee(route('parent.report', $cocuk), false)
                ->assertSee(route('parent.payments', $cocuk), false);
        }

        // Menudeki linkler de cozulmus olmali.
        $yanit->assertSee(route('parent.exams'), false);
    }

    /** Panel acilisi guncel paketin odeme durumunu turetip yazar (GET ama idempotan). */
    public function test_dashboard_marks_an_unpaid_package_past_its_due_date_as_overdue(): void
    {
        $veli = $this->veli();
        $cocuk = $this->ogrenci('Çağla Işık', Package::factory()->tier3());
        $this->bagla($veli, $cocuk);
        $abonelik = $cocuk->subscriptions()->sole();
        $this->assertSame(PaymentStatus::Pending, $abonelik->payment_status);

        $this->actingAs($veli)->get(route('parent.dashboard'))
            ->assertOk()
            ->assertSee('Ödeme: Gecikmiş');

        $this->assertSame(PaymentStatus::Overdue, $abonelik->fresh()->payment_status);

        // Tam odeme gelince ayni ekran "Odendi" der.
        Payment::create(['subscription_id' => $abonelik->id, 'amount' => 7500, 'paid_at' => '2026-09-29', 'method' => 'cash']);

        $this->actingAs($veli)->get(route('parent.dashboard'))
            ->assertOk()
            ->assertSee('Ödeme: Ödendi');

        $this->assertSame(PaymentStatus::Paid, $abonelik->fresh()->payment_status);
    }

    public function test_dashboard_shows_the_official_exam_countdown_and_the_next_exam(): void
    {
        [$veli] = $this->ucCocukluVeli();
        ExamEvent::create(['title' => 'YKS 2027', 'exam_type' => 'official', 'exam_date' => '2027-06-19']);
        ExamEvent::create(['title' => 'Kral TYT Denemesi 4', 'exam_type' => 'tyt', 'exam_date' => '2026-10-03', 'starts_at' => '10:00']);
        ExamEvent::create(['title' => 'Geçmiş Deneme', 'exam_type' => 'tyt', 'exam_date' => '2026-09-26']);

        $this->actingAs($veli)->get(route('parent.dashboard'))
            ->assertOk()
            ->assertSee('Sınava kalan')
            ->assertSee('YKS 2027')
            ->assertSee('Sıradaki deneme:')
            ->assertSee('Kral TYT Denemesi 4') // tier3 cocuk var: adlar acik
            ->assertSee('4 gün kaldı')
            ->assertDontSee('Geçmiş Deneme')
            ->assertSee(route('parent.exams'), false);
    }

    public function test_dashboard_without_children_explains_itself(): void
    {
        $this->actingAs($this->veli())->get(route('parent.dashboard'))
            ->assertOk()
            ->assertSee('Hesabınıza bağlı öğrenci yok');
    }

    public function test_dashboard_is_only_for_parents(): void
    {
        $this->get(route('parent.dashboard'))->assertRedirect(route('login'));

        $ogrenci = $this->ogrenci('Öğrenci', Package::factory()->tier1());
        $this->actingAs($ogrenci)->get(route('parent.dashboard'))->assertForbidden();
        $this->actingAs($this->koc($ogrenci))->get(route('parent.dashboard'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('parent.dashboard'))->assertForbidden();
    }

    // =========================================================================
    // parent.student
    // =========================================================================

    public function test_child_page_shows_every_section_with_realistic_data(): void
    {
        [$veli, $cagla] = $this->ucCocukluVeli();
        $koc = $this->koc($cagla);
        $turkce = $this->ders('tyt_turkce');
        $matematik = $this->ders('tyt_matematik');

        // Sure ve oturumlar
        StudyGoal::create(['student_id' => $cagla->id, 'period' => 'weekly', 'target_minutes' => 600, 'effective_from' => '2026-09-01']);
        $sabah = $this->oturum($cagla, '2026-09-29 09:00', '2026-09-29 11:30', 'Pencere Önü');
        $this->oturum($cagla, '2026-09-28 20:00', '2026-09-28 20:01', 'Yanlış Okutma');
        $this->oturum($cagla, '2026-09-27 10:00', '2026-09-27 13:00', 'Onay Bekleyen Masa', ApprovalStatus::Pending);

        // Calisma kayitlari (ogrencinin beyani)
        $this->kayit($cagla, $sabah, 120, 'soru', $matematik, '2026-09-29 11:15', 'Türev ağırlıklı');
        $this->kayit($cagla, $sabah, 1, 'deneme', null, '2026-09-28 20:45');

        // Takvim: plan maddesi, sabit program, ozel ders
        StudyPlanItem::create([
            'student_id' => $cagla->id, 'subject_id' => $turkce->id, 'title' => 'Paragraf: ana düşünce',
            'plan_date' => '2026-09-30', 'week_start' => self::BU_HAFTA, 'period' => 'week',
            'starts_at' => '16:00', 'duration_minutes' => 45, 'created_by' => $koc->id,
        ]);
        StudentCommitment::create([
            'student_id' => $cagla->id, 'kind' => 'okul', 'title' => 'Kadıköy Anadolu Lisesi',
            'weekday' => 2, 'starts_at' => '08:30', 'ends_at' => '15:00',
        ]);
        PrivateLessonSlot::create([
            'student_id' => $cagla->id, 'weekday' => 4, 'starts_at' => '17:00', 'ends_at' => '18:00',
            'starts_on' => '2026-09-01',
        ]);

        // Zayif konular, koc notlari, deneme sonuclari
        WeakTopic::create(['student_id' => $cagla->id, 'subject_id' => $turkce->id, 'topic' => 'Paragrafta ana düşünce', 'created_by' => $koc->id]);
        WeakTopic::create(['student_id' => $cagla->id, 'topic' => 'Üslü sayılar', 'status' => 'closed', 'closed_at' => now(), 'created_by' => $koc->id]);
        CoachNote::create(['student_id' => $cagla->id, 'created_by' => $koc->id, 'kind' => 'note', 'visibility' => 'parent', 'body' => 'Çağla bu hafta çok istekliydi, geometride ilerleme var.']);
        CoachNote::create(['student_id' => $cagla->id, 'created_by' => $koc->id, 'kind' => 'meeting', 'visibility' => 'parent', 'body' => 'Veliyle hedef görüşmesi yapıldı.', 'occurred_on' => '2026-09-25']);
        CoachNote::create(['student_id' => $cagla->id, 'created_by' => $koc->id, 'kind' => 'note', 'visibility' => 'private', 'body' => 'GİZLİ: yalnız koç görür.']);
        $this->sonuc($cagla, 'Kral TYT Denemesi 2', '2026-09-12', ['tyt_turkce' => [25, 4], 'tyt_matematik' => [15, 4]]);
        $this->sonuc($cagla, 'Kral TYT Denemesi 3', '2026-09-26', ['tyt_turkce' => [30, 8], 'tyt_matematik' => [20, 4]], [
            'rank_institution' => 5, 'total_institution' => 120, 'note' => 'Matematikte hız çalışılmalı.',
        ]);

        $yanit = $this->actingAs($veli)->get(route('parent.student', $cagla))->assertOk();

        $yanit
            // Sekmeler
            ->assertSee(route('parent.report', $cagla), false)
            ->assertSee(route('parent.payments', $cagla), false)
            // Ozet
            ->assertSee('Çağla Işık')
            ->assertSee('Son geliş: 29.09.2026 09:00')
            ->assertSee('2 sa 30 dk / 10 sa')
            // Ozel ders (tier3)
            ->assertSee('Özel dersler · önümüzdeki 2 hafta')
            ->assertSee('17:00–18:00')
            // Takvim
            ->assertSee('Paragraf: ana düşünce')
            ->assertSee('16:00 · 45 dk')
            ->assertSee('Okul · Kadıköy Anadolu Lisesi')
            // Calisma kayitlari
            ->assertSee('Çalışma kayıtları · son 14 gün')
            ->assertSee($matematik->name . ' · 120 soru')
            ->assertSee('Türev ağırlıklı')
            ->assertSee('Genel · 1 deneme')
            ->assertSee('20:45')
            ->assertSeeInOrder(['29 Eylül Salı', 'Türev ağırlıklı', '28 Eylül Pazartesi', 'Genel · 1 deneme'])
            // Zayif konular: yalnizca acik olan
            ->assertSee('Geliştirilmesi gereken konular')
            ->assertSee('Paragrafta ana düşünce')
            ->assertDontSee('Üslü sayılar')
            // Koc notlari: yalnizca paylasilan
            ->assertSee('Çağla bu hafta çok istekliydi, geometride ilerleme var.')
            ->assertSee('Veli görüşmesi')
            ->assertSee('25.09.2026')
            ->assertSee('Koç Gülşen')
            ->assertDontSee('GİZLİ: yalnız koç görür.')
            // Deneme sonuclari + net grafigi
            ->assertSee('Net gelişimi')
            ->assertSee('TYT · 2 deneme')
            ->assertSeeInOrder(['Kral TYT Denemesi 3', 'Kral TYT Denemesi 2'])
            ->assertSee('Toplam net 47,00')
            ->assertSee('26.09.2026')
            ->assertSee('120 kişide 5.')
            ->assertSee('Matematikte hız çalışılmalı.')
            // Gelis-cikis tablosu: kisa ve onaysiz oturum yok
            ->assertSee('Pencere Önü')
            ->assertDontSee('Yanlış Okutma')
            ->assertDontSee('Onay Bekleyen Masa')
            ->assertSee('Son 14 gün');

        // Salt okunur: ogrenci ya da koc formlari cizilmemeli.
        $yanit->assertDontSee('✓ Bitti')
            ->assertDontSee(route('coach.plan.store', $cagla), false)
            ->assertDontSee('name="_method"', false);
    }

    /** Takvimin PAZAR sutunu: hafta sinirindaki madde kaybolmamali. */
    public function test_child_page_calendar_shows_items_on_every_day_of_the_week_including_sunday(): void
    {
        [$veli, $cagla] = $this->ucCocukluVeli();

        foreach (['2026-09-28' => 'Pazartesi maddesi', '2026-10-03' => 'Cumartesi maddesi', '2026-10-04' => 'Pazar tekrarı: türev'] as $gun => $baslik) {
            StudyPlanItem::create([
                'student_id' => $cagla->id, 'title' => $baslik, 'plan_date' => $gun,
                'week_start' => self::BU_HAFTA, 'period' => 'week',
            ]);
        }

        $this->actingAs($veli)->get(route('parent.student', $cagla))
            ->assertOk()
            ->assertSee('Pazartesi maddesi')
            ->assertSee('Cumartesi maddesi')
            ->assertSee('Pazar tekrarı: türev')
            ->assertSee('0 / 3 tamamlandı');
    }

    public function test_child_page_week_navigation_and_bad_week_values(): void
    {
        [$veli, $cagla] = $this->ucCocukluVeli();
        StudyPlanItem::create([
            'student_id' => $cagla->id, 'title' => 'Gelecek hafta: limit', 'plan_date' => '2026-10-07',
            'week_start' => '2026-10-05', 'period' => 'week',
        ]);

        $bu = $this->actingAs($veli)->get(route('parent.student', $cagla))->assertOk();
        $bu->assertDontSee('Gelecek hafta: limit')
            ->assertSee(route('parent.student', [$cagla, 'hafta' => '2026-10-05']), false)
            ->assertSee(route('parent.student', [$cagla, 'hafta' => '2026-09-21']), false);

        // Hafta ortasindaki bir gun de o haftayi acar.
        $this->actingAs($veli)->get(route('parent.student', [$cagla, 'hafta' => '2026-10-08']))
            ->assertOk()
            ->assertSee('Gelecek hafta: limit')
            ->assertSee('5 Ekim – 11 Ekim 2026');

        // Elle bozulmus adres 500 vermez, suren haftaya duser.
        foreach (['bozuk-tarih', '', '2026-99-99'] as $deger) {
            $this->actingAs($veli)->get(route('parent.student', [$cagla, 'hafta' => $deger]))->assertOk();
        }
        $this->actingAs($veli)->get(route('parent.student', $cagla) . '?hafta[]=2026-10-05')
            ->assertOk()
            ->assertSee('28 Eylül – 4 Ekim 2026');
    }

    public function test_child_page_for_a_brand_new_child_renders_empty_states(): void
    {
        [$veli, , , $ahmet] = $this->ucCocukluVeli();

        $this->actingAs($veli)->get(route('parent.student', $ahmet))
            ->assertOk()
            ->assertSee('Ahmet Ünal')
            ->assertSee('Henüz kayıtlı bir çalışma yok.')
            ->assertSee('Henüz kayıt yok.')
            ->assertSee('Haftalık hedef tanımlanmamış.')
            ->assertDontSee('Özel dersler')
            ->assertDontSee('Koç notları')
            ->assertDontSee('Deneme sonuçları');
    }

    public function test_child_page_is_closed_to_unlinked_children_other_users_and_other_roles(): void
    {
        [$veli, $cagla] = $this->ucCocukluVeli();
        $yabanci = $this->ogrenci('Yabancı Öğrenci', Package::factory()->tier1());

        // Bagli olmayan cocuk: 403
        $this->actingAs($veli)->get(route('parent.student', $yabanci))->assertForbidden();

        // Olmayan kimlik: 404
        $this->actingAs($veli)->get('/veli/ogrenci/999999')->assertNotFound();

        // Bagli ama ogrenci olmayan kullanici (rolu sonradan degismis): 404
        $eskiOgrenci = $this->ogrenci('Rolü Değişen');
        $this->bagla($veli, $eskiOgrenci);
        $eskiOgrenci->forceFill(['role' => Role::Parent->value])->save();
        $this->actingAs($veli)->get(route('parent.student', $eskiOgrenci))->assertNotFound();

        // Baska veli kendi cocugu olmayan Cagla'yi goremez.
        $this->actingAs(User::factory()->parent()->create())->get(route('parent.student', $cagla))->assertForbidden();

        // Diger roller veli rotasina giremez (kendi cocugu olsa bile).
        $this->actingAs($cagla)->get(route('parent.student', $cagla))->assertForbidden();
        $this->actingAs($this->koc($cagla))->get(route('parent.student', $cagla))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('parent.student', $cagla))->assertForbidden();
        auth()->logout();
        $this->get(route('parent.student', $cagla))->assertRedirect(route('login'));
    }

    /** Koc gece yarisindan sonra not yazdi: tarih kafe gunune gore. */
    public function test_child_page_dates_a_late_night_coach_note_by_the_cafe_day(): void
    {
        [$veli, $cagla] = $this->ucCocukluVeli();
        $not = CoachNote::create(['student_id' => $cagla->id, 'created_by' => $this->koc($cagla)->id, 'body' => 'Gece yazılan not: yarın erken gel.']);
        $not->forceFill(['created_at' => Carbon::parse('2026-09-29 00:30', config('kafe.timezone'))->utc()])->save();

        $html = $this->actingAs($veli)->get(route('parent.student', $cagla))
            ->assertOk()
            ->assertSee('Gece yazılan not: yarın erken gel.')
            ->getContent();

        // Yalnizca not kartinin tarihi ("Son 14 gun" seridi de tarih tasiyor).
        $kart = mb_substr($html, mb_strpos($html, 'Koç notları'));
        $kart = mb_substr($kart, 0, mb_strpos($kart, 'Gece yazılan not'));
        $this->assertStringContainsString('29.09.2026', $kart);
        $this->assertStringNotContainsString('28.09.2026', $kart);
    }

    // =========================================================================
    // parent.report
    // =========================================================================

    private function gecenHaftaVerisi(User $ogrenci): void
    {
        StudyGoal::create(['student_id' => $ogrenci->id, 'period' => 'weekly', 'target_minutes' => 600, 'effective_from' => '2026-09-01']);
        // Onceki hafta (14-20 Eyl): 2 sa
        $this->oturum($ogrenci, '2026-09-16 10:00', '2026-09-16 12:00');
        // Rapor haftasi (21-27 Eyl): 2 sa 30 dk + 1 sa 30 dk = 4 sa, 2 gun
        $sali = $this->oturum($ogrenci, '2026-09-22 10:00', '2026-09-22 12:30');
        $this->oturum($ogrenci, '2026-09-24 15:00', '2026-09-24 16:30');
        // Onaysiz oturum rapora girmez
        $this->oturum($ogrenci, '2026-09-25 10:00', '2026-09-25 14:00', 'Masa 1', ApprovalStatus::Pending);

        $this->kayit($ogrenci, $sali, 200, 'soru', $this->ders('tyt_matematik'), '2026-09-22 12:00');

        foreach (['Türev tekrarı' => 'done', 'Paragraf 40 soru' => 'done', 'Limit testleri' => 'open'] as $baslik => $durum) {
            StudyPlanItem::create([
                'student_id' => $ogrenci->id, 'title' => $baslik, 'plan_date' => '2026-09-23',
                'week_start' => self::GECEN_HAFTA, 'period' => 'week', 'status' => $durum,
            ]);
        }

        $this->sonuc($ogrenci, 'Kral TYT Denemesi 2', '2026-09-12', ['tyt_turkce' => [25, 4], 'tyt_matematik' => [15, 4]]);
        $this->sonuc($ogrenci, 'Kral TYT Denemesi 3', '2026-09-26', ['tyt_turkce' => [30, 8], 'tyt_matematik' => [20, 4]]);
    }

    public function test_report_defaults_to_last_finished_week_and_is_stored_once(): void
    {
        [$veli, $cagla] = $this->ucCocukluVeli();
        $this->gecenHaftaVerisi($cagla);

        $this->actingAs($veli)->get(route('parent.report', $cagla))
            ->assertOk()
            ->assertSee('Çağla Işık')
            ->assertSee('4 sa')              // onayli sure
            ->assertSee('+2 sa')             // onceki haftaya gore
            ->assertSee('Geldiği gün')
            ->assertSee('200 soru')
            ->assertSee('2 / 3')             // plan tamamlama
            ->assertSee('4 sa / 10 sa')      // hedef
            ->assertSee('Kral TYT Denemesi 3')
            ->assertSee('47,00')
            ->assertSee('+9,00')             // net degisimi
            ->assertSee('26.09.2026')
            ->assertSee(route('parent.student', $cagla), false)
            ->assertSee(route('parent.payments', $cagla), false);

        $rapor = WeeklyReport::sole();
        $this->assertSame($cagla->id, $rapor->student_id);
        $this->assertSame(self::GECEN_HAFTA, $rapor->week_start->toDateString());
        $this->assertSame(240, $rapor->payload['minutes']);
        $this->assertSame(2, $rapor->payload['attended_days']);

        // Ikinci acilis (yenile / ikinci veli) yeni satir uretmez.
        $this->actingAs($veli)->get(route('parent.report', [$cagla, 'hafta' => self::GECEN_HAFTA]))->assertOk();
        $this->assertSame(1, WeeklyReport::count());
    }

    /** Pazartesi 00:30 (UTC'de hala pazar): gecen hafta bitmis sayilmali. */
    public function test_report_is_ready_just_after_midnight_on_monday(): void
    {
        [$veli, $cagla] = $this->ucCocukluVeli();
        $this->oturum($cagla, '2026-09-22 10:00', '2026-09-22 12:30');
        $this->travelTo(Carbon::parse('2026-09-28 00:30', config('kafe.timezone')));

        $this->actingAs($veli)->get(route('parent.report', $cagla))
            ->assertOk()
            ->assertDontSee('Hafta tamamlanınca hazır olacak')
            ->assertSee('2 sa 30 dk');

        $this->assertSame(self::GECEN_HAFTA, WeeklyReport::sole()->week_start->toDateString());
    }

    public function test_report_for_the_running_week_is_not_generated(): void
    {
        [$veli, $cagla] = $this->ucCocukluVeli();

        $this->actingAs($veli)->get(route('parent.report', [$cagla, 'hafta' => self::BU_HAFTA]))
            ->assertOk()
            ->assertSee('Hafta tamamlanınca hazır olacak');

        $this->actingAs($veli)->get(route('parent.report', [$cagla, 'hafta' => '2026-12-07']))
            ->assertOk()
            ->assertSee('Hafta tamamlanınca hazır olacak');

        $this->assertSame(0, WeeklyReport::count());
    }

    public function test_report_navigation_links_and_bad_week_values(): void
    {
        [$veli, $cagla] = $this->ucCocukluVeli();

        $this->actingAs($veli)->get(route('parent.report', $cagla))
            ->assertOk()
            ->assertSee(route('parent.report', $cagla) . '?hafta=2026-09-14', false)
            ->assertSee(route('parent.report', $cagla) . '?hafta=2026-09-28', false);

        foreach (['bozuk', '2026-02-30', ''] as $deger) {
            $this->actingAs($veli)->get(route('parent.report', [$cagla, 'hafta' => $deger]))->assertOk();
        }
        $this->actingAs($veli)->get(route('parent.report', $cagla) . '?hafta[]=x')->assertOk();
    }

    public function test_report_is_closed_to_unlinked_children_and_other_roles(): void
    {
        [$veli, $cagla] = $this->ucCocukluVeli();
        $yabanci = $this->ogrenci('Yabancı Öğrenci', Package::factory()->tier1());
        $this->gecenHaftaVerisi($yabanci);

        $this->actingAs($veli)->get(route('parent.report', $yabanci))->assertForbidden();
        // Reddedilen istek rapor da uretmemeli.
        $this->assertSame(0, WeeklyReport::where('student_id', $yabanci->id)->count());

        $this->actingAs($cagla)->get(route('parent.report', $cagla))->assertForbidden();
        $this->actingAs($this->koc($cagla))->get(route('parent.report', $cagla))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('parent.report', $cagla))->assertForbidden();

        $eskiOgrenci = $this->ogrenci('Rolü Değişen');
        $this->bagla($veli, $eskiOgrenci);
        $eskiOgrenci->forceFill(['role' => Role::Coach->value])->save();
        $this->actingAs($veli)->get(route('parent.report', $eskiOgrenci))->assertNotFound();
    }

    // =========================================================================
    // parent.payments
    // =========================================================================

    public function test_payments_page_shows_package_payments_and_tab_for_the_child(): void
    {
        [$veli, $cagla] = $this->ucCocukluVeli();
        $abonelik = $cagla->subscriptions()->sole();
        $abonelik->update(['price' => 12000]);
        Payment::create(['subscription_id' => $abonelik->id, 'amount' => 8000, 'paid_at' => '2026-09-02', 'method' => 'cash']);
        $this->tuket($cagla, 'Türk Kahvesi', '2026-09-15 16:20', 2, 35);
        $this->tuket($cagla, 'Çay', '2026-09-16 11:00', 3, 10, kapsanan: 3);
        $this->tuket($cagla, 'Ağustos Tostu', '2026-08-31 18:00', 1, 60);

        $this->actingAs($veli)->get(route('parent.payments', $cagla))
            ->assertOk()
            ->assertSee('Çağla Işık')
            ->assertSee('Eylül 2026')
            ->assertSee('Kral')
            ->assertSee('12.000,00 ₺')
            ->assertSee('Ödenen 8.000,00 ₺')
            ->assertSee('Kalan 4.000,00 ₺')
            ->assertSee('Türk Kahvesi ×2')
            ->assertSee('70,00 ₺')
            ->assertSee('pakete dahil')
            ->assertDontSee('Ağustos Tostu')
            ->assertSee('12.070,00 ₺')        // ay toplami
            ->assertSee('Paketten kalan borç')
            ->assertSee(route('parent.payments', [$cagla, 'ay' => '2026-08']), false)
            ->assertSee(route('parent.payments', [$cagla, 'ay' => '2026-10']), false)
            ->assertSee(route('parent.student', $cagla), false)
            ->assertSee(route('parent.report', $cagla), false);

        $this->actingAs($veli)->get(route('parent.payments', [$cagla, 'ay' => '2026-08']))
            ->assertOk()
            ->assertSee('Ağustos 2026')
            ->assertSee('Ağustos Tostu')
            ->assertSee('Bu ay başlayan paket yok.');
    }

    public function test_payments_page_for_a_child_without_anything_is_empty_not_broken(): void
    {
        [$veli, , , $ahmet] = $this->ucCocukluVeli();

        $this->actingAs($veli)->get(route('parent.payments', $ahmet))
            ->assertOk()
            ->assertSee('Bu ay başlayan paket yok.')
            ->assertSee('Bu ay adisyon yok.')
            ->assertSee('0,00 ₺');
    }

    public function test_payments_page_with_a_bad_month_value_falls_back_to_this_month(): void
    {
        [$veli, $cagla] = $this->ucCocukluVeli();

        foreach (['2026-13', 'bozuk', '26-09', ''] as $deger) {
            $this->actingAs($veli)->get(route('parent.payments', [$cagla, 'ay' => $deger]))
                ->assertOk()
                ->assertSee('Eylül 2026');
        }
    }

    public function test_payments_page_with_an_array_month_value_falls_back_instead_of_crashing(): void
    {
        [$veli, $cagla] = $this->ucCocukluVeli();

        $this->actingAs($veli)->get(route('parent.payments', $cagla) . '?ay[]=2026-08')
            ->assertOk()
            ->assertSee('Eylül 2026');
    }

    /**
     * Gecen ayin odenmemis paketi bugun vadesini coktan gecmis. Veli
     * Odemeler'de "Bekliyor" degil "Gecikmiş" gormeli - panel guncel paketi
     * senkronluyor ama bu sayfa hic senkronlamiyor.
     */
    public function test_payments_page_shows_an_unpaid_past_package_as_overdue(): void
    {
        [$veli, $cagla] = $this->ucCocukluVeli();
        Subscription::factory()->create([
            'student_id' => $cagla->id,
            'package_id' => Package::factory()->tier3()->create()->id,
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-31',
            'price' => 7500,
            'payment_status' => PaymentStatus::Pending->value,
        ]);

        $this->actingAs($veli)->get(route('parent.payments', [$cagla, 'ay' => '2026-08']))
            ->assertOk()
            ->assertSee('Kalan 7.500,00 ₺')
            ->assertSee('Gecikmiş')
            ->assertDontSee('Bekliyor');
    }

    /** Ayin son gunu baslayan paket o ayin dokumunde gorunmeli. */
    public function test_payments_page_lists_a_package_that_starts_on_the_last_day_of_the_month(): void
    {
        [$veli, $cagla] = $this->ucCocukluVeli();
        Subscription::factory()->create([
            'student_id' => $cagla->id,
            'package_id' => Package::factory()->tier2()->create()->id,
            'starts_on' => '2026-08-31',
            'ends_on' => '2026-09-29',
            'price' => 9000,
        ]);

        $this->actingAs($veli)->get(route('parent.payments', [$cagla, 'ay' => '2026-08']))
            ->assertOk()
            ->assertSee('Orta')
            ->assertSee('9.000,00 ₺')
            ->assertDontSee('Bu ay başlayan paket yok.');
    }

    public function test_payments_page_is_closed_to_unlinked_children_and_other_roles(): void
    {
        [$veli, $cagla] = $this->ucCocukluVeli();
        $yabanci = $this->ogrenci('Yabancı Öğrenci', Package::factory()->tier1());

        $this->actingAs($veli)->get(route('parent.payments', $yabanci))->assertForbidden();
        $this->actingAs($veli)->get('/veli/ogrenci/999999/odemeler')->assertNotFound();

        $this->actingAs($cagla)->get(route('parent.payments', $cagla))->assertForbidden();
        $this->actingAs($this->koc($cagla))->get(route('parent.payments', $cagla))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('parent.payments', $cagla))->assertForbidden();

        $eskiOgrenci = $this->ogrenci('Rolü Değişen');
        $this->bagla($veli, $eskiOgrenci);
        $eskiOgrenci->forceFill(['role' => Role::Parent->value])->save();
        $this->actingAs($veli)->get(route('parent.payments', $eskiOgrenci))->assertNotFound();
    }

    // =========================================================================
    // parent.exams
    // =========================================================================

    public function test_exam_calendar_shows_the_month_upcoming_and_flexible_exams_with_titles(): void
    {
        [$veli] = $this->ucCocukluVeli(); // Cagla tier3: kulup var
        ExamEvent::create(['title' => 'Kral TYT Denemesi 4', 'exam_type' => 'tyt', 'exam_date' => '2026-10-03', 'starts_at' => '10:00', 'note' => 'Kimlik getirin']);
        ExamEvent::create(['title' => 'Eylül AYT Denemesi', 'exam_type' => 'ayt', 'exam_date' => '2026-09-12', 'starts_at' => '13:30']);
        ExamEvent::create(['title' => 'Serbest Türkiye Geneli', 'exam_type' => 'tyt', 'exam_date' => '2026-09-15', 'is_flexible' => true, 'available_until' => '2026-10-15']);

        $this->actingAs($veli)->get(route('parent.exams'))
            ->assertOk()
            ->assertSee('Deneme Takvimi')
            ->assertSee('Eylül 2026')
            ->assertSee('Eylül AYT Denemesi')      // izgarada (gecmis gun)
            ->assertSee('Kral TYT Denemesi 4')     // yaklasanlar
            ->assertSee('Kimlik getirin')
            ->assertSee('4 gün kaldı')
            ->assertSee('Serbest denemeler')
            ->assertSee('Serbest Türkiye Geneli')
            ->assertSee('15 Eylül – 15 Ekim')
            ->assertSee(route('parent.exams', ['ay' => '2026-08']), false)
            ->assertSee(route('parent.exams', ['ay' => '2026-10']), false)
            ->assertDontSee(route('admin.exams.index'), false);

        $this->actingAs($veli)->get(route('parent.exams', ['ay' => '2026-10']))
            ->assertOk()
            ->assertSee('Ekim 2026');

        foreach (['2026-13', 'bozuk', ''] as $deger) {
            $this->actingAs($veli)->get(route('parent.exams', ['ay' => $deger]))->assertOk()->assertSee('Eylül 2026');
        }
    }

    /** Ayin SON gunundeki deneme izgarada gorunmeli. */
    public function test_exam_calendar_grid_includes_the_last_day_of_the_month(): void
    {
        [$veli] = $this->ucCocukluVeli();
        ExamEvent::create(['title' => 'Ağustos İlk Gün Denemesi', 'exam_type' => 'tyt', 'exam_date' => '2026-08-01']);
        ExamEvent::create(['title' => 'Ağustos Son Gün Denemesi', 'exam_type' => 'tyt', 'exam_date' => '2026-08-31']);

        $this->actingAs($veli)->get(route('parent.exams', ['ay' => '2026-08']))
            ->assertOk()
            ->assertSee('Ağustos 2026')
            ->assertSee('Ağustos İlk Gün Denemesi')
            ->assertSee('Ağustos Son Gün Denemesi');
    }

    public function test_exam_calendar_hides_titles_when_no_child_has_the_exam_club(): void
    {
        $veli = $this->veli();
        $this->bagla($veli, $this->ogrenci('Şükrü Öztürk', Package::factory()->tier1()));
        ExamEvent::create(['title' => 'Kulübe Özel Deneme', 'exam_type' => 'tyt', 'exam_date' => '2026-10-03', 'note' => 'Kulüp notu']);

        $this->actingAs($veli)->get(route('parent.exams'))
            ->assertOk()
            ->assertSee('TYT')
            ->assertDontSee('Kulübe Özel Deneme')
            ->assertDontSee('Kulüp notu');
    }

    public function test_exam_calendar_is_only_for_parents(): void
    {
        $this->get(route('parent.exams'))->assertRedirect(route('login'));

        $ogrenci = $this->ogrenci('Öğrenci', Package::factory()->tier3());
        $this->actingAs($ogrenci)->get(route('parent.exams'))->assertForbidden();
        $this->actingAs($this->koc($ogrenci))->get(route('parent.exams'))->assertForbidden();
        $this->actingAs(User::factory()->admin()->create())->get(route('parent.exams'))->assertForbidden();

        // Cocugu olmayan veli de takvimi acar (kafe geneli).
        $this->actingAs($this->veli())->get(route('parent.exams'))->assertOk()->assertSee('Planlanmış deneme yok.');
    }

    public function test_exam_calendar_with_an_array_month_value_falls_back_instead_of_crashing(): void
    {
        [$veli] = $this->ucCocukluVeli();

        $this->actingAs($veli)->get(route('parent.exams') . '?ay[]=2026-08')
            ->assertOk()
            ->assertSee('Eylül 2026');
    }
}
