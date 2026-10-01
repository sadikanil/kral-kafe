<?php

namespace Tests\Feature;

use Anthropic\Client;
use App\Services\ExamImport\AnthropicExamPdfReader;
use App\Services\ExamImport\ExamPdfReadException;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\TestCase;

/**
 * Claude okuyucusunun istegi (1 Ekim 2026): ag yok, SDK'nin HTTP katmani
 * sahte PSR-18 istemcisiyle degistirilir.
 *
 * Dogrulanan: PDF belge blogu + onbellek isareti, JSON semasi, effort,
 * sunucu tarafi yedek; ret ve yarim cikti yoneticiye anlasilir hata olur.
 */
class AnthropicExamPdfReaderTest extends TestCase
{
    /** @var list<array{request:\Psr\Http\Message\RequestInterface}> */
    private array $gecmis = [];

    private function okuyucu(array $yanit, int $durum = 200): AnthropicExamPdfReader
    {
        config(['services.anthropic.api_key' => 'test-anahtari', 'services.exam_ai.anthropic_model' => 'claude-opus-5-5', 'services.exam_ai.effort' => 'low']);

        $yigin = HandlerStack::create(new MockHandler([new Response($durum, ['Content-Type' => 'application/json'], json_encode($yanit))]));
        $yigin->push(Middleware::history($this->gecmis));

        return new AnthropicExamPdfReader(new Client(
            apiKey: 'test-anahtari',
            requestOptions: ['transporter' => new Guzzle(['handler' => $yigin]), 'maxRetries' => 0],
        ));
    }

    private function mesaj(string $metin, string $durak = 'end_turn'): array
    {
        return [
            'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5-5',
            'content' => [['type' => 'text', 'text' => $metin]],
            'stop_reason' => $durak, 'stop_sequence' => null,
            'usage' => ['input_tokens' => 1200, 'output_tokens' => 300],
        ];
    }

    public function test_the_card_request_carries_the_pdf_schema_and_fallback(): void
    {
        $karne = ['name' => 'ELİF YILDIRIM', 'class' => '12-A', 'score' => 409.027, 'ranks' => [], 'subjects' => [], 'topics' => []];
        $okuyucu = $this->okuyucu($this->mesaj(json_encode($karne)));

        $sonuc = $okuyucu->readCard('%PDF-1.7 sahte', 4, 'ELİF YILDIRIM', ['tyt_kimya' => 'TYT Kimya']);

        $this->assertSame('ELİF YILDIRIM', $sonuc['name']);

        $istek = $this->gecmis[0]['request'];
        $govde = json_decode((string) $istek->getBody(), true);
        $this->assertStringEndsWith('/v1/messages', $istek->getUri()->getPath());
        $this->assertStringContainsString('server-side-fallback-2026-07-01', $istek->getHeaderLine('anthropic-beta'));
        $this->assertSame('claude-opus-5-5', $govde['model']);
        $this->assertSame('default', $govde['fallbacks']);
        $this->assertSame('low', $govde['output_config']['effort']);
        $this->assertSame('json_schema', $govde['output_config']['format']['type']);
        $this->assertContains('tyt_kimya', $govde['output_config']['format']['schema']['properties']['topics']['items']['properties']['subject_code']['enum']);

        $belge = $govde['messages'][0]['content'][0];
        $this->assertSame(['document', 'base64', 'application/pdf', 'ephemeral'],
            [$belge['type'], $belge['source']['type'], $belge['source']['media_type'], $belge['cache_control']['type']]);
        $this->assertSame(base64_encode('%PDF-1.7 sahte'), $belge['source']['data']);
        $this->assertStringContainsString('4. sayfadaki', $govde['messages'][0]['content'][1]['text']);
    }

    public function test_a_refusal_or_truncated_output_becomes_a_readable_error(): void
    {
        try {
            $this->okuyucu($this->mesaj('{', 'max_tokens'))->readIndex('%PDF', ['tyt_kimya' => 'TYT Kimya']);
            $this->fail('Hata bekleniyordu');
        } catch (ExamPdfReadException $e) {
            $this->assertSame('Çıktı yarım kaldı (sayfa çok uzun); yeniden deneyin.', $e->getMessage());
        }

        $this->expectExceptionMessage('ANTHROPIC_API_KEY geçersiz.');
        $this->okuyucu(['type' => 'error', 'error' => ['type' => 'authentication_error', 'message' => 'invalid x-api-key']], 401)
            ->readIndex('%PDF', []);
    }

    public function test_without_a_key_nothing_is_sent(): void
    {
        $okuyucu = $this->okuyucu($this->mesaj('{}'));
        config(['services.anthropic.api_key' => null]);

        $this->expectExceptionMessage('ANTHROPIC_API_KEY tanımlı değil.');
        try {
            $okuyucu->readIndex('%PDF', []);
        } finally {
            $this->assertSame([], $this->gecmis);
        }
    }
}
