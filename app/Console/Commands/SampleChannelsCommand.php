<?php

namespace App\Console\Commands;

use App\Models\Channel;
use App\Services\ChannelSampler;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Re-applies each channel's sample limit across its whole history.
 *
 * Refreshes sample the days they touch, which is enough day to day. This is
 * for the times that is not: durations that arrived late from calm:enrich, a
 * limit changed by hand, or a backfill that brought in older uploads.
 */
class SampleChannelsCommand extends Command
{
    protected $signature = 'calm:sample {--channel= : Only this YouTube channel id}';

    protected $description = 'Re-apply the sample limits that decide how much of a channel reaches the feed';

    public function handle(ChannelSampler $sampler): int
    {
        $channels = $this->channels();

        if ($channels->isEmpty()) {
            $this->info('No channels are being sampled.');

            return self::SUCCESS;
        }

        $setAside = 0;

        foreach ($channels as $channel) {
            $count = $sampler->apply($channel);
            $setAside += $count;

            $this->line(sprintf(
                '%s: keeping %d a day, %d set aside.',
                $channel->display_name,
                (int) $channel->sample_limit,
                $count,
            ));
        }

        $this->newLine();
        $this->info(sprintf(
            '%d %s set aside across %d %s.',
            $setAside,
            Str::plural('video', $setAside),
            $channels->count(),
            Str::plural('channel', $channels->count()),
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Channel>
     */
    private function channels()
    {
        return Channel::query()
            ->whereNotNull('sample_limit')
            ->when(
                $this->option('channel'),
                fn ($query, string $id) => $query->where('youtube_channel_id', $id)
            )
            ->orderBy('title')
            ->get();
    }
}
