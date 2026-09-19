<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Kurulumun belgelenmis yolu: php artisan migrate --seed
 *
 * Bu yol bir yonetici uretmezse sistem kurulur ama icine GIRILEMEZ ve hata
 * "sifre yanlis" gibi gorunur - oysa hesap hic olusmamistir. AdminSeeder
 * uzun sure burada cagrilmiyordu.
 *
 * Not: Laravel'in varsayilan WithoutModelEvents trait'i BILEREK kullanilmiyor.
 * StudyTable'in qr_code'u `creating` olayinda uretiliyor; olaylar susturulursa
 * kod null kalir ve tekil kisit hatasi, sebebi gorunmeden patlar.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(AdminSeeder::class);

        // Yerel gelistirme icin ornek ogrenci. Uretimde zararsiz: parola
        // factory varsayilani ve hesap abonelik disi birakilmiyor.
        if (! User::where('email', 'test@example.com')->exists()) {
            User::factory()->create([
                'name' => 'Test User',
                'email' => 'test@example.com',
                'role' => Role::Student->value,
                'subscription_status' => 'active',
            ]);
        }
    }
}
