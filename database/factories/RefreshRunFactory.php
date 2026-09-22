<?php

namespace Database\Factories;

use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Models\Channel;
use App\Models\RefreshRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RefreshRun>
 */
class RefreshRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel_id' => Channel::factory(),
            'trigger' => RefreshTrigger::Manual,
            'status' => RefreshStatus::Ok,
            'new_videos_count' => 0,
            'error_message' => null,
            'started_at' => now()->subMinutes(14),
            'finished_at' => now()->subMinutes(14)->addSeconds(3),
        ];
    }

    public function scheduled(): static
    {
        return $this->state(fn (): array => ['trigger' => RefreshTrigger::Scheduled]);
    }

    /**
     * The feed worked but the API did not, so videos have no duration yet.
     */
    public function degraded(): static
    {
        return $this->state(fn (): array => ['status' => RefreshStatus::Degraded]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => RefreshStatus::Failed,
            'error_message' => "Couldn't reach the feed.",
        ]);
    }

    public function running(): static
    {
        return $this->state(fn (): array => [
            'status' => RefreshStatus::Running,
            'started_at' => now(),
            'finished_at' => null,
        ]);
    }
}
