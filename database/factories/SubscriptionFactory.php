<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Package;
use App\Models\Subscription;
use App\Models\User;
use App\Support\LocalDay;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Carbon;

/** @extends Factory<Subscription> */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        $bas = Carbon::parse(LocalDay::today())->startOfMonth();

        return [
            'student_id' => User::factory()->student(),
            'package_id' => Package::factory(),
            'starts_on' => $bas->toDateString(),
            'ends_on' => $bas->copy()->addMonthNoOverflow()->subDay()->toDateString(),
            'price' => 7500,
            'payment_status' => PaymentStatus::Pending->value,
            'note' => null,
            'created_by' => null,
        ];
    }
}
