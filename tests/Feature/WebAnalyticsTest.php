<?php

namespace Tests\Feature;

use Tests\TestCase;

/** Vercel Web Analytics yalnizca canlida, temizlenmis adresle yuklenir. */
class WebAnalyticsTest extends TestCase
{
    public function test_the_analytics_script_loads_only_in_production(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('/_vercel/insights/script.js', false);

        $this->app['env'] = 'production';

        $this->get(route('login'))->assertOk()
            ->assertSee('<script defer src="/_vercel/insights/script.js"></script>', false)
            ->assertSee("window.va('beforeSend'", false);
    }
}
