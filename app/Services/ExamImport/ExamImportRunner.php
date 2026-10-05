<?php

namespace App\Services\ExamImport;

use App\Models\ExamImport;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;

/**
 * Deneme PDF'ini arka planda okuyan zincir (5 Ekim 2026).
 *
 * Vercel'de kuyruk sunucusu yok, Hobby planda cron gunde bir. Bu yuzden
 * okuma kendi kendini cagiran bir zincir: her halka (link) kuyrugun
 * basini bir adim ilerletir (Vercel 60 sn sinirinda tek karne) ve is
 * kaldiysa bir sonraki halkayi tetikler (kick). Tetikleme istegi cevabi
 * beklemez; Vercel istemci ayrilsa da fonksiyonu sonuna kadar calistirir
 * (iptal yalnizca "supportsCancellation" ile acilir). Sayfa kapali olsa da
 * okuma surer.
 *
 * Zincir koparsa (fonksiyon olur, tetikleme kaybolur) nudge() onu yeniden
 * baslatir: okuma sayfasi her yoklamada, yonetici paneli, Deneme Sonuclari
 * listesi ve gunluk/gece cron'u cagirir. Son adimdan bu yana NABIZ_SN
 * gecmediyse zincir canli sayilir, tetiklenmez. Fazladan tetikleme
 * zararsiz: kilit (ExamImportProcessor::stepQueue) ikinciyi okutmaz.
 *
 * Gizli anahtar (CRON_SECRET) yoksa zincir kapali; okuma eskisi gibi
 * acik sayfadan ilerler (yerel gelistirme).
 */
final class ExamImportRunner
{
    /** Bu kadar saniye nabiz yoksa zincir kopmus sayilir (adim <= 60 sn). */
    public const NABIZ_SN = 90;

    /** Tetikleyen istek cevabi beklemez; bu kadar saniye sonra birakir. */
    private const TETIK_SN = 2;

    public function __construct(private readonly ExamImportProcessor $isleyici)
    {
    }

    /**
     * Tetikleme reddedildi (ornegin Vercel koruma sayfasi 401): zincir
     * calismiyor. Bu isaret varken okuma eskisi gibi acik sayfadan ilerler;
     * uc bir kez gercekten calisinca isaret silinir.
     */
    public const KAPALI = 'exam-import-chain-down';

    public static function enabled(): bool
    {
        return (string) config('kafe.cron_anahtari') !== '' && ! Cache::has(self::KAPALI);
    }

    /** Uc gercekten calisti: zincir saglam. */
    public static function reachable(): void
    {
        Cache::forget(self::KAPALI);
    }

    /**
     * Zincirin bir halkasi: bir adim, is kaldiysa sonraki halka. Kilit
     * baskasindaysa hicbir sey yapmaz - zinciri kilit sahibi surduruyor.
     *
     * @return array{ran:bool,busy:bool,remaining:bool,pause:int}
     */
    public function link(): array
    {
        $basla = microtime(true);
        $sonuc = $this->isleyici->stepQueue();

        if ($sonuc['ran'] && $sonuc['remaining']) {
            // Kota/5xx: hemen denemek ayni hatayi alir. Bekleme Vercel'in 60 sn
            // sinirina sigar (adim + bekleme + tetikleme <= ~55 sn).
            $kalan = (int) floor(52 - (microtime(true) - $basla));
            if ($sonuc['pause'] > 0 && $kalan > 0) {
                Sleep::for(min($sonuc['pause'], $kalan))->seconds();
            }

            self::kick();
        }

        return $sonuc;
    }

    /** Sonraki halkayi baslatir; cevabi beklemez. */
    public static function kick(): void
    {
        if (! self::enabled()) {
            return;
        }

        // Tetikleme de nabiz sayilir: yoklamalar ayni anda yeni zincir acmasin.
        Cache::put(ExamImportProcessor::HEARTBEAT, now()->getTimestamp(), 600);

        try {
            $yanit = Http::withToken((string) config('kafe.cron_anahtari'))
                ->timeout(self::TETIK_SN)->connectTimeout(self::TETIK_SN)
                ->get(route('cron.exam-imports'));

            // Cevap 2 sn icinde geldiyse uc ya hizli bitti (kilit baskasinda,
            // is yok) ya da hic calismadi (koruma sayfasi, yanlis anahtar).
            if (! $yanit->successful()) {
                Log::warning('Deneme okuma zinciri reddedildi; acik sayfadan okunacak', ['status' => $yanit->status()]);
                Cache::put(self::KAPALI, $yanit->status(), 3600);
                Cache::forget(ExamImportProcessor::HEARTBEAT);
            }
        } catch (ConnectionException) {
            // Beklenen: cevap beklenmiyor, istek gitti ve halka calisiyor.
        } catch (\Throwable $e) {
            Log::warning('Deneme okuma zinciri tetiklenemedi', ['error' => $e->getMessage()]);
        }
    }

    /** Okunacak is var ve zincir duruyorsa yeniden baslatir. Ucuz: iki sorgu. */
    public static function nudge(): void
    {
        if (! self::enabled() || ! ExamImport::queued()->exists()) {
            return;
        }

        $son = Cache::get(ExamImportProcessor::HEARTBEAT);

        if ($son !== null && now()->getTimestamp() - (int) $son < self::NABIZ_SN) {
            return;
        }

        self::kick();
    }
}
