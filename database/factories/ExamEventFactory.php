<?php

namespace Database\Factories;

use App\Enums\ExamType;
use App\Models\ExamEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ExamEvent>
 */
class ExamEventFactory extends Factory
{
    protected $model = ExamEvent::class;

    public function definition(): array
    {
        return [
            'title' => fake()->randomElement(['Genel Deneme', 'Kurum Denemesi', 'Türkiye Geneli']) . ' ' . fake()->numberBetween(1, 20),
            'exam_type' => fake()->randomElement(ExamType::cases())->value,
            'exam_date' => fake()->dateTimeBetween('now', '+60 days')->format('Y-m-d'),
            'starts_at' => '10:00',
            'note' => null,
            'created_by' => null,
        ];
    }
}
