<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExamEvent;
use App\Models\ExamReport;
use App\Models\User;
use App\Services\ExamReportAnalyzer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Deneme sonuc PDF'leri: yonetici ogrencinin sayfasina yukler, analiz
 * ettirir, gerekirse yeniden analiz eder ya da kaldirir.
 *
 * Analiz yukleme isteginin ICINDE calisir (kuyruk sync). Basarisiz olursa
 * dosya yine kaydedilir ve durum 'failed' olur; "Yeniden analiz et" ile
 * tekrar denenir. Yukleme ile analizi ayirmak: zaman asimi dosyayi
 * kaybettirmesin. Daha once basariyla analiz edilmis raporun yeniden
 * analizi basarisiz olursa eski analiz korunur.
 */
class ExamReportController extends Controller
{
    public function __construct(private ExamReportAnalyzer $analizci)
    {
    }

    public function index(User $user)
    {
        abort_unless($user->isStudent(), 404);

        return view('admin.exam-reports.index', [
            'student' => $user,
            'reports' => ExamReport::where('student_id', $user->id)->with('examEvent')->orderByDesc('created_at')->get(),
            'events' => ExamEvent::orderByDesc('exam_date')->limit(30)->get(),
        ]);
    }

    public function store(Request $request, User $user)
    {
        abort_unless($user->isStudent(), 404);

        $veri = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'exam_event_id' => ['nullable', 'integer', 'exists:exam_events,id'],
            'pdf' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        $disk = config('filesystems.uploads');
        $yol = $request->file('pdf')->storeAs(
            'deneme-raporlari/' . $user->id,
            Str::uuid() . '.pdf',
            $disk
        );

        $rapor = ExamReport::create([
            'student_id' => $user->id,
            'exam_event_id' => $veri['exam_event_id'] ?? null,
            'title' => $veri['title'],
            'file_path' => $yol,
            'uploaded_by' => auth()->id(),
            'status' => ExamReport::PENDING,
        ]);

        $hata = $this->calistir($rapor);

        return redirect()->route('admin.exam-reports.index', $user)
            ->with($hata === null ? 'success' : 'error',
                $hata === null
                    ? 'Rapor yüklendi ve analiz edildi.'
                    : 'Rapor yüklendi ama analiz yapılamadı: ' . $hata);
    }

    public function analyze(ExamReport $report)
    {
        $oncedenAnalizli = $report->isAnalyzed();
        $hata = $this->calistir($report);

        if ($hata === null) {
            return back()->with('success', 'Analiz tamamlandı.');
        }

        // Eski analiz korunduysa bunu soyle: sayfada hala sonuclar gorunuyor,
        // "yapilamadi" tek basina yoneticiyi sasirtir.
        return back()->with('error', $oncedenAnalizli
            ? 'Yeniden analiz başarısız oldu; önceki analiz yerinde duruyor. Hata: ' . $hata
            : 'Analiz yapılamadı: ' . $hata);
    }

    public function destroy(ExamReport $report)
    {
        Storage::disk(config('filesystems.uploads'))->delete($report->file_path);
        $ogrenci = $report->student_id;
        $report->delete();

        return redirect()->route('admin.exam-reports.index', $ogrenci)
            ->with('success', 'Rapor kaldırıldı.');
    }

    public function pdf(ExamReport $report)
    {
        // response() Content-Length icin size() cagiriyor; eksik dosyada
        // (gecici UPLOAD_DISK=public onizleme, dosyasiz yedek) 500 olurdu.
        $disk = Storage::disk(config('filesystems.uploads'));
        abort_unless($report->file_path && $disk->exists($report->file_path), 404, 'Dosya bulunamadı.');

        return $disk->response($report->file_path, $report->fileName(), ['Content-Type' => 'application/pdf']);
    }

    /** Basariliysa null, degilse yoneticiye gosterilecek hata. */
    private function calistir(ExamReport $rapor): ?string
    {
        $icerik = Storage::disk(config('filesystems.uploads'))->get($rapor->file_path);
        $sonuc = $this->analizci->analyze((string) $icerik, $rapor->fileName());

        if ($sonuc['success']) {
            $rapor->update(['status' => ExamReport::DONE, 'analysis' => $sonuc['data'], 'error' => null, 'analyzed_at' => now()]);

            return null;
        }

        $hata = Str::limit($sonuc['error'], 490);

        // Onceki iyi analiz gecici bir servis hatasiyla (503, zaman asimi)
        // ogrenciden ve veliden gizlenmesin: durum DONE kalir, hata yalnizca
        // yoneticiye flash olur. Hic analiz edilmemis rapor FAILED olur.
        if (! $rapor->isAnalyzed()) {
            $rapor->update(['status' => ExamReport::FAILED, 'error' => $hata]);
        }

        return $hata;
    }
}
