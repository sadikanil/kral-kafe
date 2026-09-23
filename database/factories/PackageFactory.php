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

    // --- Dalga 19: paket seviyeleri -----------------------------------------

    public function tier1(): static
    {
        return $this->state(['name' => 'Standart', 'tier' => 1,
            'has_reserved_table' => true, 'includes_coaching' => false,
            'includes_exam_club' => false, 'includes_private_lessons' => false]);
    }

    public function tier2(): static
    {
        return $this->state(['name' => 'Orta', 'tier' => 2,
            'has_reserved_table' => true, 'includes_coaching' => true,
            'includes_exam_club' => false, 'includes_private_lessons' => false]);
    }

    public function tier3(): static
    {
        return $this->state(['name' => 'Kral', 'tier' => 3,
            'has_reserved_table' => true, 'includes_coaching' => true,
            'includes_exam_club' => true, 'includes_private_lessons' => true]);
    }

    public function examOnly(): static
    {
        return $this->state(['name' => 'Sadece Deneme', 'tier' => null,
            'has_reserved_table' => false, 'includes_coaching' => false,
            'includes_exam_club' => true, 'includes_private_lessons' => false]);
    }

    public function examClubAddon(): static
    {
        return $this->examOnly()->state(['name' => 'Deneme Kulübü (ek)', 'is_addon' => true]);
    }
}
