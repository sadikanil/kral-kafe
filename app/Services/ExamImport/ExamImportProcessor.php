<?php

namespace App\Services\ExamImport;

use App\Contracts\ExamPdfReader;
use App\Enums\ExamType;
use App\Enums\Role;
use App\Models\ExamImport;
use App\Models\ExamImportRow;
use App\Models\ExamResult;
use App\Models\Subject;
use App\Models\User;
use App\Models\WeakTopic;
use App\Services\NotificationBuilder;
use App\Support\ExamTopics;
use App\Support\StudentNameMatcher;
use App\Support\TopicFollowUp;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Kurum geneli deneme PDF'i: oku, esle, yayinla (1 Ekim 2026).
 *
 * step() her cagrida TEK is yapar (dizin ya da bir karne) ki istek
 * Vercel'in 60 sn sinirinda kalsin; sayfa bitene kadar tekrar cagirir.
 * Hata kaldigi yerde durur; "Devam et" ayni adimi yeniden dener, okunmus
 * karneler yeniden okunmaz (ucret iki kez odenmez).
 *
 * Kuyruk (5 Ekim 2026): ayni anda yalnizca BIR okuma. Okunacak aktarimlar
 * yukleme tarihine gore kuyruk (ExamImport::scopeQueued); stepQueue()
 * kuyrugun BASINI bir adim ilerletir. Adimlari arka planda zincir surer
 * (ExamImportRunner): sayfa kapali olsa da okuma devam eder. Iki istek
 * ayni anda gelirse kilit (cache_locks, veritabani) ikinciyi okutmaz.
 * Kalici hata alan aktarim kuyruktan cikar, sirayi tikamaz; "Devam et"
 * geri sokar.
 *
 * Yayin (publish) yalnizca yonetici kontrolunden sonra: satir ogrenciye
 * eslenmis ve "kontrol et" beklemiyorsa sonuc, konu tablosu, eksik konular
 * ve bildirimler yazilir. Eksik konu KURALLA (ExamTopics::isWeak) - yorum
 * ureten tek kisi insandir (README SS6.1-6); koc onlari plana ekler.
 */
class ExamImportProcessor
{
    public function __construct(
        private readonly ExamPdfReader $okuyucu,
        private readonly NotificationBuilder $bildirimler,
    ) {
    }

    /** Okuma kilidinin suresi: tek adim Vercel'de en fazla 60 sn. */
    private const KILIT_SN = 75;

    /** Son adimin zamani (zincir canli mi?): ExamImportRunner::nudge okur. */
    public const HEARTBEAT = 'exam-import-heartbeat';

    /** Gecici hatada (zaman asimi, kota, 5xx) ayni adim en fazla bu kadar kez tekrarlanir. */
    public const TEKRAR = 3;

    /**
     * Tarayicidan tek adim (arka plan zinciri kapaliyken: CRON_SECRET yok,
     * yerel gelistirme). Hatali aktarim kuyruga geri girer, sonra kuyrugun
     * basi bir adim ilerler.
     *
     * @return array<string,mixed> durum() + waiting
     */
    public function step(ExamImport $aktarim): array
    {
        $this->resume($aktarim);
        $adim = $aktarim->fresh()->isProcessing() ? $this->stepQueue() : ['busy' => false];

        return ['waiting' => $adim['busy']] + $this->durum($aktarim->fresh());
    }

    /** "Devam et": hatali aktarim kuyruga geri girer (yukleme sirasindaki yerine). */
    public function resume(ExamImport $aktarim): bool
    {
        if ($aktarim->status !== ExamImport::FAILED) {
            return false;
        }

        $aktarim->update([
            'status' => $aktarim->rows()->exists() ? ExamImport::READING : ExamImport::UPLOADED,
            'error' => null,
            'meta' => array_merge($aktarim->meta ?? [], ['retries' => 0]),
        ]);

        return true;
    }

