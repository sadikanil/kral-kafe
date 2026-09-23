<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Package;
use App\Models\StudyPlanItem;
use App\Models\User;
use App\Models\WeakTopic;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Koc formlari (QA turu, faz 2): ayni sayfadaki iki formun birbirine
 * karismamasi ve her alanin gercek bir etiketi olmasi.
 *
 * Placeholder etiket DEGIL: yazmaya baslayinca kaybolur, kontrasti dusuk
 * ve ekran okuyucu icin guvenilir bir ad degil. Deger gibi duran bir
 * placeholder ("60") ise dolu alan sanilir; o yuzden yalnizca "Örn." ile
 * baslayan ornekler kalir.
 *
 * Saat: 29 Eylul 2026 sali 14:00 (kafe saati); tamamlanmis son hafta
 * 21 - 27 Eylul, rapor formu o hafta icin gorunur.
 */
class CoachFormsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-09-29 14:00', config('kafe.timezone')));
    }

    /** @return array{0: User, 1: User} */
    private function kocVeOgrenci(): array
    {
        $ogrenci = User::factory()->student()->withPackage(Package::factory()->tier3()->create())
            ->create(['name' => 'Ayşe Yılmaz', 'grade' => '12', 'field' => 'say']);
        $koc = User::factory()->create(['role' => Role::Coach->value]);
        $koc->coachStudents()->attach($ogrenci->id);

        return [$koc, $ogrenci];
    }

    /** Kocun dort ogrenci sayfasi, formlari dolu gelecek sekilde. */
    private function sayfalar(User $ogrenci): array
    {
        // Plan satirindaki "tasi" kutusu ve konu satiri da taransin.
        StudyPlanItem::create([
            'student_id' => $ogrenci->id, 'title' => 'Paragraf', 'plan_date' => '2026-09-30',
            'week_start' => '2026-09-28', 'period' => 'week',
        ]);
        WeakTopic::create(['student_id' => $ogrenci->id, 'topic' => 'Türev']);

        return [
            route('coach.plan.index'),
            route('coach.plan.show', $ogrenci),
            route('coach.notes.index', $ogrenci),
            route('coach.topics.index', $ogrenci),
            route('coach.report', [$ogrenci, 'hafta' => '2026-09-21']),
        ];
    }

    private function dom(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">' . $html);
        libxml_clear_errors();

        return new DOMXPath($dom);
    }

    /**
     * Etiketsiz alanlarin adlari. Etiket sayilanlar: <label for>, alani
     * saran <label>, aria-label ya da aria-labelledby.
     *
     * @return list<string>
     */
    private function etiketsizAlanlar(DOMXPath $xpath): array
    {
        $etiketsiz = [];
        $alanlar = $xpath->query('//input[not(@type="hidden") and not(@type="submit") and not(@type="button")] | //select | //textarea');

        foreach ($alanlar as $alan) {
            /** @var DOMElement $alan */
            $id = $alan->getAttribute('id');
            $etiketli = trim($alan->getAttribute('aria-label')) !== ''
                || trim($alan->getAttribute('aria-labelledby')) !== ''
                || $xpath->query('ancestor::label', $alan)->length > 0
                || ($id !== '' && $xpath->query('//label[@for="' . $id . '"]')->length > 0);

            if (! $etiketli) {
                $etiketsiz[] = $alan->getAttribute('name') ?: $alan->nodeName;
            }
        }

        return $etiketsiz;
    }

    public function test_every_coach_form_control_has_a_label(): void
    {
        [$koc, $ogrenci] = $this->kocVeOgrenci();

        foreach ($this->sayfalar($ogrenci) as $url) {
            $html = $this->actingAs($koc)->get($url)->assertOk()->getContent();

            $this->assertSame([], $this->etiketsizAlanlar($this->dom($html)), "{$url} sayfasinda etiketsiz alan var");
        }

        // Rapor yorumu gercekten ekranda olmali; yoksa tarama bos gecer.
        $this->actingAs($koc)->get(route('coach.report', [$ogrenci, 'hafta' => '2026-09-21']))
            ->assertSee('name="coach_comment"', false);
    }

    public function test_placeholders_are_only_examples(): void
    {
        [$koc, $ogrenci] = $this->kocVeOgrenci();

        foreach ($this->sayfalar($ogrenci) as $url) {
            $xpath = $this->dom($this->actingAs($koc)->get($url)->assertOk()->getContent());

            foreach ($xpath->query('//*[@placeholder]') as $alan) {
                /** @var DOMElement $alan */
                $this->assertStringStartsWith('Örn. ', $alan->getAttribute('placeholder'),
                    "{$url}: '{$alan->getAttribute('name')}' placeholder'i deger gibi duruyor");
            }
        }
    }

    /**
     * Ters yon: plan formundaki bir hata program formunun varsayilan
     * saatlerini silmemeli ve onu hatali gostermemeli.
     */
    public function test_a_plan_form_error_leaves_the_program_form_untouched(): void
    {
        [$koc, $ogrenci] = $this->kocVeOgrenci();

        $yanit = $this->actingAs($koc)->from(route('coach.plan.show', $ogrenci))
            ->followingRedirects()
            ->post(route('coach.plan.store', $ogrenci), [
                'plan_date' => '2026-09-30', 'title' => 'Paragraf', 'starts_at' => '25:00',
            ])
            ->assertOk();
        $html = $yanit->getContent();

        // Plan formu acik gelir; saat hatasi kendi alaninin yaninda.
        $yanit->assertSee('id="planaEkle" open', false);
        $this->assertMatchesRegularExpression('/<input[^>]*name="starts_at"[^>]*is-invalid[^>]*value="25:00"[^>]*>\s*<span class="invalid-feedback">/', $html);

        // Program formu varsayilan saatlerini korur ve hatali gorunmez.
        $this->assertMatchesRegularExpression('/<input(?![^>]*is-invalid)[^>]*name="commitment_starts_at"[^>]*value="08:00"/', $html);
        $this->assertMatchesRegularExpression('/<input(?![^>]*is-invalid)[^>]*name="commitment_ends_at"[^>]*value="15:00"/', $html);
    }

    /** Programin hatasi Plana ekle formunu acmaz; kendi hatasi acar. */
    public function test_only_plan_errors_open_the_add_form(): void
    {
        [$koc, $ogrenci] = $this->kocVeOgrenci();

        $this->actingAs($koc)->from(route('coach.plan.show', $ogrenci))
            ->followingRedirects()
            ->post(route('coach.commitments.store', $ogrenci), ['kind' => 'okul', 'weekdays' => []])
            ->assertOk()
            ->assertSee('En az bir gün seçin.')
            ->assertDontSee('id="planaEkle" open', false);

        $this->actingAs($koc)->from(route('coach.plan.show', $ogrenci))
            ->followingRedirects()
            ->post(route('coach.plan.store', $ogrenci), ['plan_date' => '2026-09-30'])
            ->assertOk()
            ->assertSee('Bir ders seçin ya da not yazın.')
            ->assertSee('id="planaEkle" open', false);
    }

    public function test_the_program_is_saved_from_the_renamed_time_fields(): void
    {
        [$koc, $ogrenci] = $this->kocVeOgrenci();

        $this->actingAs($koc)->from(route('coach.plan.show', $ogrenci))
            ->post(route('coach.commitments.store', $ogrenci), [
                'kind' => 'okul', 'weekdays' => [2],
                'commitment_starts_at' => '08:15', 'commitment_ends_at' => '14:40',
            ])
            ->assertSessionHasNoErrors();

        $satir = \App\Models\StudentCommitment::sole();
        $this->assertSame('08:15', $satir->starts_at);
        $this->assertSame('14:40', $satir->ends_at);
    }

    /** Yeni alan adlari hata metnine "commitment starts at" diye sizmamali. */
    public function test_program_time_errors_name_the_field_in_turkish(): void
    {
        [$koc, $ogrenci] = $this->kocVeOgrenci();

        $this->actingAs($koc)->from(route('coach.plan.show', $ogrenci))
            ->post(route('coach.commitments.store', $ogrenci), [
                'kind' => 'okul', 'weekdays' => [2], 'commitment_starts_at' => '8', 'commitment_ends_at' => '',
            ])
            ->assertSessionHasErrors([
                'commitment_starts_at' => 'başlangıç saati H:i biçimiyle eşleşmiyor.',
                'commitment_ends_at' => 'bitiş saati alanı gereklidir.',
            ]);
    }

    /**
     * Hata metni hem alanin yaninda hem sayfanin ustundeki kutuda cikiyor;
     * dil dosyasinda karsiligi olmayan anahtar "duration minutes en az 5"
     * diye Ingilizce sizar. Adlar alanin ETIKETIYLE ayni olmali: plan
     * formunda "Saat" yazan alana "başlangıç saati" denmez.
     *
     * Mesajin tamami degil yalnizca alan adi denetleniyor: kalip metinler
     * dil dosyasinda, o dosya bu testin konusu degil.
     */
    public function test_coach_form_errors_name_every_field_in_turkish(): void
    {
        [$koc, $ogrenci] = $this->kocVeOgrenci();
        $madde = StudyPlanItem::create([
            'student_id' => $ogrenci->id, 'title' => 'Paragraf', 'plan_date' => '2026-09-30',
            'week_start' => '2026-09-28', 'period' => 'week',
        ]);
        $program = ['kind' => 'okul', 'weekdays' => [1], 'commitment_starts_at' => '08:00', 'commitment_ends_at' => '15:00'];
        $plan = ['plan_date' => '2026-09-30', 'title' => 'Paragraf'];

        $vakalar = [
            // [yontem, rota, veri, alan, beklenen ad]
            ['POST', route('coach.plan.store', $ogrenci), ['title' => 'Paragraf'], 'plan_date', 'gün'],
            ['POST', route('coach.plan.store', $ogrenci), ['plan_date' => '2026-09-30', 'subject_id' => 999999], 'subject_id', 'ders'],
            ['POST', route('coach.plan.store', $ogrenci), ['plan_date' => '2026-09-30', 'subject_id' => 1, 'subject_topic_id' => 'x'], 'subject_topic_id', 'konu'],
            ['POST', route('coach.plan.store', $ogrenci), $plan + ['starts_at' => '25:00'], 'starts_at', 'saat'],
            ['POST', route('coach.plan.store', $ogrenci), $plan + ['duration_minutes' => 3], 'duration_minutes', 'süre'],
            ['POST', route('coach.plan.store', $ogrenci), ['plan_date' => '2026-09-30', 'title' => str_repeat('ş', 151)], 'title', 'not'],
            ['PATCH', route('coach.plan.move', $madde), ['plan_date' => ''], 'plan_date', 'gün'],
            ['POST', route('coach.topics.store', $ogrenci), ['topic' => ''], 'topic', 'konu'],
            ['POST', route('coach.topics.store', $ogrenci), ['topic' => 'Türev', 'subject_id' => 999999], 'subject_id', 'ders'],
            ['POST', route('coach.commitments.store', $ogrenci), ['kind' => 'kurs'] + $program, 'kind', 'tür'],
            ['POST', route('coach.commitments.store', $ogrenci), ['weekdays' => 'pzt'] + $program, 'weekdays', 'günler'],
            ['POST', route('coach.commitments.store', $ogrenci), ['weekdays' => [8]] + $program, 'weekdays.0', 'gün'],
            ['POST', route('coach.notes.store', $ogrenci), ['kind' => 'dedikodu', 'body' => 'x'], 'kind', 'tür'],
            ['POST', route('coach.notes.store', $ogrenci), ['kind' => 'note', 'body' => ''], 'body', 'not'],
            ['POST', route('coach.notes.store', $ogrenci), ['kind' => 'note', 'body' => 'x', 'visibility' => 'herkes'], 'visibility', 'görünürlük'],
            ['POST', route('coach.notes.store', $ogrenci), ['kind' => 'note', 'body' => 'x', 'occurred_on' => 'dun'], 'occurred_on', 'görüşme günü'],
            ['POST', route('coach.report.comment', [$ogrenci, 'hafta' => '2026-09-21']), ['coach_comment' => str_repeat('ş', 2001)], 'coach_comment', 'yorum'],
        ];

        foreach ($vakalar as [$yontem, $rota, $veri, $alan, $ad]) {
            $this->actingAs($koc)->from(route('coach.plan.show', $ogrenci))
                ->call($yontem, $rota, $veri)
                ->assertSessionHasErrors($alan);

            $mesaj = session('errors')->first($alan);
            $this->assertStringContainsString(' ' . $ad . ' ', ' ' . mb_strtolower($mesaj) . ' ', "{$alan}: {$mesaj}");
            $this->assertStringNotContainsString(str_replace('_', ' ', explode('.', $alan)[0]), $mesaj, "{$alan}: {$mesaj}");
        }
    }
}
