<?php

namespace App\Services\ExamImport;

use App\Contracts\ExamPdfReader;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * OpenAI ile kurum PDF'i okuma (1 Ekim 2026) - alternatif saglayici.
 *
 * Projede zaten OPENAI_API_KEY var (stok ve tekil deneme raporu analizi);
 * ANTHROPIC_API_KEY yokken bu kullanilir. Ayni sema ve istem; PDF Chat
 * Completions "file" parcasi olarak, cikti json_schema (strict).
 */
class OpenAIExamPdfReader implements ExamPdfReader
{
    public function provider(): string
    {
        return 'openai';
    }

    public function readIndex(string $pdf, array $dersler): array
    {
        return $this->iste($pdf, ExamPdfSchema::indexPrompt($dersler), ExamPdfSchema::index($dersler), 'deneme_dizini');
    }

    public function readCard(string $pdf, int $sayfa, string $ad, array $dersler): array
    {
        return $this->iste($pdf, ExamPdfSchema::cardPrompt($sayfa, $ad, $dersler), ExamPdfSchema::card($dersler), 'deneme_karnesi');
    }

    /** @return array<string,mixed> */
    private function iste(string $pdf, string $istem, array $sema, string $ad): array
    {
        $anahtar = config('services.openai.api_key');

        if (blank($anahtar)) {
            throw new ExamPdfReadException('OPENAI_API_KEY tanımlı değil.');
        }

        try {
            // expect=false: buyuk govdede "Expect: 100-continue" 417'ye yol aciyor.
            $yanit = Http::withToken($anahtar)->withOptions(['expect' => false])->timeout(50)->post('https://api.openai.com/v1/chat/completions', [
                'model' => config('services.exam_ai.openai_model'),
                'messages' => [
                    ['role' => 'system', 'content' => ExamPdfSchema::system()],
                    ['role' => 'user', 'content' => [
                        ['type' => 'file', 'file' => [
                            'filename' => 'deneme.pdf',
                            'file_data' => 'data:application/pdf;base64,' . base64_encode($pdf),
                        ]],
                        ['type' => 'text', 'text' => $istem],
                    ]],
                ],
                'response_format' => ['type' => 'json_schema', 'json_schema' => ['name' => $ad, 'strict' => true, 'schema' => $sema]],
            ]);
        } catch (\Throwable $e) {
            Log::error('Deneme PDF okuma (OpenAI) basarisiz', ['error' => $e->getMessage()]);

            if (ExamPdfReadException::isTimeout($e)) {
                throw ExamPdfReadException::timeout($e);
            }

            throw ExamPdfReadException::transient('Yapay zekâ servisine ulaşılamadı: ' . $e->getMessage(), $e, 5);
        }

        if (! $yanit->successful()) {
            Log::error('Deneme PDF okuma (OpenAI): API hatasi', ['status' => $yanit->status(), 'body' => $yanit->body()]);

            throw ExamPdfReadException::forStatus($yanit->status(), 'Yapay zekâ servisi hata döndü (' . $yanit->status() . ').');
        }

        $veri = json_decode((string) $yanit->json('choices.0.message.content'), true);

        if (! is_array($veri)) {
            throw new ExamPdfReadException('Yapay zekâ geçerli bir sonuç döndürmedi.');
        }

        return $veri;
    }
}