    /**
     * Kuyrugun basini bir adim ilerletir (5 Ekim 2026). Ayni anda yalnizca
     * BIR okuma: kilit (cache_locks, veritabani) baska bir istek okurken
     * ikinciyi okutmaz - ayni karne iki kez okunmaz, kota iki kat yanmaz.
     *
     * @return array{ran:bool,busy:bool,remaining:bool,pause:int}
     */
    public function stepQueue(): array
    {
        $kilit = Cache::lock('exam-import-reader', self::KILIT_SN);

        if (! $kilit->get()) {
            return ['ran' => false, 'busy' => true, 'remaining' => true, 'pause' => 0];
        }

        try {
            $bas = ExamImport::queued()->first();

            if ($bas === null) {
                Cache::forget(self::HEARTBEAT);

                return ['ran' => false, 'busy' => false, 'remaining' => false, 'pause' => 0];
            }

            Cache::put(self::HEARTBEAT, now()->getTimestamp(), 600);
            $bekle = $this->adim($bas);
            $kalan = ExamImport::queued()->exists();

            // Is bittiyse nabiz silinir: sonradan yuklenen deneme hemen baslar.
            $kalan ? Cache::put(self::HEARTBEAT, now()->getTimestamp(), 600) : Cache::forget(self::HEARTBEAT);

            return ['ran' => true, 'busy' => false, 'remaining' => $kalan, 'pause' => $bekle];
        } finally {
            $kilit->release();
        }
    }

