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
    | Dusus sinyali esikleri (Dalga 16)
    |--------------------------------------------------------------------------
    |
    | Kural tabanli, yorumsuz sinyaller. YALNIZCA koc ve yonetici gorur
    | (SS6.1-5); veliye giden sey kocun yorumudur.
    |
    | dusus_gelis_farki : son 7 gunun gelis sayisi, onceki 7 gunden bu kadar
    |                     AZSA sinyal. 1 gun bilerek yetmiyor - kucuk
    |                     dalgalanma gurultuye doner ve koc listeye bakmayi
    |                     birakir.
    | dusus_hedef_orani : tamamlanmis son haftada hedefin bu yuzdesinin
    |                     ALTINDA kalindiysa sinyal. Suren haftaya bakilsaydi
    |                     her ogrenci pazartesi sabahi isaretlenirdi.
    | dusus_devamsiz_gun: son gelisin uzerinden bu kadar gun gectiyse sinyal.
    |
    */

    'dusus_gelis_farki' => (int) env('KAFE_DUSUS_GELIS_FARKI', 2),
    'dusus_hedef_orani' => (int) env('KAFE_DUSUS_HEDEF_ORANI', 50),
    'dusus_devamsiz_gun' => (int) env('KAFE_DUSUS_DEVAMSIZ_GUN', 3),

    /*
    |--------------------------------------------------------------------------
    | Cron anahtari (Dalga 11)
    |--------------------------------------------------------------------------
    |
    | Vercel Cron'un gunluk ucu cagirirken tasidigi gizli anahtar. TANIMSIZSA
    | uc hic calismaz: "anahtar yoksa herkese acik" varsayilani, degiskeni
    | girmeyi unutan bir dagitimda ucu internete acardi.
    |
    */

    // Stok sayimi hatirlatmasi (Dalga 27): son sayimdan bu kadar gun sonra
    // yoneticiye zil bildirimi; sayim yapilmazsa her bu kadar gunde bir tekrar.
    'stok_sayim_gun' => (int) env('KAFE_STOK_SAYIM_GUN', 7),

    // Degisken adi CRON_SECRET olmak ZORUNDA: Vercel Cron yalnizca bu
    // degiskeni okuyup "Authorization: Bearer" basligina koyar.
    'cron_anahtari' => (string) env('CRON_SECRET', env('KAFE_CRON_ANAHTARI', '')),

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
