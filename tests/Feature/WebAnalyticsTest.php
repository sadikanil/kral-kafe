<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Vercel Web Analytics ve Speed Insights yalnizca canlida, temizlenmis adresle yuklenir. */
class WebAnalyticsTest extends TestCase
{
    public function test_the_analytics_scripts_load_only_in_production(): void
    {
        $this->get(route('login'))->assertOk()
            ->assertDontSee('/_vercel/insights/script.js', false)
            ->assertDontSee('/_vercel/speed-insights/script.js', false);

        $this->app['env'] = 'production';

        $yanit = $this->get(route('login'))->assertOk()
            ->assertSee("window.va('beforeSend', temizle)", false)
            ->assertSee("window.si('beforeSend', temizle)", false);

        // Ayni kaynak (CSP 'self') ve istegin nonce'u (satir ici temizle bloku icin).
        $html = $yanit->getContent();
        $this->assertMatchesRegularExpression('#<script nonce="[^"]+" defer src="/_vercel/insights/script\.js"></script>#', $html);
        $this->assertMatchesRegularExpression('#<script nonce="[^"]+" defer src="/_vercel/speed-insights/script\.js"></script>#', $html);
        $this->assertMatchesRegularExpression('#<script nonce="[^"]+">\s*\(function \(\) \{\s*function temizle#', $html);
    }
}
