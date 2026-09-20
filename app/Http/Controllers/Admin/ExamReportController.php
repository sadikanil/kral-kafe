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
 * kaybettirmesin.
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

        $this->calistir($rapor);

        return redirect()->route('admin.exam-reports.index', $user)
            ->with($rapor->status === ExamReport::DONE ? 'success' : 'error',
                $rapor->status === ExamReport::DONE
                    ? 'Rapor yüklendi ve analiz edildi.'
                    : 'Rapor yüklendi ama analiz yapılamadı: ' . $rapor->error);
    }

    public function analyze(ExamReport $report)
    {
        $this->calistir($report);

        return back()->with($report->status === ExamReport::DONE ? 'success' : 'error',
            $report->status === ExamReport::DONE ? 'Analiz tamamlandı.' : 'Analiz yapılamadı: ' . $report->error);
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
        return Storage::disk(config('filesystems.uploads'))
            ->response($report->file_path, $report->fileName(), ['Content-Type' => 'application/pdf']);
    }

    private function calistir(ExamReport $rapor): void
    {
        $icerik = Storage::disk(config('filesystems.uploads'))->get($rapor->file_path);
        $sonuc = $this->analizci->analyze((string) $icerik, $rapor->fileName());

        $rapor->update($sonuc['success']
            ? ['status' => ExamReport::DONE, 'analysis' => $sonuc['data'], 'error' => null, 'analyzed_at' => now()]
            : ['status' => ExamReport::FAILED, 'error' => Str::limit($sonuc['error'], 490)]);
    }
}
