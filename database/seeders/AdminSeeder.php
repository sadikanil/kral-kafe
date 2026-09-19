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
     * calistiriliyor. Sifre config('kafe.yonetici_sifresi') uzerinden okunur
     * (ADMIN_PASSWORD); tanimsizsa rastgele uretilip YALNIZCA konsola yazilir.
     *
     * Burada dogrudan env() cagrilmaz: config onbellege alindiginda bos doner
     * ve testten override edilemez. Gerekcesi config/kafe.php icinde.
     */
    public function run(): void
    {
        $password = config('kafe.yonetici_sifresi') ?: Str::random(16);

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
