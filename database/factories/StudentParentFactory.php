<?php

namespace Database\Factories;

use App\Models\StudentParent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StudentParent>
 */
class StudentParentFactory extends Factory
{
    protected $model = StudentParent::class;

    public function definition(): array
    {
        return [
            'student_id' => User::factory()->student(),
            'parent_id' => User::factory()->parent(),
            'created_by' => null,
        ];
    }
}
