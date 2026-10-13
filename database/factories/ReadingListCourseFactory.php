<?php

namespace Database\Factories;

use App\Models\ReadingListCourse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReadingListCourse>
 */
class ReadingListCourseFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'reading_list_id' => fake()->unique()->numberBetween(1, 100000),
            'title' => fake()->sentence(4),
            'url' => fake()->url(),
            'notes' => null,
            'important' => 5,
            'urgent' => 5,
            'estimated_minutes' => 390,
            'percent_complete' => 0,
            'position' => 0,
        ];
    }
}
