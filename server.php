<?php

/**
 * Laravel - A PHP Framework For Web Artisans
 * Custom server script to handle socket issues
 */

$uri = urldecode(
    parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? ''
);

// php2shell sertlestirmesi (7 Kasim 2026): dosya olarak duran bir PHP betigi
// (orn. public/storage altina dusmus bir .php) dogrudan calistirilmaz; asagidaki
// "return false" yerlesik sunucuya onu calistirtirdi. Yalnizca yerel sunucu.
if ($uri !== '/index.php' && preg_match('/\.(php\d?|phtml|phar|pht)$/i', $uri)) {
    http_response_code(403);

    return true;
}

// This file allows us to emulate Apache's "mod_rewrite" functionality from the
// built-in PHP web server. This provides a convenient way to test a Laravel
// application without having installed a "real" web server software here.
if ($uri !== '/' && file_exists(__DIR__ . '/public' . $uri)) {
    return false;
}

require_once __DIR__ . '/public/index.php';
