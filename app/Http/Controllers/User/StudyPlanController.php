<?php

namespace App\Http\Controllers\User;

use App\Enums\PlanPeriod;
use App\Http\Controllers\Controller;
use App\Models\ExamEvent;
use App\Models\StudyPlanItem;
use App\Support\WeekParameter;
use App\Support\WeekPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Haftalik calisma plani - ogrenci tarafi (Dalga 13).
 *
 * Ogrenci yalnizca KENDI maddesini tamamlayabilir. Plani koc belirler;
 * ogrenci koc maddesini ekleyemez, silemez, degistiremez.
 *
 * Dalga 30c: tek istisna serbest deneme - ogrenci onu istedigi gune koyar
 * ve kendi koydugunu geri alabilir (karar, 23 Eyl).
 */
class StudyPlanController extends Controller
{
    /** Haftalik takvim; ?hafta= haftanin herhangi bir gunu. */
    public function show(Request $request): View
    {
        $ogrenci = $request->user();
        $hafta = WeekParameter::resolveCurrent($request->query('hafta'));

        return view('user.plan', [
            'hafta' => $hafta,
            'days' => WeekPlan::for($ogrenci, $hafta),
            'examClub' => $ogrenci->entitlements()->examClub,
            'flexible' => ExamEvent::flexibleOpen()->get(),
            // Planina koydugu serbest denemeler (deneme id => gun): form
            // "Ekle" yerine gunu ve "Tasi"yi gostersin.
            'scheduled' => StudyPlanItem::where('student_id', $ogrenci->id)
                ->where('created_by', $ogrenci->id)
                ->whereNotNull('exam_event_id')
                ->get(['exam_event_id', 'plan_date'])
                ->mapWithKeys(fn (StudyPlanItem $m) => [$m->exam_event_id => $m->plan_date]),
        ]);
    }

    /**
     * Serbest denemeyi bir gune koyar. Gun pencerenin icinde (ve bugunden
     * once degil) olmali; tarihli deneme tasinamaz. Serbest deneme = Deneme
     * Kulubu, hakki olmayan koyamaz.
     */
    public function scheduleExam(Request $request): RedirectResponse
    {
        $ogrenci = $request->user();
        abort_unless($ogrenci->entitlements()->examClub, 403);

        $v = $request->validate([
            'exam_event_id' => ['required', 'integer', 'exists:exam_events,id'],
            'plan_date' => ['required', 'date_format:Y-m-d'],
        ]);

        $deneme = ExamEvent::findOrFail($v['exam_event_id']);
        if (! $deneme->is_flexible) {
            throw ValidationException::withMessages(['exam_event_id' => 'Bu denemenin günü sabit; takvimde zaten görünüyor.']);
        }

        $ilk = max($deneme->exam_date->toDateString(), \App\Support\LocalDay::today());
        $son = $deneme->available_until->toDateString();
        if ($v['plan_date'] < $ilk || $v['plan_date'] > $son) {
            throw ValidationException::withMessages(['plan_date' => "Bu deneme için {$deneme->windowLabel()} arasında bir gün seçin."]);
        }

        // Ogrenci basina deneme basina TEK madde: cift dokunus ya da yeniden
        // gonderilen POST ikinci satir acmasin, farkli gun secmek var olani
        // tasisin (QA hata 8). Anahtarda created_by da var: ogrenci yalnizca
        // kendi koydugunu tasir, baskasinin maddesine dokunmaz (removeExam
        // ile ayni sinir).
        $madde = StudyPlanItem::updateOrCreate(
            ['student_id' => $ogrenci->id, 'exam_event_id' => $deneme->id, 'created_by' => $ogrenci->id],
            [
                'title' => "{$deneme->title} ({$deneme->exam_type->label()})",
                'plan_date' => $v['plan_date'],
                'period' => PlanPeriod::Week->value,
                'week_start' => PlanPeriod::Week->startFor($v['plan_date']),
            ],
        );

        $mesaj = $madde->wasRecentlyCreated
            ? 'Deneme planına eklendi.'
            : 'Deneme ' . $madde->plan_date->locale('tr')->translatedFormat('j F') . ' gününe taşındı.';

        return redirect()->route('user.plan', ['hafta' => $v['plan_date']])->with('success', $mesaj);
    }

    /** Yalnizca kendi koydugu deneme; kocun maddesine dokunamaz. */
    public function removeExam(StudyPlanItem $item): RedirectResponse
    {
        abort_unless($item->student_id === auth()->id()
            && $item->exam_event_id !== null
            && $item->created_by === auth()->id(), 403);

        $item->delete();

        return back()->with('success', 'Deneme planından çıkarıldı.');
    }

    public function complete(StudyPlanItem $item): RedirectResponse
    {
        // Sahiplik kontrolu ACIK: policy olmadan da kirilmamali, cunku bu
        // tek satir "baskasinin planini isaretleme" sinirinin tamami.
        abort_unless($item->student_id === auth()->id(), 403);

        $item->markDone();

        return $this->backToItem($item)->with('success', 'Tamamlandı olarak işaretlendi.');
    }

    /** Yanlis dokunusu geri alir; complete() ile ayni sahiplik siniri. */
    public function reopen(StudyPlanItem $item): RedirectResponse
    {
        abort_unless($item->student_id === auth()->id(), 403);

        $item->reopen();

        return $this->backToItem($item)->with('success', 'Madde yeniden açıldı.');
    }

    /**
     * Haftalik takvimden gelindiyse maddenin hizasina doner (QA a11y A15):
     * uzun hafta izgarasi her dokunustan sonra en uste atiyordu. Paneldeki
     * "Bugun" karti zaten ustte; oraya capa eklemek gereksiz.
     */
    private function backToItem(StudyPlanItem $item): RedirectResponse
    {
        $geri = back();

        return parse_url(url()->previous(), PHP_URL_PATH) === parse_url(route('user.plan'), PHP_URL_PATH)
            ? $geri->withFragment('madde-' . $item->id)
            : $geri;
    }
}
