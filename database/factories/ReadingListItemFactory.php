<?php

namespace Database\Factories;

use App\Models\ReadingListItem;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ReadingListItem>
 */
class ReadingListItemFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $videoId = Str::random(11);

        return [
            'reading_list_id' => fake()->unique()->numberBetween(1, 100000),
            'youtube_video_id' => $videoId,
            'title' => fake()->sentence(5),
            'url' => 'https://www.youtube.com/watch?v='.$videoId,
            'category' => 'Learning',
            'percent_complete' => 0,
            'position' => 0,
        ];
    }

    /**
     * Pointing at a video already archived here.
     */
    public function forVideo(string $videoId): static
    {
        return $this->state(fn (): array => [
            'youtube_video_id' => $videoId,
            'url' => 'https://www.youtube.com/watch?v='.$videoId,
        ]);
    }
}
