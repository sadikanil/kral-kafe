<?php

namespace App\Console\Commands;

use App\Support\PhpSurumu;
use Illuminate\Console\Command;

/**
 * PHP surumunu php2shell yamasina gore denetler (bkz. PhpSurumu).
 *
 * --kesin: yamasizsa hata koduyla cikar. Vercel derlemesinde composer'in
 * "vercel" betigi bunu cagirir; runtime betik hatasinda derlemeyi durdurur.
 */
class CheckPhpVersion extends Command
{
    protected $signature = 'kafe:php-surumu
        {surum? : Denetlenecek surum (varsayilan: calisan PHP)}
        {--kesin : Yamasizsa hata koduyla cik}';

    protected $description = 'PHP surumunun php2shell (CVE-2026-109618/109619) yamasini tasiyip tasimadigini denetler';

    public function handle(): int
    {
        $surum = $this->argument('surum') ?? PhpSurumu::simdiki();

        if (PhpSurumu::yamaliMi($surum)) {
            $this->info("PHP {$surum}: php2shell yamasi var.");

            return self::SUCCESS;
        }

        $this->error("PHP {$surum}: php2shell (CVE-2026-109618 / CVE-2026-109619) yamasi YOK.");
        $this->line('Yamali surumler: ' . implode(' · ', PhpSurumu::YAMALI) . ' ve sonrasi.');

        return $this->option('kesin') ? self::FAILURE : self::SUCCESS;
    }
}
