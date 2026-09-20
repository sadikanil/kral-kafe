<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Deneme sonuc PDF'ini yapay zekaya okutur; basarili ve basarisiz alanlari
 * yapilandirilmis olarak doner.
 *
 * PDF dosya olarak gonderilir (Chat Completions "file" icerik parcasi),
 * metin cikarimi yapilmaz: kurum raporlari tablo agirlikli ve bazen
 * taranmis; metin cikarimi tabloyu bozar, dosya girisi ikisini de okur.
 *
 * Urun felsefesi: sistem sifat uretmez. Prompt yalnizca sayilari, konu
 * bazli guclu/zayif alanlari ve calisma odagini ister; motivasyon, kisilik
 * ya da "tembel/calisiyor" turu yorum ACIKCA yasak.
 */
class ExamReportAnalyzer
{
    private ?string $apiKey;
    private string $model = 'gpt-4o';

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
    }

    /**
     * @return array{success:bool,data?:array<string,mixed>,error?:string}
     */
    public function analyze(string $pdfData, string $fileName = 'deneme.pdf'): array
    {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'Yapay zeka analizi kullanılamıyor: OPENAI_API_KEY tanımlı değil.'];
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(90)->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPrompt()],
                    [
                        'role' => 'user',
                        'content' => [
                            ['type' => 'text', 'text' => $this->userPrompt()],
                            [
                                'type' => 'file',
                                'file' => [
                                    'filename' => $fileName,
                                    'file_data' => 'data:application/pdf;base64,' . base64_encode($pdfData),
                                ],
                            ],
                        ],
                    ],
                ],
                'max_tokens' => 3000,
                'response_format' => ['type' => 'json_object'],
            ]);

            if (! $response->successful()) {
                Log::error('Deneme raporu analizi: API hatasi', ['status' => $response->status(), 'body' => $response->body()]);

                return ['success' => false, 'error' => 'Yapay zeka servisi hata döndü (' . $response->status() . ').'];
            }

            $sonuc = json_decode((string) $response->json('choices.0.message.content'), true);

            if (! is_array($sonuc)) {
                return ['success' => false, 'error' => 'Yapay zeka geçerli bir sonuç döndürmedi.'];
            }

            return ['success' => true, 'data' => $this->normalize($sonuc)];
        } catch (\Throwable $e) {
            Log::error('Deneme raporu analizi basarisiz', ['error' => $e->getMessage()]);

            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    private function systemPrompt(): string
    {
        return 'Sen bir sınav sonuç raporu okuyucususun. Görevin, verilen deneme sınavı sonuç PDF\'inden '
            . 'SAYISAL verileri ve KONU bazlı güçlü/zayıf alanları çıkarmak. Yalnızca PDF\'te olan bilgiyi kullan; '
            . 'olmayanı uydurma, bilinmeyen alanı null bırak. Öğrencinin kişiliği, motivasyonu, çalışkanlığı ya da '
            . 'geleceği hakkında HİÇBİR yorum yapma; sıfat kullanma. Türkçe yaz. Her zaman geçerli JSON döndür.';
    }

    private function userPrompt(): string
    {
        return <<<'PROMPT'
Bu PDF bir deneme sınavı sonuç belgesi. Aşağıdaki JSON şemasıyla, yalnızca belgede olan verilerle cevap ver:

{
  "exam": {"name": "sınav adı veya null", "type": "TYT|AYT|TYT+AYT|LGS|Diğer|null", "date": "YYYY-AA-GG veya null"},
  "overall": {"correct": sayı|null, "wrong": sayı|null, "blank": sayı|null, "net": sayı|null, "score": sayı|null, "rank": "metin veya null"},
  "subjects": [{"name": "ders adı", "correct": sayı|null, "wrong": sayı|null, "blank": sayı|null, "net": sayı|null}],
  "strong_areas": [{"subject": "ders", "topic": "konu ya da alt alan", "evidence": "belgedeki dayanak, örn. 12/12 doğru"}],
  "weak_areas": [{"subject": "ders", "topic": "konu ya da alt alan", "evidence": "belgedeki dayanak, örn. 2/10 doğru"}],
  "focus_suggestions": ["konu bazlı, somut çalışma odağı (en fazla 5)"],
  "summary": "sayılara dayalı, yorumsuz 2-3 cümlelik özet"
}

Kurallar:
- Konu bazlı kırılım belgede yoksa strong_areas/weak_areas'ı ders düzeyinde doldur ve evidence'ta net sayısını ver.
- Doğru sayısı en yüksek/net oranı en iyi alanlar güçlü, en düşükler zayıf sayılır; eşik yok, sıralamaya göre en fazla 6'şar alan.
- Belgede birden fazla oturum (TYT ve AYT) varsa subjects'te hepsini listele, exam.type "TYT+AYT" olsun.
PROMPT;
    }

    /**
     * Sekli garanti altina alir: view her anahtari varsayabilsin.
     *
     * @param  array<string,mixed>  $ham
     * @return array<string,mixed>
     */
    private function normalize(array $ham): array
    {
        $liste = fn ($v) => is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
        $metinListesi = fn ($v) => is_array($v) ? array_values(array_filter(array_map(fn ($x) => is_string($x) ? trim($x) : null, $v))) : [];
        $exam = is_array($ham['exam'] ?? null) ? $ham['exam'] : [];
        $overall = is_array($ham['overall'] ?? null) ? $ham['overall'] : [];

        return [
            'exam' => [
                'name' => $exam['name'] ?? null,
                'type' => $exam['type'] ?? null,
                'date' => $exam['date'] ?? null,
            ],
            'overall' => [
                'correct' => $overall['correct'] ?? null,
                'wrong' => $overall['wrong'] ?? null,
                'blank' => $overall['blank'] ?? null,
                'net' => $overall['net'] ?? null,
                'score' => $overall['score'] ?? null,
                'rank' => $overall['rank'] ?? null,
            ],
            'subjects' => $liste($ham['subjects'] ?? null),
            'strong_areas' => $liste($ham['strong_areas'] ?? null),
            'weak_areas' => $liste($ham['weak_areas'] ?? null),
            'focus_suggestions' => array_slice($metinListesi($ham['focus_suggestions'] ?? null), 0, 5),
            'summary' => is_string($ham['summary'] ?? null) ? trim($ham['summary']) : null,
        ];
    }
}
