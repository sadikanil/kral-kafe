<?php

namespace App\Http\Controllers\Coach;

use App\Enums\PlanPeriod;
use App\Http\Controllers\Controller;
use App\Models\StudyPlanItem;
use App\Models\Subject;
use App\Models\User;
use App\Models\WeakTopic;
use App\Support\LocalDay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Zayif konu listesi - koc tarafi (Dalga 17b).
 *
 * Konu KAPANIR, silinmez (silme ayri bir uc): "bunu hallettin" bilgisi
 * ogrencinin gorebilecegi tek ilerleme isareti.
 *
 * Her konu tek dokunusla PLAN MADDESINE donusebilir. Zayif konu listesi
 * kendi basina bir gorev listesi degil; plana donusmezse ogrenci onu
 * hicbir yerde gormez ve liste kocun not defterinden ibaret kalir.
 */
class WeakTopicController extends Controller
{
    public function index(User $student): View
    {
        $this->kapiyiAc($student);

        return view('coach.topics.index', [
            'student' => $student,
            // Koc ACIK ve KAPANMIS konularin ikisini de gorur; gecmis
            // "neyi hallettik" sorusunun cevabi. Veli ve ogrenci yalnizca
            // aciklari gorur.
            //
            // ACIKLAR USTTE: islem dugmeleri (Plana ekle, Kapat) yalnizca
            // onlarda. orderBy('status') alfabetik siralar ve 'closed' <
            // 'open' oldugu icin gecmis ustte kalir, aylar icinde uzayan
            // liste acik konulari gomerdi. CASE, ucuncu bir durum eklenirse
            // de alfabeye yaslanmadan calisir (SQLite ve Postgres ikisi de).
            'topics' => WeakTopic::forStudent($student)
                ->with(['subject', 'author'])
                ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
                ->orderByDesc('created_at')
                ->get(),
            'subjects' => Subject::forStudent($student)->get(),
        ]);
    }

    public function store(Request $request, User $student): RedirectResponse
    {
        $this->kapiyiAc($student);

        $dogrulanmis = $request->validate([
            'topic' => ['required', 'string', 'max:150'],
            // Ders ISTEGE BAGLI: her zayif konu bir derse oturmuyor
            // ("soru cozme hizi", "deneme stresi").
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
        ], [], [
            // Dil dosyasinda karsiligi yok; yoksa "subject id" diye sizar.
            'topic' => 'konu',
            'subject_id' => 'ders',
        ]);

        $baslik = trim($dogrulanmis['topic']);

        if ($baslik === '') {
            return back()->withErrors(['topic' => 'Konu boş olamaz.'])->withInput();
        }

        WeakTopic::create([
            'student_id' => $student->id,
            'subject_id' => $dogrulanmis['subject_id'] ?? null,
            'topic' => $baslik,
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', 'Konu eklendi.');
    }

    public function close(WeakTopic $topic): RedirectResponse
    {
        $this->kapiyiAc($topic->student);

        $topic->close();

        return back()->with('success', 'Konu kapatıldı.');
    }

    public function reopen(WeakTopic $topic): RedirectResponse
    {
        $this->kapiyiAc($topic->student);

        $topic->reopen();

        return back()->with('success', 'Konu yeniden açıldı.');
    }

    public function destroy(WeakTopic $topic): RedirectResponse
    {
        $this->kapiyiAc($topic->student);

        $topic->delete();

        return back()->with('success', 'Konu silindi.');
    }

    /**
     * Konuyu bu haftanin plan maddesine cevirir.
     *
     * Konu ile madde arasinda BAG TUTULMUYOR: ayni konu birden fazla hafta
     * planlanabilir (uzerinde birkac hafta calisilir), tek bir madde
     * kimligine baglamak o durumu temsil edemezdi.
     */
    public function plan(WeakTopic $topic): RedirectResponse
    {
        $this->kapiyiAc($topic->student);

        StudyPlanItem::create([
            'student_id' => $topic->student_id,
            'subject_id' => $topic->subject_id,
            'title' => $topic->topic,
            // Dalga 30c: takvimde bugune duser; koc tasiyabilir.
            'plan_date' => LocalDay::today(),
            'period' => PlanPeriod::Week->value,
            'week_start' => PlanPeriod::Week->startFor(LocalDay::today()),
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', 'Konu bu haftanın planına eklendi.');
    }

    private function kapiyiAc(?User $student): void
    {
        abort_if($student === null || ! $student->isStudent(), 404);
        abort_unless(auth()->user()->canCoach($student), 403);
    }
}
