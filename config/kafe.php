<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Kafe saat dilimi
    |--------------------------------------------------------------------------
    |
    | config/app.php icindeki 'timezone' UTC olarak KALIR ve degistirilmez:
    | Postgres sutunlari "timestamp without time zone" oldugu icin uygulama
    | saat dilimi degisirse kayitli tum satirlar sessizce yeniden yorumlanir.
    |
    | Bunun yerine gosterim ve gun/hafta sinirlari bu deger uzerinden kurulur.
    |
    */

    'timezone' => env('KAFE_TIMEZONE', 'Europe/Istanbul'),

    /*
    |--------------------------------------------------------------------------
    | Calisma saatleri
    |--------------------------------------------------------------------------
    |
    | Unutulan calisma oturumlari kapanis saatinde otomatik kapanir.
    | Yerel saat (yukaridaki timezone) olarak yazilir.
    |
    */

    'acilis' => env('KAFE_ACILIS', '09:00'),
    'kapanis' => env('KAFE_KAPANIS', '21:00'),

    /*
    |--------------------------------------------------------------------------
    | Oturum kurallari
    |--------------------------------------------------------------------------
    |
    | azami_saat        : bu sureyi asan oturum otomatik kapatilir ve anomali sayilir
    | sayilabilir_dakika: bundan kisa oturum kaydedilir ama istatistige girmez
    |                     (yanlis okutma / cift dokunus)
    |
    */

    'azami_saat' => (int) env('KAFE_AZAMI_SAAT', 12),
    'sayilabilir_dakika' => (int) env('KAFE_SAYILABILIR_DAKIKA', 2),

    /*
    |--------------------------------------------------------------------------
    | Kafe agi (QR sahteciligine karsi birincil savunma)
    |--------------------------------------------------------------------------
    |
    | Bos birakilirsa kapi DEVRE DISI kalir: oturum acilir ama kafe disindan
    | geldiyse anomali olarak isaretlenir ve yoneticide gorunur.
    |
    | Sert blok yalnizca IP dogrulandiktan SONRA acilmalidir; yanlis bir IP ile
    | zorlama acilirsa ilk gun hicbir ogrenci oturum acamaz.
    |
    */

    'izinli_ipler' => array_filter(explode(',', (string) env('KAFE_IPLER', ''))),
    'ip_zorunlu' => (bool) env('KAFE_IP_ZORUNLU', false),

];
