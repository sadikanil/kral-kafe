<?php

namespace Database\Factories;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Rol durumlari. Testler 'role' ve 'subscription_status' ciftini elle
     * kuruyordu; ikisini bir arada veren tek yer burasi olsun.
     */
    public function admin(): static
    {
        return $this->state(fn () => ['role' => Role::Admin->value, 'subscription_status' => 'active']);
    }

    public function student(): static
    {
        return $this->state(fn () => ['role' => Role::Student->value, 'subscription_status' => 'active']);
    }

    /**
     * Ogrenciye bugun yururlukte bir paket atar (Dalga 19). Haklar paketten
     * geldigi icin masa/deneme kapisindan gecmesi gereken testler bunu kullanir.
     */
    public function withPackage(\Database\Factories\PackageFactory|\App\Models\Package $paket): static
    {
        return $this->afterCreating(fn (\App\Models\User $ogrenci) => \App\Models\Subscription::factory()->create([
            'student_id' => $ogrenci->id,
            'package_id' => $paket instanceof \App\Models\Package ? $paket->id : $paket->create()->id,
        ]));
    }

    public function parent(): static
    {
        return $this->state(fn () => ['role' => Role::Parent->value, 'subscription_status' => 'active']);
    }
}