    /**
     * Tek adim: dizin ya da siradaki karne. Gecici hata (zaman asimi, kota,
     * 5xx, baglanti) aktarimi durdurmaz, adim sonra tekrarlanir (sayac
     * meta'da); TEKRAR kez asarsa ya da kalici hatada aktarim durur ve
     * kuyruktan cikar - sirayi tikamaz.
     *
     * @return int tekrar oncesi beklenecek saniye
     */
    private function adim(ExamImport $aktarim): int
    {
        $once = $aktarim->status;
        $bekle = $this->adimiCalistir($aktarim);

        // Okuma bitti ya da durdu: yoneticiye haber (sayfa kapali olabilir).
        // Bildirim yazilamazsa okuma bozulmasin.
        if ($aktarim->status !== $once) {
            try {
                $this->bildirimler->examImport($aktarim);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $bekle;
    }

    private function adimiCalistir(ExamImport $aktarim): int
    {
        try {
            match ($aktarim->status) {
                ExamImport::UPLOADED => $this->dizin($aktarim),
                ExamImport::READING => $this->siradakiKarne($aktarim),
                default => null,
            };

            // Basarili adim: onceki gecici hatanin izi silinir.
            if ($aktarim->error !== null || ($aktarim->meta['retries'] ?? 0) > 0) {
                $aktarim->update(['meta' => array_merge($aktarim->meta ?? [], ['retries' => 0]), 'error' => null]);
            }

            return 0;
        } catch (ExamPdfReadException $e) {
            $tekrar = (int) ($aktarim->meta['retries'] ?? 0);
            $gecici = $e->transient || ExamPdfReadException::isRetryable($e->getMessage());
            $tekrarlanir = $gecici && $tekrar < self::TEKRAR;

            $aktarim->update([
                'status' => $tekrarlanir ? $aktarim->status : ExamImport::FAILED,
                'error' => $tekrarlanir
                    ? $e->getMessage() . ' Yeniden deneniyor (' . ($tekrar + 1) . '/' . self::TEKRAR . ').'
                    : $e->getMessage() . ($gecici ? ' ' . self::TEKRAR . ' denemede de olmadı; "Devam et" ile yeniden başlatın.' : ''),
                'meta' => array_merge($aktarim->meta ?? [], ['retries' => $tekrarlanir ? $tekrar + 1 : 0]),
            ]);

            return $tekrarlanir ? $e->pause : 0;
        }
    }

    /** @return array{status:string,done:int,total:int,message:?string,queue:int,ahead:?array} */
    public function durum(ExamImport $aktarim): array
    {
        $karneli = $aktarim->rows()->whereNotNull('card_page');

        return [
            'status' => $aktarim->status,
            'done' => (clone $karneli)->where('card_read', true)->count(),
            'total' => $karneli->count(),
            'message' => $aktarim->error,
            // Kuyruk: onunde kac aktarim var ve su an hangisi okunuyor.
            'queue' => $sira = $aktarim->queuePosition(),
            'ahead' => $sira > 0 ? $this->ozet(ExamImport::queued()->with('event')->first()) : null,
        ];
    }

    /** @return array{title:string,done:int,total:int} */
    private function ozet(ExamImport $aktarim): array
    {
        $karneli = $aktarim->rows()->whereNotNull('card_page');

        return [
            'title' => $aktarim->event->title,
            'done' => (clone $karneli)->where('card_read', true)->count(),
            'total' => $karneli->count(),
        ];
    }

    /** Ogrenciye bagli, kontrol beklemeyen satirlari yayinlar; yayinlanan sayisi. */
    public function publish(ExamImport $aktarim, User $yonetici): int
    {
        $olay = $aktarim->event;
        $sayi = 0;
        $bildirilecek = [];

        DB::transaction(function () use ($aktarim, $yonetici, $olay, &$sayi, &$bildirilecek) {
            foreach ($aktarim->rows()->with('student')->get() as $satir) {
                if (! in_array($satir->match, [ExamImportRow::AUTO, ExamImportRow::MANUAL], true)
                    || $satir->student === null || ! $satir->student->isStudent()) {
                    continue;
                }

                [$sonuc, $eksikler, $duzelen] = $this->sonucuYaz($aktarim, $satir, $satir->student, $yonetici, $olay->exam_type);
                $bildirilecek[] = [$sonuc, $eksikler, $duzelen];
                $sayi++;
            }

            $aktarim->update(['status' => ExamImport::PUBLISHED, 'published_at' => now()]);
        });

        // Bildirim yazimi islemden sonra: biri basarisiz olursa sonuclar geri alinmasin.
        foreach ($bildirilecek as [$sonuc, $eksikler, $duzelen]) {
            $this->bildirimler->examResult($sonuc, $eksikler, $duzelen);
        }

        return $sayi;
    }

    // --- Okuma -----------------------------------------------------------------

    private function dizin(ExamImport $aktarim): void
    {
        $dersler = $this->dersler($aktarim->event->exam_type);
        $veri = $this->okuyucu->readIndex($this->pdf($aktarim), $dersler);

        $ogrenciler = collect($veri['students'] ?? [])->filter(fn ($o) => is_array($o) && filled($o['name'] ?? null));
        if ($ogrenciler->isEmpty()) {
            throw new ExamPdfReadException('Belgede öğrenci listesi bulunamadı.');
        }

        DB::transaction(function () use ($aktarim, $veri, $ogrenciler, $dersler) {
            $aktarim->rows()->delete();

            foreach ($ogrenciler as $o) {
                $sayfa = $this->tamSayi($o['card_page'] ?? null);

                $aktarim->rows()->create([
                    'name' => Str::limit(trim((string) $o['name']), 120, ''),
                    'class_label' => $this->metin($o['class'] ?? null, 40),
                    'card_page' => $sayfa !== null && $sayfa >= 1 ? $sayfa : null,
                    'data' => [
                        'score' => $this->sayi($o['score'] ?? null),
                        'subjects' => $this->dersSatirlari($o['subjects'] ?? [], $dersler),
                    ],
                ]);
            }

            $katilim = is_array($veri['participants'] ?? null) ? $veri['participants'] : [];
            $aktarim->update([
                'provider' => $this->okuyucu->provider(),
                'meta' => [
                    'exam_name' => $this->metin($veri['exam']['name'] ?? null, 150),
                    'exam_date' => $this->metin($veri['exam']['date'] ?? null, 20),
                    'participants' => array_map(fn ($v) => $this->tamSayi($v), array_intersect_key(
                        $katilim, array_flip(['institution', 'district', 'city', 'country']))),
                ],
            ]);

            StudentNameMatcher::apply(
                $aktarim->rows()->get(),
                User::where('role', Role::Student->value)->get(['id', 'name', 'role']),
            );

            $aktarim->update([
                'status' => $aktarim->rows()->whereNotNull('card_page')->exists() ? ExamImport::READING : ExamImport::REVIEW,
            ]);
        });
    }

    private function siradakiKarne(ExamImport $aktarim): void
    {
        $satir = $aktarim->rows()->whereNotNull('card_page')->where('card_read', false)->first();

        if ($satir === null) {
            $aktarim->update(['status' => ExamImport::REVIEW]);

            return;
        }

        $dersler = $this->dersler($aktarim->event->exam_type);
        $karne = $this->okuyucu->readCard($this->pdf($aktarim), $satir->card_page, $satir->name, $dersler);

        $siralar = [];
        foreach (['branch', 'institution', 'district', 'city', 'country'] as $duzey) {
            $s = $karne['ranks'][$duzey] ?? null;
            $siralar[$duzey] = [
                'rank' => $this->tamSayi(is_array($s) ? ($s['rank'] ?? null) : null),
                'total' => $this->tamSayi(is_array($s) ? ($s['total'] ?? null) : null),
            ];
        }

        $karneDersleri = $this->dersSatirlari($karne['subjects'] ?? [], $dersler);

        $satir->update([
            'card_read' => true,
            'class_label' => $satir->class_label ?? $this->metin($karne['class'] ?? null, 40),
            'data' => array_merge($satir->data ?? [], [
                'card_name' => $this->metin($karne['name'] ?? null, 120),
                'score' => $this->sayi($karne['score'] ?? null) ?? ($satir->data['score'] ?? null),
                'ranks' => $siralar,
                'subjects' => $karneDersleri ?: ($satir->data['subjects'] ?? []),
                'topics' => $this->konuSatirlari($karne['topics'] ?? [], $dersler),
                // Okuma kontrolu: ayni ders liste sayfasinda ve karnede farkli
                // okunduysa yonetici PDF'e bakar (yapay zeka hatasi yakalanir).
                'mismatch' => $this->farklar($satir->data['subjects'] ?? [], $karneDersleri),
            ]),
        ]);

        if (! $aktarim->rows()->whereNotNull('card_page')->where('card_read', false)->exists()) {
            $aktarim->update(['status' => ExamImport::REVIEW]);
        }
    }

    // --- Yayin -----------------------------------------------------------------

    /** @return array{0:ExamResult,1:list<string>,2:list<string>} eksikler ve artik eksik olmayanlar */
    private function sonucuYaz(ExamImport $aktarim, ExamImportRow $satir, User $ogrenci, User $yonetici, ExamType $tur): array
    {
        $veri = $satir->data ?? [];
        $siralar = $veri['ranks'] ?? [];
        $katilim = $aktarim->meta['participants'] ?? [];
        $sira = fn (string $d) => $siralar[$d]['rank'] ?? null;
        $toplam = fn (string $d) => $siralar[$d]['total'] ?? ($katilim[$d] ?? null);

        $sonuc = ExamResult::updateOrCreate(
            ['exam_event_id' => $aktarim->exam_event_id, 'student_id' => $ogrenci->id],
            [
                'rank_institution' => $sira('institution'), 'total_institution' => $toplam('institution'),
                'rank_district' => $sira('district'), 'total_district' => $toplam('district'),
                'rank_city' => $sira('city'), 'total_city' => $toplam('city'),
                'rank_country' => $sira('country'), 'total_country' => $toplam('country'),
                'score' => $veri['score'] ?? null,
                'topics' => $veri['topics'] ?? [],
                'exam_import_id' => $aktarim->id,
                'entered_by' => $yonetici->id,
            ],
        );

        // Yalnizca bu denemenin (tur + ogrencinin alani) dersleri: elle
        // girisle (ExamResultController) ayni kapsam.
        $kapsam = Subject::forExam($tur, $ogrenci)->pluck('id', 'code');
        foreach ($veri['subjects'] ?? [] as $ders) {
            $id = $kapsam[$ders['code']] ?? null;
            if ($id === null) {
                continue;
            }
            $sonuc->subjects()->updateOrCreate(['subject_id' => $id], [
                'correct' => $ders['correct'], 'wrong' => $ders['wrong'], 'blank' => $ders['blank'] ?? 0,
            ]);
        }

        // Eksik konular: kuralla; ayni acik konu ikinci kez yazilmaz.
        $kodlar = Subject::whereNotNull('code')->pluck('id', 'code');
        $eksikler = [];
        foreach ($sonuc->weakTopics() as $konu) {
            $dersId = $kodlar[$konu['subject_code'] ?? ''] ?? null;
            $ad = Str::limit($konu['topic'], 150, '');

            WeakTopic::firstOrCreate(
                ['student_id' => $ogrenci->id, 'subject_id' => $dersId, 'topic' => $ad, 'status' => 'open'],
                ['source' => 'exam', 'created_by' => $yonetici->id],
            );
            $eksikler[] = trim(($konu['subject'] ?? '') . ' · ' . $ad, ' ·');
        }

        // Odev ise yaradi mi (6 Ekim 2026): bu denemede artik eksik olmayan
        // acik konular listeden duser.
        $sonuc->load(['event', 'subjects']);
        $duzelen = TopicFollowUp::closeFixed($sonuc);

        return [$sonuc, $eksikler, $duzelen];
    }

    // --- Yardimcilar ---------------------------------------------------------

    /** @return array<string,string> kod => ad, denemenin turune gore */
    public function dersler(ExamType $tur): array
    {
        $turler = match ($tur) {
            ExamType::Tyt => ['tyt'],
            ExamType::Ayt => ['ayt'],
            default => ['tyt', 'ayt'],
        };

        return Subject::active()->whereIn('exam_type', $turler)->whereNotNull('code')
            ->orderBy('sort_order')->pluck('name', 'code')->all();
    }

    private function pdf(ExamImport $aktarim): string
    {
        $icerik = Storage::disk(config('filesystems.uploads'))->get($aktarim->file_path);

        if (! is_string($icerik) || $icerik === '') {
            throw new ExamPdfReadException('PDF dosyası bulunamadı.');
        }

        return $icerik;
    }

    /** @param array<string,string> $dersler */
    private function dersSatirlari(mixed $satirlar, array $dersler): array
    {
        return collect(is_array($satirlar) ? $satirlar : [])
            ->filter(fn ($d) => is_array($d) && isset($dersler[$d['code'] ?? '']))
            ->map(function (array $d) {
                $dogru = max(0, (int) ($d['correct'] ?? 0));
                $yanlis = max(0, (int) ($d['wrong'] ?? 0));
                $soru = $this->tamSayi($d['questions'] ?? null);
                $bos = $this->tamSayi($d['blank'] ?? null) ?? ($soru !== null ? max(0, $soru - $dogru - $yanlis) : 0);

                return ['code' => $d['code'], 'label' => $this->metin($d['label'] ?? null, 60), 'correct' => $dogru, 'wrong' => $yanlis, 'blank' => max(0, $bos)];
            })
            ->unique('code')->values()->all();
    }

    /** @param array<string,string> $dersler */
    private function konuSatirlari(mixed $satirlar, array $dersler): array
    {
        return collect(is_array($satirlar) ? $satirlar : [])
            ->filter(fn ($k) => is_array($k) && filled($k['topic'] ?? null) && (int) ($k['questions'] ?? 0) > 0)
            ->map(function (array $k) use ($dersler) {
                $soru = (int) $k['questions'];
                $dogru = min($soru, max(0, (int) ($k['correct'] ?? 0)));
                $yanlis = min($soru - $dogru, max(0, (int) ($k['wrong'] ?? 0)));
                $kod = isset($dersler[$k['subject_code'] ?? '']) ? $k['subject_code'] : null;

                return [
                    'subject_code' => $kod,
                    'subject' => $kod ? $dersler[$kod] : null,
                    'topic' => Str::limit(trim((string) $k['topic']), 150, ''),
                    'questions' => $soru, 'correct' => $dogru, 'wrong' => $yanlis,
                    'blank' => $soru - $dogru - $yanlis,
                ];
            })
            ->values()->all();
    }

    /** Liste ve karnede dogru/yanlis sayisi farkli okunan derslerin kodlari. */
    private function farklar(array $liste, array $karne): array
    {
        $karne = collect($karne)->keyBy('code');

        return collect($liste)
            ->filter(fn (array $d) => $karne->has($d['code'])
                && [$karne[$d['code']]['correct'], $karne[$d['code']]['wrong']] !== [$d['correct'], $d['wrong']])
            ->pluck('code')->values()->all();
    }

    private function tamSayi(mixed $v): ?int
    {
        return is_numeric($v) ? max(0, (int) $v) : null;
    }

    private function sayi(mixed $v): ?float
    {
        return is_numeric($v) ? round((float) $v, 3) : null;
    }

    private function metin(mixed $v, int $uzunluk): ?string
    {
        return is_string($v) && trim($v) !== '' ? Str::limit(trim($v), $uzunluk, '') : null;
    }
}
