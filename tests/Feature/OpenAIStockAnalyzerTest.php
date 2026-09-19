<?php

namespace Tests\Feature;

use App\Services\OpenAIStockAnalyzer;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAIStockAnalyzerTest extends TestCase
{
    public function test_it_can_be_constructed_without_an_api_key(): void
    {
        config(['services.openai.api_key' => null]);

        $this->assertInstanceOf(OpenAIStockAnalyzer::class, new OpenAIStockAnalyzer());
    }

    public function test_it_reports_a_configuration_error_instead_of_calling_the_api_without_a_key(): void
    {
        config(['services.openai.api_key' => null]);
        Http::fake();

        $result = (new OpenAIStockAnalyzer())->analyzeStockPhoto('/tmp/does-not-matter.jpg');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('OPENAI_API_KEY', $result['error']);
        Http::assertNothingSent();
    }

    public function test_batch_analysis_also_short_circuits_without_a_key(): void
    {
        config(['services.openai.api_key' => null]);
        Http::fake();

        $result = (new OpenAIStockAnalyzer())->analyzeBatch(['/tmp/a.jpg', '/tmp/b.jpg']);

        $this->assertFalse($result['success']);
        Http::assertNothingSent();
    }
}
