<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * Sifre koda sabitlenmez: depo herkese acik ve bu seeder uretimde de
     * calistiriliyor. ADMIN_PASSWORD tanimliysa o kullanilir, degilse rastgele
     * bir sifre uretilip YALNIZCA konsola yazilir.
     */
    public function run(): void
    {
        $password = env('ADMIN_PASSWORD') ?: Str::random(16);

        User::create([
            'name' => 'Admin',
            'email' => 'admin@kralkafe.com',
            'password' => Hash::make($password),
            'role' => 'admin',
            'subscription_status' => 'active',
            'subscription_start' => now(),
        ]);

        $this->command->info('Yönetici kullanıcı oluşturuldu:');
        $this->command->info('E-posta: admin@kralkafe.com');
        $this->command->warn("Şifre: {$password}");
        $this->command->warn('Bu şifre bir daha gösterilmeyecek; şimdi kaydedin.');
    }
}
