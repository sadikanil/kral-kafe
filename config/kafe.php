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
    | Deneme takvimi hatirlaticisi
    |--------------------------------------------------------------------------
    |
    | Siradaki denemeye bu kadar gun ya da daha az kaldiysa panel hatirlaticisi
    | uyari rengine doner. Deneme her zaman gosterilir; bu yalnizca vurgu esigi.
    |
    */

    'deneme_hatirlatma_gun' => (int) env('KAFE_DENEME_HATIRLATMA_GUN', 7),

    /*
    |--------------------------------------------------------------------------
    | Odeme vadesi
    |--------------------------------------------------------------------------
    |
    | Abonelik baslangicindan bu kadar gun sonra odenmemis bakiye "gecikmis"
    | sayilir (Subscription::syncPaymentStatus).
    |
    */

    'odeme_vadesi_gun' => (int) env('KAFE_ODEME_VADESI_GUN', 7),

    /*
    |--------------------------------------------------------------------------
    | Konum esigi (Dalga 10b)
    |--------------------------------------------------------------------------
    |
    | Oturum baslangicindaki konum kafeden bu kadar metreden uzaksa, oturum
    | onay kuyrugunda "uzak" isaretlenir - ENGELLENMEZ.
    |
    | 250 m bilerek genis: ic mekanda GPS sapmasi 50-100 metreyi buluyor ve
    | dar bir esik masada oturan gercek ogrenciyi supheli gosterirdi. Gercek
    | dagilimi gorduk ten sonra daraltilabilir.
    |
    */

    'konum_esigi_metre' => (int) env('KAFE_KONUM_ESIGI_METRE', 250),

    /*
    |--------------------------------------------------------------------------
    | Kafe agi — RAFTA (19 Eylul 2026)
    |--------------------------------------------------------------------------
    |
    | Bu iki ayari HICBIR KOD OKUMUYOR. Bilerek: kafenin cikis IP'si olculdu ve
    | dinamik cikti (TT ADSL havuzu), dolayisiyla beyaz liste bir kapi olarak
    | kullanilamaz — modem her resetlendiginde butun kafe disarida kalirdi.
    |
    | Ayarlar kurumsal sabit IP alinirsa diye duruyor. Uzerine mantik yazmadan
    | once README.md SS9.3 okunmali.
    |
    */

    'izinli_ipler' => array_filter(explode(',', (string) env('KAFE_IPLER', ''))),
    'ip_zorunlu' => (bool) env('KAFE_IP_ZORUNLU', false),


    /*
    |--------------------------------------------------------------------------
    | Ilk yonetici sifresi
    |--------------------------------------------------------------------------
    |
    | AdminSeeder bunu okur. Bos birakilirsa rastgele uretilip yalnizca konsola
    | yazilir; depo public oldugu icin koda ya da README'ye yazilmaz.
    |
    | Seeder icinde dogrudan env() cagirmak YANLIS olurdu: config onbellege
    | alindiginda env() bos doner ve kurulum sessizce tahmin edilemez bir sifre
    | uretir. Ayrica env() testten override edilemiyor - .env'de bir deger
    | varsa putenv() onu gecemiyor, testler ortama bagimli hale geliyor.
    |
    */

    'yonetici_sifresi' => env('ADMIN_PASSWORD'),

];
