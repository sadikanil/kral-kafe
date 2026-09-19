<?php

namespace Tests\Feature;

use App\Services\OpenAIStockAnalyzer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIStockAnalyzerTest extends TestCase
{
    private function fakeApiReturning(array $payload): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode($payload)]],
                ],
            ]),
        ]);
    }

    public function test_it_can_be_constructed_without_an_api_key(): void
    {
        config(['services.openai.api_key' => null]);

        $this->assertInstanceOf(OpenAIStockAnalyzer::class, new OpenAIStockAnalyzer());
    }

    public function test_it_reports_a_configuration_error_instead_of_calling_the_api_without_a_key(): void
    {
        config(['services.openai.api_key' => null]);
        Http::fake();

        $result = (new OpenAIStockAnalyzer())->analyzeStockPhoto('ham-veri', 'image/jpeg');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('OPENAI_API_KEY', $result['error']);
        Http::assertNothingSent();
    }

    public function test_it_sends_the_image_as_a_base64_data_uri(): void
    {
        config(['services.openai.api_key' => 'test-anahtari']);
        $this->fakeApiReturning(['products_detected' => [], 'summary' => 'bos raf']);

        (new OpenAIStockAnalyzer())->analyzeStockPhoto('ham-veri', 'image/png');

        Http::assertSent(function ($request) {
            $content = $request->data()['messages'][1]['content'];
            $imageUrl = collect($content)->firstWhere('type', 'image_url')['image_url']['url'];

            return $imageUrl === 'data:image/png;base64,' . base64_encode('ham-veri')
                && $request->hasHeader('Authorization', 'Bearer test-anahtari');
        });
    }

    public function test_it_formats_a_successful_analysis(): void
    {
        config(['services.openai.api_key' => 'test-anahtari']);
        $this->fakeApiReturning([
            'products_detected' => [
                ['product_id' => 7, 'name' => 'Kola', 'estimated_quantity' => 3, 'confidence' => 0.9],
            ],
            'overall_confidence' => 0.9,
            'summary' => 'Uc adet kola',
        ]);

        $result = (new OpenAIStockAnalyzer())->analyzeStockPhoto('ham-veri', 'image/jpeg');

        $this->assertTrue($result['success']);
        $this->assertSame('Kola', $result['products_detected'][0]['name']);
        $this->assertSame(3, $result['products_detected'][0]['estimated_quantity']);
    }

    public function test_merge_results_combines_several_photo_analyses_without_calling_the_api(): void
    {
        config(['services.openai.api_key' => 'test-anahtari']);
        Http::fake();

        $one = [
            'success' => true,
            'products_detected' => [['product_id' => 7, 'name' => 'Kola', 'estimated_quantity' => 3, 'confidence' => 0.9]],
            'anomalies' => [],
            'overall_confidence' => 0.9,
        ];
        $two = [
            'success' => true,
            'products_detected' => [['product_id' => 7, 'name' => 'Kola', 'estimated_quantity' => 5, 'confidence' => 0.7]],
            'anomalies' => [],
            'overall_confidence' => 0.7,
        ];

        $merged = (new OpenAIStockAnalyzer())->mergeResults([$one, $two]);

        $this->assertTrue($merged['success']);
        $this->assertSame(2, $merged['photo_count']);
        $this->assertCount(1, $merged['products_detected']);
        Http::assertNothingSent();
    }
}
