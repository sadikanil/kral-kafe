<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_forgot_password_screen_can_be_rendered(): void
    {
        $this->get('/sifremi-unuttum')->assertOk();
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/sifremi-unuttum', ['email' => $user->email])
            ->assertSessionHasNoErrors();

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/sifremi-unuttum', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $this->get('/sifre-sifirla/' . $notification->token)->assertOk();

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->post('/sifremi-unuttum', ['email' => $user->email]);

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $response = $this->post('/sifre-sifirla', [
                'token' => $notification->token,
                'email' => $user->email,
                'password' => 'yeni-sifre-123',
                'password_confirmation' => 'yeni-sifre-123',
            ]);

            $response->assertSessionHasNoErrors()->assertRedirect(route('login'));

            return true;
        });

        $this->assertTrue(
            auth()->attempt(['email' => $user->email, 'password' => 'yeni-sifre-123'])
        );
    }

    public function test_password_is_not_reset_with_an_invalid_token(): void
    {
        $user = User::factory()->create();

        $this->post('/sifre-sifirla', [
            'token' => 'gecersiz-token',
            'email' => $user->email,
            'password' => 'yeni-sifre-123',
            'password_confirmation' => 'yeni-sifre-123',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(
            auth()->attempt(['email' => $user->email, 'password' => 'yeni-sifre-123'])
        );
    }
}
