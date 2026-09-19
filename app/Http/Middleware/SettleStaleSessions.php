<?php

namespace App\Http\Middleware;

use App\Services\SessionCloser;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bayat calisma oturumlarini, biri onlara BAKMADAN once kapatir.
 *
 * "Tembel kapatma birincil" karari burada uygulaniyor. Cron kacarsa, gec
 * calisirsa ya da hic kurulmazsa sistem yine dogru veri gosterir.
 *
 * Neden middleware, kontrolcu basinda uc ayri cagri degil: acik oturum okuyan
 * yol sayisi artiyor (canli ekran, ogrenci paneli, QR ekrani, Dalga 5'te
 * raporlar). Biri unutulursa kullanici bayat veri gorur ve bunu kimse fark
 * etmez - hata mesaji yok, yalnizca yanlis sayi.
 *
 * Ayrica oturum BASLATMAYI da kurtariyor: kismi tekil indeks yuzunden
 * unutulmus bir oturum ogrencinin yeni oturum acmasini engelliyor. Kapanmis
 * olmasi, 'switched' yerine dogru 'auto_closed' sebebiyle kapanmasini saglar.
 *
 * GET istegi yazma yapiyor ama yazma DETERMINISTIK ve idempotan: ayni satira
 * ayni degerler yazilir, es zamanli iki istek catismaz.
 */
class SettleStaleSessions
{
    public function __construct(private readonly SessionCloser $closer)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (auth()->check()) {
            $this->closer->closeStale();
        }

        return $next($request);
    }
}
