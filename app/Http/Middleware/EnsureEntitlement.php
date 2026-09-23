<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Paket hakki kapisi (Dalga 19). Kullanim: ->middleware('entitlement:examClub')
 *
 * Hak adi App\Support\Entitlements'in ozellik adidir.
 */
class EnsureEntitlement
{
    public function handle(Request $request, Closure $next, string $hak): Response
    {
        abort_unless($request->user()?->entitlements()->{$hak}, 403, 'Paketin bu bölümü kapsamıyor.');

        return $next($request);
    }
}
