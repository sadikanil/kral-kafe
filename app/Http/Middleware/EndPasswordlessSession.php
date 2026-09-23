<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Dalga 18b: yonetici sifreyi sifirladiysa kullanici her cihazdan atilir.
 *
 * Oturum surucusune bagli degil: acik oturum hangi cihazda olursa olsun,
 * sonraki isteginde sifresinin silindigi gorulur ve cikis yaptirilir.
 */
class EndPasswordlessSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check() && Auth::user()->password === null) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login');
        }

        return $next($request);
    }
}
