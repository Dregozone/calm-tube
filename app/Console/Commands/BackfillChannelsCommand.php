<?php

namespace App\Console\Commands;

use App\Exceptions\YouTubeException;
use App\Models\Channel;
use App\Services\ChannelBackfiller;
use App\Services\YouTube\DataApiClient;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Recovers uploads that fell out of the fifteen entry RSS window.
 *
 * A channel posting Shorts heavily can push its long-form videos out of that
 * window within hours, so on a busy channel the feed never sees them. This
 * walks the uploads playlist instead, which holds the whole history.
 *
 * Cheap enough to be unremarkable: one quota unit lists fifty videos, so a
 * couple of hundred per channel across a few dozen channels is a fraction of
 * one day's allowance.
 */
class BackfillChannelsCommand extends Command
{
    protected $signature = 'calm:backfill
                            {--channel= : Backfill only this YouTube channel id}
                            {--depth=200 : How many uploads to walk back per channel}';

    protected $description = 'Recover older uploads the RSS feed can no longer reach';

    public function handle(DataApiClient $api, ChannelBackfiller $backfiller): int
    {
        if (! $api->isConfigured()) {
            $this->error('Backfilling needs a YouTube API key. Set YOUTUBE_API_KEY in .env.');

            return self::FAILURE;
        }

        $depth = max((int) $this->option('depth'), 1);
        $channels = $this->channels();

        if ($channels === null) {
            return self::FAILURE;
        }

        if ($channels->isEmpty()) {
            $this->info('No channels to backfill yet.');

            return self::SUCCESS;
        }

        $recovered = 0;

        foreach ($channels as $channel) {
            try {
                $found = $backfiller->backfill($channel, $depth)->count();
            } catch (YouTubeException $exception) {
                $this->error("{$channel->display_name}: {$exception->getMessage()}");

                break;
            }

            $recovered += $found;
            $this->line("{$channel->display_name}: recovered {$found} ".Str::plural('video', $found));
        }

        $this->newLine();
        $this->info(sprintf(
            'Recovered %d %s across %d %s.',
            $recovered,
            Str::plural('video', $recovered),
            $channels->count(),
            Str::plural('channel', $channels->count())
        ));

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Channel>|null
     */
    private function channels()
    {
        $requested = $this->option('channel');

        if (! is_string($requested) || $requested === '') {
            return Channel::query()->enabled()->get();
        }

        $channel = Channel::query()->where('youtube_channel_id', $requested)->first();

        if ($channel === null) {
            $this->error("Channel {$requested} not found. Add it first.");

            return null;
        }

        return new Collection([$channel]);
    }
}
