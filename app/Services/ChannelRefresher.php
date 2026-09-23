<?php

namespace App\Services;

use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Exceptions\FeedUnavailableException;
use App\Exceptions\YouTubeException;
use App\Models\Channel;
use App\Models\RefreshRun;
use App\Models\Video;
use App\Services\YouTube\DataApiClient;
use App\Services\YouTube\RssFeedClient;
use App\Support\FeedEntry;
use App\Support\FeedResponse;
use App\Support\RefreshResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Runs one channel refresh from end to end.
 *
 * The only place that writes to videos, and the only place that knows the
 * order of operations. Failures are returned rather than thrown, so a run
 * over many channels never stops because one of them was unreachable.
 */
class ChannelRefresher
{
    /**
     * How far an overflow recovery will walk back before giving up, so a
     * misjudged trigger cannot run away through a channel's whole history.
     */
    private const int OVERFLOW_LIMIT = 200;

    public function __construct(
        private readonly RssFeedClient $feeds,
        private readonly DataApiClient $api,
        private readonly VideoEnricher $enricher,
        private readonly ChannelBackfiller $backfiller,
        private readonly ChannelSampler $sampler,
    ) {}

    public function refresh(
        Channel $channel,
        RefreshTrigger $trigger = RefreshTrigger::Manual,
    ): RefreshResult {
        $run = $this->startRun($channel, $trigger);

        try {
            $feed = $this->fetchFeed($channel);
        } catch (FeedUnavailableException $exception) {
            return $this->refreshFromUploads($channel, $run, $exception);
        }

        $created = $feed->notModified
            ? new Collection
            : $this->store($channel, $feed->entries);

        $degradedBecause = $this->enrich($created);

        $recovered = $this->recoverOverflow($channel, $feed->entries, $created->count());

        $stored = $created->merge($recovered);

        // After enrichment, because the rule ranks on duration and a video
        // with no duration yet is never set aside.
        $this->sampler->applyTo($channel, $stored);

        $channel->forceFill([
            'feed_etag' => $feed->etag,
            'feed_last_modified' => $feed->lastModified,
            'last_refreshed_at' => now(),
            'last_refresh_error' => null,
        ])->save();

        $newVideos = $stored->count();
        $reachedFeed = $this->reachedFeed($stored);

        Log::channel('calm')->info('Channel refreshed', [
            'channel_id' => $channel->youtube_channel_id,
            'new' => $newVideos,
            'reached_feed' => $reachedFeed,
            'recovered' => $recovered->count(),
            'not_modified' => $feed->notModified,
            'degraded' => $degradedBecause,
        ]);

        return $this->finish($run, $degradedBecause === null
            ? RefreshResult::ok($newVideos, $reachedFeed)
            : RefreshResult::degraded($newVideos, $degradedBecause, $reachedFeed));
    }

    /**
     * Stores the entries this channel has not seen before.
     *
     * Entries already in the database are skipped entirely. This is where the
     * archive guarantee lives: a retitled or re-thumbnailed video is never
     * looked at again, so it cannot change.
     *
     * @param  list<FeedEntry>  $entries
     * @return Collection<int, Video>
     */
    private function store(Channel $channel, array $entries): Collection
    {
        /** @var Collection<int, Video> $created */
        $created = new Collection;

        if ($entries === []) {
            return $created;
        }

        // Checked across every channel, not just this one: a YouTube video id
        // belongs to exactly one video, and the column is unique.
        $known = Video::query()
            ->whereIn('youtube_video_id', array_map(
                fn (FeedEntry $entry): string => $entry->videoId,
                $entries,
            ))
            ->pluck('youtube_video_id')
            ->all();

        foreach ($entries as $entry) {
            if (in_array($entry->videoId, $known, true)) {
                continue;
            }

            $created->push($channel->videos()->create([
                'youtube_video_id' => $entry->videoId,
                'title' => $entry->title,
                'description' => $entry->description,
                'published_at' => $entry->publishedAt,
                'thumbnail_url' => $entry->thumbnailUrl,
            ]));
        }

        return $created;
    }

