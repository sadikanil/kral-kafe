<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TurkishMessagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_login_is_reported_in_turkish(): void
    {
        $user = User::factory()->create();

        $this->from('/giris')
            ->post('/giris', ['kimlik' => $user->email, 'password' => 'yanlis-sifre'])
            ->assertSessionHasErrors(['kimlik' => 'Girdiğiniz bilgiler kayıtlarımızla eşleşmiyor.']);
    }

    public function test_unknown_email_on_password_reset_is_reported_in_turkish(): void
    {
        $this->from('/sifremi-unuttum')
            ->post('/sifremi-unuttum', ['email' => 'yok@example.com'])
            ->assertSessionHasErrors(['email' => 'Bu e-posta adresine sahip bir kullanıcı bulamadık.']);
    }

    public function test_validation_messages_are_in_turkish(): void
    {
        $this->from('/sifremi-unuttum')
            ->post('/sifremi-unuttum', ['email' => 'gecersiz'])
            ->assertSessionHasErrors(['email' => 'e-posta geçerli bir e-posta adresi olmalıdır.']);
    }
}
