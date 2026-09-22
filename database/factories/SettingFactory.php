<?php

namespace Database\Factories;

use App\Models\Setting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Setting>
 */
class SettingFactory extends Factory
{
    protected $model = Setting::class;

    public function definition(): array
    {
        return [
            'key' => Setting::KAFE_KONUM,
            'value' => ['lat' => 39.9208, 'lng' => 32.8541],
        ];
    }
}
