<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Depo public oldugu icin yonetici sifresi koda ya da dokumantasyona
 * sabitlenmemeli.
 */
class AdminSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_uses_the_password_from_the_environment_when_given(): void
    {
        config(['kafe.yonetici_sifresi' => 'cok-gizli-bir-sifre']);

        $this->seed(AdminSeeder::class);

        $admin = User::where('email', 'admin@kralkafe.com')->sole();
        $this->assertTrue(Hash::check('cok-gizli-bir-sifre', $admin->password));
    }

    public function test_it_generates_a_random_password_when_none_is_configured(): void
    {
        config(['kafe.yonetici_sifresi' => null]);

        $this->seed(AdminSeeder::class);

        $admin = User::where('email', 'admin@kralkafe.com')->sole();

        $this->assertFalse(Hash::check('admin123', $admin->password),
            'Seeder hala tahmin edilebilir bir sifre kullaniyor');
        $this->assertFalse(Hash::check('', $admin->password));
    }

    public function test_no_default_password_is_written_down_anywhere(): void
    {
        $suclular = [];

        $taranacak = array_merge(
            glob(base_path('*.md')) ?: [],
            glob(database_path('seeders/*.php')) ?: []
        );

        foreach ($taranacak as $dosya) {
            if (str_contains(file_get_contents($dosya), 'admin123')) {
                $suclular[] = str_replace(base_path() . '/', '', $dosya);
            }
        }

        $this->assertSame([], $suclular,
            'Bu dosyalar varsayilan yonetici sifresini aciga cikariyor; depo public');
    }
    /**
     * Kurulumun belgelenmis yolu `php artisan migrate --seed`. Bu yol bir
     * yonetici uretmezse sistem kurulur ama ICINE GIRILEMEZ - ve hata "sifre
     * yanlis" gibi gorunur, oysa hesap hic olusmamistir.
     */
    public function test_the_default_seeder_produces_an_admin_that_can_sign_in(): void
    {
        config(['kafe.yonetici_sifresi' => 'kurulum-sifresi-123']);

        $this->seed();

        $admin = User::where('email', 'admin@kralkafe.com')->sole();
        $this->assertSame('admin', $admin->role);

        $this->post(route('login'), [
            'email' => 'admin@kralkafe.com',
            'password' => 'kurulum-sifresi-123',
        ])->assertRedirect(route('admin.dashboard'));
    }
}
