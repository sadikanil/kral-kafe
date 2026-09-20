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

use App\Support\Teshis;

require __DIR__ . '/../vendor/autoload.php';

// Panelden yapistirilan degerlerin sonuna Enter ile gelen satir sonu, deger
// hangi anahtardaysa orayi sessizce bozar: DB_PASSWORD'de dogrulandi, APP_KEY
// ("Unsupported cipher or incorrect key length") ve DB_HOST ("could not
// translate host name") icin de ayni sey gecerli. Laravel bootlanmadan once
// hepsi kirpilir. Mesru hicbir deger satir sonuyla bitmez.
$kirpilanlar = Teshis::satirSonlariniKirp();

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

/*
|--------------------------------------------------------------------------
| GECICI TESHIS  —  /?teshis=<jeton>
|--------------------------------------------------------------------------
|
| APP_DEBUG kapaliyken canli 500'un sebebi hicbir yerden okunamiyor. Bu blok
| dogru jetonla gelen istege duz metin bir rapor doner: ortam ozeti (sirlar
| yalnizca uzunluk olarak), ham PDO baglanti sonucu ve Laravel'in AYNI istegi
| islerken urettigi durum kodu ya da istisna. Jeton yanlissa hic bir sey
| belli etmeden normal akisa duser.
|
| Sorun cozulunce bu blok ve App\Support\Teshis kaldirilacak.
*/
if (isset($_GET['teshis']) && Teshis::yetkili((string) $_GET['teshis'])) {
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');

    register_shutdown_function(function (): void {
        $son = error_get_last();

        if ($son !== null && in_array($son['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
            echo "\n--- OLUMCUL HATA ---\n", Teshis::temizle($son['message']), ' @ ', $son['file'], ':', $son['line'], "\n";
        }
    });

    $oku = static fn (string $anahtar, string $varsayilan = ''): string => (string) ($_SERVER[$anahtar] ?? $_ENV[$anahtar] ?? (getenv($anahtar) ?: $varsayilan));

    $cikti = [
        'KRAL KAFE TESHIS ' . gmdate('Y-m-d H:i:s') . ' UTC',
        'PHP ' . PHP_VERSION . ' (' . PHP_SAPI . ') pdo_pgsql=' . (extension_loaded('pdo_pgsql') ? 'var' : 'YOK'),
        'satir sonu kirpilan degiskenler: ' . ($kirpilanlar === [] ? 'yok' : implode(', ', $kirpilanlar)),
        '',
        '--- ortam ---',
        ...Teshis::ortamOzeti(),
        '',
        '--- ham PDO ---',
    ];

    try {
        $dsn = sprintf(
            'pgsql:host=%s;port=%s;dbname=%s;sslmode=%s',
            $oku('DB_HOST'),
            $oku('DB_PORT', '5432'),
            $oku('DB_DATABASE', 'postgres'),
            $oku('DB_SSLMODE', 'prefer'),
        );
        $pdo = new PDO($dsn, $oku('DB_USERNAME'), $oku('DB_PASSWORD'), [PDO::ATTR_TIMEOUT => 8]);
        $cikti[] = 'BAGLANDI: PostgreSQL ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
    } catch (Throwable $hata) {
        $cikti[] = 'HATA: ' . Teshis::temizle($hata->getMessage());
    }

    $cikti[] = '';
    $cikti[] = '--- laravel ---';

    echo implode("\n", $cikti), "\n";
    flush();

    try {
        $app = require __DIR__ . '/../bootstrap/app.php';
        $kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
        $yanit = $kernel->handle(Illuminate\Http\Request::capture());

        echo 'durum: ', $yanit->getStatusCode();

        if ($yanit->isRedirection()) {
            echo ' -> ', Teshis::temizle((string) $yanit->headers->get('Location'));
        }

        echo "\n";

        if (isset($yanit->exception) && $yanit->exception instanceof Throwable) {
            echo Teshis::hataOzeti($yanit->exception), "\n";
        }
    } catch (Throwable $hata) {
        echo Teshis::hataOzeti($hata), "\n";
    }

    exit;
}

require __DIR__ . '/../public/index.php';
