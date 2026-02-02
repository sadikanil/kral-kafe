<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ActiveSubscription
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!auth()->check()) {
            return redirect()->route('login');
        }

        $user = auth()->user();

        // Admins bypass subscription check
        if ($user->isAdmin()) {
            return $next($request);
        }

        if (!$user->hasActiveSubscription()) {
            // If already on dashboard, allow access to show the error message
            if ($request->routeIs('user.dashboard')) {
                return $next($request);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Aboneliğiniz aktif değil.',
                ], 403);
            }

            return redirect()->route('user.dashboard')
                ->with('error', 'Aboneliğiniz aktif değil. Lütfen yönetici ile iletişime geçin.');
        }

        return $next($request);
    }
}
