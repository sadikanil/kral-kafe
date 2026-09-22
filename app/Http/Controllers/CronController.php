<?php

namespace App\Http\Controllers;

use App\Services\NotificationBuilder;
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
        $beklenen = (string) config('kafe.cron_anahtari');
        $gelen = (string) $request->header('X-Cron-Anahtari', '');

        // hash_equals: zamanlama saldirisina karsi. Bos anahtar hicbir zaman
        // gecerli degil - kisa devre once.
        if ($beklenen === '' || ! hash_equals($beklenen, $gelen)) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $gun = LocalDay::today();

        return response()->json([
            'gun' => $gun,
            'devamsizlik' => $bildirimler->absenceNotices($gun),
            'deneme_hatirlatmasi' => $bildirimler->examReminders($gun),
        ]);
    }
}
