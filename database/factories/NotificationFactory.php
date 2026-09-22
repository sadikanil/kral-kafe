<?php

namespace Database\Factories;

use App\Enums\NotificationType;
use App\Models\Notification;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Notification>
 */
class NotificationFactory extends Factory
{
    protected $model = Notification::class;

    public function definition(): array
    {
        return [
            'type' => NotificationType::Absence->value,
            'user_id' => User::factory(),
            'student_id' => null,
            'related_id' => null,
            'unique_key' => 'absence:' . fake()->unique()->numerify('########'),
            'title' => 'Bildirim',
            'body' => null,
        ];
    }
}
