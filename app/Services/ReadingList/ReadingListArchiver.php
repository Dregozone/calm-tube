<?php

namespace App\Services\ReadingList;

use App\Exceptions\YouTubeException;
use App\Models\Channel;
use App\Models\Video;
use App\Services\VideoEnricher;
use App\Services\YouTube\DataApiClient;
use App\Services\YouTube\ImageArchiver;
use App\Support\ChannelData;
use App\Support\DiscoveredVideo;
use Illuminate\Support\Collection;

/**
 * Archives reading list videos the first time they are seen, so they play in
 * Calm Tube's own player and keep the form they had when you first saw them.
 *
 * A video from a channel you do not follow still needs a channel to belong
 * to. That channel is archived as unfollowed: it is never refreshed, never
 * reaches the feed, and never appears among the channels you chose.
 */
class ReadingListArchiver
{
    public function __construct(
        private readonly DataApiClient $api,
        private readonly VideoEnricher $enricher,
        private readonly ImageArchiver $images,
    ) {}

    /**
     * Archives whichever of these videos are not already here. Videos already
     * archived are left exactly as they are.
     *
     * Needs an API key, because nothing else says which channel a video
     * belongs to; without one the videos wait for the next refresh.
     *
     * @param  list<string>  $videoIds
     */
    public function archive(array $videoIds): void
    {
        $known = Video::query()->whereIn('youtube_video_id', $videoIds)->pluck('youtube_video_id')->all();
        $missing = array_values(array_diff(array_unique($videoIds), $known));

        if ($missing === [] || ! $this->api->isConfigured()) {
            return;
        }

        try {
            $discovered = $this->api->discoveredVideos($missing);
        } catch (YouTubeException) {
            return;
        }

        /** @var Collection<int, Video> $created */
        $created = new Collection;

        foreach ($discovered as $video) {
            $created->push($this->channelFor($video)->videos()->create([
                'youtube_video_id' => $video->entry->videoId,
                'title' => $video->entry->title,
                'description' => $video->entry->description,
                'published_at' => $video->entry->publishedAt,
                'thumbnail_url' => $video->entry->thumbnailUrl,
            ]));
        }

        if ($created->isEmpty()) {
            return;
        }

        try {
            $this->enricher->enrich($created);
        } catch (YouTubeException) {
            // Durations are nice to have; the nightly enrich picks them up.
        }

        $this->enricher->detectShorts($created);
        $this->enricher->archiveThumbnails($created);
    }

    /**
     * The channel you follow, if you do. Otherwise one archived as
     * unfollowed, which nothing but you ever turns into a followed one.
     */
    private function channelFor(DiscoveredVideo $video): Channel
    {
        $existing = Channel::query()->firstWhere('youtube_channel_id', $video->channelId);

        if ($existing instanceof Channel) {
            return $existing;
        }

        $details = $this->channelDetails($video->channelId);

        $channel = Channel::query()->create([
            'youtube_channel_id' => $video->channelId,
            'title' => $details->title ?? $video->channelTitle,
            'handle' => $details?->handle,
            'avatar_url' => $details?->avatarUrl,
            'uploads_playlist_id' => $details?->uploadsPlaylistId,
            'is_enabled' => false,
            'is_followed' => false,
        ]);

        $avatarPath = $this->images->archiveAvatar($channel);

        if ($avatarPath !== null) {
            $channel->forceFill(['avatar_path' => $avatarPath])->save();
        }

        return $channel;
    }

    /**
     * For the avatar. A channel without one is still a channel.
     */
    private function channelDetails(string $channelId): ?ChannelData
    {
        try {
            return $this->api->channelById($channelId);
        } catch (YouTubeException) {
            return null;
        }
    }
}
