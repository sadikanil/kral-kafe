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
        Gate::authorize('view', $report);

        return Storage::disk(config('filesystems.uploads'))
            ->response($report->file_path, $report->fileName(), ['Content-Type' => 'application/pdf']);
    }
}
