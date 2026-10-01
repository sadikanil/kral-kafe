<?php

namespace App\Services\ExamImport;

use App\Contracts\ExamPdfReader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Google Gemini ile kurum PDF'i okuma (1 Ekim 2026) - Vertex AI uzerinden.
 *
 * Neden Vertex AI, AI Studio anahtari degil: Google Cloud'un 300$ deneme
 * kredisi Mart 2026'dan beri AI Studio'daki Gemini API'yi KAPSAMIYOR,
 * yalnizca Vertex AI'yi. Ayrica Vertex AI musteri verisini egitimde
 * kullanmaz ve bolge (europe-west4 gibi) secilebilir - PDF'te ogrenci adlari
 * var (README SS14, KVKK).
 *
 * Kimlik: hizmet hesabi JSON anahtari -> imzali JWT -> 1 saatlik erisim
 * jetonu. Jeton yalnizca bellekte tutulur (lambda omru); veritabani
 * onbellegine kimlik bilgisi yazilmaz. Ayni PDF on bir istekte tekrar
 * gonderilir; Gemini'nin ortuk onbellegi ayni onekin (PDF ilk parcada)
 * tekrarini indirimli sayar.
 *
 * Sema ExamPdfSchema'dan; Gemini'nin responseSchema'si OpenAPI alt kumesi
 * oldugu icin cevrilir (anyOf[T,null] -> nullable, additionalProperties
 * yok, alan sirasi propertyOrdering ile).
 */
class GeminiExamPdfReader implements ExamPdfReader
{
    private const KAPSAM = 'https://www.googleapis.com/auth/cloud-platform';

    /** @var array{jeton:string,bitis:int}|null */
    private static ?array $jeton = null;

    public function provider(): string
    {
        return 'gemini';
    }

    public function readIndex(string $pdf, array $dersler): array
    {
        return $this->iste($pdf, ExamPdfSchema::indexPrompt($dersler), ExamPdfSchema::index($dersler));
    }

    public function readCard(string $pdf, int $sayfa, string $ad, array $dersler): array
    {
        return $this->iste($pdf, ExamPdfSchema::cardPrompt($sayfa, $ad, $dersler), ExamPdfSchema::card($dersler));
    }

    /** JSON Schema -> Gemini responseSchema (OpenAPI alt kumesi). */
    public static function schema(array $sema): array
    {
        if (isset($sema['anyOf'])) {
            $turler = array_column($sema['anyOf'], 'type');
            $asil = collect($sema['anyOf'])->first(fn ($s) => ($s['type'] ?? null) !== 'null');

            return self::schema($asil) + (in_array('null', $turler, true) ? ['nullable' => true] : []);
        }

        $sonuc = array_intersect_key($sema, array_flip(['type', 'enum', 'required', 'description']));

        if (isset($sema['properties'])) {
            $sonuc['properties'] = array_map(fn ($s) => self::schema($s), $sema['properties']);
            $sonuc['propertyOrdering'] = array_keys($sema['properties']);
        }

        if (isset($sema['items'])) {
            $sonuc['items'] = self::schema($sema['items']);
        }

        return $sonuc;
    }

