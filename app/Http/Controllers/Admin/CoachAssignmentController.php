<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Koc-ogrenci atamasi (Dalga 14).
 *
 * Atamayi YALNIZCA yonetici yapar - rota grubu 'admin' middleware'i
 * altinda. Kocun kendine ogrenci atayabilmesi, atamanin butun anlamini
 * (kimin kimi gordugu) ortadan kaldirirdi.
 */
class CoachAssignmentController extends Controller
{
    public function attach(Request $request, User $student): RedirectResponse
    {
        abort_unless($student->isStudent(), 404);

        // Dalga 19-20: koclugu kapsamayan pakete (Tier 1) koc atanmaz.
        if (! $student->entitlements()->coaching) {
            return back()->with('error', 'Öğrencinin paketi koçluk içermiyor.');
        }

        $request->validate([
            // Koc olarak yalnizca koc ya da yonetici atanabilir. Rol kontrolu
            // SORGUDA: 'exists:users,id' tek basina bir ogrenciyi de
            // gecirirdi ve o ogrenci digerinin verisini gorur hale gelirdi.
            'coach_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->whereIn('role', [
                    Role::Coach->value,
                    Role::Admin->value,
                ]),
            ],
        ], [
            'coach_id.exists' => 'Seçilen kişi koç ya da yönetici değil.',
        ]);

        // syncWithoutDetaching: formu iki kez gonderen tarayici ikinci satir
        // olusturmamali. Tablodaki unique kisit son duvar, bu ilk duvar -
        // kisite carpmak 500 verirdi, burasi sessizce dogru sonuca varir.
        $student->coaches()->syncWithoutDetaching([
            $request->integer('coach_id') => ['created_by' => auth()->id()],
        ]);

        return back()->with('success', 'Koç atandı.');
    }

    public function detach(User $student, User $coach): RedirectResponse
    {
        abort_unless($student->isStudent(), 404);

        $student->coaches()->detach($coach->id);

        return back()->with('success', 'Koç ataması kaldırıldı.');
    }
}
