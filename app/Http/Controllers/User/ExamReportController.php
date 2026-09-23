<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\ExamReport;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;

/**
 * Ogrencinin deneme raporlari - salt okunur. Yukleme yalnizca yonetici.
 */
class ExamReportController extends Controller
{
    public function index()
    {
        return view('user.exam-reports.index', [
            'reports' => ExamReport::where('student_id', Auth::id())->with('examEvent')->orderByDesc('created_at')->get(),
        ]);
    }

    public function show(ExamReport $report)
    {
        Gate::authorize('view', $report);

        return view('user.exam-reports.show', ['report' => $report->load('examEvent')]);
    }

    public function pdf(ExamReport $report)
    {
        // Once yetki: dosyasi olmayan baskasinin raporu 404 degil 403 almali,
        // yoksa var olup olmadigi ele verilir.
        Gate::authorize('view', $report);

        // response() Content-Length icin size() cagiriyor; eksik dosyada
        // (depo tasima, dosyasiz yedekten donus) Flysystem hatasi 500 olurdu.
        $disk = Storage::disk(config('filesystems.uploads'));
        abort_unless($report->file_path && $disk->exists($report->file_path), 404, 'Dosya bulunamadı.');

        return $disk->response($report->file_path, $report->fileName(), ['Content-Type' => 'application/pdf']);
    }
}
