<?php

namespace App\Http\Controllers\Coach;

use App\Enums\PlanPeriod;
use App\Http\Controllers\Controller;
use App\Models\StudyPlanItem;
use App\Models\Subject;
use App\Models\User;
use App\Services\DeclineSignals;
use App\Support\LocalDay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Calisma plani - koc tarafi (Dalga 14).
 *
 * Dalga 13'te bu form ogrenci duzenleme ekraninin dibinde bir kartti ve
 * yalnizca yonetici erisiyordu. Plan kendi sayfasina cikti: koc kendi
 * ogrencilerini listeler, donemi (haftalik/aylik) secer, maddeleri yonetir.
 *
 * Yonetici ayni zamanda koctur (karar 11) ve buraya girip TUM ogrencileri
 * gorur; ayri bir koc hesabi acmasi gerekmez.
 */
class StudyPlanController extends Controller
{
    public function index(DeclineSignals $sinyaller): View
    {
        $bakan = auth()->user();

        // Sinir accessibleStudentIds()'den geliyor: koc kendi atananlarini,
        // yonetici hepsini gorur. Ayri bir filtre yazmak, gunun birinde
        // buradaki listenin veli panelinden farkli davranmasi demekti.
        $ogrenciler = User::query()
            ->visibleTo($bakan)
            ->orderBy('name')
            ->get();

        $hafta = PlanPeriod::Week->startFor(LocalDay::today());

        return view('coach.plan.index', [
            'ogrenciler' => $ogrenciler,
            'hafta' => $hafta,
            'ilerleme' => $this->haftalikIlerleme($ogrenciler->pluck('id')->all(), $hafta),
            // Dusus sinyalleri (Dalga 16). YALNIZCA burada: veli ve ogrenci
            // ham sinyali gormez (SS6.1-5), onlara giden sey kocun yorumu.
            // Ogrenci sayisindan bagimsiz olarak uc sorgu calisir.
            'sinyaller' => $sinyaller->forStudents($ogrenciler),
        ]);
    }

    /**
     * Haftalik takvim (Dalga 30c). ?hafta= haftanin herhangi bir gunu.
     */
    public function show(Request $request, User $student): View
    {
        $this->kapiyiAc($student);

        $hafta = \App\Support\WeekParameter::resolveCurrent($request->query('hafta'));
        $dersler = Subject::forStudent($student)->get();

        return view('coach.plan.show', [
            'student' => $student,
            'hafta' => $hafta,
            'days' => \App\Support\WeekPlan::for($student, $hafta),
            // Dalga 30b: yalnizca ogrencinin sorumlu oldugu dersler.
            'dersler' => $dersler,
            // Konu secimi derse gore suzulur (sayfadaki JS bu listeden).
            'konular' => \App\Models\SubjectTopic::whereIn('subject_id', $dersler->pluck('id'))
                ->orderBy('sort_order')->get(['id', 'subject_id', 'name'])
                ->groupBy('subject_id')
                ->map(fn ($k) => $k->map(fn ($t) => ['id' => $t->id, 'name' => $t->name])->values()),
            'commitments' => \App\Models\StudentCommitment::where('student_id', $student->id)
                ->orderBy('weekday')->orderBy('starts_at')->get(),
            // Calisma kayitlari (Dalga 28): ogrencinin ne bitirdigi.
            'studyLogs' => \App\Models\StudyLog::recentFor($student),
        ]);
    }

