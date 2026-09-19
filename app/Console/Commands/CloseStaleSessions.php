<?php

namespace App\Console\Commands;

use App\Services\SessionCloser;
use Illuminate\Console\Command;

/**
 * Unutulan oturumlari kapatir.
 *
 * Bu komut sistemin DOGRULUGU icin gerekli DEGIL - SessionCloser deterministik
 * oldugu icin tembel kapatma ayni sonuca variyor. Komut yalnizca kimse
 * bakmadiginda da verinin taze olmasi icindir (raporlar, disari aktarim).
 */
class CloseStaleSessions extends Command
{
    protected $signature = 'oturum:kapat';

    protected $description = 'Kafe kapanisini ya da azami sureyi asan calisma oturumlarini kapatir';

    public function handle(SessionCloser $closer): int
    {
        $sayi = $closer->closeStale();

        $this->info($sayi === 0
            ? 'Kapatilacak oturum yok.'
            : "{$sayi} oturum kapatildi.");

        return self::SUCCESS;
    }
}
