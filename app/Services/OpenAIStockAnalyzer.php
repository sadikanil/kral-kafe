<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\Product;

class OpenAIStockAnalyzer
{
    private ?string $apiKey;
    private string $model = 'gpt-4o';

    public function __construct()
    {
        $this->apiKey = config('services.openai.api_key');
    }

    /**
     * Analyze a stock photo and detect products with quantities.
     *
     * Ham goruntu verisi alir, dosya yolu degil: fotograflar yerelde diskte,
     * uretimde nesne depolamada (Supabase Storage) duruyor.
     *
     * @param string $imageData Ham goruntu icerigi
     * @param string $mimeType Goruntunun MIME tipi
     * @param array $expectedProducts Products expected at this location
     * @return array Analysis result
     */
    public function analyzeStockPhoto(string $imageData, string $mimeType, array $expectedProducts = []): array
    {
        if (empty($this->apiKey)) {
            Log::warning('Stock analysis skipped: OPENAI_API_KEY is not configured');

            return $this->getEmptyResult('Yapay zeka analizi kullanılamıyor: OPENAI_API_KEY tanımlı değil.');
        }

        try {
            $encodedImage = base64_encode($imageData);

            // Build product list for the prompt
            $productList = collect($expectedProducts)->map(function ($product) {
                return "- {$product['name']} (ID: {$product['id']})";
            })->implode("\n");

            $prompt = $this->buildPrompt($productList);

            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Content-Type' => 'application/json',
            ])->timeout(60)->post('https://api.openai.com/v1/chat/completions', [
                        'model' => $this->model,
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => 'Sen bir kafe stok analiz asistanısın. Görevin raf, dolap veya buzdolabı fotoğraflarını analiz ederek ürünleri saymak. Her zaman JSON formatında yanıt ver.'
                            ],
                            [
                                'role' => 'user',
                                'content' => [
                                    [
                                        'type' => 'text',
                                        'text' => $prompt
                                    ],
                                    [
                                        'type' => 'image_url',
                                        'image_url' => [
                                            'url' => "data:{$mimeType};base64,{$encodedImage}",
                                            'detail' => 'high'
                                        ]
                                    ]
                                ]
                            ]
                        ],
                        'max_tokens' => 2000,
                        'response_format' => ['type' => 'json_object']
                    ]);

            if ($response->successful()) {
                $content = $response->json('choices.0.message.content');
                $result = json_decode($content, true);

                return $this->formatAnalysisResult($result, $expectedProducts);
            }

            Log::error('OpenAI API error', [
                'status' => $response->status(),
                'body' => $response->body()
            ]);

            return $this->getEmptyResult('API hatası');

        } catch (\Exception $e) {
            Log::error('Stock analysis failed', [
                'error' => $e->getMessage(),
            ]);

            return $this->getEmptyResult($e->getMessage());
        }
    }

    /**
     * Build the analysis prompt.
     */
    private function buildPrompt(string $productList): string
    {
        $prompt = "Bu kafe rafı/dolabı/buzdolabı fotoğrafını analiz et.\n\n";

        if (!empty($productList)) {
            $prompt .= "Bu lokasyonda beklenen ürünler:\n{$productList}\n\n";
        }

        $prompt .= "Lütfen şu formatta JSON yanıt ver:\n";
        $prompt .= "{\n";
        $prompt .= '  "products_detected": [' . "\n";
        $prompt .= "    {\n";
        $prompt .= '      "product_id": <ürün ID veya null>,' . "\n";
        $prompt .= '      "name": "<ürün adı>",' . "\n";
        $prompt .= '      "estimated_quantity": <tahmini adet>,' . "\n";
        $prompt .= '      "confidence": <0-1 arası güven skoru>,' . "\n";
        $prompt .= '      "notes": "<varsa notlar>"' . "\n";
        $prompt .= "    }\n";
        $prompt .= "  ],\n";
        $prompt .= '  "anomalies": [' . "\n";
        $prompt .= "    {\n";
        $prompt .= '      "type": "<unexpected_item|low_stock|empty_shelf|damaged>",' . "\n";
        $prompt .= '      "description": "<açıklama>"' . "\n";
        $prompt .= "    }\n";
        $prompt .= "  ],\n";
        $prompt .= '  "overall_confidence": <0-1 arası genel güven skoru>,' . "\n";
        $prompt .= '  "summary": "<kısa özet>"' . "\n";
        $prompt .= "}\n\n";
        $prompt .= "Önemli: Emin olmadığın ürünlerde düşük güven skoru ver. Sayamadığın ürünler için null dön.";

        return $prompt;
    }

    /**
     * Format the analysis result.
     */
    private function formatAnalysisResult(array $result, array $expectedProducts): array
    {
        $formatted = [
            'success' => true,
            'products_detected' => [],
            'anomalies' => $result['anomalies'] ?? [],
            'overall_confidence' => $result['overall_confidence'] ?? 0.5,
            'summary' => $result['summary'] ?? 'Analiz tamamlandı',
            'analyzed_at' => now()->toIso8601String(),
        ];

        // Process detected products
        foreach ($result['products_detected'] ?? [] as $detected) {
            $productData = [
                'product_id' => $detected['product_id'] ?? null,
                'name' => $detected['name'] ?? 'Bilinmeyen Ürün',
                'estimated_quantity' => $detected['estimated_quantity'] ?? 0,
                'confidence' => $detected['confidence'] ?? 0.5,
                'notes' => $detected['notes'] ?? null,
            ];

            // Match with expected products if no ID
            if ($productData['product_id'] === null && !empty($expectedProducts)) {
                $matched = $this->matchProduct($productData['name'], $expectedProducts);
                if ($matched) {
                    $productData['product_id'] = $matched['id'];
                    $productData['matched_name'] = $matched['name'];
                }
            }

            $formatted['products_detected'][] = $productData;
        }

        // Check for missing expected products
        $detectedIds = collect($formatted['products_detected'])
            ->pluck('product_id')
            ->filter()
            ->toArray();

        foreach ($expectedProducts as $expected) {
            if (!in_array($expected['id'], $detectedIds)) {
                $formatted['anomalies'][] = [
                    'type' => 'missing_product',
                    'product_id' => $expected['id'],
                    'description' => "Beklenen ürün tespit edilemedi: {$expected['name']}"
                ];
            }
        }

        return $formatted;
    }

    /**
     * Try to match a detected product name with expected products.
     */
    private function matchProduct(string $detectedName, array $expectedProducts): ?array
    {
        $detectedLower = mb_strtolower($detectedName);

        foreach ($expectedProducts as $product) {
            $productLower = mb_strtolower($product['name']);

            // Exact match
            if ($detectedLower === $productLower) {
                return $product;
            }

            // Partial match
            if (str_contains($detectedLower, $productLower) || str_contains($productLower, $detectedLower)) {
                return $product;
            }

            // Levenshtein distance for fuzzy matching
            if (levenshtein($detectedLower, $productLower) < 5) {
                return $product;
            }
        }

        return null;
    }

    /**
     * Get empty result for error cases.
     */
    private function getEmptyResult(string $error = null): array
    {
        return [
            'success' => false,
            'products_detected' => [],
            'anomalies' => [],
            'overall_confidence' => 0,
            'summary' => $error ?? 'Analiz yapılamadı',
            'error' => $error,
            'analyzed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Analyze multiple photos for a batch.
     */
    /**
     * Birden fazla fotografin analiz sonucunu tek sonuca indirger.
     *
     * Sonuclar disaridan verilir; bu metot API'ye istek atmaz.
     */
    public function mergeResults(array $results): array
    {
        return $this->mergeBatchResults($results);
    }

    /**
     * Merge results from multiple photos.
     */
    private function mergeBatchResults(array $results): array
    {
        $merged = [
            'success' => true,
            'products_detected' => [],
            'anomalies' => [],
            'overall_confidence' => 0,
            'summary' => '',
            'photo_count' => count($results),
        ];

        $productQuantities = [];
        $totalConfidence = 0;
        $successCount = 0;

        foreach ($results as $result) {
            if (!$result['success']) {
                continue;
            }

            $successCount++;
            $totalConfidence += $result['overall_confidence'];

            foreach ($result['products_detected'] as $product) {
                $key = $product['product_id'] ?? $product['name'];

                if (!isset($productQuantities[$key])) {
                    $productQuantities[$key] = [
                        'product_id' => $product['product_id'],
                        'name' => $product['name'],
                        'quantities' => [],
                        'confidence_sum' => 0,
                    ];
                }

                $productQuantities[$key]['quantities'][] = $product['estimated_quantity'];
                $productQuantities[$key]['confidence_sum'] += $product['confidence'];
            }

            $merged['anomalies'] = array_merge($merged['anomalies'], $result['anomalies'] ?? []);
        }

        // Average the quantities
        foreach ($productQuantities as $data) {
            $merged['products_detected'][] = [
                'product_id' => $data['product_id'],
                'name' => $data['name'],
                'estimated_quantity' => (int) round(array_sum($data['quantities']) / count($data['quantities'])),
                'confidence' => $data['confidence_sum'] / count($data['quantities']),
            ];
        }

        $merged['overall_confidence'] = $successCount > 0 ? $totalConfidence / $successCount : 0;
        $merged['success'] = $successCount > 0;
        $merged['summary'] = "{$successCount}/{$merged['photo_count']} fotoğraf başarıyla analiz edildi.";

        return $merged;
    }
}
