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

    public function show(Request $request, User $student): View
    {
        $this->kapiyiAc($student);

        $donem = PlanPeriod::fromRequest($request->query('donem'));
        $baslangic = $donem->startForRequest($request->query('baslangic'));

        return view('coach.plan.show', [
            'student' => $student,
            'donem' => $donem,
            'baslangic' => $baslangic,
            'maddeler' => StudyPlanItem::forPeriod($student, $donem, $baslangic)
                ->with('subject')
                ->orderBy('id')
                ->get(),
            'dersler' => Subject::active()->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request, User $student): RedirectResponse
    {
        // YETKI ONCE, dogrulama sonra. Tersi olsaydi atanmamis bir ogrenci
        // icin gonderilen bozuk form 422 doner ve "bu ogrenciye yetkin yok"
        // yerine "baslik gerekli" derdi - sinirin varligini sizdirirdi.
        $this->kapiyiAc($student);

        $dogrulanmis = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            'period' => ['required', Rule::enum(PlanPeriod::class)],
            'baslangic' => ['nullable', 'date'],
        ]);

        $donem = PlanPeriod::from($dogrulanmis['period']);

        StudyPlanItem::create([
            'student_id' => $student->id,
            'subject_id' => $dogrulanmis['subject_id'] ?? null,
            'title' => $dogrulanmis['title'],
            'period' => $donem->value,
            // Donem baslangicini PlanPeriod kuruyor; ham tarih yazilsaydi
            // aylik madde ayin ortasina dusup hicbir listede gorunmezdi.
            'week_start' => $donem->startFor($dogrulanmis['baslangic'] ?? LocalDay::today()),
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', 'Plan maddesi eklendi.');
    }

    public function destroy(StudyPlanItem $item): RedirectResponse
    {
        $this->kapiyiAc($item->student);

        $item->delete();

        return back()->with('success', 'Plan maddesi silindi.');
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
