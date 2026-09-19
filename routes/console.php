<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Unutulan calisma oturumlarini kapatir.
//
// Bu zamanlama sistemin DOGRULUGU icin gerekli DEGIL: SessionCloser bitis anini
// oturumun kendi verisinden hesapliyor, dolayisiyla gec calissa da, hic
// calismasa da sonuc ayni. SettleStaleSessions middleware'i, veriye BAKAN her
// istekte ayni hesabi zaten yapiyor.
//
// Buradaki amac yalnizca tazelik: kimse panele bakmasa bile kayitlar kapali
// olsun. Kapanistan bir saat sonra, kafe saatiyle.
Schedule::command('oturum:kapat')
    ->dailyAt('22:00')
    ->timezone(config('kafe.timezone'));
