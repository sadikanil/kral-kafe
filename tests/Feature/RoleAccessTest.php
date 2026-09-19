<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Bugune kadar test takiminda TEK BIR 403 iddiasi yoktu: AdminMiddleware'in
 * REDDETME kolunun calistigi kanitli degildi. Rol sayisi ikiden altiya
 * cikmadan once bu kolun testi yazilmali - aksi halde "zaten calisiyordu"
 * varsayimiyla uzerine yetki katmani insa edilir.
 */
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_role_enum_covers_every_role_in_the_matrix(): void
    {
        $this->assertSame(
            ['admin', 'coach', 'teacher', 'student', 'parent', 'staff'],
            Role::values()
        );
    }

    public function test_a_student_is_refused_from_the_admin_panel(): void
    {
        $ogrenci = User::factory()->create([
            'role' => Role::Student->value,
            'subscription_status' => 'active',
        ]);

        $this->actingAs($ogrenci)->get('/yonetim')->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get('/yonetim')->assertRedirect(route('login'));
    }

    public function test_an_admin_passes(): void
    {
        $yonetici = User::factory()->create([
            'role' => Role::Admin->value,
            'subscription_status' => 'active',
        ]);

        $this->actingAs($yonetici)->get('/yonetim')->assertOk();
    }

    public function test_the_user_model_answers_role_questions_through_the_enum(): void
    {
        $koc = User::factory()->make(['role' => Role::Coach->value]);

        $this->assertTrue($koc->hasRole(Role::Coach));
        $this->assertFalse($koc->hasRole(Role::Admin));
        $this->assertFalse($koc->isAdmin());
        $this->assertFalse($koc->isStudent());
    }

    public function test_subscription_is_only_a_student_concern(): void
    {
        // Koc/ogretmen/veli/staff icin abonelik kavrami yok; durumu 'inactive'
        // olursa kendi panellerinden kilitlenmemeliler.
        $koc = User::factory()->make([
            'role' => Role::Coach->value,
            'subscription_status' => 'inactive',
        ]);
        $ogrenci = User::factory()->make([
            'role' => Role::Student->value,
            'subscription_status' => 'inactive',
        ]);

        $this->assertFalse($koc->needsActiveSubscription());
        $this->assertTrue($ogrenci->needsActiveSubscription());
    }
}
