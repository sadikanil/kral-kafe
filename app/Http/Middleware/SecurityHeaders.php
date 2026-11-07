<?php

namespace App\Http\Middleware;

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
 * "max-age=63072000; includeSubDomains; preload" goruldu). CSP de yok:
 * sayfalardaki satir ici betikler nonce'a tasinmadan acilirsa uygulama
 * kirilir (docs/security/php2shell.md, sonraki adimlar).
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

        $response->headers->remove('X-Powered-By');

        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }

        return $response;
    }
}
