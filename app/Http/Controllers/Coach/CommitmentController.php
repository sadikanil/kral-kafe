<?php

namespace App\Http\Controllers\Coach;

use App\Enums\CommitmentKind;
use App\Http\Controllers\Controller;
use App\Models\StudentCommitment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Haftalik sabit program (Dalga 30c): okul, dershane, distaki ozel ders.
 *
 * Yonetici ve koc girer (karar, 23 Eyl). Plan ile AYNI kapi
 * (User::canCoach). "Pzt-Cum" gun basina bir satirdir: tek gunu (ornegin
 * cuma erken cikis) silmek mumkun olsun.
 */
class CommitmentController extends Controller
{
    public function store(Request $request, User $student): RedirectResponse
    {
        $this->kapiyiAc($student);

        // Alanlar commitment_ onekli: plan formu ayni sayfada ve kendi
        // title/starts_at alanlarini tasiyor. Ortak ad, bir formun hatasinda
        // eski girdiyi ve hata durumunu obur forma da yaziyordu (program
        // saati plan maddesine sessizce kaydolabiliyordu). Kolonlar ayni.
        $v = $request->validate([
            'kind' => ['required', Rule::enum(CommitmentKind::class)],
            'commitment_title' => ['nullable', 'string', 'max:100'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:1,7'],
            'commitment_starts_at' => ['required', 'date_format:H:i'],
            'commitment_ends_at' => ['required', 'date_format:H:i', 'after:commitment_starts_at'],
        ], [
            'weekdays.required' => 'En az bir gün seçin.',
            'commitment_ends_at.after' => 'Bitiş başlangıçtan sonra olmalı.',
        ], [
            // Onekli adlar dil dosyasindaki starts_at karsiligini tutmaz;
            // kind ve weekdays'in orada hic karsiligi yok ("weekdays.0").
            'kind' => 'tür',
            'weekdays' => 'günler',
            'weekdays.*' => 'gün',
            'commitment_title' => 'ad',
            'commitment_starts_at' => 'başlangıç saati',
            'commitment_ends_at' => 'bitiş saati',
        ]);

        foreach (array_unique(array_map('intval', $v['weekdays'])) as $gun) {
            StudentCommitment::create([
                'student_id' => $student->id,
                'kind' => $v['kind'],
                'title' => $v['commitment_title'] ?? null,
                'weekday' => $gun,
                'starts_at' => $v['commitment_starts_at'],
                'ends_at' => $v['commitment_ends_at'],
                'created_by' => auth()->id(),
            ]);
        }

        return back()->with('success', 'Programa eklendi.');
    }

    public function destroy(StudentCommitment $commitment): RedirectResponse
    {
        $this->kapiyiAc($commitment->student);

        $commitment->delete();

        return back()->with('success', 'Programdan silindi.');
    }

    private function kapiyiAc(?User $student): void
    {
        abort_if($student === null || ! $student->isStudent(), 404);
        abort_unless(auth()->user()->canCoach($student), 403);
    }
}
