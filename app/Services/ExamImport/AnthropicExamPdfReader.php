<?php

namespace App\Services\ExamImport;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Contracts\ExamPdfReader;
use Illuminate\Support\Facades\Log;

/**
 * Claude ile kurum PDF'i okuma (1 Ekim 2026) - onerilen saglayici, README SS14.
 *
 * - PDF belge blogu olarak gider (metin cikarimi yok: tablolar bozulmasin);
 *   blok onbellege yazilir (cacheControl). Dizin + 9 karne = 10 istek ayni
 *   PDF'i tasir; ilkinden sonrakiler onbellekten okunur (yaklasik %10 fiyat).
 * - Cikti JSON semasina bagli (outputConfig.format): gecersiz JSON donmez.
 * - Aktarim isi: effort 'low' varsayilan (EXAM_AI_EFFORT); her istek
 *   Vercel'in 60 sn sinirinda kalsin diye 50 sn zaman asimi, tekrar yok
 *   (tekrari yonetici "Devam et" ile yapar).
 * - Sunucu tarafi yedek (fallbacks: default): model bir guvenlik
 *   siniflandiricisina takilirsa istek ayni cagrida baska modelle surer.
 */
class AnthropicExamPdfReader implements ExamPdfReader
{
    private Client $client;

    public function __construct(?Client $client = null)
    {
        $this->client = $client ?? new Client(
            apiKey: (string) config('services.anthropic.api_key'),
            requestOptions: ['timeout' => 50.0, 'maxRetries' => 0],
        );
    }

    public function provider(): string
    {
        return 'anthropic';
    }

    public function readIndex(string $pdf, array $dersler): array
    {
        return $this->iste($pdf, ExamPdfSchema::indexPrompt($dersler), ExamPdfSchema::index($dersler));
    }

    public function readCard(string $pdf, int $sayfa, string $ad, array $dersler): array
    {
        return $this->iste($pdf, ExamPdfSchema::cardPrompt($sayfa, $ad, $dersler), ExamPdfSchema::card($dersler));
    }

    /** @return array<string,mixed> */
    private function iste(string $pdf, string $istem, array $sema): array
    {
        if (blank(config('services.anthropic.api_key'))) {
            throw new ExamPdfReadException('ANTHROPIC_API_KEY tanımlı değil.');
        }

        try {
            $mesaj = $this->client->beta->messages->create(
                model: (string) config('services.exam_ai.anthropic_model'),
                maxTokens: 16000,
                system: [['type' => 'text', 'text' => ExamPdfSchema::system()]],
                messages: [[
                    'role' => 'user',
                    'content' => [
                        [
                            'type' => 'document',
                            'source' => ['type' => 'base64', 'mediaType' => 'application/pdf', 'data' => base64_encode($pdf)],
                            'cacheControl' => ['type' => 'ephemeral'],
                        ],
                        ['type' => 'text', 'text' => $istem],
                    ],
                ]],
                outputConfig: [
                    'effort' => (string) config('services.exam_ai.effort'),
                    'format' => ['type' => 'json_schema', 'schema' => $sema],
                ],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (RateLimitException $e) {
            throw new ExamPdfReadException('Yapay zekâ servisi şu an yoğun; birkaç dakika sonra "Devam et"e basın.', previous: $e);
        } catch (AuthenticationException $e) {
            throw new ExamPdfReadException('ANTHROPIC_API_KEY geçersiz.', previous: $e);
        } catch (APIStatusException $e) {
            Log::error('Deneme PDF okuma (Claude): API hatasi', ['status' => $e->status, 'error' => $e->getMessage()]);

            throw new ExamPdfReadException('Yapay zekâ servisi hata döndü (' . $e->status . ').', previous: $e);
        } catch (APITimeoutException $e) {
            throw new ExamPdfReadException('Sayfa 50 saniyede okunamadı; "Devam et" ile yeniden deneyin.', previous: $e);
        } catch (APIConnectionException $e) {
            Log::error('Deneme PDF okuma (Claude): baglanti', ['error' => $e->getMessage()]);

            throw new ExamPdfReadException('Yapay zekâ servisine ulaşılamadı.', previous: $e);
        }

        if ($mesaj->stopReason === 'refusal') {
            throw new ExamPdfReadException('Yapay zekâ bu sayfayı okumayı reddetti; sayfayı elle girin.');
        }
        if ($mesaj->stopReason === 'max_tokens') {
            throw new ExamPdfReadException('Çıktı yarım kaldı (sayfa çok uzun); yeniden deneyin.');
        }

        foreach ($mesaj->content as $blok) {
            if ($blok->type === 'text') {
                $veri = json_decode($blok->text, true);

                if (is_array($veri)) {
                    return $veri;
                }
            }
        }

        throw new ExamPdfReadException('Yapay zekâ geçerli bir sonuç döndürmedi.');
    }
}
