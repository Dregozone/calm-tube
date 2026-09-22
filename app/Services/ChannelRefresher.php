<?php

namespace App\Services;

use App\Enums\RefreshStatus;
use App\Enums\RefreshTrigger;
use App\Exceptions\FeedUnavailableException;
use App\Models\Channel;
use App\Models\RefreshRun;
use App\Models\Video;
use App\Services\YouTube\RssFeedClient;
use App\Support\FeedEntry;
use App\Support\RefreshResult;
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
    public function __construct(private readonly RssFeedClient $feeds) {}

    public function refresh(
        Channel $channel,
        RefreshTrigger $trigger = RefreshTrigger::Manual,
    ): RefreshResult {
        $run = $this->startRun($channel, $trigger);

        try {
            $feed = $this->feeds->fetch(
                $channel->youtube_channel_id,
                $channel->feed_etag,
                $channel->feed_last_modified,
            );
        } catch (FeedUnavailableException $exception) {
            return $this->fail($channel, $run, $exception->getMessage());
        }

        $newVideos = $feed->notModified
            ? 0
            : $this->store($channel, $feed->entries);

        $channel->forceFill([
            'feed_etag' => $feed->etag,
            'feed_last_modified' => $feed->lastModified,
            'last_refreshed_at' => now(),
            'last_refresh_error' => null,
        ])->save();

        Log::channel('calm')->info('Channel refreshed', [
            'channel_id' => $channel->youtube_channel_id,
            'new' => $newVideos,
            'not_modified' => $feed->notModified,
        ]);

        return $this->finish($run, RefreshResult::ok($newVideos));
    }

    /**
     * Stores the entries this channel has not seen before.
     *
     * Entries already in the database are skipped entirely. This is where the
     * archive guarantee lives: a retitled or re-thumbnailed video is never
     * looked at again, so it cannot change.
     *
     * @param  list<FeedEntry>  $entries
     */
    private function store(Channel $channel, array $entries): int
    {
        if ($entries === []) {
            return 0;
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

        $created = 0;

        foreach ($entries as $entry) {
            if (in_array($entry->videoId, $known, true)) {
                continue;
            }

            $channel->videos()->create([
                'youtube_video_id' => $entry->videoId,
                'title' => $entry->title,
                'description' => $entry->description,
                'published_at' => $entry->publishedAt,
                'thumbnail_url' => $entry->thumbnailUrl,
            ]);

            $created++;
        }

        return $created;
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
