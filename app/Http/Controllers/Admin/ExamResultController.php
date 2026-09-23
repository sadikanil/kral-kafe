<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExamEvent;
use App\Models\ExamResult;
use App\Models\Subject;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Deneme sonucu girisi (Dalga 12).
 *
 * Sonuclari YONETICI giriyor: sonuclar kuruma toplu geliyor, ogrenciden
 * girmesini beklemek hem gecikme hem hata kaynagi olurdu.
 */
class ExamResultController extends Controller
{
    /** Siralama seviyeleri: sutun soneki => formdaki ad. */
    private const SEVIYELER = ['institution' => 'kurum', 'district' => 'ilçe', 'city' => 'il', 'country' => 'Türkiye'];

    public function edit(ExamEvent $examEvent, User $student): View
    {
        abort_unless($student->isStudent(), 404);

        return view('admin.exam-results.edit', [
            'event' => $examEvent,
            'student' => $student,
            'result' => $this->mevcutSonuc($examEvent, $student),
            // Dalga 30b: denemenin turu + ogrencinin alani.
            'subjects' => Subject::forExam($examEvent->exam_type, $student)->get(),
        ]);
    }

    public function store(Request $request, ExamEvent $examEvent, User $student): RedirectResponse
    {
        abort_unless($student->isStudent(), 404);

        $dersler = Subject::forExam($examEvent->exam_type, $student)->get();
        $kurallar = [];
        $adlar = ['subjects' => 'dersler'];

        foreach (self::SEVIYELER as $alan => $etiket) {
            // Siralamalar SONRADAN aciklaniyor; hepsi istege bagli. Sira
            // katilimciyi gecemez (kutular karisirsa "87 kişide 1.240.");
            // katilimci bos ya da hataliysa kiyas yok, sira tek basina kalir.
            $kurallar["rank_{$alan}"] = ['nullable', 'integer', 'min:1',
                Rule::when(filter_var($request->input("total_{$alan}"), FILTER_VALIDATE_INT) !== false, "lte:total_{$alan}")];
            $kurallar["total_{$alan}"] = ['nullable', 'integer', 'min:1'];
            $adlar["rank_{$alan}"] = "{$etiket} sırası";
            $adlar["total_{$alan}"] = "{$etiket} katılımcı sayısı";
        }

        // Anahtarlar dogrudan subject_id oluyor: yalnizca bu denemenin
        // (tur + alan) dersleri. Bilinmeyen id FK hatasiyla 500, kapsam
        // disi ders ise ogrencinin netini haksiz yere sisiriyordu.
        $kurallar['subjects'] = ['required', 'array:' . $dersler->pluck('id')->implode(',')];
        foreach (['correct', 'wrong', 'blank'] as $sayi) {
            $kurallar["subjects.*.{$sayi}"] = ['required', 'integer', 'min:0', 'max:200'];
        }
        $kurallar['note'] = ['nullable', 'string', 'max:1000'];

        // 10+ derslik formda "subjects.7.correct" hangi kutu belli degil
        foreach ($dersler as $ders) {
            $adlar["subjects.{$ders->id}.correct"] = "{$ders->name} doğru sayısı";
            $adlar["subjects.{$ders->id}.wrong"] = "{$ders->name} yanlış sayısı";
            $adlar["subjects.{$ders->id}.blank"] = "{$ders->name} boş sayısı";
        }

        // Sayfa ustundeki hata ozeti dort seviyeyi alt alta dizer; mesaj
        // seviyeyi (kurum/ilce/il/Turkiye) adlandirmazsa hangi cift karisti
        // belli olmaz. :Attribute degil: Str::ucfirst Turkce i'yi noktali I
        // yerine duz I yapar (ilce -> Ilce).
        $dogrulanmis = $request->validate($kurallar, [
            'rank_*.lte' => ':attribute, katılımcı sayısından büyük olamaz.',
            'subjects.array' => 'Bu denemede olmayan bir ders gönderildi.',
        ], $adlar);

        DB::transaction(function () use ($dogrulanmis, $examEvent, $student) {
            // Ayni denemeye ikinci kez girmek YENI KAYIT acmaz, mevcudu
            // gunceller: siralamalar sonradan aciklandigi icin ayni sonuc
            // birden cok kez duzenlenecek.
            $sonuc = ExamResult::updateOrCreate(
                ['exam_event_id' => $examEvent->id, 'student_id' => $student->id],
                collect($dogrulanmis)->except('subjects')->put('entered_by', auth()->id())->all(),
            );

            foreach ($dogrulanmis['subjects'] as $dersId => $sayilar) {
                $sonuc->subjects()->updateOrCreate(
                    ['subject_id' => (int) $dersId],
                    $sayilar,
                );
            }
        });

        return redirect()
            ->route('admin.exam-results.edit', [$examEvent, $student])
            ->with('success', 'Deneme sonucu kaydedildi.');
    }

    private function mevcutSonuc(ExamEvent $examEvent, User $student): ?ExamResult
    {
        return ExamResult::where('exam_event_id', $examEvent->id)
            ->where('student_id', $student->id)
            ->with('subjects')
            ->first();
    }
}
