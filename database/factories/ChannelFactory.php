<?php

namespace Database\Factories;

use App\Models\Channel;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Channel>
 */
class ChannelFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->company();

        return [
            'youtube_channel_id' => 'UC'.Str::random(22),
            'title' => $title,
            'custom_name' => null,
            'handle' => '@'.Str::slug($title),
            'avatar_url' => 'https://yt3.ggpht.com/'.Str::random(16).'=s800-c-k-c0x00ffffff-no-rj',
            'avatar_path' => null,
            'uploads_playlist_id' => 'UU'.Str::random(22),
            'is_enabled' => true,
            'playback_rate' => null,
            'feed_etag' => null,
            'feed_last_modified' => null,
            'last_refreshed_at' => now()->subMinutes(14),
            'last_refresh_error' => null,
        ];
    }

    /**
     * Still followed, but not refreshed and kept out of the feed.
     */
    public function disabled(): static
    {
        return $this->state(fn (): array => ['is_enabled' => false]);
    }

    public function neverRefreshed(): static
    {
        return $this->state(fn (): array => [
            'last_refreshed_at' => null,
            'last_refresh_error' => null,
        ]);
    }

    /**
     * The last refresh attempt failed, so the channel row shows an error.
     */
    public function failing(): static
    {
        return $this->state(fn (): array => [
            'last_refresh_error' => "Couldn't reach the feed.",
            'last_refreshed_at' => now()->subHours(2),
        ]);
    }

    /**
     * Every video on this channel plays at the given speed.
     */
    public function atSpeed(float $rate): static
    {
        return $this->state(fn (): array => ['playback_rate' => $rate]);
    }

    public function archived(): static
    {
        return $this->state(fn (array $attributes): array => [
            'avatar_path' => 'calm-tube/avatars/'.$attributes['youtube_channel_id'].'.jpg',
        ]);
    }
}
