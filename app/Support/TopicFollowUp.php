<?php

namespace App\Support;

use App\Models\ExamResult;
use App\Models\StudyPlanItem;
use App\Models\Subject;
use App\Models\WeakTopic;
use Illuminate\Support\Collection;

/**
 * Eksik konunun takibi: odev ise yaradi mi? (6 Ekim 2026)
 *
 * Bir onceki ayni tur denemede eksik cikan her konu bu denemede yeniden
 * olculur: basari once/simdi ve arada verilen odev (plan maddesi, konu
 * adiyla). Durum KURALLA (ExamTopics::isWeak), sifat yok (README SS6.1-6):
 *   - "fixed": bu denemede 2+ soru ve %50 ve uzeri -> artik eksik degil
 *   - "weak":  hala eksik
 *   - "few":   bu denemede 1 soru; karar icin yetmez
 *   - "absent": bu denemede o konudan soru yok
 *
 * Kapanis da ayni kural: acik eksik konu bu denemede "fixed" ise eksik
 * listesinden duser (closeFixed). Koc gerekirse yeniden acar.
 */
final class TopicFollowUp
{
    /**
     * @return list<array{subject:?string,topic:string,before:int,after:?int,questions:?int,status:string,homework:?string}>
     */
    public static function rows(ExamResult $sonuc, ?ExamResult $onceki): array
    {
        if ($onceki === null) {
            return [];
        }

        $simdi = self::byKey($sonuc->topics ?? []);
        $eksikler = collect($onceki->weakTopics());
        if ($eksikler->isEmpty()) {
            return [];
        }

        // Arada verilen odev: onceki sonuc yayinlandiktan sonra, bu sonuctan
        // once eklenen, konu adini tasiyan plan maddeleri.
        $maddeler = StudyPlanItem::where('student_id', $sonuc->student_id)
            ->whereIn('title', $eksikler->pluck('topic')->all())
            ->where('created_at', '>=', $onceki->created_at)
            ->where('created_at', '<=', $sonuc->created_at)
            ->get(['title', 'status'])
            ->groupBy('title');

        return $eksikler->map(function (array $k) use ($simdi, $maddeler) {
            $yeni = $simdi[self::key($k)] ?? null;
            $odev = $maddeler->get($k['topic']);

            return [
                'subject' => $k['subject'] ?? null,
                'topic' => $k['topic'],
                'before' => ExamTopics::success($k),
                'after' => $yeni ? ExamTopics::success($yeni) : null,
                'questions' => $yeni ? (int) $yeni['questions'] : null,
                'status' => self::status($yeni),
                'homework' => $odev === null ? null : ($odev->contains('status', 'done') ? 'done' : 'open'),
            ];
        })
            // Once duzelenler, sonra hala eksikler; olculemeyenler sonda.
            ->sortBy(fn (array $s) => array_search($s['status'], ['fixed', 'weak', 'few', 'absent'], true))
            ->values()->all();
    }

    /**
     * Bu denemede artik eksik olmayan acik konulari kapatir; kapananlarin
     * "Ders · Konu" adlari. Denemeden SONRA acilmis konuya dokunmaz: eski
     * bir denemenin sonucu gec yayinlanirsa yeni eksikleri kapatmasin.
     *
     * @return list<string>
     */
    public static function closeFixed(ExamResult $sonuc): array
    {
        $simdi = self::byKey($sonuc->topics ?? []);
        $denemeGunuSonu = LocalDay::bounds($sonuc->event->exam_date->toDateString())[1];
        $dersAdlari = Subject::pluck('name', 'id');

        $kapanan = [];
        foreach (WeakTopic::open()->where('student_id', $sonuc->student_id)->where('created_at', '<=', $denemeGunuSonu)->get() as $konu) {
            $ders = $konu->subject_id ? $dersAdlari[$konu->subject_id] ?? null : null;
            $yeni = $simdi[self::key(['subject' => $ders, 'topic' => $konu->topic])] ?? null;

            if (self::status($yeni) === 'fixed' && $konu->close()) {
                $kapanan[] = trim(($ders ?? '') . ' · ' . $konu->topic, ' ·');
            }
        }

        return $kapanan;
    }

    private static function status(?array $konu): string
    {
        return match (true) {
            $konu === null => 'absent',
            (int) $konu['questions'] < 2 => 'few',
            ExamTopics::isWeak($konu) => 'weak',
            default => 'fixed',
        };
    }

    /** @return Collection<string,array> */
    private static function byKey(array $konular): Collection
    {
        return collect($konular)->keyBy(fn (array $k) => self::key($k));
    }

    /** Ayni ad iki derste olabilir: anahtar ders + konu, harf buyuklugu yok sayilir. */
    private static function key(array $k): string
    {
        return mb_strtolower(trim((string) ($k['subject'] ?? '')) . '|' . trim((string) $k['topic']));
    }
}