    /** @return array<string,mixed> */
    private function iste(string $pdf, string $istem, array $sema): array
    {
        $proje = config('services.google_vertex.project');
        $bolge = config('services.google_vertex.location') ?: 'global';
        $model = config('services.exam_ai.gemini_model');

        if (blank($proje) || blank($model)) {
            throw new ExamPdfReadException('Gemini için GOOGLE_VERTEX_PROJECT ve EXAM_AI_GEMINI_MODEL tanımlı olmalı.');
        }

        $sunucu = $bolge === 'global' ? 'aiplatform.googleapis.com' : "{$bolge}-aiplatform.googleapis.com";
        $adres = "https://{$sunucu}/v1/projects/{$proje}/locations/{$bolge}/publishers/google/models/{$model}:generateContent";

        try {
            // expect=false: govde 1 MB'i asinca cURL "Expect: 100-continue"
            // ekliyor ve Google onu 417 ile reddediyor (1 Ekim 2026, canlida).
            $yanit = Http::withToken($this->erisimJetonu())->withOptions(['expect' => false])->timeout(50)->post($adres, [
                'systemInstruction' => ['parts' => [['text' => ExamPdfSchema::system()]]],
                // PDF ilk parcada: on bir istekte ayni onek, ortuk onbellek.
                'contents' => [['role' => 'user', 'parts' => [
                    ['inlineData' => ['mimeType' => 'application/pdf', 'data' => base64_encode($pdf)]],
                    ['text' => $istem],
                ]]],
                'generationConfig' => [
                    'temperature' => 0,
                    'responseMimeType' => 'application/json',
                    'responseSchema' => self::schema($sema),
                ],
            ]);
        } catch (ExamPdfReadException $e) {
            throw $e;
        } catch (\Throwable $e) {
            Log::error('Deneme PDF okuma (Gemini) basarisiz', ['error' => $e->getMessage()]);

            throw new ExamPdfReadException('Yapay zekâ servisine ulaşılamadı: ' . $e->getMessage(), previous: $e);
        }

        if (! $yanit->successful()) {
            Log::error('Deneme PDF okuma (Gemini): API hatasi', ['status' => $yanit->status(), 'body' => $yanit->body()]);

            throw new ExamPdfReadException(match ($yanit->status()) {
                404 => "Gemini modeli bulunamadı ({$model}). EXAM_AI_GEMINI_MODEL'i Vertex AI Model Garden'daki adla değiştirin.",
                403 => 'Vertex AI erişimi reddedildi: projede Vertex AI API açık mı, hizmet hesabına "Vertex AI User" rolü verildi mi?',
                429 => 'Gemini kotası doldu ya da deneme kredisi bitti; biraz sonra "Devam et" deyin.',
                default => 'Yapay zekâ servisi hata döndü (' . $yanit->status() . ')'
                    . (filled($yanit->json('error.message')) ? ': ' . mb_strimwidth((string) $yanit->json('error.message'), 0, 200, '…') : '.'),
            });
        }

        $aday = $yanit->json('candidates.0');
        $durak = $aday['finishReason'] ?? null;

        if ($durak !== null && $durak !== 'STOP') {
            throw new ExamPdfReadException($durak === 'MAX_TOKENS'
                ? 'Sayfa tek seferde okunamadı (çıktı sınırı); "Devam et" ile tekrar deneyin.'
                : "Yapay zekâ bu sayfayı okumayı reddetti ({$durak}).");
        }

        // Dusunen modellerde dusunce parcalari (thought) cevaba dahil degil.
        $metin = collect($aday['content']['parts'] ?? [])
            ->reject(fn ($p) => $p['thought'] ?? false)
            ->pluck('text')->implode('');

        $veri = json_decode($metin, true);

        if (! is_array($veri)) {
            throw new ExamPdfReadException('Yapay zekâ geçerli bir sonuç döndürmedi.');
        }

        return $veri;
    }

    /** Hizmet hesabi anahtariyla OAuth erisim jetonu (JWT bearer akisi). */
    private function erisimJetonu(): string
    {
        if (self::$jeton !== null && self::$jeton['bitis'] > time() + 60) {
            return self::$jeton['jeton'];
        }

        $ham = (string) config('services.google_vertex.credentials');
        // Vercel'e tek satir girilebilsin diye base64 de kabul edilir.
        $kimlik = json_decode(str_starts_with(ltrim($ham), '{') ? $ham : (string) base64_decode($ham, true), true);

        if (! is_array($kimlik) || empty($kimlik['client_email']) || empty($kimlik['private_key'])) {
            throw new ExamPdfReadException('GOOGLE_VERTEX_CREDENTIALS geçerli bir hizmet hesabı anahtarı (JSON) değil.');
        }

        $simdi = time();
        $hedef = $kimlik['token_uri'] ?? 'https://oauth2.googleapis.com/token';
        $parca = fn (array $v) => rtrim(strtr(base64_encode(json_encode($v)), '+/', '-_'), '=');
        $imzasiz = $parca(['alg' => 'RS256', 'typ' => 'JWT']) . '.' . $parca([
            'iss' => $kimlik['client_email'],
            'scope' => self::KAPSAM,
            'aud' => $hedef,
            'iat' => $simdi,
            'exp' => $simdi + 3600,
        ]);

        if (! openssl_sign($imzasiz, $imza, $kimlik['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new ExamPdfReadException('Hizmet hesabı anahtarı okunamadı (private_key).');
        }

        $yanit = Http::asForm()->timeout(15)->post($hedef, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $imzasiz . '.' . rtrim(strtr(base64_encode($imza), '+/', '-_'), '='),
        ]);

        if (! $yanit->successful() || blank($yanit->json('access_token'))) {
            Log::error('Gemini: erisim jetonu alinamadi', ['status' => $yanit->status(), 'body' => $yanit->body()]);

            throw new ExamPdfReadException('Google hizmet hesabıyla giriş yapılamadı (' . $yanit->status() . ').');
        }

        self::$jeton = ['jeton' => $yanit->json('access_token'), 'bitis' => $simdi + (int) $yanit->json('expires_in', 3600)];

        return self::$jeton['jeton'];
    }

    /** Testler arasinda bellekteki jetonu sifirlar. */
    public static function forgetToken(): void
    {
        self::$jeton = null;
    }
}
