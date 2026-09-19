<?php

/**
 * Vercel giris noktasi.
 *
 * Vercel'de /var/task salt-okunur; yazilabilir tek yer /tmp. Laravel'in
 * calisma aninda yazmasi gereken iki yer var:
 *
 *  1. Derlenmis Blade sablonlari.
 *  2. bootstrap/cache - paket ve servis bildirimleri. Runtime composer'i
 *     --no-scripts ile calistirabiliyor, o durumda bu dosyalar lambda'ya hic
 *     girmiyor ve Laravel ilk istekte kendisi uretmek zorunda kaliyor.
 *
 * Dizinleri olusturmak TEK BASINA YETMIYOR: Laravel nereye yazacagini
 * ortam degiskenlerinden okuyor. Degiskenler verilmezse cerceve varsayilani
 * olan realpath(storage_path('framework/views')) devreye giriyor ve o dizin
 * .vercelignore ile disarida kaldigi icin realpath() FALSE donuyor. Sonuc:
 *
 *     Please provide a valid cache path. (500)
 *
 * Bu yuzden dizinleri acan yer, degiskenleri de kendisi tanimliyor. Panelde
 * tanimlanmis bir deger varsa ona dokunulmuyor - asagisi yalnizca varsayilan.
 *
 * LOG_CHANNEL de burada: cerceve varsayilani storage/logs'a yazmaya calisir,
 * orasi salt-okunur ve uygulama loglamaya calisirken ikinci bir 500 uretir.
 */
$varsayilanlar = [
    'VIEW_COMPILED_PATH' => '/tmp/storage/framework/views',
    'APP_PACKAGES_CACHE' => '/tmp/bootstrap/cache/packages.php',
    'APP_SERVICES_CACHE' => '/tmp/bootstrap/cache/services.php',
    'APP_CONFIG_CACHE' => '/tmp/bootstrap/cache/config.php',
    'APP_EVENTS_CACHE' => '/tmp/bootstrap/cache/events.php',
    'APP_ROUTES_CACHE' => '/tmp/bootstrap/cache/routes-v7.php',
    'LOG_CHANNEL' => 'stderr',
];

foreach ($varsayilanlar as $anahtar => $deger) {
    $mevcut = $_SERVER[$anahtar] ?? $_ENV[$anahtar] ?? getenv($anahtar);

    if ($mevcut === false || $mevcut === '') {
        $_SERVER[$anahtar] = $deger;
        $_ENV[$anahtar] = $deger;
        putenv("{$anahtar}={$deger}");
    }
}

// Yalnizca dosya yolu olan degiskenlerin dizinleri acilir.
$dizinler = [$_SERVER['VIEW_COMPILED_PATH']];

foreach (['APP_PACKAGES_CACHE', 'APP_SERVICES_CACHE', 'APP_CONFIG_CACHE', 'APP_EVENTS_CACHE', 'APP_ROUTES_CACHE'] as $anahtar) {
    $dizinler[] = dirname($_SERVER[$anahtar]);
}

foreach (array_unique($dizinler) as $dizin) {
    if (! is_dir($dizin) && ! mkdir($dizin, 0755, true) && ! is_dir($dizin)) {
        http_response_code(500);
        exit("Yazilabilir dizin olusturulamadi: {$dizin}");
    }
}

// GECICI TESHIS - SIR ICERMEZ, yalnizca "var mi / hangi surucu".
// Uygulama 500 verse bile bu baslik gider, cunku Laravel bootlanmadan once yazilir.
// Sebep bulununca kaldirilacak.
$oku = static fn (string $k): string => (string) ($_SERVER[$k] ?? $_ENV[$k] ?? getenv($k) ?: '-');
$anahtar = $oku('APP_KEY');
header('X-Kk-Cfg: key=' . ($anahtar === '-' ? 'yok' : (str_starts_with($anahtar, 'base64:') ? 'base64' : 'hamdeger'))
    . ' len=' . strlen($anahtar)
    . ' db=' . $oku('DB_CONNECTION')
    . ' port=' . $oku('DB_PORT')
    . ' env=' . $oku('APP_ENV')
    . ' sess=' . $oku('SESSION_DRIVER')
    . ' cache=' . $oku('CACHE_STORE'));

require __DIR__ . '/../public/index.php';
