<?php

namespace Database\Factories;

use App\Models\Package;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Package> */
class PackageFactory extends Factory
{
    protected $model = Package::class;

    public function definition(): array
    {
        return [
            'name' => 'Standart Paket',
            'monthly_price' => 7500,
            'has_reserved_table' => true,
            'includes_coaching' => false,
            'weekly_mock_exams' => 0,
            'description' => 'Rezerve masa + sınırsız çay',
            'is_active' => true,
        ];
    }
}
