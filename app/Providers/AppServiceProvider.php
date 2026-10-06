<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telefon bildirimi (6 Ekim 2026): istek boyunca biriken bildirimler
        // sonda tek havuzda gider; anahtar istek basina bir kez okunur.
        $this->app->singleton(\App\Services\Push\WebPush::class);
        $this->app->singleton(\App\Services\Push\PushNotifier::class);

        // Kurum deneme PDF'i okuyucu (1 Ekim 2026): saglayici yapilandirmadan.
        // Acik secim yoksa Claude anahtari varsa Claude, yoksa OpenAI.
        $this->app->bind(\App\Contracts\ExamPdfReader::class, function () {
            $saglayici = config('services.exam_ai.provider') ?: match (true) {
                filled(config('services.anthropic.api_key')) => 'anthropic',
                filled(config('services.google_vertex.credentials')) => 'gemini',
                default => 'openai',
            };

            return match ($saglayici) {
                'openai' => new \App\Services\ExamImport\OpenAIExamPdfReader,
                'gemini' => new \App\Services\ExamImport\GeminiExamPdfReader,
                default => new \App\Services\ExamImport\AnthropicExamPdfReader,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production') || !empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https') {
            \Illuminate\Support\Facades\URL::forceScheme('https');
        }

        // Dalga 30a: varsayilan sayfalama Tailwind'e gore; bizimki elle CSS.
        \Illuminate\Pagination\Paginator::defaultView('vendor.pagination.kafe');
    }
}
