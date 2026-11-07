<?php

namespace App\Support;

/**
 * Content-Security-Policy (7 Kasim 2026).
 *
 * Betikler yalnizca kendi kaynagimizdan (public/js) ya da bu istege ozel
 * nonce'u tasiyan satir ici <script @nonce> bloklarindan calisir. Sayfaya
 * sizan bir <script> ya da onclick="..." calismaz: nonce'u bilmez.
 *
 * Satir ici olay nitelikleri (onsubmit, onclick, onchange) bu yuzden
 * kullanilmaz; yerine data-confirm, data-yazdir, data-yenile,
 * data-otomatik-gonder (public/js/kabuk.js). Muhafiz: tests/Feature/CspTest.
 *
 * Stil satir ici kalabilir ('unsafe-inline'): gorunumlerde yuzlerce
 * style="..." var ve stil enjeksiyonu betik kadar tehlikeli degil.
 *
 * Mod (KAFE_CSP): enforce (varsayilan) | report (yalniz rapor, engellemez)
 * | off. Canlida bir sey kirilirsa panelden "report" yapip yeniden dagit;
 * kod degismeden eski davranisa doner.
 */
final class Csp
{
    private const NITELIK = 'csp_nonce';

    /** Bu istegin nonce'u; ilk cagrida uretilir, istek boyunca ayni kalir. */
    public static function nonce(): string
    {
        $istek = request();
        $nonce = $istek->attributes->get(self::NITELIK);

        if (! is_string($nonce) || $nonce === '') {
            $nonce = base64_encode(random_bytes(18));
            $istek->attributes->set(self::NITELIK, $nonce);
        }

        return $nonce;
    }

    public static function mod(): string
    {
        $mod = strtolower((string) config('kafe.csp', 'enforce'));

        return in_array($mod, ['enforce', 'report', 'off'], true) ? $mod : 'enforce';
    }

    public static function basliginAdi(): ?string
    {
        return match (self::mod()) {
            'enforce' => 'Content-Security-Policy',
            'report' => 'Content-Security-Policy-Report-Only',
            default => null,
        };
    }

    public static function politika(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'",
            "style-src 'self' 'unsafe-inline'",
            // https: urun fotograflari ve deneme PDF'leri nesne depolamada
            // (Supabase Storage); data:/blob: QR, kamera ve onizlemeler.
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            // Vercel Analytics / Speed Insights ayni kaynaga (/_vercel/...) yazar.
            "connect-src 'self'",
            "media-src 'self' blob:",
            "worker-src 'self'",
            "manifest-src 'self'",
            "frame-src 'none'",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
        ]);
    }
}
