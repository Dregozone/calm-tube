<?php

namespace App\Services;

use App\Models\Channel;
use App\Models\Video;
use App\Services\YouTube\DataApiClient;
use App\Support\ChannelData;
use App\Support\FeedEntry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Recovers uploads the RSS feed can no longer reach.
 *
 * The feed holds fifteen entries whatever they are, so a channel that posts
 * Shorts heavily pushes its long-form videos out of that window within hours.
 * The uploads playlist holds the whole history, so it is where those videos
 * are found.
 */
class ChannelBackfiller
{
    public function __construct(
        private readonly DataApiClient $api,
        private readonly VideoEnricher $enricher,
    ) {}

    /**
     * Walks the uploads playlist and stores whatever is missing.
     *
     * Stops early at the first video already stored when $untilKnown is set,
     * which is what an ordinary refresh wants: it only needs to close the gap
     * since last time, not re-walk the archive.
     *
     * @return Collection<int, Video>
     */
    public function backfill(Channel $channel, int $depth, bool $untilKnown = false): Collection
    {
        /** @var Collection<int, Video> $created */
        $created = new Collection;

        $playlistId = $this->uploadsPlaylistId($channel);

        if ($playlistId === null) {
            return $created;
        }

        $pageToken = null;
        $seen = 0;

        do {
            $page = $this->api->uploadsPage($playlistId, $pageToken);
            $entries = $page['entries'];

            if ($entries === []) {
                break;
            }

            $known = $this->knownVideoIds($entries);

            foreach ($entries as $entry) {
                $seen++;

                if (in_array($entry->videoId, $known, true)) {
                    if ($untilKnown) {
                        return $this->finish($channel, $created);
                    }

                    continue;
                }

                $created->push($this->store($channel, $entry));
            }

            $pageToken = $page['nextPageToken'];
        } while ($pageToken !== null && $seen < $depth);

        return $this->finish($channel, $created);
    }

    /**
     * Channels added without an API key have no uploads playlist stored, so it
     * is fetched on demand, along with the handle and avatar that were also
     * unavailable at the time.
     */
    private function uploadsPlaylistId(Channel $channel): ?string
    {
        if ($channel->uploads_playlist_id !== null) {
            return $channel->uploads_playlist_id;
        }

        $data = $this->api->channelById($channel->youtube_channel_id);

        if (! $data instanceof ChannelData) {
            Log::channel('calm')->warning('Could not resolve uploads playlist', [
                'channel_id' => $channel->youtube_channel_id,
            ]);

            return null;
        }

        $channel->forceFill(array_filter([
            'uploads_playlist_id' => $data->uploadsPlaylistId,
            'handle' => $channel->handle ?? $data->handle,
            'avatar_url' => $channel->avatar_url ?? $data->avatarUrl,
        ]))->save();

        return $data->uploadsPlaylistId;
    }

    /**
     * @param  list<FeedEntry>  $entries
     * @return list<string>
     */
    private function knownVideoIds(array $entries): array
    {
        return array_values(Video::query()
            ->whereIn('youtube_video_id', array_map(
                fn (FeedEntry $entry): string => $entry->videoId,
                $entries,
            ))
            ->pluck('youtube_video_id')
            ->all());
    }

    private function store(Channel $channel, FeedEntry $entry): Video
    {
        return $channel->videos()->create([
            'youtube_video_id' => $entry->videoId,
            'title' => $entry->title,
            'description' => $entry->description,
            'published_at' => $entry->publishedAt,
            'thumbnail_url' => $entry->thumbnailUrl,
        ]);
    }

    /**
     * @param  Collection<int, Video>  $created
     * @return Collection<int, Video>
     */
    private function finish(Channel $channel, Collection $created): Collection
    {
        if ($created->isNotEmpty()) {
            $this->enricher->enrich($created);
            $this->enricher->detectShorts($created);
            $this->enricher->archiveThumbnails($created);

            Log::channel('calm')->info('Channel backfilled', [
                'channel_id' => $channel->youtube_channel_id,
                'recovered' => $created->count(),
            ]);
        }

        return $created;
    }
}
