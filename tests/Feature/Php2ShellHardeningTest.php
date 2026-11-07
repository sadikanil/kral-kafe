<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\PhpSurumu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * php2shell sertlestirmesi (CVE-2026-109618 / CVE-2026-109619, 7 Kasim 2026).
 *
 * Yamanin kendisi PHP'de; burada test edilen, onun sessizce geri
 * alinmamasi: Vercel runtime surumu, derleme kapisi, php.ini, basliklar.
 * Adimlar ve gerekceler: docs/security/php2shell.md
 */
class Php2ShellHardeningTest extends TestCase
{
    use RefreshDatabase;

    private function jsonDosyasi(string $dosya): array
    {
        return json_decode(file_get_contents(base_path($dosya)), true, flags: JSON_THROW_ON_ERROR);
    }

    // --- PHP surumu ---------------------------------------------------------

    public function test_vercel_runs_the_runtime_that_ships_a_patched_php(): void
    {
        // vercel-php@0.7.4 -> PHP 8.3.8 (8.3 icin yamali derleme yok);
        // 0.8.0 -> @libphp/amazon-linux-2023-v84 0.0.9 -> PHP 8.4.27.
        $this->assertSame('vercel-php@0.8.0', $this->jsonDosyasi('vercel.json')['functions']['api/index.php']['runtime']);
        $this->assertSame('22.x', $this->jsonDosyasi('package.json')['engines']['node']);
    }

    public function test_composer_requires_a_patched_php_and_the_build_checks_it(): void
    {
        $composer = $this->jsonDosyasi('composer.json');

        $this->assertSame('~8.4.27 || ^8.5.12', $composer['require']['php']);
        $this->assertTrue(PhpSurumu::yamaliMi($composer['config']['platform']['php']));

        // Runtime composer'i --ignore-platform-reqs ile kurar, Composer'in
        // kendi surum denetimi o yuzden uretilmez: kapi bu betik.
        $this->assertContains('@php artisan kafe:php-surumu --kesin', $composer['scripts']['vercel']);
    }

    public function test_the_build_gate_fails_only_on_an_unpatched_php(): void
    {
        $this->artisan('kafe:php-surumu', ['surum' => '8.3.8', '--kesin' => true])
            ->expectsOutputToContain('yamasi YOK')
            ->assertExitCode(1);

        $this->artisan('kafe:php-surumu', ['surum' => '8.4.27', '--kesin' => true])
            ->expectsOutputToContain('yamasi var')
            ->assertExitCode(0);

        // --kesin olmadan yalnizca uyarir.
        $this->artisan('kafe:php-surumu', ['surum' => '8.3.8'])->assertExitCode(0);
    }

    public function test_the_admin_sees_the_running_php_version(): void
    {
        $sayfa = $this->actingAs(User::factory()->admin()->create())
            ->get(route('admin.settings.edit'))
            ->assertOk()
            ->assertSee('PHP ' . PhpSurumu::simdiki());

        PhpSurumu::yamaliMi()
            ? $sayfa->assertSee('Güvenlik yaması var')->assertDontSee('docs/security/php2shell.md')
            : $sayfa->assertSee('Güvenlik yaması yok')->assertSee('docs/security/php2shell.md');
    }

    // --- php.ini ------------------------------------------------------------

    public function test_the_vercel_php_ini_hardens_the_runtime(): void
    {
        $ini = parse_ini_file(base_path('api/php.ini'), false, INI_SCANNER_TYPED);

        $this->assertFalse($ini['expose_php']);
        $this->assertFalse($ini['display_errors']);
        $this->assertFalse($ini['display_startup_errors']);
        $this->assertTrue($ini['log_errors']);
        $this->assertFalse($ini['session.upload_progress.enabled']);
    }

    // --- Basliklar ----------------------------------------------------------

    public function test_every_response_carries_the_security_headers(): void
    {
        foreach ([route('login'), '/boyle-bir-sayfa-yok'] as $adres) {
            $yanit = $this->get($adres)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeaderMissing('X-Powered-By');

            // Masa QR okuyucu kamerayi, oturum baslatma konumu kullaniyor.
            $politika = $yanit->headers->get('Permissions-Policy');
            $this->assertStringContainsString('camera=(self)', $politika);
            $this->assertStringContainsString('geolocation=(self)', $politika);
            $this->assertStringContainsString('microphone=()', $politika);
        }
    }

    // --- Web sunucusu -------------------------------------------------------

    public function test_php_files_are_never_executed_from_upload_locations(): void
    {
        $this->assertStringContainsString(
            'RewriteRule ^(storage|uploads)/.*\.(php[0-9]?|phtml|phar|pht)$ - [F,L,NC]',
            file_get_contents(public_path('.htaccess')),
        );
        $this->assertStringContainsString('Require all denied', file_get_contents(storage_path('app/public/.htaccess')));

        // Yerel yerlesik sunucu yonlendiricisi: .php dogrudan sunulmaz.
        $onceki = $_SERVER['REQUEST_URI'] ?? null;

        try {
            $_SERVER['REQUEST_URI'] = '/storage/foto.php';
            $this->assertTrue(include base_path('server.php'));

            $_SERVER['REQUEST_URI'] = '/css/app.css';
            $this->assertFalse(include base_path('server.php'));
        } finally {
            $_SERVER['REQUEST_URI'] = $onceki;
        }
    }

    // --- Ortam sablonlari ---------------------------------------------------

    public function test_the_production_template_is_safe_and_holds_no_secrets(): void
    {
        $sablon = file_get_contents(base_path('.env.production.example'));

        $this->assertMatchesRegularExpression('/^APP_ENV=production$/m', $sablon);
        $this->assertMatchesRegularExpression('/^APP_DEBUG=false$/m', $sablon);
        $this->assertMatchesRegularExpression('/^SESSION_SECURE_COOKIE=true$/m', $sablon);
        $this->assertMatchesRegularExpression('/^SESSION_ENCRYPT=true$/m', $sablon);

        foreach (['APP_KEY', 'DB_PASSWORD', 'DB_USERNAME', 'DB_HOST'] as $sir) {
            $this->assertMatchesRegularExpression("/^{$sir}=$/m", $sablon, "{$sir} sablonda bos kalmali");
        }

        // Bos "SESSION_SECURE_COOKIE=" false sayilir ve HTTPS algilamasini kapatir.
        $this->assertDoesNotMatchRegularExpression('/^SESSION_SECURE_COOKIE=$/m', file_get_contents(base_path('.env.example')));
    }
}