    /**
     * Gune ders + konu (Dalga 30c). Baslik: yazilan not, yoksa konu, yoksa
     * ders. week_start ve period yazilmaya devam eder - haftalik ilerleme,
     * koc listesi ve haftalik rapor onlardan okuyor.
     */
    public function store(Request $request, User $student): RedirectResponse
    {
        // YETKI ONCE, dogrulama sonra. Tersi olsaydi atanmamis bir ogrenci
        // icin gonderilen bozuk form 422 doner ve "bu ogrenciye yetkin yok"
        // yerine "baslik gerekli" derdi - sinirin varligini sizdirirdi.
        $this->kapiyiAc($student);

        $v = $request->validate([
            'plan_date' => ['required', 'date_format:Y-m-d'],
            'subject_id' => ['nullable', 'required_without:title', 'integer', 'exists:subjects,id'],
            'subject_topic_id' => ['nullable', 'integer',
                Rule::exists('subject_topics', 'id')->where('subject_id', (int) $request->input('subject_id'))],
            'title' => ['nullable', 'string', 'max:150'],
            'starts_at' => ['nullable', 'date_format:H:i'],
            'duration_minutes' => ['nullable', 'integer', 'min:5', 'max:720'],
        ], [
            'subject_id.required_without' => 'Bir ders seçin ya da not yazın.',
            'subject_topic_id.exists' => 'Konu seçilen derse ait değil.',
        ]);

        $konu = isset($v['subject_topic_id']) ? \App\Models\SubjectTopic::find($v['subject_topic_id']) : null;
        $ders = isset($v['subject_id']) ? Subject::find($v['subject_id']) : null;

        StudyPlanItem::create([
            'student_id' => $student->id,
            'subject_id' => $ders?->id,
            'subject_topic_id' => $konu?->id,
            'title' => filled($v['title'] ?? null) ? $v['title'] : ($konu?->name ?? $ders->name),
            'plan_date' => $v['plan_date'],
            'period' => PlanPeriod::Week->value,
            'week_start' => PlanPeriod::Week->startFor($v['plan_date']),
            'starts_at' => $v['starts_at'] ?? null,
            'duration_minutes' => $v['duration_minutes'] ?? null,
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', 'Plana eklendi.');
    }

    /** Maddeyi baska bir gune tasir; hafta da onunla degisir. */
    public function move(Request $request, StudyPlanItem $item): RedirectResponse
    {
        $this->kapiyiAc($item->student);

        $v = $request->validate(['plan_date' => ['required', 'date_format:Y-m-d']]);

        $item->update([
            'plan_date' => $v['plan_date'],
            'period' => PlanPeriod::Week->value,
            'week_start' => PlanPeriod::Week->startFor($v['plan_date']),
        ]);

        return back()->with('success', 'Taşındı.');
    }

    public function destroy(StudyPlanItem $item): RedirectResponse
    {
        $this->kapiyiAc($item->student);

        $item->delete();

        return back()->with('success', 'Plandan silindi.');
    }

    /**
     * Ogrenci basina bu haftanin [tamamlanan, toplam] sayisi.
     *
     * TEK sorgu. Ogrenci basina StudyPlanItem::progress() cagirmak N+1
     * olurdu; fonksiyon ile veritabani arasindaki mesafe bu projede bir
     * kez pahaliya mal oldu (README SS10.12), listede tekrarlanmasin.
     *
     * Donem suzgeci burada da SART: aylik hedefler haftalik sayima
     * karismamali.
     *
     * @param  list<int>  $ogrenciIds
     * @return array<int,array{0:int,1:int}>
     */
    private function haftalikIlerleme(array $ogrenciIds, string $hafta): array
    {
        if ($ogrenciIds === []) {
            return [];
        }

        return StudyPlanItem::query()
            ->whereIn('student_id', $ogrenciIds)
            ->where('period', PlanPeriod::Week->value)
            ->whereDate('week_start', $hafta)
            ->selectRaw('student_id, count(*) as toplam, sum(case when status = ? then 1 else 0 end) as biten', ['done'])
            ->groupBy('student_id')
            ->get()
            ->mapWithKeys(fn ($satir) => [
                (int) $satir->student_id => [(int) $satir->biten, (int) $satir->toplam],
            ])
            ->all();
    }

    /**
     * Ogrenci gecerli mi ve bakan kisi ona plan yazabilir mi?
     * (Sabit program da ayni kapidan: CommitmentController::kapiyiAc.)
     *
     * Tek yerde: dort ucun dordu de ayni kapidan geciyor. Ayri ayri
     * yazilsaydi biri gunun birinde digerinden gevsek kalirdi.
     */
    private function kapiyiAc(?User $student): void
    {
        abort_if($student === null || ! $student->isStudent(), 404);
        abort_unless(auth()->user()->canCoach($student), 403);
    }
}
