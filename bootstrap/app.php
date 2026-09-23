<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Uygulama Vercel'de bir proxy arkasinda calisiyor. Bu cagri olmadan
        // request()->ip() platformun ic adresini doner ve her ziyaretci icin
        // ayni cikar - QR sahteciligine karsi planlanan IP kapisi sessizce
        // ise yaramaz hale gelir. isSecure() de yanlis doner, bu da uretilen
        // adreslerin http olmasina yol acar.
        $middleware->trustProxies(at: '*');

        // Bayat calisma oturumlarini, biri onlara bakmadan once kapatir.
        // "Tembel kapatma birincil" karari: cron kacsa da veri dogru gorunur.
        $middleware->appendToGroup('web', \App\Http\Middleware\SettleStaleSessions::class);

        // Sifresi sifirlanan kullanici (Dalga 18b) her cihazdan atilir.
        $middleware->appendToGroup('web', \App\Http\Middleware\EndPasswordlessSession::class);

        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'subscription' => \App\Http\Middleware\ActiveSubscription::class,
            'role' => \App\Http\Middleware\EnsureRole::class,
            'entitlement' => \App\Http\Middleware\EnsureEntitlement::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
