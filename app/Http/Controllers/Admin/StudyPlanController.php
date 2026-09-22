<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StudyPlanItem;
use App\Models\User;
use App\Support\LocalDay;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Haftalik calisma plani - yonetici tarafi (Dalga 13).
 *
 * Plani yonetici belirliyor (karar 10). Ogrenci yalnizca tamamliyor.
 */
class StudyPlanController extends Controller
{
    public function store(Request $request, User $student): RedirectResponse
    {
        abort_unless($student->isStudent(), 404);

        $dogrulanmis = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'subject_id' => ['nullable', 'integer', 'exists:subjects,id'],
            // Hafta verilmezse ICINDE BULUNULAN hafta. Gecmis bir haftaya
            // madde eklemek bilincli bir istek olmali, varsayilan degil.
            'week_start' => ['nullable', 'date'],
        ]);

        StudyPlanItem::create([
            'student_id' => $student->id,
            'subject_id' => $dogrulanmis['subject_id'] ?? null,
            'title' => $dogrulanmis['title'],
            'week_start' => LocalDay::weekStart($dogrulanmis['week_start'] ?? LocalDay::today()),
            'created_by' => auth()->id(),
        ]);

        return back()->with('success', 'Plan maddesi eklendi.');
    }

    public function destroy(StudyPlanItem $item): RedirectResponse
    {
        $item->delete();

        return back()->with('success', 'Plan maddesi silindi.');
    }
}
