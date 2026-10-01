<?php

namespace Tests\Feature;

use App\Contracts\ExamPdfReader;
use App\Services\ExamImport\ExamPdfReadException;
use App\Services\ExamImport\ExamPdfSchema;
use App\Services\ExamImport\GeminiExamPdfReader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Gemini okuyucusu (Vertex AI) - ag yok, Http::fake.
 *
 * Dogrulanan: hizmet hesabiyla imzali JWT -> erisim jetonu, Vertex adresi
 * (global / bolgesel), PDF ilk parcada, sema cevirisi, hata metinleri.
 */
class GeminiExamPdfReaderTest extends TestCase
{
    private string $anahtar;

    protected function setUp(): void
    {
        parent::setUp();
        GeminiExamPdfReader::forgetToken();

        $ozel = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($ozel, $pem);
        $this->anahtar = (string) openssl_pkey_get_details($ozel)['key'];

        config([
            'services.google_vertex.project' => 'kral-kafe-test',
            'services.google_vertex.location' => 'global',
            // Vercel'e tek satir: base64.
            'services.google_vertex.credentials' => base64_encode(json_encode([
                'type' => 'service_account',
                'client_email' => 'okuyucu@kral-kafe-test.iam.gserviceaccount.com',
                'private_key' => $pem,
                'token_uri' => 'https://oauth2.googleapis.com/token',
            ])),
            'services.exam_ai.gemini_model' => 'gemini-test-flash',
        ]);
    }

    protected function tearDown(): void
    {
        GeminiExamPdfReader::forgetToken();
        parent::tearDown();
    }

