<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sifre sifirlama e-postasi Turkce (QA bug 4).
 *
 * Laravel'in bildirimi ve e-posta sablonu JSON anahtarlariyla yaziyor
 * ('Reset Password', 'Hello!'); lang/tr.json olmadan hepsi Ingilizce kaliyordu.
 * Smoke testi konuyu ve ilk satiri, bu test islenmis e-postanin tamamini
 * denetler - selamlama, imza ve alt not sablondan geliyor.
 */
class ResetMailTurkishTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_rendered_reset_email_has_no_english_left(): void
    {
        $koc = User::factory()->create(['email' => 'koc@kralkafe.com']);
        $posta = (new ResetPassword('belirtec'))->toMail($koc);
        $html = (string) $posta->render();

        $this->assertSame('Şifre Sıfırlama', $posta->subject);
        $this->assertSame('Şifremi Sıfırla', $posta->actionText);

        foreach (['Hello!', 'Regards', 'Reset Password', 'You are receiving', 'will expire',
            'If you did not request', "having trouble clicking", 'All rights reserved'] as $ingilizce) {
            $this->assertStringNotContainsString($ingilizce, $html);
        }

        $this->assertStringContainsString('Merhaba', $html);
        $this->assertStringContainsString('Saygılarımızla', $html);
        // :count yer tutucusu Turkce cumlede de dolmali.
        $sure = config('auth.passwords.' . config('auth.defaults.passwords') . '.expire');
        $this->assertStringContainsString("{$sure} dakika", $html);
    }
}
