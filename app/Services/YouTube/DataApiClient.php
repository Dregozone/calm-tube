<?php

namespace App\Services\YouTube;

use App\Enums\LiveStatus;
use App\Exceptions\ApiRequestException;
use App\Exceptions\QuotaExceededException;
use App\Support\ChannelData;
use App\Support\FeedEntry;
use App\Support\VideoData;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The only thing that talks to the YouTube Data API v3.
 *
 * Reads cost one quota unit per call regardless of how many ids or parts they
 * carry, so lookups are batched and search.list is never used.
 */
class DataApiClient
{
    private const string BASE_URL = 'https://www.googleapis.com/youtube/v3/';

    /**
     * Deliberately excludes snippet. It costs nothing extra, but it carries the
     * title and description, and having them in the response is a standing
     * invitation to overwrite the archived ones.
     */
    private const string VIDEO_PARTS = 'contentDetails,liveStreamingDetails,status';

    private const string CHANNEL_PARTS = 'snippet,contentDetails';

    /**
     * snippet is required here, unlike videos.list, because a playlist item is
     * the first and only source of a backfilled video's archived title,
     * description and thumbnail. Requesting it to create a row is fine;
     * requesting it to update one is what the archive rule forbids.
     */
    private const string PLAYLIST_PARTS = 'snippet,contentDetails';

    private const int BATCH_SIZE = 50;

    public function __construct(private readonly DurationParser $durations) {}

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    /**
     * Details for the given videos, keyed by video id.
     *
     * Ids YouTube does not return are simply absent: those videos have been
     * deleted, made private, or blocked.
     *
     * @param  list<string>  $ids
     * @return Collection<string, VideoData>
     */
    public function videos(array $ids): Collection
    {
        /** @var Collection<string, VideoData> $videos */
        $videos = collect();

        if ($ids === [] || ! $this->isConfigured()) {
            return $videos;
        }

        foreach (array_chunk($ids, self::BATCH_SIZE) as $batch) {
            $payload = $this->get('videos', [
                'part' => self::VIDEO_PARTS,
                'id' => implode(',', $batch),
            ]);

            foreach ($this->items($payload) as $item) {
                $videoId = $this->text(data_get($item, 'id'));

                if ($videoId === null) {
                    continue;
                }

                $videos->put($videoId, $this->toVideoData($videoId, $item));
            }
        }

        return $videos;
    }

    /**
     * One page of a channel's uploads, newest first.
     *
     * The uploads playlist holds a channel's whole history, unlike the RSS
     * feed's fifteen entries, so this is how videos pushed out of that window
     * are recovered.
     *
     * @return array{entries: list<FeedEntry>, nextPageToken: ?string}
     */
    public function uploadsPage(string $playlistId, ?string $pageToken = null): array
    {
        if (! $this->isConfigured()) {
            return ['entries' => [], 'nextPageToken' => null];
        }

        $payload = $this->get('playlistItems', array_filter([
            'part' => self::PLAYLIST_PARTS,
            'playlistId' => $playlistId,
            'maxResults' => self::BATCH_SIZE,
            'pageToken' => $pageToken,
        ]));

        $entries = [];

        foreach ($this->items($payload) as $item) {
            $entry = $this->toEntry($item);

            if ($entry instanceof FeedEntry) {
                $entries[] = $entry;
            }
        }

        return [
            'entries' => $entries,
            'nextPageToken' => $this->text(data_get($payload, 'nextPageToken')),
        ];
    }

    private function toEntry(mixed $item): ?FeedEntry
    {
        $videoId = $this->text(data_get($item, 'contentDetails.videoId'))
            ?? $this->text(data_get($item, 'snippet.resourceId.videoId'));

        $title = $this->text(data_get($item, 'snippet.title'));

        // videoPublishedAt is when the video went up; snippet.publishedAt is
        // when it was added to the playlist, which can differ.
        $published = $this->text(data_get($item, 'contentDetails.videoPublishedAt'))
            ?? $this->text(data_get($item, 'snippet.publishedAt'));

        if ($videoId === null || $title === null || $published === null) {
            return null;
        }

        return new FeedEntry(
            videoId: $videoId,
            title: $title,
            description: $this->text(data_get($item, 'snippet.description')),
            publishedAt: CarbonImmutable::parse($published),
            thumbnailUrl: $this->largestThumbnail(data_get($item, 'snippet.thumbnails')),
        );
    }