    /**
     * Adds durations, live status and Shorts detection to newly stored videos.
     *
     * Returns why the refresh was degraded, or null if it was not. A missing
     * key or an exhausted quota costs durations, never the videos themselves.
     *
     * @param  Collection<int, Video>  $created
     */
    private function enrich(Collection $created): ?string
    {
        if ($created->isEmpty()) {
            return null;
        }

        $degradedBecause = null;

        if ($this->api->isConfigured()) {
            try {
                $this->enricher->enrich($created);
            } catch (YouTubeException $exception) {
                $degradedBecause = $exception->getMessage();
            }
        } else {
            $degradedBecause = 'No YouTube API key, so durations are unavailable.';
        }

        $this->enricher->detectShorts($created);
        $this->enricher->archiveThumbnails($created);

        return $degradedBecause;
    }

    /**
     * @throws FeedUnavailableException
     */
    private function fetchFeed(Channel $channel): FeedResponse
    {
        if (! config('calm-tube.refresh.try_feed')) {
            throw FeedUnavailableException::disabled($channel->youtube_channel_id);
        }

        return $this->feeds->fetch(
            $channel->youtube_channel_id,
            $channel->feed_etag,
            $channel->feed_last_modified,
        );
    }

    /**
     * The uploads playlist holds the same uploads the feed would have listed,
     * so an unreachable feed costs a quota unit rather than the refresh.
     *
     * Without an API key there is nowhere else to look, and the refresh fails
     * as it did before.
     */
    private function refreshFromUploads(
        Channel $channel,
        RefreshRun $run,
        FeedUnavailableException $exception,
    ): RefreshResult {
        if (! $this->api->isConfigured()) {
            return $this->fail($channel, $run, $exception->getMessage());
        }

        Log::channel('calm')->warning('Feed unavailable, using the uploads playlist', [
            'channel_id' => $channel->youtube_channel_id,
            'reason' => $exception->getMessage(),
        ]);

        $recovered = $this->backfiller->backfill($channel, self::OVERFLOW_LIMIT, untilKnown: true);

        $this->sampler->applyTo($channel, $recovered);

        $reachedFeed = $this->reachedFeed($recovered);

        $channel->forceFill([
            // Validators belong to a feed that did not answer.
            'feed_etag' => null,
            'feed_last_modified' => null,
            'last_refreshed_at' => now(),
            'last_refresh_error' => null,
        ])->save();

        return $this->finish($run, RefreshResult::ok($recovered->count(), $reachedFeed));
    }

    /**
     * A feed where every entry is new means the fifteen entry window may have
     * overflowed since last time, taking videos with it. When that happens the
     * uploads playlist is walked back to the first video already stored.
     *
     * Skipped on a channel's first refresh, where everything is new by
     * definition; calm:backfill is the explicit way to reach further back.
     *
     * @param  list<FeedEntry>  $entries
     * @return Collection<int, Video>
     */
    private function recoverOverflow(Channel $channel, array $entries, int $created): Collection
    {
        $isFirstRefresh = $channel->videos()->count() === $created;

        if ($entries === [] || $created < count($entries) || $isFirstRefresh) {
            return new Collection;
        }

        if (! $this->api->isConfigured()) {
            return new Collection;
        }

        return $this->backfiller->backfill($channel, self::OVERFLOW_LIMIT, untilKnown: true);
    }

    /**
     * How many of the videos just stored will actually appear in the feed.
     *
     * Asked of the database rather than of the models, because enrichment and
     * sampling have both written to them since they were made, and because
     * scopeInFeed is the only definition of the answer.
     *
     * @param  Collection<int, Video>  $stored
     */
    private function reachedFeed(Collection $stored): int
    {
        if ($stored->isEmpty()) {
            return 0;
        }

        return Video::query()
            ->inFeed()
            ->whereKey($stored->map(fn (Video $video): int => $video->getKey())->all())
            ->count();
    }

    private function startRun(Channel $channel, RefreshTrigger $trigger): RefreshRun
    {
        return RefreshRun::create([
            'channel_id' => $channel->id,
            'trigger' => $trigger,
            'status' => RefreshStatus::Running,
            'started_at' => now(),
        ]);
    }

    private function finish(RefreshRun $run, RefreshResult $result): RefreshResult
    {
        $run->update([
            'status' => $result->status,
            'new_videos_count' => $result->newVideos,
            'error_message' => $result->errorMessage,
            'finished_at' => now(),
        ]);

        return $result;
    }

    private function fail(Channel $channel, RefreshRun $run, string $message): RefreshResult
    {
        Log::channel('calm')->error('Channel refresh failed', [
            'channel_id' => $channel->youtube_channel_id,
            'error' => $message,
        ]);

        $channel->forceFill(['last_refresh_error' => $message])->save();

        return $this->finish($run, RefreshResult::failed($message));
    }
}
