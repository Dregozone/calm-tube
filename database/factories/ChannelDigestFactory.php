<?php

namespace Database\Factories;

use App\Models\Channel;
use App\Models\ChannelDigest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelDigest>
 */
class ChannelDigestFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'week_starts_on' => now()->subWeek()->startOfWeek()->toDateString(),
            'method' => ChannelDigest::METHOD_AI,
            'model' => 'qwen3.5:4b',
            'picked_video_ids' => [],
        ];
    }
}
