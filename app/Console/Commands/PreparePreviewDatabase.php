<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\OnizlemeSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Vercel onizleme derlemesinde onizleme veritabanini hazirlar (9 Kasim 2026).
 *
 * composer.json -> scripts.vercel son adim. Onizlemeler eskiden veritabani
 * ayari olmadigi icin her sayfada 500 veriyordu; canliya baglamak ise
 * denemeleri gercek uye kayitlarina yazardi. Ayri proje: kral-kafe-onizleme.
 *
 * Kilitler (hepsi gecmeden hicbir sey yazilmaz):
 *  1. VERCEL_ENV === 'preview' (canli derlemede hic calismaz),
 *  2. KAFE_ONIZLEME_DB=1 (yalnizca Vercel Preview ortaminda tanimli),
 *  3. baglanti canli projenin referansini TASIMIYOR - tasiyorsa derleme durur.
 *
 * Baglanamazsa (ornegin sifre henuz girilmemis) uyarir ve gecer: onizleme
 * yine yayinlanir, yalnizca veritabanisiz kalir. Migration hatasi ise
 * derlemeyi durdurur - gercek bir sorun.
 */
class PreparePreviewDatabase extends Command
{
    protected $signature = 'kafe:onizleme-hazirla';

    protected $description = 'Vercel onizlemesi: onizleme veritabanina migration ve (bossa) demo verisi';

    public function handle(): int
    {
        $ayar = config('kafe.onizleme');

        if ($ayar['vercel_ortami'] !== 'preview') {
            $this->line('Önizleme derlemesi değil (' . ($ayar['vercel_ortami'] ?: 'yerel') . '); veritabanına dokunulmadı.');

            return self::SUCCESS;
        }

        if (! $ayar['acik']) {
            $this->line('KAFE_ONIZLEME_DB tanımlı değil; önizleme veritabanı hazırlanmadı.');

            return self::SUCCESS;
        }

        $ad = config('database.default');
        $baglanti = (array) config("database.connections.{$ad}");
        $kimlik = strtolower(implode(' ', [$baglanti['url'] ?? '', $baglanti['host'] ?? '', $baglanti['username'] ?? '', $baglanti['database'] ?? '']));

        if (str_contains($kimlik, strtolower($ayar['canli_ref']))) {
            $this->error('Önizleme CANLI veritabanına bağlı görünüyor; migration çalıştırılmadı. Vercel Preview ortamındaki DB_* değişkenlerini kontrol et.');

            return self::FAILURE;
        }

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            // PDO hata metni sifre icermez; adres ve kullanici adi teshis icin yeterli.
            $this->warn('Önizleme veritabanına bağlanılamadı; atlandı: ' . $e->getMessage());

            return self::SUCCESS;
        }

        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->error('Önizleme veritabanında migration başarısız.');

            return self::FAILURE;
        }

        if (User::query()->exists()) {
            $this->line('Önizleme veritabanında kullanıcı var; demo verisi yeniden yazılmadı.');

            return self::SUCCESS;
        }

        $this->call('db:seed', ['--class' => OnizlemeSeeder::class, '--force' => true]);
        $this->info('Önizleme veritabanı hazır: demo verisi yazıldı.');

        return self::SUCCESS;
    }
}
