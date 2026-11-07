<?php

namespace App\Http\Middleware;

use App\Support\Csp;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guvenlik basliklari (php2shell sertlestirmesi, 7 Kasim 2026).
 *
 * Yanitta zaten olan baslik ezilmez. Permissions-Policy kamerayi (masa QR
 * okuyucu) ve konumu (oturum baslatma, kafe konumu) yalnizca kendi
 * kaynagimiza acar; mikrofon, odeme ve USB kapali.
 *
 * X-Powered-By PHP'nin kendisinden gelir (expose_php). Asil kapatma
 * api/php.ini'de; buradaki header_remove php.ini'nin uygulanmadigi
 * barindirmada da surumun sizmamasi icin.
 *
 * HSTS burada yok: Vercel kendi alan adlarinda gonderiyor (canlida
 * "max-age=63072000; includeSubDomains; preload" goruldu).
 *
 * CSP (7 Kasim 2026): satir ici betikler nonce'a tasindi; politika ve mod
 * App\Support\Csp'de. Hata ayiklama acikken 5xx sayfasinda gonderilmez:
 * Laravel'in hata sayfasi kendi satir ici betikleriyle gelir.
 */
class SecurityHeaders
{
    private const BASLIKLAR = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(self), geolocation=(self), microphone=(), payment=(), usb=()',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach (self::BASLIKLAR as $ad => $deger) {
            if (! $response->headers->has($ad)) {
                $response->headers->set($ad, $deger);
            }
        }

        $this->csp($response);

        $response->headers->remove('X-Powered-By');

        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }

    private function csp(Response $response): void
    {
        $ad = Csp::basliginAdi();

        if ($ad === null
            || $response->headers->has('Content-Security-Policy')
            || $response->headers->has('Content-Security-Policy-Report-Only')
            || (config('app.debug') && $response->getStatusCode() >= 500)) {
            return;
        }

        $response->headers->set($ad, Csp::politika(Csp::nonce()));
    }
}
