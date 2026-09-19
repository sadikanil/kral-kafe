<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Vercel'de uygulama bir proxy arkasinda calisir. trustProxies() cagrilmazsa
 * $request->ip() platformun ic adresini doner ve HER ziyaretci icin aynidir.
 *
 * Bu, QR sahteciligine karsi planlanan IP kapisini sessizce ise yaramaz hale
 * getirir: adres allowlist'e konursa kapi HERKESE acilir, konmazsa HERKESI
 * engeller. Birincisi daha tehlikeli cunku sessizdir.
 */
class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')->get('/_test/ip', fn () => request()->ip());
    }

    public function test_the_forwarded_client_ip_is_used_behind_a_proxy(): void
    {
        $this->get('/_test/ip', ['X-Forwarded-For' => '203.0.113.9'])
            ->assertOk()
            ->assertSee('203.0.113.9');
    }

    public function test_https_is_detected_from_the_forwarded_header(): void
    {
        Route::middleware('web')->get('/_test/secure', fn () => request()->isSecure() ? 'https' : 'http');

        $this->get('/_test/secure', ['X-Forwarded-Proto' => 'https'])
            ->assertOk()
            ->assertSee('https');
    }
}
