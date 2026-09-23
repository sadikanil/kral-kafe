<?php

namespace Tests\Feature;

use App\Models\MonthlyBill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminPagesTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create([
            'role' => 'admin',
            'subscription_status' => 'active',
        ]);
    }

    public function test_monthly_report_page_renders_bills_for_the_selected_month(): void
    {
        $customer = User::factory()->create(['role' => 'student']);

        MonthlyBill::create([
            'user_id' => $customer->id,
            'bill_month' => '2026-03-01',
            'total_items' => 7,
            'total_amount' => 123.45,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->admin())
            ->get('/yonetim/raporlar/aylik?year=2026&month=3');

        $response->assertOk()
            ->assertSee('Mart 2026')
            ->assertSee($customer->name)
            ->assertSee('123,45 ₺');
    }

    public function test_monthly_report_page_renders_when_there_are_no_bills(): void
    {
        $response = $this->actingAs($this->admin())
            ->get('/yonetim/raporlar/aylik?year=2026&month=7');

        $response->assertOk()->assertSee('fatura bulunamadı');
    }
}
