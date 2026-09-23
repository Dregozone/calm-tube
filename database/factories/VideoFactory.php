<?php

namespace Database\Factories;

use App\Enums\LiveStatus;
use App\Models\Channel;
use App\Models\Video;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Video>
 */
class VideoFactory extends Factory
{
    /**
     * A normal, feed-visible, unwatched video, so most tests need no state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $videoId = Str::random(11);

        return [
            'channel_id' => Channel::factory(),
            'youtube_video_id' => $videoId,
            'title' => Str::title(rtrim(fake()->unique()->sentence(5), '.')),
            'description' => fake()->paragraph(),
            'thumbnail_url' => "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg",
            'thumbnail_path' => null,
            'published_at' => fake()->dateTimeBetween('-1 year'),
            'duration_seconds' => fake()->numberBetween(480, 1500),
            'is_short' => false,
            'live_status' => LiveStatus::None,
            'scheduled_start_at' => null,
            'resume_seconds' => null,
            'sampled_out_at' => null,
            'watched_at' => null,
            'hidden_at' => null,
            'unavailable_at' => null,
            'enriched_at' => now(),
        ];
    }

    public function watched(): static
    {
        return $this->state(fn (): array => ['watched_at' => now()->subDay(), 'resume_seconds' => null]);
    }

    /**
     * Started but not finished, so the watch page offers to pick it up.
     */
    public function partlyWatched(int $seconds = 600): static
    {
        return $this->state(fn (): array => [
            'resume_seconds' => $seconds,
            'watched_at' => null,
        ]);
    }

    public function hidden(): static
    {
        return $this->state(fn (): array => ['hidden_at' => now()->subDay()]);
    }

    public function short(): static
    {
        return $this->state(fn (): array => [
            'is_short' => true,
            'duration_seconds' => 45,
        ]);
    }

    /**
     * Shorts detection could not decide. These are still shown.
     */
    public function shortUnknown(): static
    {
        return $this->state(fn (): array => ['is_short' => null]);
    }

    public function live(): static
    {
        return $this->state(fn (): array => [
            'live_status' => LiveStatus::Live,
            'duration_seconds' => null,
        ]);
    }

    public function upcoming(): static
    {
        return $this->state(fn (): array => [
            'live_status' => LiveStatus::Upcoming,
            'duration_seconds' => null,
            'scheduled_start_at' => now()->addDays(3),
        ]);
    }

    /**
     * Ingested from the feed, but the API has not filled in its details yet.
     */
    public function unenriched(): static
    {
        return $this->state(fn (): array => [
            'duration_seconds' => null,
            'is_short' => null,
            'enriched_at' => null,
        ]);
    }

    /**
     * Deleted, private or region blocked on YouTube. The archive survives.
     */
    public function unavailable(): static
    {
        return $this->state(fn (): array => ['unavailable_at' => now()->subDay()]);
    }

    /**
     * Its thumbnail has been downloaded and is served from disk.
     */
    /**
     * Held back from the feed by its channel's sample limit.
     */
    public function setAside(): static
    {
        return $this->state(fn (): array => ['sampled_out_at' => now()]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'thumbnail_path' => 'calm-tube/thumbnails/'.$attributes['youtube_video_id'].'.jpg',
        ]);
    }
}
