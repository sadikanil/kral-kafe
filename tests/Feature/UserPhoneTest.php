<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Dalga 18: yonetici kullaniciyi telefonla ekler. E-posta ve sifre istege
 * bagli - sifreyi kullanici ilk giriste kendisi belirler.
 */
class UserPhoneTest extends TestCase
{
    use RefreshDatabase;

    private function yonetici(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_a_user_can_be_added_with_only_a_phone(): void
    {
        $this->actingAs($this->yonetici())
            ->post(route('admin.users.store'), [
                'name' => 'Ayse Yilmaz',
                'phone' => '0532 123 45 67',
                'role' => 'student',
            ])->assertSessionHasNoErrors();

        $ayse = User::where('name', 'Ayse Yilmaz')->sole();
        $this->assertSame('5321234567', $ayse->phone);
        $this->assertNull($ayse->email);
        $this->assertNull($ayse->password);
    }

    public function test_the_same_phone_cannot_be_used_twice(): void
    {
        User::factory()->student()->create(['phone' => '5321234567']);

        $this->actingAs($this->yonetici())
            ->post(route('admin.users.store'), [
                'name' => 'Ikinci',
                'phone' => '+90 532 123 45 67',
                'role' => 'student',
            ])->assertSessionHasErrors('phone');
    }

    public function test_a_user_needs_a_phone_or_an_email(): void
    {
        $this->actingAs($this->yonetici())
            ->post(route('admin.users.store'), [
                'name' => 'Kimliksiz',
                'role' => 'student',
            ])->assertSessionHasErrors('phone');
    }

    public function test_an_invalid_phone_is_refused(): void
    {
        $this->actingAs($this->yonetici())
            ->post(route('admin.users.store'), [
                'name' => 'Yanlis',
                'phone' => '0212 123 45 67',
                'role' => 'student',
            ])->assertSessionHasErrors('phone');
    }

    public function test_editing_keeps_the_own_phone_and_normalizes_it(): void
    {
        $ogrenci = User::factory()->student()->create(['phone' => '5321234567']);

        $this->actingAs($this->yonetici())
            ->put(route('admin.users.update', $ogrenci), [
                'name' => $ogrenci->name,
                'phone' => '0532 123 45 67',
                'role' => 'student',
                'subscription_status' => 'active',
            ])->assertSessionHasNoErrors();

        $this->assertSame('5321234567', $ogrenci->fresh()->phone);
    }

    public function test_a_user_is_shown_by_phone_before_email(): void
    {
        $this->assertSame('0532 123 45 67', User::factory()->make(['phone' => '5321234567', 'email' => 'a@b.c'])->contactLabel());
        $this->assertSame('a@b.c', User::factory()->make(['phone' => null, 'email' => 'a@b.c'])->contactLabel());
    }

    public function test_the_user_list_can_be_searched_by_phone(): void
    {
        User::factory()->student()->create(['name' => 'Aranan', 'phone' => '5321234567']);
        User::factory()->student()->create(['name' => 'Baskasi', 'phone' => '5559998877']);

        $this->actingAs($this->yonetici())
            ->get(route('admin.users.index', ['search' => '0532 123']))
            ->assertSee('Aranan')
            ->assertDontSee('Baskasi');
    }
}
