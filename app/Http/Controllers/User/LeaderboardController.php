<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Services\Leaderboard;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Liderlik tablosu (7 Kasim 2026): haftalik ve aylik, ilk uc podyumda.
 *
 * Uye digerlerinin yalnizca kisaltilmis adini ve toplam puanini gorur;
 * kendi puan dokumunu ayrica. Yonetici tam ad ve dokum gorur.
 */
class LeaderboardController extends Controller
{
    public function index(Request $request, Leaderboard $tablo): View
    {
        $pencere = Leaderboard::pencere((string) $request->query('donem', 'hafta'), $request->query('tarih'));
        $satirlar = $tablo->satirlar($pencere['from'], $pencere['to']);
        $ben = $request->user();

        return view('user.leaderboard', [
            'pencere' => $pencere,
            'podyum' => $satirlar->take(3),
            'kalanlar' => $satirlar->slice(3)->values(),
            'benim' => $satirlar->first(fn (array $s) => $s['user']->id === $ben->id),
            'yonetici' => $ben->isAdmin(),
            'puan' => config('kafe.puan'),
        ]);
    }
}
