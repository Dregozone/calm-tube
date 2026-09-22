<?php

namespace App\Console\Commands;

use App\Enums\RefreshTrigger;
use App\Jobs\RefreshChannel;
use App\Models\Channel;
use App\Support\RefreshResult;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Fetches new uploads for every enabled channel.
 *
 * One channel failing never stops the rest: each result is reported and the
 * run carries on.
 */
class RefreshChannelsCommand extends Command
{
    protected $signature = 'calm:refresh
                            {--channel= : Refresh only this YouTube channel id}
                            {--scheduled : Record the run as scheduled rather than manual}';

    protected $description = 'Fetch new uploads from the channels you follow';

    public function handle(): int
    {
        $channels = $this->channels();

        if (! $channels instanceof Collection) {
            return self::FAILURE;
        }

        if ($channels->isEmpty()) {
            $this->info('No channels to refresh yet.');

            return self::SUCCESS;
        }

        $trigger = $this->option('scheduled')
            ? RefreshTrigger::Scheduled
            : RefreshTrigger::Manual;

        $newVideos = 0;
        $failed = 0;

        foreach ($channels as $channel) {
            $result = RefreshChannel::dispatchSync($channel, $trigger);

            if (! $result instanceof RefreshResult) {
                continue;
            }

            if ($result->isFailed()) {
                $failed++;
                $this->error("{$channel->display_name}: {$result->errorMessage}");

                continue;
            }

            $newVideos += $result->newVideos;
            $this->line("{$channel->display_name}: {$this->describe($result->newVideos)}");
        }

        $this->newLine();
        $this->info(sprintf(
            '%s across %d %s.%s',
            $this->describe($newVideos),
            $channels->count(),
            Str::plural('channel', $channels->count()),
            $failed === 0 ? '' : " {$failed} failed."
        ));

        return self::SUCCESS;
    }

    /**
     * Null means the requested channel is not followed.
     *
     * @return Collection<int, Channel>|null
     */
    private function channels(): ?Collection
    {
        $requested = $this->option('channel');

        if (! is_string($requested) || $requested === '') {
            return Channel::query()->enabled()->get();
        }

        $channel = Channel::query()
            ->where('youtube_channel_id', $requested)
            ->first();

        if ($channel === null) {
            $this->error("Channel {$requested} not found. Add it first.");

            return null;
        }

        return new Collection([$channel]);
    }

    private function describe(int $count): string
    {
        return $count === 0
            ? 'No new videos'
            : sprintf('%d new %s', $count, Str::plural('video', $count));
    }
}
