<?php

namespace Database\Factories;

use App\Models\Mix;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Mix>
 */
class MixFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $videoId = Str::random(11);

        return [
            'youtube_video_id' => $videoId,
            'title' => fake()->sentence(3).' — '.fake()->randomElement(['lofi mix', 'jazz for work', 'ambient focus']),
            'author_name' => fake()->company(),
            'thumbnail_url' => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
            'thumbnail_path' => null,
            'duration_seconds' => fake()->numberBetween(3600, 7200),
            'resume_seconds' => null,
            'last_played_at' => null,
        ];
    }

    /**
     * Played part of the way through, so it picks up from there.
     */
    public function partPlayed(int $seconds = 1390): static
    {
        return $this->state(fn (): array => [
            'resume_seconds' => $seconds,
            'last_played_at' => now()->subHour(),
        ]);
    }

    /**
     * Added before the player had ever reported how long it is.
     */
    public function withoutDuration(): static
    {
        return $this->state(fn (): array => ['duration_seconds' => null]);
    }
}
