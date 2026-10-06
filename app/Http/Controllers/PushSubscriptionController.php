<?php

namespace App\Http\Controllers;

use App\Models\PushSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Telefon bildirimi aboneligi (6 Ekim 2026). Tarayici izin verince
 * aboneligini buraya yollar; "Kapat" ya da cikista silinir. Ayni cihaz
 * baska hesapla girerse abonelik yeni hesaba gecer: bir telefona iki
 * kisinin bildirimi gitmesin.
 */
class PushSubscriptionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $veri = $request->validate([
            'endpoint' => ['required', 'string', 'url:https', 'max:2000'],
            'keys.p256dh' => ['required', 'string', 'max:120'],
            'keys.auth' => ['required', 'string', 'max:40'],
        ]);

        PushSubscription::updateOrCreate(
            ['endpoint_hash' => PushSubscription::hashOf($veri['endpoint'])],
            [
                'user_id' => $request->user()->id,
                'endpoint' => $veri['endpoint'],
                'p256dh' => $veri['keys']['p256dh'],
                'auth' => $veri['keys']['auth'],
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
            ],
        );

        return response()->json(['ok' => true]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $request->validate(['endpoint' => ['required', 'string', 'max:2000']]);

        PushSubscription::where('endpoint_hash', PushSubscription::hashOf($request->input('endpoint')))
            ->where('user_id', $request->user()->id)
            ->delete();

        return response()->json(['ok' => true]);
    }
}
