<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Enums\SessionEndReason;
use App\Models\StudySession;
use App\Models\StudyTable;
use App\Support\LocalDay;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Canli ekran: su an iceride kim, hangi masada, ne kadardir.
 */
class LiveController extends Controller
{
    public function index(): View
    {
        $acikOturumlar = StudySession::open()
            ->with(['student', 'table', 'pauses'])
            ->orderBy('started_at')
            ->get();

        // Doluluk yuklu listeden: StudyTable::occupancy() acik oturumlari bir
        // kez daha sayardi (P9). Masa basina tek oturum (kismi tekil indeks),
        // yani acik oturum sayisi dolu masa sayisidir - occupancy ile ayni tanim.
        $toplamYer = StudyTable::active()->count();
        $doluluk = ['total' => $toplamYer, 'free' => max(0, $toplamYer - $acikOturumlar->count())];

        // "12 saati asan oturum yoneticiye anomali olarak duser" (FEATURE 1).
        // Sessizce kapatmak kurali uygulamak sayilmaz; birinin gormesi gerek.
        //
        // Pencere YEDI GUN, "bugun" degil: yonetici her gun canli ekrana
        // bakmak zorunda degil. Tek gunluk pencerede hafta sonuna ya da izin
        // gunune dusen her anomali hic gorulmeden kaybolurdu - yani kural
        // uygulanmis sayilmazdi.
        [$gunBasi] = LocalDay::bounds(
            Carbon::parse(LocalDay::today(), LocalDay::timezone())->subDays(6)->toDateString()
        );
        [, $gunSonu] = LocalDay::bounds(LocalDay::today());

        $anomaliler = StudySession::where('end_reason', SessionEndReason::OverLimit)
            ->whereBetween('ended_at', [$gunBasi, $gunSonu])
            ->with(['student', 'table'])
            ->orderByDesc('ended_at')
            ->get();

        // Onay kuyrugu (Dalga 9): bitmis ama karara baglanmamis oturumlar.
        // Otomatik kapananlar da buraya duser - asil dogrulanmasi gereken
        // onlar, cunku ogrenci cikisi bildirmemis demektir. Sistemin
        // kapattiklari (gece 00:00, sure asimi) en ustte ve etiketli; toplu
        // onaya girmezler.
        $onayBekleyenler = StudySession::awaitingApproval()
            ->with(['student', 'table', 'pauses'])
            ->orderBy('ended_at')
            ->get()
            ->sortBy(fn (StudySession $s) => $s->end_reason?->needsExplicitReview() ? 0 : 1)
            ->values();

        return view('admin.live', [
            'sessions' => $acikOturumlar,
            'pending' => $onayBekleyenler,
            'bulkIds' => $onayBekleyenler->reject(fn (StudySession $s) => $s->end_reason?->needsExplicitReview())->pluck('id'),
            'anomalies' => $anomaliler,
            'tableCount' => $doluluk['total'],
            'freeTables' => $doluluk['free'],
        ]);
    }
}