    private function sahte(array $gemini, int $durum = 200): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'erisim-jetonu', 'expires_in' => 3599]),
            '*aiplatform.googleapis.com/*' => Http::response($gemini, $durum),
        ]);
    }

    private function cevap(array $veri, string $durak = 'STOP'): array
    {
        return ['candidates' => [[
            'content' => ['role' => 'model', 'parts' => [
                ['text' => 'dusunce', 'thought' => true],
                ['text' => json_encode($veri)],
            ]],
            'finishReason' => $durak,
        ]]];
    }

    public function test_the_card_request_signs_in_and_sends_the_pdf_first(): void
    {
        $this->sahte($this->cevap(['name' => 'ELİF YILDIRIM', 'class' => '12-A', 'score' => 409.027, 'ranks' => [], 'subjects' => [], 'topics' => []]));

        $sonuc = (new GeminiExamPdfReader)->readCard('%PDF-1.7 sahte', 4, 'ELİF YILDIRIM', ['tyt_kimya' => 'TYT Kimya']);

        $this->assertSame('ELİF YILDIRIM', $sonuc['name']);

        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'oauth2.googleapis.com')) {
                return false;
            }
            [$baslik, $govde, $imza] = explode('.', $r['assertion']);
            $iddia = json_decode(base64_decode(strtr($govde, '-_', '+/')), true);
            $gecerli = openssl_verify("{$baslik}.{$govde}", base64_decode(strtr($imza, '-_', '+/')), $this->anahtar, OPENSSL_ALGO_SHA256);

            return $r['grant_type'] === 'urn:ietf:params:oauth:grant-type:jwt-bearer'
                && $iddia['iss'] === 'okuyucu@kral-kafe-test.iam.gserviceaccount.com'
                && $iddia['scope'] === 'https://www.googleapis.com/auth/cloud-platform'
                && $gecerli === 1;
        });

        Http::assertSent(function (Request $r) {
            if (! str_contains($r->url(), 'aiplatform')) {
                return false;
            }
            $parcalar = $r['contents'][0]['parts'];

            return $r->url() === 'https://aiplatform.googleapis.com/v1/projects/kral-kafe-test/locations/global/publishers/google/models/gemini-test-flash:generateContent'
                && $r->hasHeader('Authorization', 'Bearer erisim-jetonu')
                && $parcalar[0]['inlineData'] === ['mimeType' => 'application/pdf', 'data' => base64_encode('%PDF-1.7 sahte')]
                && str_contains($parcalar[1]['text'], '4. sayfadaki')
                && $r['generationConfig']['responseMimeType'] === 'application/json'
                && $r['generationConfig']['temperature'] === 0
                && str_contains($r['systemInstruction']['parts'][0]['text'], 'tahmin etme');
        });
    }

    public function test_the_token_is_reused_across_pages(): void
    {
        $this->sahte($this->cevap(['name' => 'A', 'class' => null, 'score' => null, 'ranks' => [], 'subjects' => [], 'topics' => []]));
        $okuyucu = new GeminiExamPdfReader;

        $okuyucu->readCard('%PDF', 1, 'A', []);
        $okuyucu->readCard('%PDF', 2, 'A', []);

        Http::assertSentCount(3);
    }

    public function test_a_regional_location_uses_the_regional_host(): void
    {
        config(['services.google_vertex.location' => 'europe-west4']);
        $this->sahte($this->cevap(['exam' => [], 'participants' => [], 'students' => []]));

        (new GeminiExamPdfReader)->readIndex('%PDF', []);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://europe-west4-aiplatform.googleapis.com/v1/projects/kral-kafe-test/locations/europe-west4/publishers/google/models/gemini-test-flash:generateContent');
    }

    public function test_the_schema_is_translated_to_the_gemini_subset(): void
    {
        $sema = GeminiExamPdfReader::schema(ExamPdfSchema::card(['tyt_kimya' => 'TYT Kimya']));
        $json = json_encode($sema);

        $this->assertStringNotContainsString('anyOf', $json);
        $this->assertStringNotContainsString('additionalProperties', $json);
        $this->assertSame(['type' => 'string', 'nullable' => true], $sema['properties']['class']);
        $this->assertSame(['type' => 'integer', 'nullable' => true], $sema['properties']['ranks']['properties']['branch']['properties']['rank']);
        $this->assertSame(['tyt_kimya', 'other'], $sema['properties']['topics']['items']['properties']['subject_code']['enum']);
        $this->assertSame(['name', 'class', 'score', 'ranks', 'subjects', 'topics'], $sema['propertyOrdering']);
        $this->assertSame($sema['propertyOrdering'], $sema['required']);
    }

    public function test_an_unknown_model_names_the_setting_to_fix(): void
    {
        $this->sahte(['error' => ['code' => 404, 'message' => 'not found']], 404);

        $this->expectException(ExamPdfReadException::class);
        $this->expectExceptionMessage('EXAM_AI_GEMINI_MODEL');

        (new GeminiExamPdfReader)->readIndex('%PDF', []);
    }

    public function test_a_cut_off_answer_is_a_readable_error(): void
    {
        $this->sahte($this->cevap(['name' => 'A'], 'MAX_TOKENS'));

        $this->expectException(ExamPdfReadException::class);
        $this->expectExceptionMessage('çıktı sınırı');

        (new GeminiExamPdfReader)->readCard('%PDF', 1, 'A', []);
    }

    public function test_bad_credentials_are_reported_without_a_network_call(): void
    {
        Http::fake();
        config(['services.google_vertex.credentials' => 'bozuk']);

        try {
            (new GeminiExamPdfReader)->readIndex('%PDF', []);
            $this->fail('Hata bekleniyordu');
        } catch (ExamPdfReadException $e) {
            $this->assertStringContainsString('GOOGLE_VERTEX_CREDENTIALS', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_the_provider_setting_selects_gemini(): void
    {
        config(['services.exam_ai.provider' => 'gemini']);
        $this->assertInstanceOf(GeminiExamPdfReader::class, app(ExamPdfReader::class));

        config(['services.exam_ai.provider' => null, 'services.anthropic.api_key' => null]);
        $this->assertInstanceOf(GeminiExamPdfReader::class, app(ExamPdfReader::class));
    }
}
