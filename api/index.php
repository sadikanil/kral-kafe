<?php

/**
 * Vercel giris noktasi.
 *
 * Vercel'de /var/task salt-okunur; yazilabilir tek yer /tmp. Laravel'in
 * calisma aninda yazmasi gereken iki yer var:
 *
 *  1. Derlenmis Blade sablonlari (VIEW_COMPILED_PATH).
 *  2. bootstrap/cache - paket ve servis bildirimleri. Runtime composer'i
 *     --no-scripts ile calistirdigi icin "artisan package:discover" hic
 *     kosmuyor ve bu dosyalar lambda'ya hic girmiyor; Laravel ilk istekte
 *     bunlari uretmeye calisip "directory must be present and writable"
 *     istisnasi atiyor.
 *
 * Ikisi de asagida olusturuluyor. Hangi dosyanin nereye yazilacagini
 * APP_*_CACHE ortam degiskenleri belirler (bkz. DEPLOY.md).
 */
$dizinler = [
    $_SERVER['VIEW_COMPILED_PATH'] ?? '/tmp/storage/framework/views',
    '/tmp/bootstrap/cache',
];

foreach ($dizinler as $dizin) {
    if (! is_dir($dizin) && ! mkdir($dizin, 0755, true) && ! is_dir($dizin)) {
        http_response_code(500);
        exit("Yazilabilir dizin olusturulamadi: {$dizin}");
    }
}

require __DIR__ . '/../public/index.php';
