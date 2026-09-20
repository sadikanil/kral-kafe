<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rota grubunu belirli rollere kapatir: role:parent, role:coach,teacher.
 *
 * AdminMiddleware'in genellenmis hali. Yonetici panelinin kendi middleware'i
 * yerinde kaliyor (isAdmin uzerinden), buna dokunulmadi; veli ve sonraki
 * paneller bunu kullanir.
 *
 * Bilinmeyen bir rol adi yapilandirma hatasidir ve sessizce "kimseyi
 * gecirme"ye dusmemeli - rotayi yazan kisi ilk istekte gormeli.
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (! auth()->check()) {
            return redirect()->route('login');
        }

        $izinli = array_map(
            fn (string $ad) => Role::from($ad),
            $roles
        );

        if (! auth()->user()->hasRole(...$izinli)) {
            abort(403, 'Bu sayfaya erişim yetkiniz yok.');
        }

        return $next($request);
    }
}
