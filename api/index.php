<?php

/**
 * Vercel giris noktasi.
 *
 * Vercel'in dosya sistemi salt-okunur; yazilabilir tek yer /tmp ve orasi da
 * cagrilar arasinda silinebiliyor. Laravel'in calisma aninda yazmasi gereken
 * tek dizin derlenmis Blade sablonlari; onu /tmp altina aliyoruz.
 *
 * Oturum, onbellek ve kuyruk veritabanina (Supabase) yazar; yuklenen dosyalar
 * nesne depolamaya (UPLOAD_DISK=s3) gider. Log'lar stderr'e akar.
 */
$compiledViews = $_SERVER['VIEW_COMPILED_PATH'] ?? '/tmp/storage/framework/views';

if (! is_dir($compiledViews)) {
    mkdir($compiledViews, 0755, true);
}

require __DIR__ . '/../public/index.php';
