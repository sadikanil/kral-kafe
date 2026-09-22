<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ExamType;
use App\Http\Controllers\Controller;
use App\Models\Subject;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Ders tanimlari (Dalga 12).
 *
 * Dersler VERIDEN geliyor, koda gomulu degil: TYT, AYT ve LGS listeleri
 * farkli ve kurumdan kuruma degisebiliyor.
 *
 * Ders SILINMEZ, kapatilir: silinen bir derse bagli gecmis deneme sonuclari
 * da giderdi. Masalarla ve paketlerle ayni karar.
 */
class SubjectController extends Controller
{
    public function index(): View
    {
        return view('admin.subjects.index', [
            'subjects' => Subject::orderBy('exam_type')->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $dogrulanmis = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'exam_type' => ['required', 'string', 'max:10'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);

        Subject::firstOrCreate(
            ['exam_type' => $dogrulanmis['exam_type'], 'name' => $dogrulanmis['name']],
            ['sort_order' => $dogrulanmis['sort_order'] ?? 0],
        );

        return back()->with('success', 'Ders eklendi.');
    }

    public function toggleStatus(Subject $subject): RedirectResponse
    {
        $subject->update(['is_active' => ! $subject->is_active]);

        return back()->with('success', $subject->is_active ? 'Ders açıldı.' : 'Ders kapatıldı.');
    }
}