    /**
     * The biggest variant present in the response, taken as given.
     *
     * YouTube only includes maxres for uploads that have one, so picking from
     * what is there avoids guessing at a URL that may not exist.
     */
    private function largestThumbnail(mixed $thumbnails): ?string
    {
        foreach (['maxres', 'standard', 'high', 'medium', 'default'] as $variant) {
            $url = $this->text(data_get($thumbnails, "{$variant}.url"));

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    public function channelById(string $channelId): ?ChannelData
    {
        return $this->channel(['id' => $channelId]);
    }

    public function channelByHandle(string $handle): ?ChannelData
    {
        return $this->channel(['forHandle' => Str::start($handle, '@')]);
    }

    public function channelByUsername(string $username): ?ChannelData
    {
        return $this->channel(['forUsername' => $username]);
    }

    /**
     * Resolves any video to the channel that owns it, which is how a pasted
     * video link becomes a followable channel.
     *
     * This is the one place snippet is requested; there is no stored video for
     * it to corrupt.
     */
    public function channelIdForVideo(string $videoId): ?string
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $payload = $this->get('videos', ['part' => 'snippet', 'id' => $videoId]);

        return $this->text(data_get($this->items($payload), '0.snippet.channelId'));
    }

    /**
     * @param  array<string, string>  $lookup
     */
    private function channel(array $lookup): ?ChannelData
    {
        if (! $this->isConfigured()) {
            return null;
        }

        $payload = $this->get('channels', ['part' => self::CHANNEL_PARTS, ...$lookup]);
        $item = data_get($this->items($payload), '0');

        $channelId = $this->text(data_get($item, 'id'));
        $title = $this->text(data_get($item, 'snippet.title'));

        if ($channelId === null || $title === null) {
            return null;
        }

        return new ChannelData(
            channelId: $channelId,
            title: $title,
            handle: $this->text(data_get($item, 'snippet.customUrl')),
            avatarUrl: $this->text(data_get($item, 'snippet.thumbnails.high.url'))
                ?? $this->text(data_get($item, 'snippet.thumbnails.default.url')),
            uploadsPlaylistId: $this->text(data_get($item, 'contentDetails.relatedPlaylists.uploads')),
        );
    }

    private function toVideoData(string $videoId, mixed $item): VideoData
    {
        $scheduledStartAt = $this->text(data_get($item, 'liveStreamingDetails.scheduledStartTime'));

        return new VideoData(
            videoId: $videoId,
            durationSeconds: $this->durations->toSeconds(
                $this->text(data_get($item, 'contentDetails.duration'))
            ),
            liveStatus: $this->liveStatus($item),
            scheduledStartAt: $scheduledStartAt === null
                ? null
                : CarbonImmutable::parse($scheduledStartAt),
        );
    }

    /**
     * Derived from liveStreamingDetails rather than snippet.liveBroadcastContent,
     * so the title never has to be in the response.
     */
    private function liveStatus(mixed $item): LiveStatus
    {
        $details = data_get($item, 'liveStreamingDetails');

        if (! is_array($details)) {
            return LiveStatus::None;
        }

        // Started and ended: an ordinary video now.
        if (isset($details['actualEndTime'])) {
            return LiveStatus::None;
        }

        if (isset($details['actualStartTime'])) {
            return LiveStatus::Live;
        }

        return isset($details['scheduledStartTime'])
            ? LiveStatus::Upcoming
            : LiveStatus::None;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function get(string $endpoint, array $query): array
    {
        $response = $this->request()->get(self::BASE_URL.$endpoint, [
            ...$query,
            'key' => $this->apiKey(),
        ]);

        if ($response->forbidden() && $this->isQuotaFailure($response)) {
            throw QuotaExceededException::make();
        }

        if ($response->failed()) {
            throw ApiRequestException::status(
                $endpoint,
                $response->status(),
                $this->failureReason($response)
            );
        }

        $payload = $response->json();

        return is_array($payload) ? $payload : [];
    }

    private function request(): PendingRequest
    {
        return Http::retry(
            max((int) config('calm-tube.refresh.retries'), 1),
            (int) config('calm-tube.refresh.retry_delay'),
            fn (Throwable $exception): bool => $this->shouldRetry($exception),
            throw: false,
        )->timeout((int) config('calm-tube.refresh.timeout'));
    }

    /**
     * Retry a connection blip or an outage, never a refusal. Retrying a
     * rejected key or an exhausted quota only wastes time.
     */
    private function shouldRetry(Throwable $exception): bool
    {
        if ($exception instanceof ConnectionException) {
            return true;
        }

        return $exception instanceof RequestException
            && $exception->response->serverError();
    }

    private function isQuotaFailure(Response $response): bool
    {
        return $this->failureReason($response) === 'quotaExceeded';
    }

    private function failureReason(Response $response): ?string
    {
        return $this->text($response->json('error.errors.0.reason'));
    }

    /**
     * @return list<mixed>
     */
    private function items(mixed $payload): array
    {
        $items = data_get($payload, 'items');

        return is_array($items) ? array_values($items) : [];
    }

    private function text(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function apiKey(): ?string
    {
        return $this->text(config('calm-tube.api_key'));
    }
}
