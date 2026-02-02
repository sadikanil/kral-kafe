<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::create([
            'name' => 'Admin',
            'email' => 'admin@kralkafe.com',
            'password' => Hash::make('admin123'),
            'role' => 'admin',
            'subscription_status' => 'active',
            'subscription_start' => now(),
        ]);

        $this->command->info('Admin kullanıcı oluşturuldu:');
        $this->command->info('E-posta: admin@kralkafe.com');
        $this->command->info('Şifre: admin123');
    }
}
