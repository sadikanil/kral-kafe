<?php

namespace Database\Factories;

use App\Enums\PaymentMethod;
use App\Models\Payment;
use App\Models\Subscription;
use App\Support\LocalDay;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Payment> */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'subscription_id' => Subscription::factory(),
            'amount' => 7500,
            'paid_at' => LocalDay::today(),
            'method' => PaymentMethod::Cash->value,
            'note' => null,
            'recorded_by' => null,
        ];
    }
}
