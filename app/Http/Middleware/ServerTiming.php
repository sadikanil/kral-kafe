<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-Timing basligi (Faz 2 / H, P17).
 *
 * Uretimde yanit suresi ag, Laravel acilisi ve veritabani olarak
 * ayrilamiyordu. Baslik tarayicinin gelistirici aracinda (Network > Timing)
 * su ayrimi gosterir:
 *
 *   boot  istek PHP'ye girdiginden bu ara katmana kadar (cerceve acilisi)
 *   db    sorgularin toplam suresi ve sayisi; ilk sorgu baglanti kurulumunu
 *         da icerir (Supavisor el sikismasi, bkz. DB_PERSISTENT)
 *   app   istegin toplami
 *
 * Global ve en basta: oturumun okuma/yazma sorgulari da sayilir. Zamanlama
 * saldirgana da ipucu verdigi icin yalnizca SERVER_TIMING=true iken calisir;
 * kapaliyken dinleyici bile kurulmaz.
 */
class ServerTiming
{
    public function __construct(private Dispatcher $olaylar)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('database.server_timing')) {
            return $next($request);
        }

        $giris = microtime(true);
        $baslangic = defined('LARAVEL_START') ? LARAVEL_START : $giris;
        $sayi = 0;
        $sure = 0.0;

        // DB::listen varsayilan baglantiyi kurar; olay dinleyicisi kurmaz.
        $this->olaylar->listen(QueryExecuted::class, function (QueryExecuted $sorgu) use (&$sayi, &$sure) {
            $sayi++;
            $sure += $sorgu->time;
        });

        $yanit = $next($request);

        $yanit->headers->set('Server-Timing', sprintf(
            'boot;dur=%.1f, db;dur=%.1f;desc="%d sorgu", app;dur=%.1f',
            ($giris - $baslangic) * 1000,
            $sure,
            $sayi,
            (microtime(true) - $baslangic) * 1000,
        ));

        return $yanit;
    }
}
