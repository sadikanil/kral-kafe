<?php

namespace App\Http\Controllers;

use App\Services\ExamImport\ExamImportRunner;
use App\Services\NotificationBuilder;
use App\Services\SessionCloser;
use App\Support\LocalDay;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gunluk zamanlanmis is ucu (Dalga 11).
 *
 * Vercel serverless oldugu icin gercek bir cron yok; Vercel Cron bu adresi
 * cagiriyor. Yani bu, KIMLIK DOGRULAMASI OLMAYAN bir internet ucu olurdu -
 * o yuzden gizli anahtar zorunlu.
 *
 * Anahtar TANIMSIZSA uc hic calismiyor. "Anahtar yoksa herkese acik"
 * varsayilani, degiskeni girmeyi unutan bir dagitimda ucu internete acardi
 * ve bunu kimse fark etmezdi.
 */
class CronController extends Controller
{
    public function daily(Request $request, NotificationBuilder $bildirimler): JsonResponse
    {
        $this->yetkili($request);

        $gun = LocalDay::today();
        // Deneme okuma zinciri koptuysa yeniden baslar (5 Ekim 2026).
        ExamImportRunner::nudge();

        return response()->json([
            'gun' => $gun,
            'devamsizlik' => $bildirimler->absenceNotices($gun),
            'deneme_hatirlatmasi' => $bildirimler->examReminders($gun),
            'stok_sayimi' => $bildirimler->stockCountReminders($gun),
        ]);
    }

    /**
     * Gece 00:00 kurali (1 Ekim 2026): ogrenci cikisi bildirmeden ya da
     * duraklatip gittiyse oturum gun sonunda kapanir.
     *
     * Kapanis aninin kendisi zaten oturumun verisinden gelir (SessionCloser:
     * idempotan, cron sapmasi onemsiz); bu uc yalnizca kimse giris yapmasa
     * da kapanislar sabah onay kuyrugunda hazir olsun diye var. Gunluk uctan
     * AYRI: o 23:00'te calisir ve "bugun"un devamsizligini hesaplar; gece
     * yarisindan sonra calissaydi yeni gunu bos sayardi.
     */
    public function nightly(Request $request, SessionCloser $kapatici): JsonResponse
    {
        $this->yetkili($request);

        ExamImportRunner::nudge();

        return response()->json(['kapanan_oturum' => $kapatici->closeStale()]);
    }

    /**
     * Deneme PDF okuma zincirinin bir halkasi (5 Ekim 2026): kuyrugun basi
     * bir adim, is kaldiysa sonraki halka. Sayfa kapali olsa da okuma surer.
     */
    public function examImports(Request $request, ExamImportRunner $zincir): JsonResponse
    {
        $this->yetkili($request);
        ExamImportRunner::reachable();

        return response()->json($zincir->link());
    }

    private function yetkili(Request $request): void
    {
        $beklenen = (string) config('kafe.cron_anahtari');
        // Vercel Cron yalnizca "Authorization: Bearer <CRON_SECRET>" gonderir.
        $gelen = (string) $request->bearerToken();

        // hash_equals: zamanlama saldirisina karsi. Bos anahtar hicbir zaman
        // gecerli degil - kisa devre once.
        if ($beklenen === '' || ! hash_equals($beklenen, $gelen)) {
            abort(Response::HTTP_FORBIDDEN);
        }
    }
}
